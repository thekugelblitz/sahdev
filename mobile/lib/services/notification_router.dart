import 'dart:convert';
import 'package:flutter/material.dart';
import '../screens/chat_screen.dart';
import '../screens/ticket_detail_screen.dart';
import 'audio_service.dart';
import 'background_service.dart';

class NotificationRouter {
  static final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();
  static Map<String, dynamic>? _pendingPayload;

  /// Handle incoming notification tap data (from either FCM or local flutter_local_notifications)
  static void onNotificationOpened(dynamic rawPayload) {
    if (rawPayload == null) return;

    Map<String, dynamic> data = {};

    if (rawPayload is Map<String, dynamic>) {
      data = rawPayload;
    } else if (rawPayload is String && rawPayload.isNotEmpty) {
      try {
        final decoded = jsonDecode(rawPayload);
        if (decoded is Map<String, dynamic>) {
          data = decoded;
        }
      } catch (e) {
        debugPrint('[NotificationRouter] JSON parse error: $e');
      }
    }

    if (data.isEmpty) return;

    debugPrint('[NotificationRouter] Routing notification payload: $data');

    // Immediately stop any active alarm ringing
    AudioService().stopAlertRing();

    final navState = navigatorKey.currentState;
    if (navState != null) {
      // Warm routing: Navigator is active
      _performRoute(navState, data);
    } else {
      // Cold routing: App is launching; buffer payload for when HomeShell mounts
      _pendingPayload = data;
    }
  }

  /// Process any buffered cold-start notification navigation once the UI shell is ready
  static void checkAndRoutePending(BuildContext context) {
    if (_pendingPayload != null) {
      final payload = _pendingPayload!;
      _pendingPayload = null;
      final navState = Navigator.of(context, rootNavigator: true);
      _performRoute(navState, payload);
    }
  }

  static void _performRoute(NavigatorState navState, Map<String, dynamic> data) {
    final eventType = data['event_type']?.toString() ?? '';
    final int sessionId = int.tryParse(data['session_id']?.toString() ?? '0') ?? 0;
    final int ticketId = int.tryParse(data['ticket_id']?.toString() ?? '0') ?? 0;
    final String clientName = data['client_name']?.toString() ??
        data['sender_name']?.toString() ??
        'Live Chat Client';
    final String sessionUuid = data['session_uuid']?.toString() ?? 'session_$sessionId';

    if (sessionId > 0 ||
        eventType == 'summon' ||
        eventType == 'chat_message' ||
        eventType == 'new_visitor') {
      if (sessionId > 0) {
        BackgroundService().silenceCurrentAlert(sessionId);
        AudioService().stopAlertRing();

        navState.push(
          MaterialPageRoute(
            builder: (_) => ChatScreen(
              sessionId: sessionId,
              sessionUuid: sessionUuid,
              clientName: clientName,
              initialTakenOver: true,
            ),
          ),
        );
      }
    } else if (ticketId > 0 || eventType == 'ticket') {
      if (ticketId > 0) {
        navState.push(
          MaterialPageRoute(
            builder: (_) => TicketDetailScreen(ticketId: ticketId),
          ),
        );
      }
    }
  }
}
