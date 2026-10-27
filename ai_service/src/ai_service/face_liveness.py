"""Basic face/blink checks, independent of the severity model and its active config."""
from __future__ import annotations

import base64
import hashlib
import io
import json
from pathlib import Path
from threading import BoundedSemaphore, Lock

ASSET_DIR = Path(__file__).resolve().parents[2] / 'assets' / 'liveness'
_model = None
_model_lock = Lock()
_work = BoundedSemaphore(1)

MESSAGES = {
    'no_face': 'Keep one face clearly visible in good lighting, then try again.',
    'multiple_faces': 'Only one person should be visible during the blink check.',
    'blink_missing': 'Blink slowly as requested, then keep your eyes open until the check finishes.',
    'face_moved': 'Keep your face inside the guide and hold the camera steady.',
    'invalid_capture': 'The live capture was interrupted. Keep this page open and try again.',
}


def verdict(code: str) -> dict:
    return {'accepted': False, 'code': code, 'message': MESSAGES[code]}


def check_sequence(observations: list[dict], required_blinks: int) -> dict:
    if len(observations) < 30 or required_blinks not in (1, 2):
        return verdict('invalid_capture')
    times = [o['ms'] for o in observations]
    if times[0] < 0 or times[-1] - times[0] < 4000 or times[-1] > 11000:
        return verdict('invalid_capture')
    if any(not 30 <= b-a <= 400 for a, b in zip(times, times[1:])):
        return verdict('invalid_capture')
    if any(o['faces'] > 1 for o in observations):
        return verdict('multiple_faces')
    valid = [o for o in observations if o['faces'] == 1]
    if len(valid) < .95 * len(observations) or observations[-1]['faces'] != 1:
        return verdict('no_face')
    if len({o['hash'] for o in observations}) < 10:
        return verdict('blink_missing')
    baseline = valid[0]['box']
    phase, opens, blinks, closed_at = 'initial', 0, 0, None
    for o in observations:
        if o['faces'] != 1:
            phase, opens, closed_at = 'initial', 0, None
            continue
        x, y, w, h = o['box']
        if w < .16 or h < .20 or max(w, h) > .98:
            return verdict('face_moved')
        if abs(x-baseline[0]) > .25 or abs(y-baseline[1]) > .25 or not .55 <= w/baseline[2] <= 1.8:
            return verdict('face_moved')
        is_open = max(o['left'], o['right']) <= .30
        is_closed = min(o['left'], o['right']) >= .55
        if is_open:
            opens += 1
            if opens >= 2:
                if phase == 'closed' and closed_at is not None and 70 <= o['ms']-closed_at <= 1800:
                    blinks += 1
                phase, closed_at = 'ready', None
        elif is_closed:
            opens = 0
            if phase == 'ready':
                phase, closed_at = 'closed', o['ms']
        else:
            opens = 0
        if phase == 'closed' and o['ms']-closed_at > 1800:
            phase, closed_at = 'initial', None
    if blinks < required_blinks or opens < 2:
        return verdict('blink_missing')
    return {'accepted': True, 'blink_count': blinks, 'selfie_sha256': observations[-1]['hash']}


def get_landmarker():
    global _model
    with _model_lock:
        if _model is None:
            import mediapipe as mp
            metadata = json.loads((ASSET_DIR / 'metadata.json').read_text())
            model_path = ASSET_DIR / 'face_landmarker.task'
            if hashlib.sha256(model_path.read_bytes()).hexdigest() != metadata['sha256']:
                raise RuntimeError('Face task checksum mismatch')
            _model = mp.tasks.vision.FaceLandmarker.create_from_options(mp.tasks.vision.FaceLandmarkerOptions(
                base_options=mp.tasks.BaseOptions(model_asset_path=str(model_path)),
                running_mode=mp.tasks.vision.RunningMode.IMAGE,
                num_faces=2, output_face_blendshapes=True,
                min_face_detection_confidence=.6, min_face_presence_confidence=.6,
            ))
    return _model


def analyze_frames(frames: list[dict], required_blinks: int) -> dict:
    if not _work.acquire(blocking=False):
        raise BlockingIOError('Face check busy')
    try:
        import mediapipe as mp
        import numpy as np
        from PIL import Image, UnidentifiedImageError
        detector = get_landmarker()
        observations = []
        for frame in frames:
            try:
                data = base64.b64decode(frame['jpeg'], validate=True)
                if not 100 <= len(data) <= 67500:
                    return verdict('invalid_capture')
                with Image.open(io.BytesIO(data)) as source:
                    if source.format != 'JPEG' or max(source.size) > 640 or min(source.size) < 160:
                        return verdict('invalid_capture')
                    pixels = np.array(source.convert('RGB'))
            except (ValueError, UnidentifiedImageError, OSError):
                return verdict('invalid_capture')
            result = detector.detect(mp.Image(image_format=mp.ImageFormat.SRGB, data=pixels))
            count = len(result.face_landmarks)
            if count > 1:
                return verdict('multiple_faces')
            observation = {'faces': count, 'ms': frame['ms'], 'hash': hashlib.sha256(data).hexdigest()}
            if count == 1:
                points = result.face_landmarks[0]
                xs, ys = [p.x for p in points], [p.y for p in points]
                scores = {s.category_name: s.score for s in result.face_blendshapes[0]}
                observation.update(left=scores.get('eyeBlinkLeft', 0), right=scores.get('eyeBlinkRight', 0),
                                   box=((max(xs)+min(xs))/2, (max(ys)+min(ys))/2, max(xs)-min(xs), max(ys)-min(ys)))
            observations.append(observation)
        return check_sequence(observations, required_blinks)
    finally:
        _work.release()
