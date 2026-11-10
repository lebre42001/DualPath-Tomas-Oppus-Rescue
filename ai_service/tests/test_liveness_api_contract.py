"""Private API contract tests; no camera data, model loading, or external requests."""
import asyncio
import hashlib
import hmac
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import AsyncMock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from fastapi import HTTPException
from pydantic import ValidationError
from starlette.requests import Request
from src.ai_service.liveness_api import Capture, ready_liveness, verify_liveness


class LivenessApiContractTest(unittest.TestCase):
    def payload(self, purpose='report', count=1, blinks=0):
        return {'challenge_id': 'test', 'purpose': purpose, 'required_blinks': blinks,
                'frames': [{'jpeg': 'x'*100, 'ms': i*100} for i in range(count)]}

    def request(self, payload, key='fixture-key', signature=None):
        body = json.dumps(payload).encode()
        signature = signature or hmac.new(key.encode(), body, hashlib.sha256).hexdigest()
        async def receive():
            return {'type': 'http.request', 'body': body, 'more_body': False}
        return Request({'type': 'http', 'method': 'POST', 'path': '/liveness/verify',
                        'headers': [(b'x-liveness-signature', signature.encode()),
                                    (b'content-length', str(len(body)).encode())]}, receive)

    def test_purpose_cannot_downgrade_account_blink_or_expand_report_capture(self):
        self.assertEqual(Capture.model_validate(self.payload()).purpose, 'report')
        self.assertEqual(Capture.model_validate(self.payload('account',30,1)).required_blinks,1)
        for payload in [self.payload('account',1,0), self.payload('account',30,0),
                        self.payload('report',11,0), self.payload('report',1,1), self.payload('unknown')]:
            with self.subTest(payload={k:v for k,v in payload.items() if k != 'frames'}):
                with self.assertRaises(ValidationError):
                    Capture.model_validate(payload)

    def test_signed_purpose_is_forwarded_and_tampered_body_is_rejected(self):
        result = {'accepted': True, 'blink_count': 0, 'selfie_sha256': 'fixture-hash'}
        with patch.dict('os.environ', {'FACE_LIVENESS_KEY': 'fixture-key'}), \
             patch('src.ai_service.liveness_api.run_in_threadpool', new_callable=AsyncMock, return_value=result) as run:
            self.assertEqual(asyncio.run(verify_liveness(self.request(self.payload()))), result)
            self.assertEqual(run.await_args.args[-2:], (0,'report'))
            with self.assertRaises(HTTPException) as failure:
                asyncio.run(verify_liveness(self.request(self.payload('account',30,1),signature='tampered')))
            self.assertEqual(failure.exception.status_code,403)
            self.assertEqual(run.await_count,1)

    def test_readiness_advertises_both_purposes_without_loading_severity_model(self):
        with patch.dict('os.environ', {'FACE_LIVENESS_KEY': 'fixture-key'}), \
             patch('src.ai_service.liveness_api.get_landmarker'):
            ready = ready_liveness()
        self.assertEqual(ready['report_face_check'],'face_presence_v1')
        self.assertEqual(ready['account_check'],'face_blink_challenge_v1')


if __name__ == '__main__':
    unittest.main()
