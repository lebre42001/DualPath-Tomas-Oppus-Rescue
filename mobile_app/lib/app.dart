import 'package:flutter/material.dart';

import 'core/session/stitch_session_controller.dart';
import 'core/theme/stitch_theme.dart';
import 'features/auth/presentation/auth_gateway.dart';
import 'features/dashboard/presentation/home_shell.dart';

class StitchApp extends StatelessWidget {
  const StitchApp({
    super.key,
    required this.sessionController,
  });

  final StitchSessionController sessionController;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Bontoc Rescue',
      theme: StitchTheme.light(),
      home: AnimatedBuilder(
        animation: sessionController,
        builder: (context, _) {
          if (!sessionController.isInitialized) {
            return const _StartupScreen();
          }

          if (!sessionController.isAuthenticated) {
            return AuthGateway(sessionController: sessionController);
          }

          return HomeShell(sessionController: sessionController);
        },
      ),
    );
  }
}

class _StartupScreen extends StatelessWidget {
  const _StartupScreen();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      body: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [
              Color(0xFFF4FAFF),
              Color(0xFFE6F2FA),
              Color(0xFFFFE6E2),
            ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: Center(
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(28),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 72,
                    height: 72,
                    decoration: BoxDecoration(
                      color: theme.colorScheme.primary,
                      borderRadius: BorderRadius.circular(24),
                    ),
                    child: const Icon(
                      Icons.emergency_rounded,
                      color: Colors.white,
                      size: 34,
                    ),
                  ),
                  const SizedBox(height: 18),
                  Text(
                    'Bontoc Rescue',
                    style: theme.textTheme.headlineMedium,
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Preparing the updated mobile connection to Laravel, AI severity, and live incident workflows.',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodyMedium,
                  ),
                  const SizedBox(height: 22),
                  const CircularProgressIndicator(),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
