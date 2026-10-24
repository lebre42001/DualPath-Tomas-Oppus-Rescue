<article class="panel command-compact-panel command-audit-panel" id="report-audit-history">
    <div class="panel-head command-panel-head">
        <div class="panel-heading">
            <p class="panel-kicker">Incident Action History</p>
            <h2 class="panel-title">Admin report audit history</h2>
            <p class="section-copy">Latest 6 actions across Online and LoRa reports. History remains after deletion. Times use Asia/Manila.</p>
        </div>
        <span class="tag neutral">{{ $auditTrail->total() }} actions</span>
    </div>
    <div class="command-table-wrap">
        <table class="command-audit-table">
            <thead><tr><th>Action</th><th>Incident</th><th>Source</th><th>Status</th><th>Actor</th><th>Time</th></tr></thead>
            <tbody>@include('admin.partials.report-audit-rows')</tbody>
        </table>
    </div>
    <div class="audit-history-footer">
        <span>Showing {{ $auditTrail->count() }} latest actions · {{ $auditTrail->total() }} total</span>
        <button type="button" class="btn btn-secondary" data-audit-history-open aria-haspopup="dialog" aria-controls="audit-history-dialog">View all</button>
    </div>
</article>
@include('admin.partials.report-audit-list-modal')
