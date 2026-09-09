"""Offline card/portrait checks. Private evidence on stdout only, never logged."""
import io
import json
from pathlib import Path
import re
import subprocess
import sys
import time
import unicodedata
import cv2
import numpy as np
from PIL import Image, ImageOps

cv2.setNumThreads(1)
MODEL = str(Path(__file__).parent / 'models' / 'face.xml')


def load(path):
    with Image.open(path) as src:
        if src.format not in {'JPEG', 'PNG'} or src.width * src.height > 16_000_000:
            raise ValueError('Unsupported input')
        image = ImageOps.exif_transpose(src).convert('RGB')
        image.thumbnail((1800, 1800))
        return cv2.cvtColor(np.asarray(image), cv2.COLOR_RGB2GRAY)


def faces(gray, min_fraction=.002):
    classifier = cv2.CascadeClassifier(MODEL)
    if classifier.empty():
        raise ValueError('Face detector unavailable')
    result = classifier.detectMultiScale(cv2.equalizeHist(gray), 1.1, 5, minSize=(35, 35))
    return sorted([tuple(map(int, f)) for f in result if f[2]*f[3] >= gray.size*min_fraction],
                  key=lambda f: f[2]*f[3], reverse=True)


def portrait(path):
    if not path:
        return False
    image = load(path)
    for k in range(4):
        rotated = np.ascontiguousarray(np.rot90(image, k))
        found = faces(rotated, .04)
        if len(found) == 1:
            x, y, w, h = found[0]
            # A usable portrait has a substantial, unclipped face, not a tiny crowd face.
            if x > 0 and y > 0 and x+w < rotated.shape[1] and y+h < rotated.shape[0]:
                return True
    return False


def warp(gray, points):
    pts = np.asarray(points, dtype='float32').reshape(4, 2)
    sums = pts.sum(axis=1); diffs = np.diff(pts, axis=1).reshape(-1)
    ordered = np.array([pts[np.argmin(sums)], pts[np.argmin(diffs)],
                        pts[np.argmax(sums)], pts[np.argmax(diffs)]], dtype='float32')
    if len(np.unique(ordered, axis=0)) != 4:
        return None
    width = max(np.linalg.norm(ordered[1]-ordered[0]), np.linalg.norm(ordered[2]-ordered[3]))
    height = max(np.linalg.norm(ordered[3]-ordered[0]), np.linalg.norm(ordered[2]-ordered[1]))
    if height == 0 or not 1.25 < width/height < 1.95:
        return None
    transform = cv2.getPerspectiveTransform(ordered, np.float32([[0,0],[1199,0],[1199,759],[0,759]]))
    return cv2.warpPerspective(gray, transform, (1200,760))


def card_candidates(gray):
    # Try physical boundaries before a conservative face-guided crop or the full image.
    contours, _ = cv2.findContours(cv2.Canny(cv2.GaussianBlur(gray,(5,5),0),30,100),
                                   cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
    previous = []
    for contour in sorted(contours, key=cv2.contourArea, reverse=True):
        area = cv2.contourArea(contour)
        if area < gray.size*.08:
            break
        poly = cv2.approxPolyDP(contour, .025*cv2.arcLength(contour,True),True)
        if len(poly) != 4 or not cv2.isContourConvex(poly):
            continue
        box = cv2.boundingRect(poly)
        if any(sum(abs(a-b) for a,b in zip(box, old)) < 30 for old in previous):
            continue
        candidate = warp(gray,poly)
        if candidate is not None:
            previous.append(box)
            yield candidate
        if len(previous) >= 2:
            break
    found = faces(gray)
    if found:
        x,y,w,h = found[0]
        x1=max(0,round(x-.35*w)); y1=max(0,round(y-1.05*h))
        x2=min(gray.shape[1],round(x+3.7*w)); y2=min(gray.shape[0],round(y+1.6*h))
        crop=gray[y1:y2,x1:x2]
        if crop.size:
            yield crop
    yield gray


def ocr(image, mode, deadline):
    remaining = deadline-time.monotonic()
    if remaining < .15:
        return []
    image = ImageOps.autocontrast(Image.fromarray(image))
    scale = min(3, 2200/max(image.size))
    image = image.resize((max(1,round(image.width*scale)),max(1,round(image.height*scale))))
    buffer=io.BytesIO(); image.save(buffer,format='PNG')
    try:
        proc=subprocess.run(['/usr/bin/tesseract','stdin','stdout','-l','fra+eng','--psm',str(mode),'tsv'],
            input=buffer.getvalue(),stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
            env={'PATH':'/usr/bin:/bin','OMP_THREAD_LIMIT':'1'},timeout=min(2,remaining),check=True)
    except (OSError,subprocess.SubprocessError):
        return []
    if len(proc.stdout)>262144:
        return []
    rows=[]
    for line in proc.stdout.decode('utf-8',errors='replace').splitlines():
        parts=line.split('\t',11)
        if len(parts)!=12 or parts[0]!='5':
            continue
        try:
            conf=float(parts[10])
        except ValueError:
            continue
        rows.append({'text':parts[11].strip(),'confidence':conf,'line':parts[1:5],
                     'x':int(parts[6])/image.width,'y':int(parts[7])/image.height,
                     'w':int(parts[8])/image.width,'h':int(parts[9])/image.height})
    return rows


def number_candidates(rows):
    found=[]
    for row in rows:
        text=row['text']
        if re.fullmatch(r'[0-9]{10}',text):
            found.append({'value':text,'confidence':row['confidence']})
    # Join only digit-only words on the same OCR line, preserving leading zeroes.
    lines={}
    for row in rows:
        lines.setdefault(tuple(row['line']),[]).append(row)
    for words in lines.values():
        if len(words)>1 and all(re.fullmatch(r'[0-9]+',w['text']) for w in words):
            text=''.join(w['text'] for w in words)
            if len(text)==10:
                found.append({'value':text,'confidence':min(w['confidence'] for w in words)})
    return found


def label_distance(left, right):
    previous = list(range(len(right) + 1))
    for i, a in enumerate(left, 1):
        current = [i]
        for j, b in enumerate(right, 1):
            current.append(min(current[-1]+1, previous[j]+1, previous[j-1]+(a != b)))
        previous = current
    return previous[-1]


def scan_names(rows):
    """Use labels and spatial alignment, not the OCR reading order across columns."""
    grouped = {}
    for row in rows:
        grouped.setdefault(tuple(row['line']), []).append(row)
    lines = []
    for words in grouped.values():
        lines.append({'text': ' '.join(w['text'] for w in words).strip(),
                      'confidence': min(w['confidence'] for w in words),
                      'x': min(w['x'] for w in words), 'y': min(w['y'] for w in words),
                      'bottom': max(w['y'] + w['h'] for w in words)})
    result = {'firstName': '', 'lastName': ''}
    for key in result:
        anchors = []
        for line in lines:
            label = ''.join(c for c in unicodedata.normalize('NFD', line['text'].upper())
                            if unicodedata.category(c) != 'Mn')
            tokens = re.findall(r'[A-Z]+', label)
            fuzzy_first = bool(tokens and 4 <= len(tokens[0]) <= 8
                               and label_distance(tokens[0], 'PRENOM') <= 2
                               and (line['text'] != line['text'].upper() or '/' in label))
            first = bool(re.search(r'\bPRENOM\b|/\s*NON\b', label)) or fuzzy_first
            last = bool(re.search(r'^NOM(?:\s|/|$)', label)) and not first
            if (key == 'firstName' and first) or (key == 'lastName' and last):
                anchors.append(line)
        found = set()
        for anchor in anchors:
            # Never read past the next field label in the same column.
            boundaries = [line['y'] for line in lines
                          if line['y'] > anchor['bottom'] and abs(line['x']-anchor['x']) < .10
                          and re.search(r'^(?:NOM|LIEU|DATE|PR[EÉ]NOM)\b', line['text'].upper())]
            bottom = min(boundaries) if boundaries else anchor['bottom'] + .12
            candidates = []
            for line in lines:
                value = line['text']
                dy = line['y'] - anchor['bottom']
                dx = abs(line['x'] - anchor['x'])
                if (-.012 <= dy < .12 and line['y'] > anchor['y'] + .008 and line['y'] < bottom and dx < .075 and line['confidence'] >= 70
                        and 2 <= len(value) <= 100 and value == value.upper()
                        and all(c.isalpha() or c in " -'’" for c in value)
                        and not re.search(r'\b(?:SEXE|NATIONALIT[EÉ]|HTI|NAISSANCE|NOM|NON|SIYATI|STYATI|NATIONALE|NASYONAL|NASYONALITE)\b', value)):
                    candidates.append((dy + dx, value))
            if candidates:
                found.add(min(candidates)[1])
        if len(found) == 1:
            result[key] = found.pop()
    return result


def card(gray, deadline, include_fields=False):
    recognized = False
    scan_fallback = None
    for k in range(4):
        if time.monotonic()>deadline:
            break
        oriented=np.ascontiguousarray(np.rot90(gray,k))
        if not faces(oriented):
            continue
        for candidate in card_candidates(oriented):
            if time.monotonic()>deadline:
                break
            rows=ocr(candidate,11,deadline)
            text=' '.join(r['text'] for r in rows if r['confidence']>=40)
            text=''.join(c for c in unicodedata.normalize('NFD',text.upper()) if unicodedata.category(c)!='Mn')
            labels=(bool(re.search(r'\b(?:HAITI|DAYITI)\b',text))
                    and bool(re.search(r'\b(?:CARTE|KAT)\b',text))
                    and bool(re.search(r'(?:NATIONALE|NASYONAL)',text)))
            detected=labels and bool(faces(candidate))
            if not detected:
                continue
            recognized = True
            fields = scan_names(rows) if include_fields else {}
            if include_fields:
                h, w = candidate.shape
                column = candidate[:, int(w*.32):int(w*.69)]
                extra = scan_names(ocr(column, 6, deadline))
                for key, value in extra.items():
                    if value:
                        fields[key] = value if not fields[key] or fields[key] == value else ''
            if include_fields and scan_fallback is None:
                scan_fallback = {'card_detected': True, 'numbers': [], 'fields': fields}
            numbers=[dict(n, source='front') for n in number_candidates(rows)]
            height,width=candidate.shape
            roi=candidate[int(height*.63):int(height*.99),int(width*.48):]
            for mode in (6,11):
                numbers.extend(dict(n, source='roi-'+str(mode)) for n in number_candidates(ocr(roi,mode,deadline)))
            # Re-read a sharpened number zone; no character whitelist or number repair.
            sharp = cv2.addWeighted(roi, 2.0, cv2.GaussianBlur(roi, (0,0), 1.2), -1.0, 0)
            numbers.extend(dict(n, source='sharp') for n in number_candidates(ocr(sharp,6,deadline)))
            # Return evidence from ONE recognized card, never combine different documents.
            if any(n['confidence'] >= 65 for n in numbers):
                return {'card_detected':True,'numbers':numbers, **({'fields': fields} if include_fields else {})}
    return scan_fallback or {'card_detected':recognized,'numbers':[]}


def read(front, portrait_path=None):
    deadline=time.monotonic()+12
    image=load(front)
    result=card(image,deadline)
    result['portrait_detected']=portrait(portrait_path)
    return result


if __name__=='__main__':
    try:
        print(json.dumps(read(sys.argv[1],sys.argv[2] if len(sys.argv)>2 else None)))
    except Exception:
        sys.exit(1)
