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
  int _lastAlertTimestamp = 0;
  final Set<int> _silencedSessionIds = {};
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

  /// Silences the current active summon alarm immediately and marks session as acknowledged
  void silenceCurrentAlert([int? sessionId]) {
    if (sessionId != null && sessionId > 0) {
      _silencedSessionIds.add(sessionId);
    }
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
        final bool alertSound = data['alert_sound'] == true;

        if (urgentCount > 0 || alertSound) {
          // Parse first summoned session for notification banner
          final sessions = (data['sessions'] as List?) ?? [];
          final summoned = sessions.firstWhere(
            (s) => s['summon_status'] == 'requested',
            orElse: () => null,
          );

          final clientName = summoned?['client']?['name'] ?? 'Website Visitor';
          final domain = summoned?['source']?['domain'] ?? 'Your Website';
          final int sessionId = (summoned?['id'] as num?)?.toInt() ?? 1;

          final now = DateTime.now().millisecondsSinceEpoch;
          final bool isSilenced = _silencedSessionIds.contains(sessionId);
          final bool isNewSummon = (urgentCount > _lastAlertedSummonCount);
          final bool cooldownExpired = (now - _lastAlertTimestamp > 60000); // at least 60s between ring cycles

          if (!isSilenced) {
            if (isNewSummon || (cooldownExpired && !_audio.isRinging)) {
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
              _lastAlertTimestamp = now;
            }
          }
        } else {
          if (_audio.isRinging) {
            _audio.stopAlertRing();
          }
          _silencedSessionIds.clear();
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
