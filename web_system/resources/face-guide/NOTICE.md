# Browser face positioning guide

Google MediaPipe BlazeFace short-range, float16 version 1, Apache-2.0.
Source and the SHA-256 of the unchanged model are in `metadata.json`.
Upstream: https://github.com/google-ai-edge/mediapipe
Web task documentation: https://ai.google.dev/edge/mediapipe/solutions/vision/face_detector/web_js

The npm dependency is pinned to `@mediapipe/tasks-vision@0.10.32`. This reviewed
version predates Web usage telemetry. Its JS, SIMD/non-SIMD WASM, and model are
served from the application origin through Vite. No CDN is needed at runtime.
Preview images remain on the device and are neither stored nor uploaded by the
guide. This model only measures face presence/position, not identity or liveness.
The independent server-side blink challenge remains the submission authority.

The camera can also recognize a face in a still image. A green preview guide
therefore must never be treated as a passed blink check or a verification proof.
