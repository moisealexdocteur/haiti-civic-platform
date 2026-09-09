import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/identity'))
from oni_ocr import scan_names

def word(text, line, x, y, confidence=95):
    return {'text':text,'line':[1,1,1,line],'x':x,'y':y,'w':.1,'h':.02,'confidence':confidence}

class ScanNamesTest(unittest.TestCase):
    def test_columns_do_not_change_name_association(self):
        rows=[word('Prénom / Non',1,.35,.25),word('Sexe / Sèks',2,.70,.25),
              word('ÉVA',3,.35,.29),word('Nom / Siyati',4,.35,.35),
              word('HTI',5,.70,.39),word('TEST-EXEMPLE',6,.35,.39)]
        self.assertEqual(scan_names(rows),{'firstName':'ÉVA','lastName':'TEST-EXEMPLE'})
    def test_unreadable_first_name_does_not_take_surname(self):
        rows=[word('Prénom / Non',1,.35,.25),word('ÉVA',2,.35,.29,30),
              word('Nom / Siyati',3,.35,.35),word('EXEMPLE',4,.35,.39)]
        self.assertEqual(scan_names(rows),{'firstName':'','lastName':'EXEMPLE'})
    def test_unlabelled_text_cannot_supply_names(self):
        self.assertEqual(scan_names([word('EXEMPLE',1,.35,.29)]),{'firstName':'','lastName':''})
