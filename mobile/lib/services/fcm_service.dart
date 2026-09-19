import 'dart:async';
import 'package:flutter/widgets.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../config/api_config.dart';
import 'api_service.dart';
import 'audio_service.dart';
import 'notification_service.dart';

/// Top-level background message handler for FCM.
/// Must be annotated with @pragma('vm:entry-point') so Flutter AOT compiler keeps it.
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  WidgetsFlutterBinding.ensureInitialized();
  try {
    await NotificationService().init();
    await FcmService.handleIncomingRemoteMessage(message, isBackground: true);
  } catch (e) {
    debugPrint('[FCM Background Handler Error] $e');
  }
}

class FcmService {
  static final FcmService _instance = FcmService._internal();
  factory FcmService() => _instance;
  FcmService._internal();

  final ApiService _api = ApiService();
  String? _fcmToken;
  bool _initialized = false;

  String? get fcmToken => _fcmToken;

  /// Callback for app navigation when a notification is tapped
  static void Function(String eventType, Map<String, dynamic> data)? onNotificationNavigate;

  /// Initialize Firebase & FCM listeners safely
  Future<void> init() async {
    if (_initialized) return;

    try {
      await Firebase.initializeApp();
    } catch (e) {
      debugPrint('[Firebase Init] Firebase not initialized or placeholder credentials: $e');
      return;
    }

    try {
      final messaging = FirebaseMessaging.instance;

      // 1. Request notification permissions (Android 13+ & iOS)
      final settings = await messaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
        provisional: false,
        criticalAlert: true,
      );

      debugPrint('[FCM] Permission status: ${settings.authorizationStatus}');

      // 2. Obtain current device token
      try {
        _fcmToken = await messaging.getToken();
      } catch (e) {
        debugPrint('[FCM getToken error] $e');
      }

      if (_fcmToken != null) {
        debugPrint('[FCM] Token: ${_fcmToken!.substring(0, 16)}...');
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('sdv_fcm_token', _fcmToken!);

        // Auto-sync immediately with WHMCS if already logged in / paired
        final baseUrl = prefs.getString(ApiConfig.keyWhmcsUrl);
        final token = prefs.getString(ApiConfig.keyMobileToken);
        if (baseUrl != null && token != null) {
          await syncTokenWithBackend(baseUrl: baseUrl, token: token);
        }
      }

      // 3. Listen for token refreshes
      messaging.onTokenRefresh.listen((newToken) async {
        _fcmToken = newToken;
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('sdv_fcm_token', newToken);

        final baseUrl = prefs.getString(ApiConfig.keyWhmcsUrl);
        final token = prefs.getString(ApiConfig.keyMobileToken);
        if (baseUrl != null && token != null) {
          await syncTokenWithBackend(baseUrl: baseUrl, token: token);
        }
      });

      // 4. Foreground message listener
      FirebaseMessaging.onMessage.listen((RemoteMessage message) {
        debugPrint('[FCM] Foreground message received: ${message.data}');
        handleIncomingRemoteMessage(message, isBackground: false);
      });

      // 5. Message opened while app was in background
      FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
        debugPrint('[FCM] Notification opened from background: ${message.data}');
        _handleNotificationClick(message.data);
      });

      // 6. Terminated app click
      final initialMessage = await messaging.getInitialMessage();
      if (initialMessage != null) {
        debugPrint('[FCM] App launched from terminated state via notification: ${initialMessage.data}');
        Future.delayed(const Duration(milliseconds: 600), () {
          _handleNotificationClick(initialMessage.data);
        });
      }

      _initialized = true;
    } catch (e) {
      debugPrint('[FCM Init Error] $e');
    }
  }

  /// Sync device FCM token with WHMCS backend
  Future<bool> syncTokenWithBackend({
    required String baseUrl,
    required String token,
  }) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      String? savedFcm = _fcmToken ?? prefs.getString('sdv_fcm_token');
      if (savedFcm == null || savedFcm.isEmpty) {
        try {
          savedFcm = await FirebaseMessaging.instance.getToken();
          if (savedFcm != null && savedFcm.isNotEmpty) {
            _fcmToken = savedFcm;
            await prefs.setString('sdv_fcm_token', savedFcm);
          }
        } catch (e) {
          debugPrint('[FCM GetToken Error] $e');
        }
      }

      if (savedFcm == null || savedFcm.isEmpty) {
        return false;
      }

      final res = await _api.registerFcmToken(
        baseUrl: baseUrl,
        token: token,
        fcmToken: savedFcm,
        deviceName: 'Android Staff Phone',
        platform: 'android',
        appVersion: '1.0.0',
      );

      return res.success;
    } catch (e) {
      debugPrint('[FCM Sync Error] $e');
      return false;
    }
  }

  /// Process incoming FCM message and display high-priority local notification
  static Future<void> handleIncomingRemoteMessage(RemoteMessage message, {bool isBackground = false}) async {
    final data = message.data;
    final eventType = data['event_type'] ?? 'general';

    // Global FCM deduplication key: prevent duplicate dispatches of the same message or event
    final dedupKey = 'fcm_${eventType}_${data['session_id'] ?? data['ticket_id'] ?? message.messageId}';
    if (NotificationService.shouldDeduplicate(dedupKey, 25000)) {
      debugPrint('[FCM] Suppressing duplicate FCM dispatch: $dedupKey');
      return;
    }

    final prefs = await SharedPreferences.getInstance();

    // User preference checks
    final notifySummons = prefs.getBool('pref_notify_summons') ?? true;
    final notifyVisitors = prefs.getBool('pref_notify_visitors') ?? true;
    final notifyChats = prefs.getBool('pref_notify_chats') ?? true;
    final notifyTickets = prefs.getBool('pref_notify_tickets') ?? true;
    final notifySystem = prefs.getBool('pref_notify_system') ?? true;
    final alertMode = prefs.getString('pref_alert_mode') ?? 'ringing'; // 'ringing' or 'chime'

    final notifService = NotificationService();
    final audioService = AudioService();

    // CRITICAL: When an FCM message contains a notification payload ('message.notification != null')
    // AND the app is in the background or killed, Google Play Services on Android automatically
    // renders the notification in the system drawer. Calling local notification plugin here would
    // produce a DUPLICATE notification banner. Only show local notification if system did NOT render it!
    final bool systemAlreadyRendered = isBackground && (message.notification != null);

    if (eventType == 'summon' && notifySummons) {
      final clientName = data['client_name'] ?? (message.notification?.title ?? 'Website Visitor');
      final domain = data['domain'] ?? 'Your Website';
      final int sessionId = int.tryParse(data['session_id']?.toString() ?? '1') ?? 1;

      // Coordinate deduplication with BackgroundService polling
      NotificationService.shouldDeduplicate('summon_$sessionId', 45000);

      if (!systemAlreadyRendered) {
        await notifService.showSummonAlert(
          clientName: clientName,
          domain: domain,
          sessionId: sessionId,
        );
      }

      if (!isBackground) {
        // App is open and in focus: gentle soft ping chime only, do not ring loudly
        await audioService.playChime();
      } else {
        // App is closed / in background: ring loudly if configured
        if (alertMode == 'ringing') {
          if (!audioService.isRinging) {
            await audioService.startAlarmRing();
          }
        } else {
          await audioService.playChime();
        }
      }
    } else if (eventType == 'new_visitor' && notifyVisitors) {
      final clientName = data['client_name'] ?? (message.notification?.title ?? 'Website Visitor');
      final domain = data['domain'] ?? 'Your Website';
      final int sessionId = int.tryParse(data['session_id']?.toString() ?? '0') ?? 0;

      NotificationService.shouldDeduplicate('visitor_$sessionId', 45000);

      if (!systemAlreadyRendered) {
        await notifService.showNewVisitorNotification(
          sessionId: sessionId,
          clientName: clientName,
          domain: domain,
        );
      }

      await audioService.playChime();
    } else if (eventType == 'chat_message' && notifyChats) {
      final int sessionId = int.tryParse(data['session_id']?.toString() ?? '0') ?? 0;
      final senderName = data['sender_name'] ?? 'Visitor';
      final messageText = data['message_text'] ?? (message.notification?.body ?? 'New message');

      if (!systemAlreadyRendered) {
        await notifService.showNewMessageNotification(
          sessionId: sessionId,
          senderName: senderName,
          messageText: messageText,
        );
      }

      await audioService.playChime();
    } else if (eventType == 'ticket' && notifyTickets) {
      final int ticketId = int.tryParse(data['ticket_id']?.toString() ?? '0') ?? 0;
      final subject = data['subject'] ?? (message.notification?.body ?? 'Support ticket update');
      final actionType = data['action_type'] ?? 'opened';

      if (!systemAlreadyRendered) {
        await notifService.showTicketNotification(
          ticketId: ticketId,
          subject: subject,
          actionType: actionType,
        );
      }

      await audioService.playChime();
    } else if (eventType == 'system_alert' && notifySystem) {
      final title = data['title'] ?? (message.notification?.title ?? 'System Alert');
      final body = data['body'] ?? (message.notification?.body ?? 'Alert from Sahdev Copilot');

      if (!systemAlreadyRendered) {
        await notifService.showSystemNotification(
          title: title,
          body: body,
        );
      }
    }
  }

  /// Handle notification payload routing on click
  static void _handleNotificationClick(Map<String, dynamic> data) {
    final eventType = data['event_type'] ?? 'general';
    if (onNotificationNavigate != null) {
      onNotificationNavigate!(eventType, data);
    }
  }
}
