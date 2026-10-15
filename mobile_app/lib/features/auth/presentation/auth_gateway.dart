import 'package:flutter/material.dart';

import '../../../core/network/stitch_api_exception.dart';
import '../../../core/session/stitch_session_controller.dart';

class AuthGateway extends StatefulWidget {
  const AuthGateway({
    super.key,
    required this.sessionController,
  });

  final StitchSessionController sessionController;

  @override
  State<AuthGateway> createState() => _AuthGatewayState();
}

class _AuthGatewayState extends State<AuthGateway> {
  bool _showRegister = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      body: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [
              Color(0xFFF3FAFF),
              Color(0xFFE8F3FF),
              Color(0xFFFFF2EE),
            ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 1100),
                child: Wrap(
                  spacing: 24,
                  runSpacing: 24,
                  alignment: WrapAlignment.center,
                  children: [
                    Container(
                      width: 460,
                      padding: const EdgeInsets.all(28),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(34),
                        gradient: const LinearGradient(
                          colors: [
                            Color(0xFF081A24),
                            Color(0xFF12313D),
                            Color(0xFFB81620),
                          ],
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                        ),
                        boxShadow: const [
                          BoxShadow(
                            color: Color(0x22081A24),
                            blurRadius: 32,
                            offset: Offset(0, 18),
                          ),
                        ],
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Container(
                            width: 74,
                            height: 74,
                            decoration: BoxDecoration(
                              color: Colors.white.withValues(alpha: 0.14),
                              borderRadius: BorderRadius.circular(24),
                            ),
                            child: const Icon(
                              Icons.emergency_share_rounded,
                              size: 38,
                              color: Colors.white,
                            ),
                          ),
                          const SizedBox(height: 22),
                          Text(
                            'Bontoc Rescue',
                            style: theme.textTheme.titleLarge?.copyWith(
                              color: const Color(0xFFFFD7D1),
                              letterSpacing: 1.4,
                            ),
                          ),
                          const SizedBox(height: 12),
                          Text(
                            'AI-powered emergency reporting with photo evidence, GPS, and LoRa fallback.',
                            style: theme.textTheme.displaySmall?.copyWith(
                              color: Colors.white,
                              height: 1.02,
                            ),
                          ),
                          const SizedBox(height: 16),
                          Text(
                            'Use one mobile app for civilians and responders in Bontoc, Southern Leyte. The app now mirrors the live Laravel workflow instead of the old starter screens.',
                            style: theme.textTheme.bodyLarge?.copyWith(
                              color: Colors.white.withValues(alpha: 0.82),
                            ),
                          ),
                          const SizedBox(height: 28),
                          const _HeroBullet(
                            icon: Icons.camera_alt_rounded,
                            title: 'Capture live incident evidence',
                            subtitle: 'Photo, video, and verification selfie gates are ready for the mobile workflow.',
                          ),
                          const SizedBox(height: 14),
                          const _HeroBullet(
                            icon: Icons.location_searching_rounded,
                            title: 'GPS-aware submission',
                            subtitle: 'Latitude and longitude can be pulled on-device before the report is transmitted.',
                          ),
                          const SizedBox(height: 14),
                          const _HeroBullet(
                            icon: Icons.hub_rounded,
                            title: 'Online or LoRa mode',
                            subtitle: 'Civilians can send a full online report or shift to compact LoRa fallback when needed.',
                          ),
                        ],
                      ),
                    ),
                    SizedBox(
                      width: 520,
                      child: Card(
                        child: Padding(
                          padding: const EdgeInsets.all(28),
                          child: AnimatedSwitcher(
                            duration: const Duration(milliseconds: 250),
                            child: _showRegister
                                ? RegisterForm(
                                    key: const ValueKey('register-form'),
                                    sessionController: widget.sessionController,
                                    onSwitchMode: () {
                                      setState(() => _showRegister = false);
                                    },
                                  )
                                : LoginForm(
                                    key: const ValueKey('login-form'),
                                    sessionController: widget.sessionController,
                                    onSwitchMode: () {
                                      setState(() => _showRegister = true);
                                    },
                                  ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class LoginForm extends StatefulWidget {
  const LoginForm({
    super.key,
    required this.sessionController,
    required this.onSwitchMode,
  });

  final StitchSessionController sessionController;
  final VoidCallback onSwitchMode;

  @override
  State<LoginForm> createState() => _LoginFormState();
}

class _LoginFormState extends State<LoginForm> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();

  bool _submitting = false;
  String? _errorMessage;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Form(
      key: _formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Mobile Login', style: theme.textTheme.headlineMedium),
          const SizedBox(height: 8),
          Text(
            'Sign in with your live account to open the updated Bontoc Rescue mobile workspace.',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 24),
          if (_errorMessage != null) ...[
            _ErrorBanner(message: _errorMessage!),
            const SizedBox(height: 18),
          ],
          TextFormField(
            controller: _emailController,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(
              labelText: 'Email address',
              prefixIcon: Icon(Icons.alternate_email_rounded),
            ),
            validator: (value) {
              if (value == null || value.trim().isEmpty) {
                return 'Enter your email address.';
              }
              return null;
            },
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _passwordController,
            obscureText: true,
            decoration: const InputDecoration(
              labelText: 'Password',
              prefixIcon: Icon(Icons.lock_rounded),
            ),
            validator: (value) {
              if (value == null || value.length < 8) {
                return 'Password must be at least 8 characters.';
              }
              return null;
            },
          ),
          const SizedBox(height: 24),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: _submitting ? null : _submit,
              icon: _submitting
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.login_rounded),
              label: Text(_submitting ? 'Signing In...' : 'Open Mobile Workspace'),
            ),
          ),
          const SizedBox(height: 18),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Text('Need a new account?'),
              TextButton(
                onPressed: widget.onSwitchMode,
                child: const Text('Register here'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) {
      return;
    }

    setState(() {
      _submitting = true;
      _errorMessage = null;
    });

    try {
      await widget.sessionController.login(
        email: _emailController.text.trim(),
        password: _passwordController.text,
      );
    } on StitchApiException catch (error) {
      if (!mounted) {
        return;
      }
      setState(() => _errorMessage = error.message);
    } finally {
      if (mounted) {
        setState(() => _submitting = false);
      }
    }
  }
}

class RegisterForm extends StatefulWidget {
  const RegisterForm({
    super.key,
    required this.sessionController,
    required this.onSwitchMode,
  });

  final StitchSessionController sessionController;
  final VoidCallback onSwitchMode;

  @override
  State<RegisterForm> createState() => _RegisterFormState();
}

class _RegisterFormState extends State<RegisterForm> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();

  String _selectedRole = 'civilian';
  bool _submitting = false;
  String? _errorMessage;

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Form(
      key: _formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Create Mobile Account', style: theme.textTheme.headlineMedium),
          const SizedBox(height: 8),
          Text(
            'Choose your role first. Civilians will report emergencies while responders receive the monitoring tools.',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 24),
          if (_errorMessage != null) ...[
            _ErrorBanner(message: _errorMessage!),
            const SizedBox(height: 18),
          ],
          Row(
            children: [
              Expanded(
                child: _RoleCard(
                  title: 'User / Civilian',
                  subtitle: 'Report accidents, capture evidence, and track your emergency history.',
                  icon: Icons.emergency_rounded,
                  selected: _selectedRole == 'civilian',
                  onTap: () => setState(() => _selectedRole = 'civilian'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _RoleCard(
                  title: 'Responder',
                  subtitle: 'Monitor incoming incidents and prioritize emergency actions.',
                  icon: Icons.local_hospital_rounded,
                  selected: _selectedRole == 'responder',
                  onTap: () => setState(() => _selectedRole = 'responder'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),
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
            controller: _phoneController,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              labelText: 'Contact number',
              prefixIcon: Icon(Icons.call_rounded),
            ),
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _emailController,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(
              labelText: 'Email address',
              prefixIcon: Icon(Icons.alternate_email_rounded),
            ),
            validator: (value) {
              if (value == null || value.trim().isEmpty) {
                return 'Enter your email address.';
              }
              return null;
            },
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _passwordController,
            obscureText: true,
            decoration: const InputDecoration(
              labelText: 'Password',
              prefixIcon: Icon(Icons.lock_rounded),
              helperText: 'Use at least 12 characters for the live Laravel account.',
            ),
            validator: (value) {
              if (value == null || value.length < 12) {
                return 'Password must be at least 12 characters.';
              }
              return null;
            },
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _confirmPasswordController,
            obscureText: true,
            decoration: const InputDecoration(
              labelText: 'Confirm password',
              prefixIcon: Icon(Icons.verified_user_rounded),
            ),
            validator: (value) {
              if (value != _passwordController.text) {
                return 'Passwords do not match.';
              }
              return null;
            },
          ),
          const SizedBox(height: 24),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: _submitting ? null : _submit,
              icon: _submitting
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.person_add_alt_1_rounded),
              label: Text(_submitting ? 'Creating Account...' : 'Register And Continue'),
            ),
          ),
          const SizedBox(height: 18),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Text('Already registered?'),
              TextButton(
                onPressed: widget.onSwitchMode,
                child: const Text('Back to login'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) {
      return;
    }

    setState(() {
      _submitting = true;
      _errorMessage = null;
    });

    try {
      await widget.sessionController.register(
        name: _nameController.text.trim(),
        phone: _phoneController.text.trim(),
        email: _emailController.text.trim(),
        password: _passwordController.text,
        role: _selectedRole,
      );
    } on StitchApiException catch (error) {
      if (!mounted) {
        return;
      }
      setState(() => _errorMessage = error.message);
    } finally {
      if (mounted) {
        setState(() => _submitting = false);
      }
    }
  }
}

class _HeroBullet extends StatelessWidget {
  const _HeroBullet({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  final IconData icon;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.14),
            borderRadius: BorderRadius.circular(16),
          ),
          child: Icon(icon, color: Colors.white),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(color: Colors.white),
              ),
              const SizedBox(height: 4),
              Text(
                subtitle,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: Colors.white.withValues(alpha: 0.76),
                    ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _RoleCard extends StatelessWidget {
  const _RoleCard({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.selected,
    required this.onTap,
  });

  final String title;
  final String subtitle;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = selected ? Theme.of(context).colorScheme.primary : const Color(0xFF5A6B75);

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(22),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: selected ? const Color(0xFFFFEEEC) : const Color(0xFFF7FAFC),
          borderRadius: BorderRadius.circular(22),
          border: Border.all(
            color: selected ? const Color(0xFFB81620) : const Color(0xFFD9E4EC),
            width: selected ? 1.6 : 1,
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: color),
            const SizedBox(height: 12),
            Text(
              title,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(color: const Color(0xFF0D1E25)),
            ),
            const SizedBox(height: 8),
            Text(subtitle, style: Theme.of(context).textTheme.bodyMedium),
          ],
        ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFFFE6E2),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline_rounded, color: Color(0xFFAF101A)),
          const SizedBox(width: 10),
          Expanded(child: Text(message)),
        ],
      ),
    );
  }
}