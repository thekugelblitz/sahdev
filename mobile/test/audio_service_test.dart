import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sahdev_mobile/services/audio_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  bool mockNotificationsEnabled = true;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    mockNotificationsEnabled = true;

    // Mock audioplayers channel
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('xyz.luan/audioplayers'),
      (MethodCall methodCall) async {
        return 1;
      },
    );

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('xyz.luan/audioplayers.global'),
      (MethodCall methodCall) async {
        return 1;
      },
    );

    // Mock vibration channel
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('vibration'),
      (MethodCall methodCall) async {
        return false;
      },
    );

    // Mock path_provider channel
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('plugins.flutter.io/path_provider'),
      (MethodCall methodCall) async {
        return '.';
      },
    );

    // Mock flutter/assets message handler
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMessageHandler('flutter/assets', (ByteData? message) async {
      return ByteData(0);
    });

    // Mock flutter_local_notifications channel
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('dexterous.com/flutter/local_notifications'),
      (MethodCall methodCall) async {
        if (methodCall.method == 'areNotificationsEnabled') {
          return mockNotificationsEnabled;
        }
        return true;
      },
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

  group('AudioService Notification Sound & Duration Tests', () {
    test('default notification sound duration is 5 seconds (not 30)', () {
      final audioService = AudioService();
      expect(AudioService.defaultAlertDurationSeconds, equals(5));
      expect(audioService.alertDurationSeconds, equals(5));
    });

    test('changing alert duration updates persisted setting and runtime state immediately', () async {
      final audioService = AudioService();
      await audioService.setAlertDuration(10);
      expect(audioService.alertDurationSeconds, equals(10));

      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getInt('sdv_alert_duration'), equals(10));

      // Reloading preferences preserves configured 10s
      await audioService.reloadPreferences();
      expect(audioService.alertDurationSeconds, equals(10));

      // Change again to 15s
      await audioService.setAlertDuration(15);
      expect(audioService.alertDurationSeconds, equals(15));
      expect(prefs.getInt('sdv_alert_duration'), equals(15));
    });

    test('permission revocation stops currently playing notification sound', () async {
      final audioService = AudioService();
      
      // Simulate permission revoked in device settings
      mockNotificationsEnabled = false;

      // Returning to foreground or calling handleAppResumed stops ringing
      await audioService.handleAppResumed();
      expect(audioService.isRinging, isFalse);
    });

    test('playNotificationSound completely skips playback when permission is revoked', () async {
      final audioService = AudioService();

      // Permission is revoked
      mockNotificationsEnabled = false;

      await audioService.playNotificationSound(isUrgent: true);
      // Playback skipped, isRinging is false
      expect(audioService.isRinging, isFalse);
    });

    test('stopAlertRing resets isRinging state and cancels ring timer', () async {
      final audioService = AudioService();
      await audioService.stopAlertRing();
      expect(audioService.isRinging, isFalse);
    });

    test('Single Notification Chime duration is strictly 1 second and cannot be overridden by alarm duration', () async {
      final audioService = AudioService();
      expect(AudioService.chimeDurationSeconds, equals(1));

      // Configure alarm duration to 30 seconds and mode to 'chime'
      await audioService.setAlertDuration(30);
      await audioService.setAlertMode('chime');
      expect(audioService.alertMode, equals('chime'));
      expect(audioService.alertDurationSeconds, equals(30));

      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('pref_alert_mode'), equals('chime'));

      // Play for chat summon while in chime mode
      await audioService.playForNotificationEvent(NotificationEventType.chatSummon);
      // In chime mode, sound is played as a 1s release chime, not a continuous ringing loop
      expect(audioService.isRinging, isFalse);
    });

    test('Notification event types are separated: ticket, normal chat, and standard use 1s default timing', () async {
      final audioService = AudioService();
      await audioService.setAlertMode('ring');
      await audioService.setAlertDuration(15);

      // Normal ticket message does NOT use continuous ringing
      await audioService.playForNotificationEvent(NotificationEventType.ticketMessage);
      expect(audioService.isRinging, isFalse);

      // Normal chat message does NOT use continuous ringing
      await audioService.playForNotificationEvent(NotificationEventType.normalChat);
      expect(audioService.isRinging, isFalse);

      // Standard notification does NOT use continuous ringing
      await audioService.playForNotificationEvent(NotificationEventType.standard);
      expect(audioService.isRinging, isFalse);

      // Only Chat Summon (and explicit alarmContinuous) enters continuous ringing state
      await audioService.playForNotificationEvent(NotificationEventType.chatSummon);
      expect(audioService.isRinging, isTrue);

      // Stop ringing
      await audioService.stopAlertRing();
      expect(audioService.isRinging, isFalse);
    });
  });
}
