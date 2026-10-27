// Lazy, same-origin runtime assets. This pinned SDK predates usage telemetry.
import { FaceDetector, FilesetResolver } from '@mediapipe/tasks-vision';
import simdLoader from '../../node_modules/@mediapipe/tasks-vision/wasm/vision_wasm_internal.js?url';
import simdBinary from '../../node_modules/@mediapipe/tasks-vision/wasm/vision_wasm_internal.wasm?url';
import plainLoader from '../../node_modules/@mediapipe/tasks-vision/wasm/vision_wasm_nosimd_internal.js?url';
import plainBinary from '../../node_modules/@mediapipe/tasks-vision/wasm/vision_wasm_nosimd_internal.wasm?url';
import model from '../face-guide/blaze_face_short_range.tflite?url';

export async function createFaceDetector() {
    const simd = await FilesetResolver.isSimdSupported();
    return FaceDetector.createFromOptions({
        wasmLoaderPath: simd ? simdLoader : plainLoader,
        wasmBinaryPath: simd ? simdBinary : plainBinary,
    }, {
        baseOptions: { modelAssetPath: model, delegate: 'CPU' },
        runningMode: 'VIDEO', minDetectionConfidence: .65, minSuppressionThreshold: .3,
    });
}
