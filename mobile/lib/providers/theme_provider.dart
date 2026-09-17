import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../config/theme_config.dart';

class ThemeProvider extends ChangeNotifier {
  static const String _keyTheme = 'app_theme_mode';

  String _currentTheme = 'amoled'; // 'amoled', 'dark', 'light'

  String get currentTheme => _currentTheme;
  bool get isAmoled => _currentTheme == 'amoled';

  ThemeData get themeData {
    switch (_currentTheme) {
      case 'light':
        return ThemeConfig.lightTheme;
      case 'dark':
        return ThemeConfig.darkTheme;
      case 'amoled':
      default:
        return ThemeConfig.amoledTheme;
    }
  }

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    _currentTheme = prefs.getString(_keyTheme) ?? 'amoled';
    notifyListeners();
  }

  Future<void> setTheme(String mode) async {
    if (_currentTheme == mode) return;
    _currentTheme = mode;
    notifyListeners();

    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyTheme, mode);
  }
}
