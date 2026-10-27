"""Sequence decisions use synthetic measurements, never customer camera images."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from src.ai_service.face_liveness import check_sequence

class FaceLivenessSequenceTest(unittest.TestCase):
    def observations(self):
        return [dict(ms=i*100,faces=1,hash=str(i),left=.02,right=.02,box=(.5,.5,.3,.5)) for i in range(60)]
    def blink(self,obs,start):
        for i in (start,start+1):obs[i].update(left=.9,right=.9)
    def test_open_closed_open_counts_once_and_requires_the_requested_count(self):
        obs=self.observations();self.blink(obs,10)
        self.assertTrue(check_sequence(obs,1)['accepted'])
        self.assertEqual(check_sequence(obs,2)['code'],'blink_missing')
        self.blink(obs,35);self.assertEqual(check_sequence(obs,2)['blink_count'],2)
    def test_missing_face_and_multiple_people_rejected(self):
        obs=self.observations()
        for o in obs:o['faces']=0
        self.assertEqual(check_sequence(obs,1)['code'],'no_face')
        obs=self.observations();obs[10]['faces']=2
        self.assertEqual(check_sequence(obs,1)['code'],'multiple_faces')
    def test_still_photo_and_no_blink_rejected(self):
        obs=self.observations()
        self.assertEqual(check_sequence(obs,1)['code'],'blink_missing')
        self.blink(obs,10)
        for o in obs:o['hash']='same-image'
        self.assertEqual(check_sequence(obs,1)['code'],'blink_missing')
    def test_wink_is_not_a_blink_and_final_eyes_must_be_open(self):
        obs=self.observations();obs[10]['left']=.9
        self.assertFalse(check_sequence(obs,1)['accepted'])
        self.blink(obs,30)
        for o in obs[-2:]:o.update(left=.9,right=.9)
        self.assertFalse(check_sequence(obs,1)['accepted'])
    def test_camera_interruptions_and_large_face_changes_rejected(self):
        obs=self.observations();self.blink(obs,10);obs[30]['ms']+=800
        self.assertEqual(check_sequence(obs,1)['code'],'invalid_capture')
        obs=self.observations();self.blink(obs,10);obs[25]['box']=(.9,.5,.3,.5)
        self.assertEqual(check_sequence(obs,1)['code'],'face_moved')

if __name__=='__main__':unittest.main()
