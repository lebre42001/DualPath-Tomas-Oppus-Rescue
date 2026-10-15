import 'package:flutter/material.dart';

import '../../../core/network/stitch_api_exception.dart';
import '../../../core/session/stitch_session_controller.dart';
import '../../shared/models/stitch_models.dart';

class ProfilePage extends StatefulWidget {
  const ProfilePage({
    super.key,
    required this.sessionController,
  });

  final StitchSessionController sessionController;

  @override
  State<ProfilePage> createState() => _ProfilePageState();
}

class _ProfilePageState extends State<ProfilePage> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _stationController = TextEditingController();

  bool _loading = true;
  bool _saving = false;
  String? _errorMessage;
  StitchUser? _profile;

  @override
  void initState() {
    super.initState();
    _loadProfile();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _stationController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
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
                onPressed: _loadProfile,
                child: const Text('Retry'),
              ),
            ],
          ),
        ),
      );
    }

    final profile = _profile!;
    final isCivilian = profile.isCivilian;

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
                  colors: [Color(0xFF081A24), Color(0xFF12303D), Color(0xFFB81620)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  CircleAvatar(
                    radius: 30,
                    backgroundColor: Colors.white.withValues(alpha: 0.14),
                    child: Text(
                      profile.name.isEmpty ? '?' : profile.name.substring(0, 1).toUpperCase(),
                      style: Theme.of(context).textTheme.headlineMedium?.copyWith(color: Colors.white),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    isCivilian ? 'Civilian Profile' : profile.roleLabel,
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(color: const Color(0xFFFFD7D1)),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    profile.name,
                    style: Theme.of(context).textTheme.displaySmall?.copyWith(color: Colors.white, height: 1.02),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    isCivilian
                        ? 'Update your contact details so responders can reach you and connect the correct report history to your account.'
                        : 'Keep your responder identity and station details updated for accurate assignment and dashboard visibility.',
                    style: Theme.of(context).textTheme.bodyLarge?.copyWith(color: Colors.white.withValues(alpha: 0.82)),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(22),
                child: Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Update your information', style: Theme.of(context).textTheme.titleLarge),
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: _nameController,
                        decoration: const InputDecoration(
                          labelText: 'Full name',
                          prefixIcon: Icon(Icons.badge_rounded),
                        ),
                        validator: (value) {
                          if (value == null || value.trim().isEmpty) {
                            return 'Enter your name.';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 16),
                      TextFormField(
                        initialValue: profile.email,
                        enabled: false,
                        decoration: const InputDecoration(
                          labelText: 'Email address',
                          prefixIcon: Icon(Icons.alternate_email_rounded),
                        ),
                      ),
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: _phoneController,
                        keyboardType: TextInputType.phone,
                        decoration: const InputDecoration(
                          labelText: 'Phone number',
                          prefixIcon: Icon(Icons.call_rounded),
                        ),
                      ),
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: _stationController,
                        enabled: !isCivilian,
                        decoration: InputDecoration(
                          labelText: isCivilian ? 'Civilian label' : 'Assigned station',
                          prefixIcon: const Icon(Icons.location_city_rounded),
                          helperText: isCivilian ? 'Civilian accounts keep the station label fixed on the server.' : null,
                        ),
                      ),
                      const SizedBox(height: 20),
                      Wrap(
                        spacing: 10,
                        runSpacing: 10,
                        children: [
                          _ProfileChip(label: profile.roleLabel),
                          _ProfileChip(label: profile.connectivityMode.isEmpty ? 'auto_select' : profile.connectivityMode),
                          ...profile.notificationProfile.map((item) => _ProfileChip(label: item.replaceAll('_', ' '))),
                        ],
                      ),
                      const SizedBox(height: 22),
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton.icon(
                          onPressed: _saving ? null : _saveProfile,
                          icon: _saving
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(strokeWidth: 2),
                                )
                              : const Icon(Icons.save_rounded),
                          label: Text(_saving ? 'Saving...' : 'Save Profile'),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _loadProfile() async {
    setState(() {
      _loading = true;
      _errorMessage = null;
    });

    try {
      final profile = await widget.sessionController.fetchProfile();
      _profile = profile;
      _nameController.text = profile.name;
      _phoneController.text = profile.phone;
      _stationController.text = profile.station;
    } on StitchApiException catch (error) {
      _errorMessage = error.message;
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  Future<void> _saveProfile() async {
    if (!_formKey.currentState!.validate()) {
      return;
    }

    setState(() => _saving = true);

    try {
      final updated = await widget.sessionController.updateProfile(
        name: _nameController.text.trim(),
        phone: _phoneController.text.trim(),
        station: _stationController.text.trim(),
      );
      _profile = updated;

      if (!mounted) {
        return;
      }

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Profile updated successfully.')),
      );
      setState(() {});
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
}

class _ProfileChip extends StatelessWidget {
  const _ProfileChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: const Color(0xFFEAF2FF),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelLarge?.copyWith(color: const Color(0xFF005FB2)),
      ),
    );
  }
}