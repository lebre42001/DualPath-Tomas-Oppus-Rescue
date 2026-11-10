"""Private, signed liveness endpoint; capture frames are processed in memory only."""
from __future__ import annotations
import hashlib
import hmac
import json
import os
from typing import Literal
from fastapi import APIRouter, HTTPException, Request
from pydantic import BaseModel, Field, ValidationError, model_validator
from starlette.concurrency import run_in_threadpool
from .face_liveness import analyze_frames, get_landmarker

router = APIRouter()

class Frame(BaseModel):
    jpeg: str = Field(min_length=100, max_length=90000)
    ms: int = Field(ge=0, le=11000)

class Capture(BaseModel):
    frames: list[Frame] = Field(min_length=1, max_length=90)
    required_blinks: int = Field(ge=0, le=2)
    challenge_id: str = Field(max_length=36)
    purpose: Literal['account', 'report'] = 'account'

    @model_validator(mode='after')
    def check_purpose_requirements(self):
        if self.purpose == 'account' and (self.required_blinks not in (1, 2) or len(self.frames) < 30):
            raise ValueError('Account verification requires a blink sequence.')
        if self.purpose == 'report' and (self.required_blinks != 0 or len(self.frames) > 10):
            raise ValueError('Report verification requires a face capture.')
        return self

@router.get('/ready/liveness')
def ready_liveness():
    if not os.getenv('FACE_LIVENESS_KEY', ''):
        raise HTTPException(503, detail='Live face check is unavailable.')
    try:
        get_landmarker()
    except Exception:
        raise HTTPException(503, detail='Live face check is unavailable.') from None
    return {'status': 'ready', 'report_face_check': 'face_presence_v1', 'account_check': 'face_blink_challenge_v1'}

@router.post('/liveness/verify')
async def verify_liveness(request: Request):
    key = os.getenv('FACE_LIVENESS_KEY', '')
    if not key:
        raise HTTPException(503, detail='Live face check is unavailable.')
    if int(request.headers.get('content-length', '0')) > 8*1024*1024:
        raise HTTPException(413, detail='Capture too large.')
    body = await request.body()
    if len(body) > 8*1024*1024:
        raise HTTPException(413, detail='Capture too large.')
    expected = hmac.new(key.encode(), body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(request.headers.get('x-liveness-signature', ''), expected):
        raise HTTPException(403, detail='Unauthorized.')
    try:
        capture = Capture.model_validate(json.loads(body))
    except (ValueError, ValidationError):
        raise HTTPException(422, detail='Invalid live capture.') from None
    try:
        return await run_in_threadpool(analyze_frames, [f.model_dump() for f in capture.frames], capture.required_blinks, capture.purpose)
    except BlockingIOError:
        raise HTTPException(429, detail='Face check is busy. Please retry.') from None
    except Exception:
        raise HTTPException(503, detail='Live face check is unavailable. Please retry.') from None
