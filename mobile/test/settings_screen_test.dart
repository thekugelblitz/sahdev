import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sahdev_mobile/providers/auth_provider.dart';
import 'package:sahdev_mobile/providers/theme_provider.dart';
import 'package:sahdev_mobile/screens/settings_screen.dart';
import 'package:sahdev_mobile/services/audio_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({
      'pref_alert_mode': 'chime',
      'sdv_alert_duration': 15,
    });

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('xyz.luan/audioplayers'),
      (MethodCall methodCall) async => 1,
    );

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('xyz.luan/audioplayers.global'),
      (MethodCall methodCall) async => 1,
    );

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('vibration'),
      (MethodCall methodCall) async => false,
    );

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('plugins.flutter.io/path_provider'),
      (MethodCall methodCall) async => '.',
    );

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMessageHandler('flutter/assets', (ByteData? message) async => ByteData(0));

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('dexterous.com/flutter/local_notifications'),
      (MethodCall methodCall) async => true,
    );
  });

  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(const MethodChannel('xyz.luan/audioplayers'), null);
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(const MethodChannel('xyz.luan/audioplayers.global'), null);
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(const MethodChannel('vibration'), null);
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(const MethodChannel('plugins.flutter.io/path_provider'), null);
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMessageHandler('flutter/assets', null);
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(const MethodChannel('dexterous.com/flutter/local_notifications'), null);
  });

  Widget createWidgetUnderTest() {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider<AuthProvider>(create: (_) => AuthProvider()),
        ChangeNotifierProvider<ThemeProvider>(create: (_) => ThemeProvider()),
      ],
      child: const MaterialApp(
        home: SettingsScreen(),
      ),
    );
  }

  testWidgets('Playback Duration card is completely hidden when Single Notification Chime is selected and reappears when switching to Continuous Ringing Alarm', (tester) async {
    tester.view.physicalSize = const Size(1080, 4000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final audio = AudioService();
    await audio.setAlertMode('chime');

    await tester.pumpWidget(createWidgetUnderTest());
    await tester.pumpAndSettle();

    // 1. With Single Notification Chime selected, Playback Duration card must NOT be present
    expect(find.text('PLAYBACK DURATION'), findsNothing);
    expect(find.text('Sound Alert Playback Duration'), findsNothing);
    expect(find.text('5s (Default)'), findsNothing);
    expect(find.text('Continuous Loop'), findsNothing);

    // Vibration card is still available
    expect(find.text('HAPTIC VIBRATION'), findsOneWidget);
    expect(find.text('Vibration Pattern'), findsOneWidget);

    // 2. Tap Continuous Ringing Alarm
    final continuousRadio = find.text('Continuous Ringing Alarm');
    expect(continuousRadio, findsOneWidget);
    await tester.ensureVisible(continuousRadio);
    await tester.tap(continuousRadio);
    await tester.pumpAndSettle();

    // 3. Immediately Playback Duration card and chips must appear
    expect(find.text('PLAYBACK DURATION'), findsOneWidget);
    expect(find.text('Sound Alert Playback Duration'), findsOneWidget);
    expect(find.text('5s (Default)'), findsOneWidget);
    expect(find.text('Continuous Loop'), findsOneWidget);

    // 4. Tap Single Notification Chime again
    final chimeRadio = find.text('Single Notification Chime');
    expect(chimeRadio, findsOneWidget);
    await tester.ensureVisible(chimeRadio);
    await tester.tap(chimeRadio);
    await tester.pumpAndSettle();

    // 5. Playback Duration card must be immediately hidden again
    expect(find.text('PLAYBACK DURATION'), findsNothing);
    expect(find.text('Sound Alert Playback Duration'), findsNothing);
    expect(find.text('5s (Default)'), findsNothing);
  });
}
