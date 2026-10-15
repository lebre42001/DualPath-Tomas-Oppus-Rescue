import 'package:flutter/material.dart';

import '../../../core/network/stitch_api_exception.dart';
import '../../../core/session/stitch_session_controller.dart';
import '../../shared/models/stitch_models.dart';

class DashboardPage extends StatefulWidget {
  const DashboardPage({
    super.key,
    required this.sessionController,
    required this.onCreateReport,
  });

  final StitchSessionController sessionController;
  final VoidCallback onCreateReport;

  @override
  State<DashboardPage> createState() => DashboardPageState();
}

class DashboardPageState extends State<DashboardPage> {
  late Future<DashboardSnapshot> _dashboardFuture;

  @override
  void initState() {
    super.initState();
    _dashboardFuture = widget.sessionController.fetchDashboard();
  }

  Future<void> refresh() async {
    setState(() {
      _dashboardFuture = widget.sessionController.fetchDashboard();
    });

    await _dashboardFuture;
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<DashboardSnapshot>(
      future: _dashboardFuture,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }

        if (snapshot.hasError) {
          final message = snapshot.error is StitchApiException
              ? (snapshot.error as StitchApiException).message
              : 'Unable to load the dashboard.';
          return _DashboardError(message: message, onRetry: refresh);
        }

        final dashboard = snapshot.data!;
        return RefreshIndicator(
          onRefresh: refresh,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 120),
            children: dashboard.user.isCivilian
                ? _buildCivilianLayout(context, dashboard)
                : _buildResponderLayout(context, dashboard),
          ),
        );
      },
    );
  }

  List<Widget> _buildCivilianLayout(BuildContext context, DashboardSnapshot dashboard) {
    final summary = dashboard.summary;

    return [
      _GradientHeroCard(
        eyebrow: 'CIVILIAN COMMAND',
        title: 'Send emergency details with GPS, evidence, and selfie verification.',
        description:
            'This mobile dashboard is focused on fast civilian reporting. Submit a live emergency report, review your AI result, and follow your transmission history without opening responder tools.',
        badges: [
          'Bontoc, Southern Leyte',
          'Online + LoRa',
          dashboard.user.connectivityMode.isEmpty ? 'Auto connectivity' : dashboard.user.connectivityMode,
        ],
        actionLabel: 'Create Emergency Report',
        onAction: widget.onCreateReport,
      ),
      const SizedBox(height: 18),
      Wrap(
        spacing: 12,
        runSpacing: 12,
        children: [
          _MetricCard(
            label: 'My reports',
            value: summary.myReports.toString(),
            icon: Icons.receipt_long_rounded,
            accent: const Color(0xFFB81620),
          ),
          _MetricCard(
            label: 'Transmitted today',
            value: summary.transmittedToday.toString(),
            icon: Icons.send_rounded,
            accent: const Color(0xFF006B8A),
          ),
          _MetricCard(
            label: 'Online reports',
            value: summary.onlineReports.toString(),
            icon: Icons.wifi_rounded,
            accent: const Color(0xFF0A7B56),
          ),
          _MetricCard(
            label: 'LoRa fallback',
            value: summary.loraReports.toString(),
            icon: Icons.settings_input_antenna_rounded,
            accent: const Color(0xFF7C5C0A),
          ),
        ],
      ),
      const SizedBox(height: 18),
      _SectionCard(
        title: 'Latest mobile status',
        subtitle: 'These updates come from your most recent report activity and AI processing.',
        child: dashboard.notifications.isEmpty
            ? const _EmptyStateCard(
                title: 'No updates yet',
                message: 'Once you submit a report, AI severity and transmission updates will appear here.',
              )
            : Column(
                children: dashboard.notifications
                    .map((message) => _TimelineTile(
                          icon: Icons.notifications_active_rounded,
                          title: message,
                          subtitle: 'Pull down to refresh for the latest responder-side status.',
                        ))
                    .toList(),
              ),
      ),
      const SizedBox(height: 18),
      _SectionCard(
        title: 'Recent report snapshots',
        subtitle: 'Open your report history tab for the full timeline, AI result, and transmission status.',
        child: dashboard.recentReports.isEmpty
            ? const _EmptyStateCard(
                title: 'No reports yet',
                message: 'Your submitted incidents will appear here with the current severity, transport, and status.',
              )
            : Column(
                children: dashboard.recentReports
                    .map((report) => Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: _ReportSnapshotCard(report: report, civilianView: true),
                        ))
                    .toList(),
              ),
      ),
    ];
  }

  List<Widget> _buildResponderLayout(BuildContext context, DashboardSnapshot dashboard) {
    final summary = dashboard.summary;

    return [
      _GradientHeroCard(
        eyebrow: 'RESPONDER MONITORING',
        title: 'Monitor live incidents and prioritize response decisions from one mobile feed.',
        description:
            'This responder dashboard surfaces fatal counts, assigned incidents, GPS-ready reports, and AI-assisted severity so you can coordinate without falling back to the old starter UI.',
        badges: [
          dashboard.user.roleLabel,
          dashboard.user.station,
          '${summary.activeAlerts} active alerts',
        ],
        actionLabel: 'Create Manual Report',
        onAction: widget.onCreateReport,
      ),
      const SizedBox(height: 18),
      Wrap(
        spacing: 12,
        runSpacing: 12,
        children: [
          _MetricCard(
            label: 'Active alerts',
            value: summary.activeAlerts.toString(),
            icon: Icons.crisis_alert_rounded,
            accent: const Color(0xFFB81620),
          ),
          _MetricCard(
            label: 'Assigned to me',
            value: summary.assignedToMe.toString(),
            icon: Icons.assignment_ind_rounded,
            accent: const Color(0xFF005FB2),
          ),
          _MetricCard(
            label: 'Fatal reports',
            value: summary.fatalReports.toString(),
            icon: Icons.warning_amber_rounded,
            accent: const Color(0xFF7B0D13),
          ),
          _MetricCard(
            label: 'GPS map points',
            value: dashboard.mapPoints.length.toString(),
            icon: Icons.pin_drop_rounded,
            accent: const Color(0xFF0A7B56),
          ),
        ],
      ),
      const SizedBox(height: 18),
      _SectionCard(
        title: 'Alert center',
        subtitle: 'Latest live notifications for the responder feed.',
        child: dashboard.notifications.isEmpty
            ? const _EmptyStateCard(
                title: 'No active notifications',
                message: 'Incoming reports and status changes will appear here after the next refresh.',
              )
            : Column(
                children: dashboard.notifications
                    .map((message) => _TimelineTile(
                          icon: Icons.emergency_share_rounded,
                          title: message,
                          subtitle: 'Responder mobile notification stream',
                        ))
                    .toList(),
              ),
      ),
      const SizedBox(height: 18),
      _SectionCard(
        title: 'GPS-ready incidents',
        subtitle: 'These reports already include coordinates, ready for web map tracking and dispatch decisions.',
        child: dashboard.mapPoints.isEmpty
            ? const _EmptyStateCard(
                title: 'No map points yet',
                message: 'Once reports include latitude and longitude, they will be listed here for quick responder review.',
              )
            : Column(
                children: dashboard.mapPoints
                    .map((point) => _MapPointTile(point: point))
                    .toList(),
              ),
      ),
      const SizedBox(height: 18),
      Text('Live incident cards', style: Theme.of(context).textTheme.titleLarge),
      const SizedBox(height: 12),
      if (dashboard.recentReports.isEmpty)
        const _EmptyStateCard(
          title: 'No incidents in feed',
          message: 'The responder feed will populate here once civilian or responder reports reach Laravel.',
        )
      else
        ...dashboard.recentReports.map(
          (report) => Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: _ReportSnapshotCard(report: report, civilianView: false),
          ),
        ),
    ];
  }
}

class _DashboardError extends StatelessWidget {
  const _DashboardError({
    required this.message,
    required this.onRetry,
  });

  final String message;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.cloud_off_rounded, size: 42),
                const SizedBox(height: 14),
                Text(message, textAlign: TextAlign.center),
                const SizedBox(height: 16),
                FilledButton(
                  onPressed: onRetry,
                  child: const Text('Retry'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _GradientHeroCard extends StatelessWidget {
  const _GradientHeroCard({
    required this.eyebrow,
    required this.title,
    required this.description,
    required this.badges,
    required this.actionLabel,
    required this.onAction,
  });

  final String eyebrow;
  final String title;
  final String description;
  final List<String> badges;
  final String actionLabel;
  final VoidCallback onAction;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(30),
        gradient: const LinearGradient(
          colors: [Color(0xFF0B2230), Color(0xFF153748), Color(0xFFB81620)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        boxShadow: const [
          BoxShadow(
            color: Color(0x220B2230),
            blurRadius: 28,
            offset: Offset(0, 16),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            eyebrow,
            style: Theme.of(context).textTheme.labelLarge?.copyWith(
                  color: const Color(0xFFFFD7D1),
                  letterSpacing: 1.6,
                ),
          ),
          const SizedBox(height: 12),
          Text(
            title,
            style: Theme.of(context).textTheme.displaySmall?.copyWith(
                  color: Colors.white,
                  height: 1.05,
                ),
          ),
          const SizedBox(height: 14),
          Text(
            description,
            style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                  color: Colors.white.withValues(alpha: 0.84),
                ),
          ),
          const SizedBox(height: 18),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: badges
                .where((item) => item.trim().isNotEmpty)
                .map(
                  (item) => Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text(
                      item,
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(color: Colors.white),
                    ),
                  ),
                )
                .toList(),
          ),
          const SizedBox(height: 22),
          FilledButton.icon(
            onPressed: onAction,
            style: FilledButton.styleFrom(
              backgroundColor: Colors.white,
              foregroundColor: const Color(0xFF0D1E25),
            ),
            icon: const Icon(Icons.arrow_forward_rounded),
            label: Text(actionLabel),
          ),
        ],
      ),
    );
  }
}

class _MetricCard extends StatelessWidget {
  const _MetricCard({
    required this.label,
    required this.value,
    required this.icon,
    required this.accent,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 172,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, color: accent),
              const SizedBox(height: 14),
              Text(
                value,
                style: Theme.of(context).textTheme.headlineMedium?.copyWith(color: accent),
              ),
              const SizedBox(height: 6),
              Text(label, style: Theme.of(context).textTheme.bodyMedium),
            ],
          ),
        ),
      ),
    );
  }
}

class _SectionCard extends StatelessWidget {
  const _SectionCard({
    required this.title,
    required this.subtitle,
    required this.child,
  });

  final String title;
  final String subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 6),
            Text(subtitle, style: Theme.of(context).textTheme.bodyMedium),
            const SizedBox(height: 18),
            child,
          ],
        ),
      ),
    );
  }
}

class _ReportSnapshotCard extends StatelessWidget {
  const _ReportSnapshotCard({
    required this.report,
    required this.civilianView,
  });

  final IncidentReport report;
  final bool civilianView;

  @override
  Widget build(BuildContext context) {
    final severityColor = _severityColor(report.severity);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    report.incidentType,
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                  decoration: BoxDecoration(
                    color: severityColor.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Text(
                    report.severity.isEmpty ? 'Unknown' : report.severity,
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(color: severityColor),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(report.locationText),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _SmallPill(label: report.status.toUpperCase(), color: const Color(0xFF005FB2)),
                _SmallPill(label: report.transmissionType.toUpperCase(), color: const Color(0xFF0A7B56)),
                if (report.evidenceAvailable)
                  _SmallPill(
                    label: report.hasVideoEvidence ? 'VIDEO ATTACHED' : 'PHOTO ATTACHED',
                    color: const Color(0xFF8A5E00),
                  ),
                if (report.selfieAvailable)
                  const _SmallPill(label: 'SELFIE VERIFIED', color: Color(0xFF7A3FA0)),
                if (report.aiReviewRequired)
                  const _SmallPill(label: 'RESPONDER REVIEW', color: Color(0xFFAF101A)),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              report.aiSummary.isNotEmpty ? report.aiSummary : report.description,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 14,
              runSpacing: 10,
              children: [
                Text(report.reportCode, style: Theme.of(context).textTheme.labelLarge),
                if (report.hasGps)
                  Text('GPS ${report.latitude!.toStringAsFixed(5)}, ${report.longitude!.toStringAsFixed(5)}'),
                if (!civilianView && report.reporterName.isNotEmpty) Text('Reporter: ${report.reporterName}'),
                if (!civilianView && report.assignedResponderName.isNotEmpty)
                  Text('Assigned: ${report.assignedResponderName}'),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _MapPointTile extends StatelessWidget {
  const _MapPointTile({required this.point});

  final MapPointModel point;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: const Color(0xFFF7FAFC),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: const Color(0xFFDCE6EE)),
        ),
        child: Row(
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                color: _severityColor(point.severity).withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(Icons.pin_drop_rounded, color: _severityColor(point.severity)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(point.label, style: Theme.of(context).textTheme.labelLarge),
                  const SizedBox(height: 4),
                  Text(
                    '${point.latitude.toStringAsFixed(5)}, ${point.longitude.toStringAsFixed(5)}',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ],
              ),
            ),
            Text(point.severity, style: Theme.of(context).textTheme.labelLarge),
          ],
        ),
      ),
    );
  }
}

class _TimelineTile extends StatelessWidget {
  const _TimelineTile({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  final IconData icon;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: const Color(0xFFEAF2FF),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Icon(icon, color: const Color(0xFF005FB2)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.labelLarge),
                const SizedBox(height: 4),
                Text(subtitle, style: Theme.of(context).textTheme.bodyMedium),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SmallPill extends StatelessWidget {
  const _SmallPill({
    required this.label,
    required this.color,
  });

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelLarge?.copyWith(color: color),
      ),
    );
  }
}

class _EmptyStateCard extends StatelessWidget {
  const _EmptyStateCard({
    required this.title,
    required this.message,
  });

  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FBFD),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFDCE6EE)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 8),
          Text(message, style: Theme.of(context).textTheme.bodyMedium),
        ],
      ),
    );
  }
}

Color _severityColor(String severity) {
  switch (severity) {
    case 'Fatal':
      return const Color(0xFF7B0D13);
    case 'Serious':
      return const Color(0xFFB81620);
    default:
      return const Color(0xFF0A7B56);
  }
}