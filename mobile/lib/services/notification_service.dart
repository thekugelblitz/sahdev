import 'package:flutter_local_notifications/flutter_local_notifications.dart';

class NotificationService {
  static final NotificationService _instance = NotificationService._internal();
  factory NotificationService() => _instance;
  NotificationService._internal();

  final FlutterLocalNotificationsPlugin _plugin = FlutterLocalNotificationsPlugin();
  bool _initialized = false;

  Future<void> init() async {
    if (_initialized) return;

    const androidSettings = AndroidInitializationSettings('@mipmap/ic_launcher');
    const initSettings = InitializationSettings(android: androidSettings);

    await _plugin.initialize(
      initSettings,
      onDidReceiveNotificationResponse: (NotificationResponse response) {
        // Handled via navigation
      },
    );

    _initialized = true;
  }

  /// Show high-priority heads-up alert for urgent human summon
  Future<void> showSummonAlert({
    required String clientName,
    required String domain,
    required int sessionId,
  }) async {
    const androidDetails = AndroidNotificationDetails(
      'sahdev_summon_channel',
      'Human Support Summons',
      channelDescription: 'High priority alerts when a website visitor requests human support',
      importance: Importance.max,
      priority: Priority.high,
      playSound: true,
      enableVibration: true,
      category: AndroidNotificationCategory.call,
      fullScreenIntent: true,
    );

    const notificationDetails = NotificationDetails(android: androidDetails);

    await _plugin.show(
      sessionId,
      '🚨 Human Support Summoned!',
      '$clientName is waiting for a live agent on $domain',
      notificationDetails,
      payload: sessionId.toString(),
    );
  }

  /// Show notification for new client message
  Future<void> showNewMessageNotification({
    required int sessionId,
    required String senderName,
    required String messageText,
  }) async {
    const androidDetails = AndroidNotificationDetails(
      'sahdev_messages_channel',
      'Chat Messages',
      channelDescription: 'Notifications for incoming client chat messages',
      importance: Importance.high,
      priority: Priority.high,
      playSound: true,
    );

    const notificationDetails = NotificationDetails(android: androidDetails);

    await _plugin.show(
      sessionId + 10000,
      'New message from $senderName',
      messageText,
      notificationDetails,
      payload: sessionId.toString(),
    );
  }

  Future<void> cancelNotification(int id) async {
    await _plugin.cancel(id);
  }
}
