import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

class NotificationService {
  static final NotificationService _instance = NotificationService._internal();
  factory NotificationService() => _instance;
  NotificationService._internal();

  final FlutterLocalNotificationsPlugin _plugin = FlutterLocalNotificationsPlugin();
  bool _initialized = false;

  static void Function(String payload)? onNotificationTapped;

  // In-memory deduplication cache: avoids double-alerting from simultaneous FCM + polling
  static final Map<String, int> _dedupCache = {};

  /// Check whether an alert with this key was processed recently (default: 30 seconds)
  static bool shouldDeduplicate(String key, [int ttlMs = 30000]) {
    final now = DateTime.now().millisecondsSinceEpoch;
    final last = _dedupCache[key] ?? 0;
    if (now - last < ttlMs) {
      return true;
    }
    _dedupCache[key] = now;
    return false;
  }

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

    // Check notification permission
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

    // Check if app was cold-launched directly by tapping a notification
    try {
      final launchDetails = await _plugin.getNotificationAppLaunchDetails();
      if (launchDetails != null && launchDetails.didNotificationLaunchApp) {
        final payload = launchDetails.notificationResponse?.payload;
        if (payload != null && payload.isNotEmpty) {
          Future.delayed(const Duration(milliseconds: 700), () {
            if (onNotificationTapped != null) {
              onNotificationTapped!(payload);
            }
          });
        }
      }
    } catch (e) {
      debugPrint('[NotificationService] launchDetails error: $e');
    }

    _initialized = true;
  }

  /// Show high-priority heads-up alert for urgent human summon
  Future<void> showSummonAlert({
    required String clientName,
    required String domain,
    required int sessionId,
  }) async {
    final dedupKey = 'summon_$sessionId';
    if (shouldDeduplicate(dedupKey, 30000)) {
      debugPrint('[NotificationService] Suppressing duplicate summon notification for session #$sessionId');
      return;
    }

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

    final payload = jsonEncode({
      'event_type': 'summon',
      'session_id': sessionId,
      'client_name': clientName,
      'domain': domain,
    });

    await _plugin.show(
      sessionId,
      '🚨 Human Support Summoned!',
      '$clientName is waiting for a live agent on $domain',
      notificationDetails,
      payload: payload,
    );
  }

  /// Show notification for new website visitor arrival
  Future<void> showNewVisitorNotification({
    required int sessionId,
    required String clientName,
    required String domain,
  }) async {
    final dedupKey = 'visitor_$sessionId';
    if (shouldDeduplicate(dedupKey, 30000)) {
      return;
    }

    const androidDetails = AndroidNotificationDetails(
      'sahdev_messages_channel',
      'Chat Messages',
      channelDescription: 'Notifications for new visitors starting chats',
      importance: Importance.high,
      priority: Priority.high,
      playSound: true,
    );

    const notificationDetails = NotificationDetails(android: androidDetails);

    final payload = jsonEncode({
      'event_type': 'new_visitor',
      'session_id': sessionId,
      'client_name': clientName,
      'domain': domain,
    });

    await _plugin.show(
      sessionId + 30000,
      '👋 New Live Chat Visitor',
      '$clientName started browsing on $domain',
      notificationDetails,
      payload: payload,
    );
  }

  /// Show notification for new client message
  Future<void> showNewMessageNotification({
    required int sessionId,
    required String senderName,
    required String messageText,
  }) async {
    final dedupKey = 'msg_${sessionId}_${messageText.hashCode}';
    if (shouldDeduplicate(dedupKey, 15000)) {
      return;
    }

    const androidDetails = AndroidNotificationDetails(
      'sahdev_messages_channel',
      'Chat Messages',
      channelDescription: 'Notifications for incoming client chat messages',
      importance: Importance.high,
      priority: Priority.high,
      playSound: true,
    );

    const notificationDetails = NotificationDetails(android: androidDetails);

    final payload = jsonEncode({
      'event_type': 'chat_message',
      'session_id': sessionId,
      'client_name': senderName,
      'sender_name': senderName,
      'message_text': messageText,
    });

    await _plugin.show(
      sessionId + 10000,
      'New message from $senderName',
      messageText,
      notificationDetails,
      payload: payload,
    );
  }

  /// Show notification for WHMCS support ticket or reply
  Future<void> showTicketNotification({
    required int ticketId,
    required String subject,
    required String actionType,
    String? clientName,
  }) async {
    final androidDetails = AndroidNotificationDetails(
      'sahdev_tickets_channel',
      'Support Tickets',
      channelDescription: 'Notifications for WHMCS support tickets',
      importance: Importance.high,
      priority: Priority.high,
      playSound: true,
      tag: 'ticket_$ticketId',
    );

    final notificationDetails = NotificationDetails(android: androidDetails);

    final hasRealName = clientName != null && clientName.trim().isNotEmpty && clientName != 'Client';
    final title = (actionType == 'reply')
        ? (hasRealName ? '📩 Reply: $clientName (#$ticketId)' : '📩 Ticket Reply: #$ticketId')
        : (hasRealName ? '🎫 New Ticket: $clientName (#$ticketId)' : '🎫 New Ticket: #$ticketId');

    final payload = jsonEncode({
      'event_type': 'ticket',
      'ticket_id': ticketId,
      'ticketId': ticketId,
      'ticketid': ticketId,
      'id': ticketId,
      'action_type': actionType,
      'client_name': clientName,
      'subject': subject,
      'title': title,
    });

    await _plugin.show(
      ticketId + 20000,
      title,
      subject,
      notificationDetails,
      payload: payload,
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

    final payload = jsonEncode({
      'event_type': 'system_alert',
      'title': title,
      'body': body,
    });

    await _plugin.show(
      30001,
      title,
      body,
      notificationDetails,
      payload: payload,
    );
  }

  Future<void> cancelNotification(int id) async {
    await _plugin.cancel(id);
  }

  /// Checks if notification permissions are currently enabled on the device.
  /// Re-checks dynamically at runtime rather than relying on cached initial state.
  Future<bool> areNotificationsEnabled() async {
    try {
      final androidPlugin = _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
      if (androidPlugin != null) {
        final bool? enabled = await androidPlugin.areNotificationsEnabled();
        if (enabled != null && !enabled) {
          debugPrint('[NotificationService] Android notifications revoked in device settings');
          return false;
        }
      }

      try {
        final settings = await FirebaseMessaging.instance.getNotificationSettings();
        if (settings.authorizationStatus == AuthorizationStatus.denied) {
          debugPrint('[NotificationService] Notification permission is denied (FCM settings)');
          return false;
        }
      } catch (e) {
        debugPrint('[NotificationService] FCM settings check fallback: $e');
      }

      return true;
    } catch (e) {
      debugPrint('[NotificationService] areNotificationsEnabled check error: $e');
      return true;
    }
  }
}
