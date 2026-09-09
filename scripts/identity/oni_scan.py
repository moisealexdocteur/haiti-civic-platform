"""Private, transient prefill OCR; no database lookup or identity approval."""
import json
import sys
import time
from oni_ocr import card, load

if __name__ == '__main__':
    try:
        print(json.dumps(card(load(sys.argv[1]), time.monotonic() + 12, include_fields=True)))
    except Exception:
        sys.exit(1)
