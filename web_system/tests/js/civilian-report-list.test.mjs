import test from 'node:test';
import assert from 'node:assert/strict';
import { draftReportPage, philippineDate, reportPageNumbers } from '../../resources/js/civilian-report-list.js';

test('draft search, state and inclusive Philippine dates filter before pagination with stable sorting', () => {
    const records = Array.from({ length: 23 }, (_, i) => ({ submissionId: String(i).padStart(8, '0'), fields: { description: 'Road collision 50%', location_text: 'Market_2' }, state: 'offline', updatedAt: '2026-11-06T16:00:00Z' }));
    records.push({ submissionId: 'outside', fields: {}, state: 'editing', updatedAt: '2026-11-06T15:59:59Z' });
    const filters = { search: '50% market_2', status: 'offline', from: '2026-11-07', to: '2026-11-07' };
    const first = draftReportPage(records, filters);
    assert.equal(first.pagination.total, 23); assert.equal(first.pagination.lastPage, 3); assert.equal(first.reports[0].id, '00000022');
    assert.equal(draftReportPage(records, filters, 2).pagination.from, 11);
    assert.equal(draftReportPage(records, filters, 999).reports.length, 3);
    assert.equal(draftReportPage(records, { ...filters, sort: 'oldest' }).reports[0].id, '00000000');
    assert.equal(draftReportPage(records, { search: 'missing%' }).pagination.total, 0);
    assert.equal(draftReportPage(records, { status: 'editing' }).pagination.total, 1);
    assert.equal(philippineDate('2026-11-07T15:59:59Z'), '2026-11-07');
    assert.equal(philippineDate('2026-11-07T16:00:00Z'), '2026-11-08');
    assert.equal(philippineDate('bad date'), '');
});

test('empty and shrinking draft lists always have a valid page and compact accessible page numbers', () => {
    assert.deepEqual(draftReportPage([], {}, 9).pagination, { total: 0, page: 1, lastPage: 1, from: null, to: null });
    assert.deepEqual(reportPageNumbers(1, 1), [1]);
    assert.deepEqual(reportPageNumbers(1, 20), [1, 2, 3, 4, 5]);
    assert.deepEqual(reportPageNumbers(12, 20), [10, 11, 12, 13, 14]);
    assert.deepEqual(reportPageNumbers(20, 20), [16, 17, 18, 19, 20]);
});
