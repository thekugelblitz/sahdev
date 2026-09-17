import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../config/api_config.dart';
import '../models/admin_user.dart';
import '../services/api_service.dart';
import '../services/background_service.dart';

class AuthProvider extends ChangeNotifier {
  final ApiService _api = ApiService();

  String? _baseUrl;
  String? _token;
  AdminUser? _adminUser;
  bool _isAuthenticated = false;
  bool _isLoading = false;
  bool _isOnline = true;
  String _alertMode = 'ringing'; // 'ringing' or 'chime'
  String? _errorMessage;

  String? get baseUrl => _baseUrl;
  String? get token => _token;
  AdminUser? get adminUser => _adminUser;
  bool get isAuthenticated => _isAuthenticated;
  bool get isLoading => _isLoading;
  bool get isOnline => _isOnline;
  String get alertMode => _alertMode;
  String? get errorMessage => _errorMessage;

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    _baseUrl = prefs.getString(ApiConfig.keyWhmcsUrl);
    _token = prefs.getString(ApiConfig.keyMobileToken);
    _isOnline = prefs.getBool(ApiConfig.keyIsOnline) ?? true;
    _alertMode = prefs.getString(ApiConfig.keyAlertMode) ?? 'ringing';

    final userJson = prefs.getString(ApiConfig.keyAdminUser);
    if (userJson != null) {
      try {
        _adminUser = AdminUser.fromJson(jsonDecode(userJson));
      } catch (_) {}
    }

    if (_baseUrl != null && _token != null) {
      _isAuthenticated = true;
      _configureBackgroundService();
    }
    notifyListeners();
  }

  void _configureBackgroundService() {
    if (_baseUrl != null && _token != null) {
      BackgroundService().configure(
        baseUrl: _baseUrl!,
        token: _token!,
        isOnline: _isOnline,
        alertMode: _alertMode,
      );
    }
  }

  /// Login using standard WHMCS Admin credentials
  Future<bool> login({
    required String whmcsUrl,
    required String username,
    required String password,
  }) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    final cleanUrl = ApiConfig.sanitizeUrl(whmcsUrl);
    final res = await _api.login(
      baseUrl: cleanUrl,
      username: username,
      password: password,
    );

    _isLoading = false;
    if (res.success && res.data != null) {
      final data = res.data!;
      _baseUrl = cleanUrl;
      _token = data['token'];
      _adminUser = AdminUser.fromJson(data['admin'] ?? {});
      _isAuthenticated = true;

      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(ApiConfig.keyWhmcsUrl, _baseUrl!);
      await prefs.setString(ApiConfig.keyMobileToken, _token!);
      await prefs.setString(ApiConfig.keyAdminUser, jsonEncode(_adminUser!.toJson()));

      _configureBackgroundService();
      notifyListeners();
      return true;
    } else {
      _errorMessage = res.message ?? 'Authentication failed';
      notifyListeners();
      return false;
    }
  }

  /// Login via QR code scanned from WHMCS desktop console
  Future<bool> pairWithQrCode(String qrPayloadString) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      String url = '';
      String code = '';

      final trimmed = qrPayloadString.trim();
      if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
        try {
          final decoded = jsonDecode(trimmed);
          if (decoded is Map) {
            url = (decoded['url'] ?? '').toString();
            code = (decoded['code'] ?? '').toString();
          }
        } catch (_) {}
      }

      // Fallback if raw code scanned or URL saved in prefs
      if (url.isEmpty && _baseUrl != null && _baseUrl!.isNotEmpty) {
        url = _baseUrl!;
      }
      if (code.isEmpty && trimmed.startsWith('sdv_pair_')) {
        code = trimmed;
      }

      if (url.isEmpty || code.isEmpty) {
        _errorMessage = 'Invalid QR code. Please scan the QR code displayed in WHMCS -> Mobile App (QR).';
        _isLoading = false;
        notifyListeners();
        return false;
      }

      final cleanUrl = ApiConfig.sanitizeUrl(url);
      final res = await _api.qrVerify(baseUrl: cleanUrl, pairingCode: code);

      _isLoading = false;
      if (res.success && res.data != null) {
        final data = res.data!;
        _baseUrl = cleanUrl;
        _token = data['token'];
        _adminUser = AdminUser.fromJson(data['admin'] ?? {});
        _isAuthenticated = true;

        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(ApiConfig.keyWhmcsUrl, _baseUrl!);
        await prefs.setString(ApiConfig.keyMobileToken, _token!);
        await prefs.setString(ApiConfig.keyAdminUser, jsonEncode(_adminUser!.toJson()));

        _configureBackgroundService();
        notifyListeners();
        return true;
      } else {
        _errorMessage = res.message ?? 'QR pairing failed or expired';
        notifyListeners();
        return false;
      }
    } catch (e) {
      _isLoading = false;
      _errorMessage = 'QR scan error: ${e.toString().replaceAll("Exception:", "").trim()}';
      notifyListeners();
      return false;
    }
  }

  Future<void> toggleOnline(bool online) async {
    _isOnline = online;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(ApiConfig.keyIsOnline, _isOnline);
    BackgroundService().setOnlineStatus(_isOnline);
    notifyListeners();
  }

  Future<void> setAlertMode(String mode) async {
    _alertMode = mode;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(ApiConfig.keyAlertMode, _alertMode);
    BackgroundService().setAlertMode(_alertMode);
    notifyListeners();
  }

  Future<void> logout() async {
    if (_baseUrl != null && _token != null) {
      _api.logout(baseUrl: _baseUrl!, token: _token!);
    }
    BackgroundService().stopPolling();

    _baseUrl = null;
    _token = null;
    _adminUser = null;
    _isAuthenticated = false;

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(ApiConfig.keyWhmcsUrl);
    await prefs.remove(ApiConfig.keyMobileToken);
    await prefs.remove(ApiConfig.keyAdminUser);

    notifyListeners();
  }
}
