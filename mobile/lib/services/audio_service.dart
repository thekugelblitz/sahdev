import 'dart:async';
import 'package:audioplayers/audioplayers.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:vibration/vibration.dart';

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

  final AudioPlayer _player = AudioPlayer();
  Timer? _ringTimer;
  bool _isRinging = false;
  bool _soundEnabled = true;

  String _selectedSoundKey = 'radar';
  int _alertDurationSeconds = 30; // 5, 10, 15, 30, 60, 0 = continuous
  String _vibrationPattern = 'heavy'; // 'none', 'gentle', 'double', 'heavy', 'sos'

  bool get isRinging => _isRinging;
  bool get soundEnabled => _soundEnabled;
  String get selectedSoundKey => _selectedSoundKey;
  int get alertDurationSeconds => _alertDurationSeconds;
  String get vibrationPattern => _vibrationPattern;

  Future<void> _loadPreferences() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      _selectedSoundKey = prefs.getString('sdv_sound_key') ?? 'radar';
      _alertDurationSeconds = prefs.getInt('sdv_alert_duration') ?? 30;
      _vibrationPattern = prefs.getString('sdv_vibration_pattern') ?? 'heavy';
      _soundEnabled = prefs.getBool('sdv_sound_enabled') ?? true;
    } catch (_) {}
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

  /// Preview chosen sound (used in sound selector picker)
  Future<void> previewSound(String soundKey) async {
    final opt = availableSounds.firstWhere(
      (s) => s.key == soundKey,
      orElse: () => availableSounds[0],
    );
    try {
      await _player.stop();
      await _player.setReleaseMode(ReleaseMode.release);
      await _player.play(AssetSource(opt.assetPath), volume: 1.0);
      _triggerVibration(single: true);
    } catch (e) {
      print('AudioService preview error: $e');
    }
  }

  /// Plays a single notification chime
  Future<void> playChime({String? soundKey}) async {
    if (!_soundEnabled) return;
    final key = soundKey ?? _selectedSoundKey;
    final opt = availableSounds.firstWhere(
      (s) => s.key == key,
      orElse: () => availableSounds[0],
    );

    try {
      await _player.stop();
      await _player.setReleaseMode(ReleaseMode.release);
      await _player.play(AssetSource(opt.assetPath), volume: 1.0);
      _triggerVibration(single: true);
    } catch (e) {
      print('AudioService chime error: $e');
    }
  }

  /// Starts repeating alert ring for urgent human summons with duration and vibration
  Future<void> startAlarmRing({String? soundKey, int? maxDurationSeconds}) async {
    if (!_soundEnabled || _isRinging) return;
    _isRinging = true;

    final key = soundKey ?? _selectedSoundKey;
    final duration = maxDurationSeconds ?? _alertDurationSeconds;
    final opt = availableSounds.firstWhere(
      (s) => s.key == key,
      orElse: () => availableSounds[0],
    );

    try {
      await _player.setReleaseMode(ReleaseMode.loop);
      await _player.play(AssetSource(opt.assetPath), volume: 1.0);
      _triggerVibration(single: false);

      _ringTimer?.cancel();
      if (duration > 0) {
        _ringTimer = Timer(Duration(seconds: duration), () {
          stopAlertRing();
        });
      }
    } catch (e) {
      print('AudioService alarm error: $e');
    }
  }

  /// Stops persistent ringing and vibration immediately
  Future<void> stopAlertRing() async {
    _isRinging = false;
    _ringTimer?.cancel();
    _ringTimer = null;
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
