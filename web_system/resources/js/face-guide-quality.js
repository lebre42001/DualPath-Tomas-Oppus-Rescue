// Positioning guidance only. These results never authorize verification/submission.
export function guideBounds(videoWidth, videoHeight, frameWidth, frameHeight) {
    const scale = Math.min(frameWidth / videoWidth, frameHeight / videoHeight);
    const visibleWidth = videoWidth * scale, visibleHeight = videoHeight * scale;
    const height = Math.min(visibleHeight * .82, visibleWidth * .82 / .75);
    const width = height * .75;
    return { width, height, w: width / visibleWidth, h: height / visibleHeight };
}

export function judgeFace(detections, width, height, guide) {
    const fail = (kind, message) => ({ ready: false, kind, message });
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
    if (w < Math.max(.16, guide.w * .35) || h < Math.max(.18, guide.h * .3)) {
        return fail('closer', 'Move a little closer to the camera.');
    }
    if (w > guide.w * .98 || h > guide.h * .98) return fail('back', 'Move back a little so your whole face fits.');
    const cx = x+w/2, cy = y+h/2;
    if (Math.abs(cx-.5) > guide.w*.22 || Math.abs(cy-.5) > guide.h*.23
        || x < (1-guide.w)/2-.03 || x+w > (1+guide.w)/2+.03
        || y < (1-guide.h)/2-.03 || y+h > (1+guide.h)/2+.03) {
        return fail('center', 'Move your face to the center of the guide.');
    }
    const [a,b,nose,mouth] = points;
    const eyeSpan = Math.abs(a.x-b.x), eyeY = (a.y+b.y)/2;
    // Measure roll in pixels rather than normalized axes, including portrait video.
    if (eyeSpan < w*.2 || Math.abs(a.y-b.y)*height > eyeSpan*width*.3
        || Math.abs(nose.x-(a.x+b.x)/2) > eyeSpan*.38
        || nose.y <= eyeY || mouth.y <= nose.y) {
        return fail('straight', 'Face the camera and keep your head upright.');
    }
    return { ready: true, kind: 'aligned', box: { cx, cy, w } };
}
