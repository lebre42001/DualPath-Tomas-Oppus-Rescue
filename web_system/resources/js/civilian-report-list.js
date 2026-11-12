export const DRAFT_STATES = {
    editing: 'Draft — not sent', sending: 'Delivery unconfirmed — check before sending again',
    offline: 'Draft — connection lost', connection: 'Draft — connection interrupted',
    interrupted: 'Draft — sending interrupted', slow: 'Draft — sending took too long',
    server: 'Draft — server receipt unconfirmed', session: 'Draft — sign in to continue',
    validation: 'Draft — review details and verification',
};
export const philippineDate = value => {
    const date = Date.parse(value);
    return Number.isFinite(date) ? new Date(date + 8 * 3600000).toISOString().slice(0, 10) : '';
};
export function draftReportPage(records, filters = {}, requested = 1) {
    const words = (filters.search ?? '').trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
    const matches = records.filter(record => {
        const text = [record.submissionId, record.fields?.incident_type, record.fields?.description, record.fields?.location_text, record.evidenceName].join(' ').toLocaleLowerCase();
        const date = philippineDate(record.updatedAt);
        return words.every(word => text.includes(word)) && (!filters.status || record.state === filters.status)
            && (!filters.from || date >= filters.from) && (!filters.to || (date && date <= filters.to));
    }).sort((a, b) => {
        const order = (Date.parse(a.updatedAt) || 0) - (Date.parse(b.updatedAt) || 0) || a.submissionId.localeCompare(b.submissionId);
        return filters.sort === 'oldest' ? order : -order;
    });
    const total = matches.length, lastPage = Math.max(1, Math.ceil(total / 10));
    const page = Math.max(1, Math.min(lastPage, Number(requested) || 1)), start = (page - 1) * 10;
    return {
        reports: matches.slice(start, start + 10).map(record => ({
            id: record.submissionId, code: `Draft ${record.submissionId.slice(0, 8)}`, type: record.fields?.incident_type || 'Road Accident',
            location: record.fields?.location_text || 'Location not entered',
            status: record.state, statusLabel: DRAFT_STATES[record.state] ?? DRAFT_STATES.editing,
            submittedAt: record.updatedAt, updatedAt: record.updatedAt,
        })),
        pagination: { total, page, lastPage, from: total ? start + 1 : null, to: total ? Math.min(total, start + 10) : null },
    };
}
export function reportPageNumbers(page, lastPage) {
    const start = Math.max(1, Math.min(page - 2, lastPage - 4));
    return Array.from({ length: Math.min(5, lastPage) }, (_, index) => start + index);
}
