import 'dart:convert';
import 'package:flutter/material.dart';
import '../screens/chat_screen.dart';
import '../screens/ticket_detail_screen.dart';
import 'audio_service.dart';
import 'background_service.dart';

class NotificationRouter {
  static final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();
  static Map<String, dynamic>? _pendingPayload;

  /// Flag indicating if the authenticated HomeShell is mounted and ready for routing
  static bool isShellReady = false;

  /// Callback to switch tabs in the HomeShell (0: Chat, 1: Tickets, etc.)
  static void Function(int tabIndex)? onSwitchTab;

  /// Parse and normalize arbitrary notification payloads (from FCM, local notifications, or intents)
  static Map<String, dynamic> normalizePayload(dynamic rawPayload) {
    if (rawPayload == null) return {};

    Map<String, dynamic> data = {};

    if (rawPayload is Map<String, dynamic>) {
      data = Map<String, dynamic>.from(rawPayload);
    } else if (rawPayload is Map) {
      data = rawPayload.map((k, v) => MapEntry(k.toString(), v));
    } else if (rawPayload is String && rawPayload.trim().isNotEmpty) {
      try {
        final decoded = jsonDecode(rawPayload);
        if (decoded is Map) {
          data = decoded.map((k, v) => MapEntry(k.toString(), v));
        }
      } catch (e) {
        debugPrint('[NotificationRouter] JSON decode error: $e');
      }
    }

    // Check for nested 'data' map or string
    if (data.containsKey('data')) {
      final inner = data['data'];
      if (inner is Map) {
        inner.forEach((k, v) {
          data[k.toString()] = v;
        });
      } else if (inner is String && inner.trim().startsWith('{')) {
        try {
          final decodedInner = jsonDecode(inner);
          if (decodedInner is Map) {
            decodedInner.forEach((k, v) {
              data[k.toString()] = v;
            });
          }
        } catch (_) {}
      }
    }

    // Check for nested 'payload' map or string
    if (data.containsKey('payload')) {
      final inner = data['payload'];
      if (inner is Map) {
        inner.forEach((k, v) {
          data[k.toString()] = v;
        });
      } else if (inner is String && inner.trim().startsWith('{')) {
        try {
          final decodedInner = jsonDecode(inner);
          if (decodedInner is Map) {
            decodedInner.forEach((k, v) {
              data[k.toString()] = v;
            });
          }
        } catch (_) {}
      }
    }

    return data;
  }

  /// Robust extraction of ticket ID from payload keys or title/body fallbacks
  static int extractTicketId(Map<String, dynamic> data) {
    // 1. Direct explicit keys
    final direct = data['ticket_id'] ??
        data['ticketId'] ??
        data['ticketid'] ??
        data['tid'] ??
        data['id'];

    if (direct != null) {
      final parsed = int.tryParse(direct.toString().trim());
      if (parsed != null && parsed > 0) {
        return parsed;
      }
    }

    // 2. Nested ticket map
    if (data['ticket'] is Map) {
      final ticketMap = data['ticket'] as Map;
      final nested = ticketMap['id'] ?? ticketMap['ticket_id'] ?? ticketMap['ticketid'] ?? ticketMap['tid'];
      if (nested != null) {
        final parsed = int.tryParse(nested.toString().trim());
        if (parsed != null && parsed > 0) {
          return parsed;
        }
      }
    }

    // 3. Fallback: Parse ticket mask from title or body (e.g. "#12345" or "Ticket #12345")
    final textToScan = '${data['title'] ?? ''} ${data['subject'] ?? ''} ${data['body'] ?? ''}';
    final match = RegExp(r'#(\d+)').firstMatch(textToScan);
    if (match != null) {
      final parsed = int.tryParse(match.group(1) ?? '0');
      if (parsed != null && parsed > 0) {
        return parsed;
      }
    }

    return 0;
  }

  /// Handle incoming notification tap data (from either FCM or local flutter_local_notifications)
  static void onNotificationOpened(dynamic rawPayload) {
    final data = normalizePayload(rawPayload);
    if (data.isEmpty) return;

    debugPrint('[NotificationRouter] Processing notification payload: $data');

    // Immediately stop any active alarm ringing
    AudioService().stopAlertRing();

    final navState = navigatorKey.currentState;
    if (isShellReady && navState != null) {
      // Warm routing: UI is active and authenticated
      _performRoute(navState, data);
    } else {
      // Cold routing: App is launching / authenticating; buffer payload for when HomeShell mounts
      debugPrint('[NotificationRouter] Shell not ready yet. Buffering payload for cold start.');
      _pendingPayload = data;
    }
  }

  /// Process any buffered cold-start notification navigation once the UI shell is ready
  static void checkAndRoutePending(BuildContext context) {
    if (_pendingPayload != null) {
      final payload = _pendingPayload!;
      _pendingPayload = null;
      debugPrint('[NotificationRouter] Executing buffered cold-start navigation: $payload');
      final navState = Navigator.of(context, rootNavigator: true);
      _performRoute(navState, payload);
    }
  }

  static void _performRoute(NavigatorState navState, Map<String, dynamic> data) {
    final eventType = (data['event_type'] ?? data['event'] ?? data['type'] ?? '')
        .toString()
        .toLowerCase();
    final int sessionId = int.tryParse((data['session_id'] ?? data['sessionId'])?.toString() ?? '0') ?? 0;
    final int ticketId = extractTicketId(data);

    final String clientName = data['client_name']?.toString() ??
        data['sender_name']?.toString() ??
        'Live Chat Client';
    final String sessionUuid = data['session_uuid']?.toString() ?? 'session_$sessionId';

    final isTicketEvent = eventType.contains('ticket') || ticketId > 0;
    final isChatEvent = sessionId > 0 ||
        eventType == 'summon' ||
        eventType == 'chat_message' ||
        eventType == 'new_visitor';

    if (sessionId > 0 && isChatEvent) {
      // 1. Live Chat / Summon routing
      BackgroundService().silenceCurrentAlert(sessionId);
      AudioService().stopAlertRing();

      // Switch to Chat tab
      onSwitchTab?.call(0);

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
    } else if (isTicketEvent) {
      // 2. WHMCS Ticket routing
      debugPrint('[NotificationRouter] Routing to Ticket #$ticketId (eventType: $eventType)');

      // Switch to Tickets tab in HomeShell (Tab Index 1)
      onSwitchTab?.call(1);

      if (ticketId > 0) {
        // Direct intent: push TicketDetailScreen directly
        navState.push(
          MaterialPageRoute(
            builder: (_) => TicketDetailScreen(ticketId: ticketId),
          ),
        );
      }
    }
  }
}
