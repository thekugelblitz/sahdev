import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';

class ApiResponse<T> {
  final bool success;
  final T? data;
  final String? message;

  ApiResponse({required this.success, this.data, this.message});
}

class ApiService {
  final http.Client _client = http.Client();
  final Duration _timeout = const Duration(seconds: 15);

  Map<String, String> _headers(String? token) {
    final map = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded',
    };
    if (token != null && token.isNotEmpty) {
      map['Authorization'] = 'Bearer $token';
      map['mobile_token'] = token;
    }
    return map;
  }

  /// Direct credentials login
  Future<ApiResponse<Map<String, dynamic>>> login({
    required String baseUrl,
    required String username,
    required String password,
    String deviceName = 'Android Staff Phone',
  }) async {
    try {
      final endpoint = Uri.parse(ApiConfig.buildEndpoint(baseUrl, 'mobile_login'));
      final response = await _client.post(
        endpoint,
        headers: _headers(null),
        body: {
          'username': username,
          'password': password,
          'device_name': deviceName,
        },
      ).timeout(_timeout);

      final decoded = jsonDecode(response.body);
      if (response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }
      return ApiResponse(
        success: false,
        message: decoded['message'] ?? 'Login failed. Please check credentials.',
      );
    } catch (e) {
      return ApiResponse(success: false, message: 'Connection error: ${e.toString()}');
    }
  }

  /// QR Code Pairing Token verification
  Future<ApiResponse<Map<String, dynamic>>> qrVerify({
    required String baseUrl,
    required String pairingCode,
    String deviceName = 'Android Staff Phone',
  }) async {
    try {
      final endpoint = Uri.parse(ApiConfig.buildEndpoint(baseUrl, 'mobile_qr_verify'));
      final response = await _client.post(
        endpoint,
        headers: _headers(null),
        body: {
          'code': pairingCode,
          'device_name': deviceName,
        },
      ).timeout(_timeout);

      final decoded = jsonDecode(response.body);
      if (response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }
      return ApiResponse(
        success: false,
        message: decoded['message'] ?? 'QR Code pairing code expired or invalid.',
      );
    } catch (e) {
      return ApiResponse(success: false, message: 'QR Pairing error: ${e.toString()}');
    }
  }

  /// Adaptive queue polling
  Future<ApiResponse<Map<String, dynamic>>> pollQueue({
    required String baseUrl,
    required String token,
    String filter = 'all',
    int afterMsgId = 0,
  }) async {
    try {
      final endpoint = Uri.parse(
        ApiConfig.buildEndpoint(baseUrl, 'mobile_poll') +
            "&filter=${Uri.encodeComponent(filter)}&after_msg_id=$afterMsgId",
      );
      final response = await _client.get(
        endpoint,
        headers: _headers(token),
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final decoded = jsonDecode(response.body);
        if (decoded['status'] == 'success') {
          return ApiResponse(success: true, data: decoded);
        }
      }
      if (response.statusCode == 401 || response.statusCode == 403) {
        return ApiResponse(success: false, message: 'unauthorized');
      }
      return ApiResponse(success: false, message: 'Polling error');
    } catch (e) {
      return ApiResponse(success: false, message: e.toString());
    }
  }

  /// Fetch chat message history for active session
  Future<ApiResponse<Map<String, dynamic>>> getChatHistory({
    required String baseUrl,
    required String token,
    required int sessionId,
    int limit = 60,
  }) async {
    try {
      final endpoint = Uri.parse(
        ApiConfig.buildEndpoint(baseUrl, 'mobile_chat_history') +
            "&session_id=$sessionId&limit=$limit",
      );
      final response = await _client.get(
        endpoint,
        headers: _headers(token),
      ).timeout(_timeout);

      if (response.statusCode == 200) {
        final decoded = jsonDecode(response.body);
        if (decoded['status'] == 'success') {
          return ApiResponse(success: true, data: decoded);
        }
      }
      return ApiResponse(success: false, message: 'Could not load messages');
    } catch (e) {
      return ApiResponse(success: false, message: e.toString());
    }
  }

  /// Send message as human staff agent
  Future<ApiResponse<Map<String, dynamic>>> sendMessage({
    required String baseUrl,
    required String token,
    required int sessionId,
    required String message,
  }) async {
    try {
      final endpoint = Uri.parse(ApiConfig.buildEndpoint(baseUrl, 'mobile_send'));
      final response = await _client.post(
        endpoint,
        headers: _headers(token),
        body: {
          'session_id': sessionId.toString(),
          'message': message,
        },
      ).timeout(_timeout);

      final decoded = jsonDecode(response.body);
      if (response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }
      return ApiResponse(
        success: false,
        message: decoded['message'] ?? 'Failed to deliver message',
      );
    } catch (e) {
      return ApiResponse(success: false, message: e.toString());
    }
  }

  /// 1-Tap Take Over / Release to AI
  Future<ApiResponse<Map<String, dynamic>>> takeover({
    required String baseUrl,
    required String token,
    required int sessionId,
    required bool takeover,
  }) async {
    try {
      final endpoint = Uri.parse(ApiConfig.buildEndpoint(baseUrl, 'mobile_takeover'));
      final response = await _client.post(
        endpoint,
        headers: _headers(token),
        body: {
          'session_id': sessionId.toString(),
          'takeover': takeover ? '1' : '0',
        },
      ).timeout(_timeout);

      final decoded = jsonDecode(response.body);
      if (response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }
      return ApiResponse(success: false, message: 'Failed to update takeover status');
    } catch (e) {
      return ApiResponse(success: false, message: e.toString());
    }
  }

  /// AI Co-Pilot Reply Assist
  Future<ApiResponse<String>> suggestAiReply({
    required String baseUrl,
    required String token,
    required int sessionId,
  }) async {
    try {
      final endpoint = Uri.parse(
        ApiConfig.buildEndpoint(baseUrl, 'mobile_ai_suggest') + "&session_id=$sessionId",
      );
      final response = await _client.get(
        endpoint,
        headers: _headers(token),
      ).timeout(const Duration(seconds: 25));

      final decoded = jsonDecode(response.body);
      if (response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded['suggestion'] ?? '');
      }
      return ApiResponse(success: false, message: 'AI suggestion unavailable');
    } catch (e) {
      return ApiResponse(success: false, message: e.toString());
    }
  }

  /// Get WHMCS client info, hosting services, unpaid invoices
  Future<ApiResponse<Map<String, dynamic>>> getClientDetails({
    required String baseUrl,
    required String token,
    int? clientId,
    int? sessionId,
  }) async {
    try {
      String query = "";
      if (clientId != null && clientId > 0) query += "&client_id=$clientId";
      if (sessionId != null && sessionId > 0) query += "&session_id=$sessionId";

      final endpoint = Uri.parse(ApiConfig.buildEndpoint(baseUrl, 'mobile_client_info') + query);
      final response = await _client.get(
        endpoint,
        headers: _headers(token),
      ).timeout(_timeout);

      final decoded = jsonDecode(response.body);
      if (response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }
      return ApiResponse(success: false, message: 'Could not load client profile');
    } catch (e) {
      return ApiResponse(success: false, message: e.toString());
    }
  }

  /// Fetch canned responses
  Future<ApiResponse<List<dynamic>>> getCannedResponses({
    required String baseUrl,
    required String token,
  }) async {
    try {
      final endpoint = Uri.parse(ApiConfig.buildEndpoint(baseUrl, 'mobile_canned_responses'));
      final response = await _client.get(
        endpoint,
        headers: _headers(token),
      ).timeout(_timeout);

      final decoded = jsonDecode(response.body);
      if (response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded['responses'] ?? []);
      }
      return ApiResponse(success: false, message: 'Could not load canned macros');
    } catch (e) {
      return ApiResponse(success: false, message: e.toString());
    }
  }

  /// Logout and revoke token
  Future<void> logout({required String baseUrl, required String token}) async {
    try {
      final endpoint = Uri.parse(ApiConfig.buildEndpoint(baseUrl, 'mobile_logout'));
      await _client.post(endpoint, headers: _headers(token)).timeout(const Duration(seconds: 5));
    } catch (_) {}
  }
}
