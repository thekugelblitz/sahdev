import 'package:flutter_local_notifications/flutter_local_notifications.dart';

class NotificationService {
  static final NotificationService _instance = NotificationService._internal();
  factory NotificationService() => _instance;
  NotificationService._internal();

  final FlutterLocalNotificationsPlugin _plugin = FlutterLocalNotificationsPlugin();
  bool _initialized = false;

  static void Function(String payload)? onNotificationTapped;

  Future<void> init() async {
    if (_initialized) return;

    const androidSettings = AndroidInitializationSettings('@mipmap/ic_launcher');
    const initSettings = InitializationSettings(android: androidSettings);

    await _plugin.initialize(
      initSettings,
      onDidReceiveNotificationResponse: (NotificationResponse response) {
        if (response.payload != null && onNotificationTapped != null) {
          onNotificationTapped!(response.payload!);
        }
      },
    );

    // Create high-priority notification channels on Android
    final androidPlugin = _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
    if (androidPlugin != null) {
      // 1. Urgent Human Summon Channel
      await androidPlugin.createNotificationChannel(
        const AndroidNotificationChannel(
          'sahdev_summon_channel',
          'Human Support Summons',
          description: 'High-priority full-screen alerts when a website visitor requests human support',
          importance: Importance.max,
          playSound: true,
          enableVibration: true,
        ),
      );

      // 2. Active Chat Messages Channel
      await androidPlugin.createNotificationChannel(
        const AndroidNotificationChannel(
          'sahdev_messages_channel',
          'Chat Messages',
          description: 'Notifications for incoming customer live chat messages',
          importance: Importance.high,
          playSound: true,
          enableVibration: true,
        ),
      );

      // 3. WHMCS Support Tickets Channel
      await androidPlugin.createNotificationChannel(
        const AndroidNotificationChannel(
          'sahdev_tickets_channel',
          'Support Tickets',
          description: 'Notifications for new support tickets and customer replies',
          importance: Importance.high,
          playSound: true,
          enableVibration: true,
        ),
      );

      // 4. System & AI Alerts Channel
      await androidPlugin.createNotificationChannel(
        const AndroidNotificationChannel(
          'sahdev_system_channel',
          'System & AI Notices',
          description: 'Alerts regarding AI autonomous fallback and system notices',
          importance: Importance.defaultImportance,
          playSound: true,
        ),
      );
    }

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
      onlyAlertOnce: true,
      category: AndroidNotificationCategory.call,
      fullScreenIntent: true,
    );

    const notificationDetails = NotificationDetails(android: androidDetails);

    await _plugin.show(
      sessionId,
      '🚨 Human Support Summoned!',
      '$clientName is waiting for a live agent on $domain',
      notificationDetails,
      payload: 'session:$sessionId',
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
      payload: 'session:$sessionId',
    );
  }

  /// Show notification for WHMCS support ticket or reply
  Future<void> showTicketNotification({
    required int ticketId,
    required String subject,
    required String actionType,
  }) async {
    const androidDetails = AndroidNotificationDetails(
      'sahdev_tickets_channel',
      'Support Tickets',
      channelDescription: 'Notifications for WHMCS support tickets',
      importance: Importance.high,
      priority: Priority.high,
      playSound: true,
    );

    const notificationDetails = NotificationDetails(android: androidDetails);

    final title = (actionType == 'reply')
        ? '📩 Ticket Reply: #$ticketId'
        : '🎫 New Ticket: #$ticketId';

    await _plugin.show(
      ticketId + 20000,
      title,
      subject,
      notificationDetails,
      payload: 'ticket:$ticketId',
    );
  }

  /// Show notification for system or AI notice
  Future<void> showSystemNotification({
    required String title,
    required String body,
  }) async {
    const androidDetails = AndroidNotificationDetails(
      'sahdev_system_channel',
      'System & AI Notices',
      channelDescription: 'Notifications for system status and autonomous AI notices',
      importance: Importance.defaultImportance,
      priority: Priority.defaultPriority,
      playSound: true,
    );

    const notificationDetails = NotificationDetails(android: androidDetails);

    await _plugin.show(
      30001,
      title,
      body,
      notificationDetails,
      payload: 'system_alert',
    );
  }

  Future<void> cancelNotification(int id) async {
    await _plugin.cancel(id);
  }
}
