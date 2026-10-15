import 'package:flutter/material.dart';

import '../../../core/network/stitch_api_exception.dart';
import '../../../core/session/stitch_session_controller.dart';
import '../../shared/models/stitch_models.dart';

class SettingsPage extends StatefulWidget {
  const SettingsPage({
    super.key,
    required this.sessionController,
  });

  final StitchSessionController sessionController;

  @override
  State<SettingsPage> createState() => _SettingsPageState();
}

class _SettingsPageState extends State<SettingsPage> {
  bool _loading = true;
  bool _saving = false;
  String? _errorMessage;

  bool _criticalAlerts = true;
  bool _pushNotifications = true;
  bool _smsBackup = false;
  String _connectivityMode = 'auto_select';
  String _storage = 'mysql';

  @override
  void initState() {
    super.initState();
    _loadSettings();
  }

  @override
  Widget build(BuildContext context) {
    final user = widget.sessionController.currentUser!;

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_errorMessage != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_errorMessage!, textAlign: TextAlign.center),
              const SizedBox(height: 14),
              FilledButton(
                onPressed: _loadSettings,
                child: const Text('Retry'),
              ),
            ],
          ),
        ),
      );
    }

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 120),
        child: Column(
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(30),
                gradient: const LinearGradient(
                  colors: [Color(0xFF081A24), Color(0xFF12303D), Color(0xFF005FB2)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'System Settings',
                    style: Theme.of(context).textTheme.displaySmall?.copyWith(color: Colors.white, height: 1.03),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    user.isCivilian
                        ? 'Choose how your civilian app handles alerts, push delivery, and fallback communication.'
                        : 'Tune responder alert behavior, connectivity fallback, and dispatch-ready notification settings.',
                    style: Theme.of(context).textTheme.bodyLarge?.copyWith(color: Colors.white.withValues(alpha: 0.82)),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(22),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Notification preferences', style: Theme.of(context).textTheme.titleLarge),
                    const SizedBox(height: 14),
                    SwitchListTile(
                      value: _criticalAlerts,
                      onChanged: (value) => setState(() => _criticalAlerts = value),
                      title: const Text('Critical alerts'),
                      subtitle: const Text('Always surface fatal or life-threatening events first.'),
                    ),
                    SwitchListTile(
                      value: _pushNotifications,
                      onChanged: (value) => setState(() => _pushNotifications = value),
                      title: const Text('Push notifications'),
                      subtitle: const Text('Receive standard status updates from the live Laravel system.'),
                    ),
                    SwitchListTile(
                      value: _smsBackup,
                      onChanged: (value) => setState(() => _smsBackup = value),
                      title: const Text('SMS backup'),
                      subtitle: const Text('Keep a fallback text alert path when data coverage is unstable.'),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 18),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(22),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Connectivity behavior', style: Theme.of(context).textTheme.titleLarge),
                    const SizedBox(height: 16),
                    DropdownButtonFormField<String>(
                      initialValue: _connectivityMode,
                      decoration: const InputDecoration(
                        labelText: 'Connectivity mode',
                        prefixIcon: Icon(Icons.settings_input_antenna_rounded),
                      ),
                      items: const [
                        DropdownMenuItem(value: 'auto_select', child: Text('Auto select')),
                        DropdownMenuItem(value: 'lora_fallback', child: Text('LoRa fallback')),
                        DropdownMenuItem(value: 'wifi_priority', child: Text('Wi-Fi priority')),
                      ],
                      onChanged: (value) => setState(() => _connectivityMode = value ?? 'auto_select'),
                    ),
                    const SizedBox(height: 18),
                    Text('Storage target', style: Theme.of(context).textTheme.labelLarge),
                    const SizedBox(height: 8),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: const Color(0xFFF7FAFC),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: const Color(0xFFDCE6EE)),
                      ),
                      child: Text(
                        _storage.toUpperCase(),
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                    ),
                    const SizedBox(height: 20),
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton.icon(
                        onPressed: _saving ? null : _saveSettings,
                        icon: _saving
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              )
                            : const Icon(Icons.save_rounded),
                        label: Text(_saving ? 'Saving...' : 'Save Settings'),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _loadSettings() async {
    setState(() {
      _loading = true;
      _errorMessage = null;
    });

    try {
      final settings = await widget.sessionController.fetchSettings();
      _applySettings(settings);
    } on StitchApiException catch (error) {
      _errorMessage = error.message;
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  Future<void> _saveSettings() async {
    setState(() => _saving = true);

    final settings = AppSettingsModel(
      criticalAlerts: _criticalAlerts,
      pushNotifications: _pushNotifications,
      smsBackup: _smsBackup,
      connectivityMode: _connectivityMode,
      storage: _storage,
    );

    try {
      final updated = await widget.sessionController.updateSettings(settings);
      _applySettings(updated);

      if (!mounted) {
        return;
      }

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Settings updated successfully.')),
      );
    } on StitchApiException catch (error) {
      if (!mounted) {
        return;
      }
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.message)),
      );
    } finally {
      if (mounted) {
        setState(() => _saving = false);
      }
    }
  }

  void _applySettings(AppSettingsModel settings) {
    _criticalAlerts = settings.criticalAlerts;
    _pushNotifications = settings.pushNotifications;
    _smsBackup = settings.smsBackup;
    _connectivityMode = settings.connectivityMode.isEmpty ? 'auto_select' : settings.connectivityMode;
    _storage = settings.storage.isEmpty ? 'mysql' : settings.storage;
  }
}