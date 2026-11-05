const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../Arduino/shared/report_dashboard.h'), 'utf8');
const body = source.match(/const paint=\(\)=>\{([\s\S]*?)\n  \};/);
assert.ok(body, 'Find the actual dashboard pending-report indicator');
const paint = new Function('lastStatus', 'busy', 'retryUntil', 'button', 'indicator', body[1]);
function render(status, cooldown = 0) {
  const button = {}, indicator = {};
  paint(status, false, cooldown, button, indicator);
  return {button, text: indicator.textContent};
}
for (const report_status of ['QUEUED FOR RADIO', 'QUEUED - WAITING FOR ROUTE', 'READY TO SEND']) {
  const view = render({pending: true, report_status}, Date.now() + 60000);
  assert.equal(view.button.disabled, true);
  assert.match(view.text, /saved on this node/i);
  assert.match(view.text, /not been confirmed/i);
  assert.doesNotMatch(view.text, /waiting for admin/i);
}
assert.match(render({pending: true, report_status: 'SENT - WAITING FOR SERVER'}).text, /delivery is not confirmed/i);
assert.match(render({pending: true, report_status: 'SUBMITTED - UNDER ADMIN REVIEW'}).text, /server received.*admin review/i);
const unavailable = render({pending: false, radio_ready: false});
assert.equal(unavailable.button.disabled, true);
assert.match(unavailable.text, /radio unavailable/i);
// NODE_1 does not currently expose radio_ready; its existing submit flow stays enabled.
assert.equal(render({pending: false}).button.disabled, false);
assert.equal(render({pending: false}, Date.now() + 60000).button.disabled, true);
console.log('PASS actual dashboard indicator: local queue, transmission, server receipt, cooldown, radio failure and gateway compatibility.');
