"""Synthetic negatives and policy edge cases. Never include citizens' photos."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import numpy as np
from PIL import Image, ImageDraw

spec = importlib.util.spec_from_file_location('oni_ocr', Path(__file__).resolve().parents[2] / 'scripts/identity/oni_ocr.py')
v = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v)


class OniImageTests(unittest.TestCase):
    def test_number_extraction_keeps_leading_zeroes_and_does_not_repair_letters(self):
        rows=[{'text':'01234','confidence':91,'line':[1,1,1,1]},
              {'text':'56789','confidence':92,'line':[1,1,1,1]},
              {'text':'O123456789','confidence':99,'line':[1,1,1,2]}]
        self.assertEqual(v.number_candidates(rows), [{'value':'0123456789','confidence':91}])

    def test_number_fragments_from_different_lines_are_not_joined(self):
        self.assertEqual(v.number_candidates([
            {'text':'01234','confidence':91,'line':[1,1,1,1]},
            {'text':'56789','confidence':92,'line':[1,1,1,2]}]), [])

    def test_poster_with_card_words_but_no_face_is_not_a_card(self):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'poster.png'
            image=Image.new('RGB',(1000,700),'white')
            ImageDraw.Draw(image).text((50,50),'REPUBLIQUE HAITI CARTE NATIONALE 0123456789',fill='black')
            image.save(path)
            result=v.read(str(path),str(path))
            self.assertFalse(result['card_detected'])
            self.assertFalse(result['portrait_detected'])

    def test_missing_portrait_is_not_valid(self):
        self.assertFalse(v.portrait(None))

    def test_multiple_faces_or_clipped_face_require_review(self):
        with patch.object(v,'load',return_value=np.zeros((400,400),dtype='uint8')):
            for found in [[(40,40,100,100),(200,40,100,100)],[(0,0,100,100)]]:
                with patch.object(v,'faces',return_value=found):
                    self.assertFalse(v.portrait('synthetic'))

    def test_detector_failure_is_not_success(self):
        with patch.object(v,'MODEL','/nonexistent.xml'):
            with self.assertRaises(ValueError):
                v.faces(np.zeros((100,100),dtype='uint8'))


if __name__=='__main__':
    unittest.main()
