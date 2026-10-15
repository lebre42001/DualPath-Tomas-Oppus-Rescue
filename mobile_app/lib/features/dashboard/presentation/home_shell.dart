import 'package:flutter/material.dart';

import '../../../core/session/stitch_session_controller.dart';
import '../../profile/presentation/profile_page.dart';
import '../../reports/presentation/report_form_page.dart';
import '../../reports/presentation/reports_page.dart';
import '../../settings/presentation/settings_page.dart';
import 'dashboard_page.dart';

class HomeShell extends StatefulWidget {
  const HomeShell({
    super.key,
    required this.sessionController,
  });

  final StitchSessionController sessionController;

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  final _dashboardKey = GlobalKey<DashboardPageState>();
  final _reportsKey = GlobalKey<ReportsPageState>();

  int _selectedIndex = 0;

  @override
  Widget build(BuildContext context) {
    final user = widget.sessionController.currentUser!;
    final isCivilian = user.isCivilian;
    final title = switch (_selectedIndex) {
      0 => isCivilian ? 'Civilian Dashboard' : 'Responder Monitoring',
      1 => isCivilian ? 'My Report History' : 'Incident Feed',
      2 => isCivilian ? 'Civilian Profile' : 'Responder Profile',
      _ => 'System Settings',
    };

    return Scaffold(
      appBar: AppBar(
        toolbarHeight: 82,
        titleSpacing: 20,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title),
            const SizedBox(height: 2),
            Text(
              user.roleLabel,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
        ),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: Center(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: const Color(0xFFD9E4EC)),
                ),
                child: Text(
                  user.station.isEmpty ? user.roleLabel : user.station,
                  style: Theme.of(context).textTheme.labelLarge,
                ),
              ),
            ),
          ),
          IconButton(
            tooltip: 'Logout',
            onPressed: _logout,
            icon: const Icon(Icons.logout_rounded),
          ),
        ],
      ),
      floatingActionButton: _selectedIndex <= 1
          ? FloatingActionButton.extended(
              onPressed: _openReportComposer,
              icon: Icon(isCivilian ? Icons.add_alert_rounded : Icons.playlist_add_rounded),
              label: Text(isCivilian ? 'Send Report' : 'Manual Report'),
            )
          : null,
      body: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [Color(0xFFF6FBFF), Color(0xFFEEF5FB)],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: IndexedStack(
          index: _selectedIndex,
          children: [
            DashboardPage(
              key: _dashboardKey,
              sessionController: widget.sessionController,
              onCreateReport: _openReportComposer,
            ),
            ReportsPage(
              key: _reportsKey,
              sessionController: widget.sessionController,
              onCreateReport: _openReportComposer,
            ),
            ProfilePage(sessionController: widget.sessionController),
            SettingsPage(sessionController: widget.sessionController),
          ],
        ),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _selectedIndex,
        onDestinationSelected: (index) {
          setState(() => _selectedIndex = index);
        },
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.dashboard_customize_rounded),
            label: isCivilian ? 'Dashboard' : 'Monitoring',
          ),
          NavigationDestination(
            icon: Icon(isCivilian ? Icons.history_rounded : Icons.dynamic_feed_rounded),
            label: isCivilian ? 'History' : 'Feed',
          ),
          const NavigationDestination(
            icon: Icon(Icons.account_circle_rounded),
            label: 'Profile',
          ),
          const NavigationDestination(
            icon: Icon(Icons.tune_rounded),
            label: 'Settings',
          ),
        ],
      ),
    );
  }

  Future<void> _logout() async {
    await widget.sessionController.logout();
  }

  Future<void> _openReportComposer() async {
    final created = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (context) => ReportFormPage(sessionController: widget.sessionController),
        fullscreenDialog: true,
      ),
    );

    if (!mounted || created != true) {
      return;
    }

    await _dashboardKey.currentState?.refresh();
    await _reportsKey.currentState?.refresh();

    if (!mounted) {
      return;
    }

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Emergency report submitted successfully.')),
    );
  }
}