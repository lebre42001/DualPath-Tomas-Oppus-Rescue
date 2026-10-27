# Face/blink runtime asset

Google MediaPipe Face Landmarker task (float16, version 1), Apache-2.0.
Source: https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task
The original model bytes and SHA-256 are pinned in metadata.json.
Model cards: https://developers.google.com/edge/mediapipe/solutions/vision/face_landmarker#models

This module uses face presence and eye-blink measurements for a basic camera gesture check. It does not recognize identity or certify presentation-attack detection. Recorded video, virtual cameras, or generated faces can defeat a basic blink check. No severity model/config/checkpoint is changed. Capture sequences are not training data and are not retained by the application.
