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

  /// Cached working candidate endpoint index (0 = index.php?m=sahdev&action, 1 = sahdev_act, 2 = ajax.php)
  static int? _workingCandidateIndex;

  Map<String, String> _headers(String? token) {
    final map = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8',
    };
    if (token != null && token.isNotEmpty) {
      map['Authorization'] = 'Bearer $token';
      map['mobile_token'] = token;
      map['X-Mobile-Token'] = token;
    }
    return map;
  }

  /// Safely parses JSON without throwing FormatException on HTML or malformed bodies
  dynamic _parseJsonSafely(String? body) {
    if (body == null) return null;
    final trimmed = body.trim();
    if (trimmed.isEmpty || trimmed.startsWith('<') || trimmed.startsWith('<!DOCTYPE') || trimmed.startsWith('<html')) {
      return null;
    }
    try {
      return jsonDecode(trimmed);
    } catch (_) {
      return null;
    }
  }

  /// Extracts readable error messages from response, never exposing raw HTML or cryptic parser exceptions
  String _extractErrorMessage(http.Response response, [String fallback = 'Request failed']) {
    final decoded = _parseJsonSafely(response.body);
    if (decoded is Map && decoded['message'] != null && decoded['message'].toString().isNotEmpty) {
      return decoded['message'].toString();
    }

    final statusCode = response.statusCode;
    if (statusCode == 401 || statusCode == 403) {
      return 'Access Denied (HTTP $statusCode). Server firewall or WHMCS credentials refused the request.';
    }
    if (statusCode == 404) {
      return 'WHMCS endpoint not found (HTTP 404). Please verify your WHMCS URL.';
    }
    if (statusCode >= 500) {
      return 'WHMCS Server Error (HTTP $statusCode). Please check your WHMCS server error logs.';
    }

    final trimmed = response.body.trim();
    if (trimmed.startsWith('<')) {
      final titleMatch = RegExp(r'<title>(.*?)</title>', caseSensitive: false).firstMatch(trimmed);
      if (titleMatch != null && titleMatch.group(1) != null) {
        final title = titleMatch.group(1)!.trim();
        return 'Server returned HTML ($title - HTTP $statusCode). Please check your WHMCS System URL.';
      }
      return 'Server returned an HTML page (HTTP $statusCode) instead of JSON. Please verify your WHMCS URL.';
    }

    return fallback;
  }

  /// Sends a POST request with automatic candidate endpoint fallback and 301/302 redirect following
  Future<http.Response> _postWithFallback({
    required String baseUrl,
    required String action,
    required Map<String, String> body,
    String? token,
    String extraQuery = '',
    Duration? timeout,
  }) async {
    var finalQuery = extraQuery;
    if (token != null && token.isNotEmpty) {
      final tokenParam = "mobile_token=${Uri.encodeComponent(token)}";
      finalQuery = finalQuery.isEmpty ? tokenParam : "$finalQuery&$tokenParam";
    }
    final candidates = ApiConfig.candidateEndpoints(baseUrl, action, finalQuery);
    final headers = _headers(token);
    final reqTimeout = timeout ?? _timeout;

    final postBody = Map<String, String>.from(body);
    if (token != null && token.isNotEmpty && !postBody.containsKey('mobile_token')) {
      postBody['mobile_token'] = token;
    }

    // Prioritize previously verified working endpoint candidate
    final ordered = <int>[];
    if (_workingCandidateIndex != null && _workingCandidateIndex! < candidates.length) {
      ordered.add(_workingCandidateIndex!);
    }
    for (int i = 0; i < candidates.length; i++) {
      if (!ordered.contains(i)) ordered.add(i);
    }

    http.Response? lastResponse;
    Exception? lastException;

    for (final idx in ordered) {
      var currentUrl = candidates[idx];
      try {
        var response = await _client.post(
          Uri.parse(currentUrl),
          headers: headers,
          body: postBody,
        ).timeout(reqTimeout);

        // Follow HTTP redirects (301, 302, 307, 308) automatically
        int redirectCount = 0;
        while ((response.statusCode == 301 || response.statusCode == 302 || response.statusCode == 307 || response.statusCode == 308) && redirectCount < 3) {
          final location = response.headers['location'];
          if (location != null && location.isNotEmpty) {
            redirectCount++;
            final redirectUri = Uri.parse(currentUrl).resolve(location);
            currentUrl = redirectUri.toString();
            response = await _client.post(
              redirectUri,
              headers: headers,
              body: postBody,
            ).timeout(reqTimeout);
          } else {
            break;
          }
        }

        lastResponse = response;

        // If response is valid JSON (not HTML), we found the right endpoint!
        final parsed = _parseJsonSafely(response.body);
        if (parsed != null) {
          _workingCandidateIndex = idx;
          return response;
        }
      } catch (e) {
        lastException = e is Exception ? e : Exception(e.toString());
      }
    }

    if (lastResponse != null) {
      return lastResponse;
    }
    throw lastException ?? Exception("Failed to connect to WHMCS server.");
  }

  /// Sends a GET request with automatic candidate endpoint fallback and 301/302 redirect following
  Future<http.Response> _getWithFallback({
    required String baseUrl,
    required String action,
    String? token,
    String extraQuery = '',
    Duration? timeout,
  }) async {
    var finalQuery = extraQuery;
    if (token != null && token.isNotEmpty) {
      final tokenParam = "mobile_token=${Uri.encodeComponent(token)}";
      finalQuery = finalQuery.isEmpty ? tokenParam : "$finalQuery&$tokenParam";
    }
    final candidates = ApiConfig.candidateEndpoints(baseUrl, action, finalQuery);
    final headers = _headers(token);
    final reqTimeout = timeout ?? _timeout;

    final ordered = <int>[];
    if (_workingCandidateIndex != null && _workingCandidateIndex! < candidates.length) {
      ordered.add(_workingCandidateIndex!);
    }
    for (int i = 0; i < candidates.length; i++) {
      if (!ordered.contains(i)) ordered.add(i);
    }

    http.Response? lastResponse;
    Exception? lastException;

    for (final idx in ordered) {
      var currentUrl = candidates[idx];
      try {
        var response = await _client.get(
          Uri.parse(currentUrl),
          headers: headers,
        ).timeout(reqTimeout);

        // Follow redirects
        int redirectCount = 0;
        while ((response.statusCode == 301 || response.statusCode == 302 || response.statusCode == 307 || response.statusCode == 308) && redirectCount < 3) {
          final location = response.headers['location'];
          if (location != null && location.isNotEmpty) {
            redirectCount++;
            final redirectUri = Uri.parse(currentUrl).resolve(location);
            currentUrl = redirectUri.toString();
            response = await _client.get(
              redirectUri,
              headers: headers,
            ).timeout(reqTimeout);
          } else {
            break;
          }
        }

        lastResponse = response;

        final parsed = _parseJsonSafely(response.body);
        if (parsed != null) {
          _workingCandidateIndex = idx;
          return response;
        }
      } catch (e) {
        lastException = e is Exception ? e : Exception(e.toString());
      }
    }

    if (lastResponse != null) {
      return lastResponse;
    }
    throw lastException ?? Exception("Failed to connect to WHMCS server.");
  }

  /// Direct credentials login
  Future<ApiResponse<Map<String, dynamic>>> login({
    required String baseUrl,
    required String username,
    required String password,
    String deviceName = 'Android Staff Phone',
  }) async {
    try {
      final response = await _postWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_login',
        body: {
          'username': username,
          'password': password,
          'device_name': deviceName,
        },
      );

      final decoded = _parseJsonSafely(response.body);
      if (decoded is Map<String, dynamic> && response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }

      final errorMsg = _extractErrorMessage(response, 'Login failed. Please check credentials.');
      return ApiResponse(success: false, message: errorMsg);
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
      final response = await _postWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_qr_verify',
        body: {
          'code': pairingCode,
          'device_name': deviceName,
        },
      );

      final decoded = _parseJsonSafely(response.body);
      if (decoded is Map<String, dynamic> && response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }

      final errorMsg = _extractErrorMessage(response, 'QR Code pairing code expired or invalid.');
      return ApiResponse(success: false, message: errorMsg);
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
      final response = await _getWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_poll',
        token: token,
        extraQuery: "filter=${Uri.encodeComponent(filter)}&after_msg_id=$afterMsgId",
        timeout: const Duration(seconds: 10),
      );

      final decoded = _parseJsonSafely(response.body);
      if (decoded is Map<String, dynamic> && response.statusCode == 200) {
        if (decoded['status'] == 'success') {
          return ApiResponse(success: true, data: decoded);
        }
      }
      if (response.statusCode == 401 || response.statusCode == 403) {
        return ApiResponse(success: false, message: 'unauthorized');
      }
      return ApiResponse(success: false, message: _extractErrorMessage(response, 'Polling error'));
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
      final response = await _getWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_chat_history',
        token: token,
        extraQuery: "session_id=$sessionId&limit=$limit",
      );

      final decoded = _parseJsonSafely(response.body);
      if (decoded is Map<String, dynamic> && response.statusCode == 200) {
        if (decoded['status'] == 'success') {
          return ApiResponse(success: true, data: decoded);
        }
      }
      return ApiResponse(success: false, message: _extractErrorMessage(response, 'Could not load messages'));
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
      final response = await _postWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_send',
        token: token,
        body: {
          'session_id': sessionId.toString(),
          'message': message,
        },
      );

      final decoded = _parseJsonSafely(response.body);
      if (decoded is Map<String, dynamic> && response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }
      return ApiResponse(
        success: false,
        message: _extractErrorMessage(response, 'Failed to deliver message'),
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
      final response = await _postWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_takeover',
        token: token,
        body: {
          'session_id': sessionId.toString(),
          'takeover': takeover ? '1' : '0',
        },
      );

      final decoded = _parseJsonSafely(response.body);
      if (decoded is Map<String, dynamic> && response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }
      return ApiResponse(success: false, message: _extractErrorMessage(response, 'Failed to update takeover status'));
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
      final response = await _getWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_ai_suggest',
        token: token,
        extraQuery: "session_id=$sessionId",
        timeout: const Duration(seconds: 25),
      );

      final decoded = _parseJsonSafely(response.body);
      if (decoded is Map<String, dynamic> && response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded['suggestion'] ?? '');
      }
      return ApiResponse(success: false, message: _extractErrorMessage(response, 'AI suggestion unavailable'));
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

      final response = await _getWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_client_info',
        token: token,
        extraQuery: query,
      );

      final decoded = _parseJsonSafely(response.body);
      if (decoded is Map<String, dynamic> && response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded);
      }
      return ApiResponse(success: false, message: _extractErrorMessage(response, 'Could not load client profile'));
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
      final response = await _getWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_canned_responses',
        token: token,
      );

      final decoded = _parseJsonSafely(response.body);
      if (decoded is Map<String, dynamic> && response.statusCode == 200 && decoded['status'] == 'success') {
        return ApiResponse(success: true, data: decoded['responses'] ?? []);
      }
      return ApiResponse(success: false, message: _extractErrorMessage(response, 'Could not load canned macros'));
    } catch (e) {
      return ApiResponse(success: false, message: e.toString());
    }
  }

  /// Logout and revoke token
  Future<void> logout({required String baseUrl, required String token}) async {
    try {
      await _postWithFallback(
        baseUrl: baseUrl,
        action: 'mobile_logout',
        token: token,
        body: {},
        timeout: const Duration(seconds: 5),
      );
    } catch (_) {}
  }
}
