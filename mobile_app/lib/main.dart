import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app.dart';
import 'core/network/stitch_api_client.dart';
import 'core/session/stitch_session_controller.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final preferences = await SharedPreferences.getInstance();
  final sessionController = StitchSessionController(
    apiClient: StitchApiClient(),
    preferences: preferences,
  );

  await sessionController.initialize();

  runApp(StitchApp(sessionController: sessionController));
}
