import 'dart:async';
import 'package:audioplayers/audioplayers.dart';
import 'package:vibration/vibration.dart';

class AudioService {
  static final AudioService _instance = AudioService._internal();
  factory AudioService() => _instance;
  AudioService._internal();

  final AudioPlayer _player = AudioPlayer();
  Timer? _ringTimer;
  bool _isRinging = false;
  bool _soundEnabled = true;

  bool get isRinging => _isRinging;
  bool get soundEnabled => _soundEnabled;

  void setSoundEnabled(bool enabled) {
    _soundEnabled = enabled;
    if (!enabled) {
      stopAlertRing();
    }
  }

  /// Plays a single notification chime
  Future<void> playChime() async {
    if (!_soundEnabled) return;
    try {
      await _player.stop();
      await _player.play(AssetSource('sounds/chime.mp3'), volume: 1.0);
      _triggerVibration(single: true);
    } catch (e) {
      print('AudioService chime error: $e');
    }
  }

  /// Starts repeating alarm ring for urgent human summons
  Future<void> startAlarmRing({int maxDurationSeconds = 45}) async {
    if (!_soundEnabled || _isRinging) return;
    _isRinging = true;

    try {
      await _player.setReleaseMode(ReleaseMode.loop);
      await _player.play(AssetSource('sounds/alarm.mp3'), volume: 1.0);
      _triggerVibration(single: false);

      // Auto-stop after max duration to conserve battery
      _ringTimer?.cancel();
      _ringTimer = Timer(Duration(seconds: maxDurationSeconds), () {
        stopAlertRing();
      });
    } catch (e) {
      print('AudioService alarm error: $e');
    }
  }

  /// Stops persistent ringing and vibration
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
    try {
      final hasVibrator = await Vibration.hasVibrator() ?? false;
      if (!hasVibrator) return;

      if (single) {
        Vibration.vibrate(duration: 250);
      } else {
        // Pattern: vibrate 600ms, pause 400ms
        Vibration.vibrate(pattern: [0, 600, 400, 600, 400, 600]);
      }
    } catch (_) {}
  }
}
