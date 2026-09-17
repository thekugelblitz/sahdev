import 'dart:async';
import 'package:url_launcher/url_launcher.dart';
import 'api_service.dart';
import 'audio_service.dart';
import 'notification_service.dart';

class BackgroundService {
  static final BackgroundService _instance = BackgroundService._internal();
  factory BackgroundService() => _instance;
  BackgroundService._internal();

  final ApiService _api = ApiService();
  final AudioService _audio = AudioService();
  final NotificationService _notifications = NotificationService();

  Timer? _pollingTimer;
  bool _isOnline = true;
  String? _baseUrl;
  String? _token;
  int _lastAlertedSummonCount = 0;
  String _alertMode = 'ringing'; // 'ringing' or 'chime'
  bool _isChecking = false;

  bool get isOnline => _isOnline;
  String get alertMode => _alertMode;

  void configure({
    required String baseUrl,
    required String token,
    required bool isOnline,
    String alertMode = 'ringing',
  }) {
    _baseUrl = baseUrl;
    _token = token;
    _isOnline = isOnline;
    _alertMode = alertMode;

    if (_isOnline && _baseUrl != null && _token != null) {
      startPolling();
    } else {
      stopPolling();
    }
  }

  void setOnlineStatus(bool online) {
    _isOnline = online;
    if (_isOnline) {
      startPolling();
    } else {
      stopPolling();
    }
  }

  void setAlertMode(String mode) {
    _alertMode = mode;
  }

  void startPolling({int intervalSeconds = 3}) {
    _pollingTimer?.cancel();
    _pollingTimer = Timer.periodic(Duration(seconds: intervalSeconds), (timer) async {
      if (!_isOnline || _baseUrl == null || _token == null || _isChecking) return;
      await _checkQueue();
    });
  }

  void stopPolling() {
    _pollingTimer?.cancel();
    _pollingTimer = null;
    _audio.stopAlertRing();
  }

  /// Check queue for urgent human summons and unread client messages
  Future<void> _checkQueue() async {
    _isChecking = true;
    try {
      final res = await _api.pollQueue(baseUrl: _baseUrl!, token: _token!);
      if (res.success && res.data != null) {
        final data = res.data!;
        final int urgentCount = data['urgent_summons_count'] ?? 0;
        final bool shouldAlert = (data['alert_sound'] == true || data['should_alert'] == true);

        if (urgentCount > 0) {
          // Parse first summoned session for notification banner
          final sessions = (data['sessions'] as List?) ?? [];
          final summoned = sessions.firstWhere(
            (s) => s['summon_status'] == 'requested',
            orElse: () => null,
          );

          final clientName = summoned?['client']?['name'] ?? 'Website Visitor';
          final domain = summoned?['source']?['domain'] ?? 'Your Website';
          final sessionId = summoned?['id'] ?? 1;

          // Always display the heads-up high-importance notification
          if (shouldAlert || urgentCount != _lastAlertedSummonCount || !_audio.isRinging) {
            await _notifications.showSummonAlert(
              clientName: clientName,
              domain: domain,
              sessionId: sessionId,
            );

            if (_alertMode == 'ringing') {
              if (!_audio.isRinging) {
                await _audio.startAlarmRing();
              }
            } else {
              await _audio.playChime();
            }
          }
        } else if (urgentCount == 0 && _audio.isRinging) {
          _audio.stopAlertRing();
        }

        _lastAlertedSummonCount = urgentCount;
      }
    } catch (_) {
    } finally {
      _isChecking = false;
    }
  }

  /// Opens Android system Battery Optimization settings for the app
  static Future<bool> requestIgnoreBatteryOptimizations() async {
    try {
      final uri = Uri.parse("package:com.sahdev.livechat");
      // Intent for requesting direct ignore
      final intentUri = Uri.parse("android.settings.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS");
      if (await canLaunchUrl(uri)) {
        return await launchUrl(uri);
      } else if (await canLaunchUrl(intentUri)) {
        return await launchUrl(intentUri);
      }
    } catch (_) {}
    return false;
  }
}
