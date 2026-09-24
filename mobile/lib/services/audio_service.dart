import 'dart:async';
import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:vibration/vibration.dart';
import 'notification_service.dart';

class SoundOption {
  final String key;
  final String title;
  final String description;
  final String assetPath;
  final String icon;

  const SoundOption({
    required this.key,
    required this.title,
    required this.description,
    required this.assetPath,
    this.icon = '🔔',
  });
}

class AudioService {
  static final AudioService _instance = AudioService._internal();
  factory AudioService() => _instance;
  AudioService._internal() {
    _loadPreferences();
  }

  static const List<SoundOption> availableSounds = [
    SoundOption(
      key: 'chime',
      title: 'Gentle Chime',
      description: 'Pleasant dual-bell notification chime',
      assetPath: 'sounds/chime.wav',
      icon: '✨',
    ),
    SoundOption(
      key: 'alarm',
      title: 'Urgent Alarm',
      description: 'High-visibility rapid buzzer alert',
      assetPath: 'sounds/alarm.wav',
      icon: '🚨',
    ),
    SoundOption(
      key: 'radar',
      title: 'Submarine Sonar',
      description: 'Deep resonant radar pulse ping',
      assetPath: 'sounds/radar.wav',
      icon: '📡',
    ),
    SoundOption(
      key: 'crystal',
      title: 'Crystal Drop',
      description: 'Sparkling high-frequency glass chime',
      assetPath: 'sounds/crystal.wav',
      icon: '💎',
    ),
    SoundOption(
      key: 'bell',
      title: 'Front Desk Bell',
      description: 'Crisp brass service counter bell',
      assetPath: 'sounds/bell.wav',
      icon: '🛎️',
    ),
    SoundOption(
      key: 'siren',
      title: 'Emergency Warble',
      description: 'Urgent alternating emergency siren',
      assetPath: 'sounds/siren.wav',
      icon: '📢',
    ),
    SoundOption(
      key: 'neon_ping',
      title: 'Neon Sci-Fi',
      description: 'Futuristic synthetic laser blip',
      assetPath: 'sounds/neon_ping.wav',
      icon: '⚡',
    ),
    SoundOption(
      key: 'pulse',
      title: 'Fast Pulse',
      description: 'Rapid staccato urgency bursts',
      assetPath: 'sounds/pulse.wav',
      icon: '💓',
    ),
    SoundOption(
      key: 'cosmic',
      title: 'Cosmic Arpeggio',
      description: 'Ascending 4-tone celestial synth chord',
      assetPath: 'sounds/cosmic.wav',
      icon: '🌌',
    ),
    SoundOption(
      key: 'electro',
      title: 'Digital Trill',
      description: 'High-tech alternating square wave',
      assetPath: 'sounds/electro.wav',
      icon: '🤖',
    ),
    SoundOption(
      key: 'heartbeat',
      title: 'Double Heartbeat',
      description: 'Subtle low-frequency cardiac thump',
      assetPath: 'sounds/heartbeat.wav',
      icon: '❤️',
    ),
    SoundOption(
      key: 'marimba',
      title: 'Warm Marimba',
      description: 'Acoustic wooden ascending tones',
      assetPath: 'sounds/marimba.wav',
      icon: '🎵',
    ),
  ];

  static const int defaultAlertDurationSeconds = 5;

  final AudioPlayer _player = AudioPlayer();
  Timer? _ringTimer;
  Timer? _permissionCheckTimer;
  bool _isRinging = false;
  bool _soundEnabled = true;

  String _selectedSoundKey = 'radar';
  int _alertDurationSeconds = defaultAlertDurationSeconds; // 5, 10, 15, 30, 60, 0 = continuous
  String _vibrationPattern = 'heavy'; // 'none', 'gentle', 'double', 'heavy', 'sos'

  bool get isRinging => _isRinging;
  bool get soundEnabled => _soundEnabled;
  String get selectedSoundKey => _selectedSoundKey;
  int get alertDurationSeconds => _alertDurationSeconds;
  String get vibrationPattern => _vibrationPattern;

  Future<void> reloadPreferences() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.reload();
      _selectedSoundKey = prefs.getString('sdv_sound_key') ?? 'radar';
      _alertDurationSeconds = prefs.getInt('sdv_alert_duration') ?? defaultAlertDurationSeconds;
      _vibrationPattern = prefs.getString('sdv_vibration_pattern') ?? 'heavy';
      _soundEnabled = prefs.getBool('sdv_sound_enabled') ?? true;
    } catch (e) {
      debugPrint('[AudioService] Error reloading preferences: $e');
    }
  }

  Future<void> _loadPreferences() async {
    await reloadPreferences();
  }

  Future<void> setSoundKey(String key) async {
    _selectedSoundKey = key;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('sdv_sound_key', key);
  }

  Future<void> setAlertDuration(int seconds) async {
    _alertDurationSeconds = seconds;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt('sdv_alert_duration', seconds);
  }

  Future<void> setVibrationPattern(String pattern) async {
    _vibrationPattern = pattern;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('sdv_vibration_pattern', pattern);
  }

  Future<void> setSoundEnabled(bool enabled) async {
    _soundEnabled = enabled;
    if (!enabled) {
      stopAlertRing();
    }
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('sdv_sound_enabled', enabled);
  }

  SoundOption get currentSoundOption {
    return availableSounds.firstWhere(
      (s) => s.key == _selectedSoundKey,
      orElse: () => availableSounds[0],
    );
  }

  /// Checks whether notification permissions are currently granted.
  /// If permissions are revoked or denied, stops any currently active sound and returns false.
  Future<bool> checkPermissionAndStopIfRevoked() async {
    final hasPermission = await NotificationService().areNotificationsEnabled();
    if (!hasPermission) {
      if (_isRinging) {
        debugPrint('[AudioService] Notification permission revoked while ringing. Stopping audio.');
        await stopAlertRing();
      }
      return false;
    }
    return true;
  }

  /// Called when app returns to foreground from background or system settings.
  /// Immediately re-checks notification permissions and stops any playing sound if revoked.
  Future<void> handleAppResumed() async {
    final hasPermission = await checkPermissionAndStopIfRevoked();
    if (hasPermission) {
      await reloadPreferences();
    }
  }

  /// Single permission-aware notification sound flow.
  /// 
  /// Guarantees:
  /// 1. Notification permissions are dynamically verified before any audio playback starts.
  /// 2. If permission is denied or revoked, playback is completely skipped and any active sound is stopped.
  /// 3. Always reloads persisted settings (duration, sound key, enabled status) so runtime changes take effect immediately.
  /// 4. Respects the configured duration (default: 5 seconds, not 30 seconds).
  /// 5. Automatically stops the sound when the configured duration is reached.
  /// 6. Periodically checks permission during active playback to stop immediately if revoked.
  Future<void> playNotificationSound({
    String? soundKey,
    int? customDurationSeconds,
    bool loop = true,
    bool isUrgent = false,
  }) async {
    // 1. Strict dynamic permission check immediately before triggering any sound
    final hasPermission = await checkPermissionAndStopIfRevoked();
    if (!hasPermission) {
      debugPrint('[AudioService] Skipped notification sound: Notification permission revoked/denied.');
      return;
    }

    // 2. Freshly reload persisted preferences so any runtime duration changes take effect immediately
    await reloadPreferences();

    if (!_soundEnabled) {
      debugPrint('[AudioService] Skipped notification sound: Audio alerts disabled by user.');
      return;
    }

    final key = soundKey ?? _selectedSoundKey;
    final int duration = customDurationSeconds ?? _alertDurationSeconds;
    final opt = availableSounds.firstWhere(
      (s) => s.key == key,
      orElse: () => availableSounds[0],
    );

    // Cancel any previous ring timer and permission poller
    _ringTimer?.cancel();
    _ringTimer = null;
    _permissionCheckTimer?.cancel();
    _permissionCheckTimer = null;

    try {
      await _player.stop();
    } catch (_) {}

    _isRinging = true;

    try {
      if (duration > 0 || loop) {
        await _player.setReleaseMode(ReleaseMode.loop);
        await _player.play(AssetSource(opt.assetPath), volume: 1.0);
        _triggerVibration(single: false);

        if (duration > 0) {
          // Stop sound automatically when the configured duration is reached
          _ringTimer = Timer(Duration(seconds: duration), () {
            stopAlertRing();
          });
        }
      } else {
        // Continuous loop (duration == 0)
        await _player.setReleaseMode(ReleaseMode.loop);
        await _player.play(AssetSource(opt.assetPath), volume: 1.0);
        _triggerVibration(single: false);
      }

      // 3. Active revocation check while sound is ringing
      _permissionCheckTimer = Timer.periodic(const Duration(milliseconds: 500), (timer) async {
        if (!_isRinging) {
          timer.cancel();
          return;
        }
        final stillGranted = await NotificationService().areNotificationsEnabled();
        if (!stillGranted) {
          debugPrint('[AudioService] Active ring stopped: notification permission revoked mid-playback.');
          timer.cancel();
          await stopAlertRing();
        }
      });
    } catch (e) {
      _isRinging = false;
      debugPrint('[AudioService] Notification sound playback error: $e');
    }
  }

  /// Preview chosen sound (used in sound selector picker)
  Future<void> previewSound(String soundKey) async {
    final opt = availableSounds.firstWhere(
      (s) => s.key == soundKey,
      orElse: () => availableSounds[0],
    );
    try {
      await stopAlertRing();
      await _player.setReleaseMode(ReleaseMode.release);
      await _player.play(AssetSource(opt.assetPath), volume: 1.0);
      _triggerVibration(single: true);
    } catch (e) {
      debugPrint('AudioService preview error: $e');
    }
  }

  /// Plays notification chime.
  /// Routes through single permission-aware flow so permission checks and duration cannot be bypassed.
  Future<void> playChime({String? soundKey, int? durationSeconds}) async {
    await playNotificationSound(
      soundKey: soundKey,
      customDurationSeconds: durationSeconds,
      loop: true,
      isUrgent: false,
    );
  }

  /// Starts repeating alert ring for urgent human summons with duration and vibration.
  /// Routes through single permission-aware flow so permission checks and duration cannot be bypassed.
  Future<void> startAlarmRing({String? soundKey, int? maxDurationSeconds}) async {
    await playNotificationSound(
      soundKey: soundKey,
      customDurationSeconds: maxDurationSeconds,
      loop: true,
      isUrgent: true,
    );
  }

  /// Stops persistent ringing and vibration immediately
  Future<void> stopAlertRing() async {
    _isRinging = false;
    _ringTimer?.cancel();
    _ringTimer = null;
    _permissionCheckTimer?.cancel();
    _permissionCheckTimer = null;
    try {
      await _player.stop();
      await _player.setReleaseMode(ReleaseMode.release);
      if (await Vibration.hasVibrator() ?? false) {
        Vibration.cancel();
      }
    } catch (_) {}
  }

  void _triggerVibration({required bool single}) async {
    if (_vibrationPattern == 'none') return;

    try {
      final hasVibrator = await Vibration.hasVibrator() ?? false;
      if (!hasVibrator) return;

      if (single) {
        Vibration.vibrate(duration: 200);
      } else {
        switch (_vibrationPattern) {
          case 'gentle':
            Vibration.vibrate(pattern: [0, 200, 300, 200, 300, 200]);
            break;
          case 'double':
            Vibration.vibrate(pattern: [0, 400, 200, 400, 1000, 400, 200, 400]);
            break;
          case 'sos':
            Vibration.vibrate(pattern: [0, 150, 150, 150, 150, 150, 400, 400, 200, 400, 200, 400, 400, 150, 150, 150, 150, 150]);
            break;
          case 'heavy':
          default:
            Vibration.vibrate(pattern: [0, 700, 300, 700, 300, 700, 300, 700]);
            break;
        }
      }
    } catch (_) {}
  }
}
