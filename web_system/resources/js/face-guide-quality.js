// Positioning guidance only. These results never authorize verification/submission.
export function previewSize(videoWidth, videoHeight, maxWidth, maxHeight) {
    const scale = Math.min(maxWidth / videoWidth, maxHeight / videoHeight);
    return { width: videoWidth * scale, height: videoHeight * scale };
}

export function guideBounds(videoWidth, videoHeight, frameWidth, frameHeight) {
    const scale = Math.min(frameWidth / videoWidth, frameHeight / videoHeight);
    const visibleWidth = videoWidth * scale, visibleHeight = videoHeight * scale;
    const height = Math.min(visibleHeight * .82, visibleWidth * .82 / .75);
    const width = height * .75;
    return { width, height, w: width / visibleWidth, h: height / visibleHeight };
}

export function judgeFace(detections, width, height) {
    const fail = (kind, message, box) => ({ ready: false, kind, message, ...(box ? { box } : {}) });
    if (!detections.length) return fail('missing', 'No face detected. Center your face inside the guide.');
    if (detections.length !== 1) return fail('multiple', 'Only one person should be visible.');
    const face = detections[0], box = face.boundingBox, points = face.keypoints;
    if (!box || !points || points.length < 4 || (face.categories?.[0]?.score ?? 0) < .7) {
        return fail('missing', 'Show your whole face in good lighting.');
    }
    const x = box.originX / width, y = box.originY / height, w = box.width / width, h = box.height / height;
    if (![x,y,w,h,...points.slice(0,4).flatMap(p => [p.x,p.y])].every(Number.isFinite) || w <= 0 || h <= 0) {
        return fail('missing', 'Show your whole face in good lighting.');
    }
    const cx = x+w/2, cy = y+h/2, position = { cx, cy, w, h };
    if (w < .18 || h < .20) {
        return fail('closer', 'Move a little closer to the camera.', position);
    }
    if (w > .90 || h > .95) return fail('back', 'Move back a little so your face fits.', position);
    // The oval follows a visible face. Do not reject a usable face merely
    // because the phone is held slightly higher or lower than a fixed outline.
    if (Math.abs(cx-.5) > .27 || cy < .18 || cy > .82
        || x < -.06 || x+w > 1.06 || y < -.06 || y+h > 1.10
        || points.slice(0,4).some(p => p.x < .02 || p.x > .98 || p.y < .02 || p.y > .98)) {
        return fail('center', 'Keep your eyes, nose and mouth visible in the camera.', position);
    }
    const [a,b,nose,mouth] = points;
    const eyeSpan = Math.abs(a.x-b.x), eyeY = (a.y+b.y)/2;
    // Measure roll in pixels rather than normalized axes, including portrait video.
    if (eyeSpan < w*.2 || Math.abs(a.y-b.y)*height > eyeSpan*width*.3
        || Math.abs(nose.x-(a.x+b.x)/2) > eyeSpan*.38
        || nose.y <= eyeY || mouth.y <= nose.y) {
        return fail('straight', 'Face the camera and keep your head upright.', position);
    }
    return { ready: true, kind: 'aligned', box: position };
}
