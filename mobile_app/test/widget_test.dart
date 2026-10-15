import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:mobile_app/app.dart';
import 'package:mobile_app/core/network/stitch_api_client.dart';
import 'package:mobile_app/core/session/stitch_session_controller.dart';

void main() {
  testWidgets('renders bontoc rescue startup screen before session loads', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final sessionController = StitchSessionController(
      apiClient: StitchApiClient(),
      preferences: preferences,
    );

    await tester.pumpWidget(StitchApp(sessionController: sessionController));

    expect(find.text('Bontoc Rescue'), findsOneWidget);
    expect(
      find.text('Preparing the updated mobile connection to Laravel, AI severity, and live incident workflows.'),
      findsOneWidget,
    );
  });
}
