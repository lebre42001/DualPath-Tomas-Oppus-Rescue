import test from 'node:test';
import assert from 'node:assert/strict';
import { guideBounds, judgeFace, previewSize } from '../../resources/js/face-guide-quality.js';

const width = 320, height = 240;
const face = () => ({
    boundingBox: { originX: 112, originY: 72, width: 96, height: 96 },
    categories: [{ score: .95 }],
    keypoints: [{x:.46,y:.42},{x:.54,y:.42},{x:.5,y:.5},{x:.5,y:.58}],
});
const check = faces => judgeFace(faces, width, height);

test('one centered frontal face is eligible; an empty or multiple-person frame is not', () => {
    assert.equal(check([face()]).ready, true);
    assert.equal(check([]).kind, 'missing');
    assert.equal(check([face(), face()]).kind, 'multiple');
});
test('low confidence or incomplete/invalid geometry never enables capture', () => {
    for (const change of [f => f.categories[0].score=.2, f => f.keypoints=[], f => f.boundingBox.originX=NaN]) {
        const f=face(); change(f); assert.equal(check([f]).ready, false);
    }
});
test('distance, centering, and head angle produce actionable guidance', () => {
    const small=face(); small.boundingBox.width=20; assert.equal(check([small]).kind,'closer');
    const large=face(); large.boundingBox.width=300; assert.equal(check([large]).kind,'back');
    const side=face(); side.boundingBox.originX+=100; assert.equal(check([side]).kind,'center');
    const tilted=face(); tilted.keypoints[1].y+=.15; assert.equal(check([tilted]).kind,'straight');
    const turned=face(); turned.keypoints[2].x=.6; assert.equal(check([turned]).kind,'straight');
});
test('a lower-positioned visible face is accepted without forcing it into a fixed oval', () => {
    const lower=face();
    lower.boundingBox={originX:96,originY:112,width:128,height:128};
    lower.keypoints=[{x:.46,y:.62},{x:.54,y:.62},{x:.5,y:.74},{x:.5,y:.86}];
    assert.equal(check([lower]).ready,true);
    lower.keypoints[3].y=1.02;
    assert.equal(check([lower]).ready,false,'a hidden mouth still blocks readiness');
});
test('the preview keeps the camera ratio within phone and landscape bounds', () => {
    for(const [vw,vh,mw,mh] of [[640,480,336,310],[720,960,336,310],[1280,720,528,133],[960,720,268,215]]) {
        const p=previewSize(vw,vh,mw,mh);
        assert(p.width<=mw+.01 && p.height<=mh+.01);
        assert(Math.abs(p.width/p.height-vw/vh)<.0001);
    }
});
test('the default guide fits portrait and landscape frames', () => {
    for (const [vw,vh,fw,fh] of [[720,960,255,340],[640,480,255,340],[720,960,528,133],[1280,720,528,133]]) {
        const g=guideBounds(vw,vh,fw,fh);
        assert(g.width<=fw && g.height<=fh && g.width>0 && g.height>0);
        assert(g.w<=.83 && g.h<=.83);
    }
});
