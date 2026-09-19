import 'dart:async';
import 'package:flutter/foundation.dart';
import '../models/chat_message.dart';
import '../models/client_profile.dart';
import '../services/api_service.dart';

class ChatProvider extends ChangeNotifier {
  final ApiService _api = ApiService();

  int? _activeSessionId;
  List<ChatMessage> _messages = [];
  bool _isLoadingMessages = false;
  bool _isSending = false;
  bool _isGeneratingAi = false;
  String _typingPreview = '';
  bool _isClientTyping = false;
  bool _isTakenOver = false;
  ClientProfile? _clientProfile;
  List<dynamic> _cannedResponses = [];
  Timer? _chatPollTimer;
  String? _errorMessage;

  int? get activeSessionId => _activeSessionId;
  List<ChatMessage> get messages => _messages;
  bool get isLoadingMessages => _isLoadingMessages;
  bool get isSending => _isSending;
  bool get isGeneratingAi => _isGeneratingAi;
  String get typingPreview => _typingPreview;
  bool get isClientTyping => _isClientTyping;
  bool get isTakenOver => _isTakenOver;
  ClientProfile? get clientProfile => _clientProfile;
  List<dynamic> get cannedResponses => _cannedResponses;
  String? get errorMessage => _errorMessage;

  void clearError() {
    _errorMessage = null;
    notifyListeners();
  }

  void openSession({
    required int sessionId,
    required String baseUrl,
    required String token,
    required bool initialTakenOver,
    int? clientId,
  }) {
    _activeSessionId = sessionId;
    _isTakenOver = initialTakenOver;
    _messages = [];
    _typingPreview = '';
    _isClientTyping = false;
    _clientProfile = null;
    _errorMessage = null;

    fetchMessages(baseUrl: baseUrl, token: token, isInitial: true);
    loadClientProfile(baseUrl: baseUrl, token: token, clientId: clientId, sessionId: sessionId);
    loadCannedResponses(baseUrl: baseUrl, token: token);

    // Micro-poll active chat thread every 1.8 seconds for instant keystroke preview and incoming messages
    _chatPollTimer?.cancel();
    _chatPollTimer = Timer.periodic(const Duration(milliseconds: 1800), (_) {
      fetchMessages(baseUrl: baseUrl, token: token, isInitial: false);
    });
  }

  void closeSession() {
    _chatPollTimer?.cancel();
    _chatPollTimer = null;
    _activeSessionId = null;
    _messages = [];
    _errorMessage = null;
  }

  Future<void> fetchMessages({
    required String baseUrl,
    required String token,
    bool isInitial = false,
  }) async {
    if (_activeSessionId == null) return;

    if (isInitial) {
      _isLoadingMessages = true;
      notifyListeners();
    }

    final res = await _api.getChatHistory(
      baseUrl: baseUrl,
      token: token,
      sessionId: _activeSessionId!,
    );

    if (isInitial) {
      _isLoadingMessages = false;
    }

    if (res.success && res.data != null) {
      final data = res.data!;
      final rawList = (data['messages'] as List?) ?? [];
      final fetchedMessages = rawList.map((m) => ChatMessage.fromJson(m)).toList();

      // Preserve any local messages currently marked as sending
      final sending = _messages.where((m) => m.isSending).toList();
      _messages = [...fetchedMessages, ...sending];

      final typingMap = data['typing'] as Map? ?? {};
      _isClientTyping = typingMap['is_typing'] == true;
      _typingPreview = typingMap['preview']?.toString() ?? '';

      final status = data['session_status']?.toString();
      if (status != null) {
        _isTakenOver = (status == 'taken_over');
      }

      notifyListeners();
    }
  }

  /// Sends a message with instant optimistic UI insertion
  Future<bool> sendMessage({
    required String baseUrl,
    required String token,
    required String text,
    String staffName = 'You',
  }) async {
    if (_activeSessionId == null || text.trim().isEmpty) return false;

    _isSending = true;
    _errorMessage = null;

    final tempId = -DateTime.now().millisecondsSinceEpoch;
    final optimisticMsg = ChatMessage(
      id: tempId,
      sessionId: _activeSessionId!,
      senderType: 'admin',
      senderName: staffName,
      text: text.trim(),
      isStaff: true,
      isAi: false,
      isClient: false,
      isSystem: false,
      timeFormat: 'Sending...',
      isSending: true,
    );

    _messages.add(optimisticMsg);
    notifyListeners();

    final res = await _api.sendMessage(
      baseUrl: baseUrl,
      token: token,
      sessionId: _activeSessionId!,
      message: text.trim(),
    );

    _isSending = false;
    if (res.success && res.data != null) {
      _isTakenOver = true;
      _typingPreview = '';
      _isClientTyping = false;

      // Swap temporary message with server-confirmed message immediately
      final serverMsgMap = res.data!['message'] as Map<String, dynamic>?;
      if (serverMsgMap != null) {
        final confirmedMsg = ChatMessage.fromJson(serverMsgMap);
        final idx = _messages.indexWhere((m) => m.id == tempId);
        if (idx != -1) {
          _messages[idx] = confirmedMsg;
        } else {
          _messages.removeWhere((m) => m.id == tempId);
          _messages.add(confirmedMsg);
        }
        notifyListeners();
      } else {
        _messages.removeWhere((m) => m.id == tempId);
      }

      await fetchMessages(baseUrl: baseUrl, token: token);
      return true;
    } else {
      _messages.removeWhere((m) => m.id == tempId);
      _errorMessage = res.message ?? 'Message delivery failed. Please verify server connection.';
      notifyListeners();
      return false;
    }
  }

  Future<bool> toggleTakeover({
    required String baseUrl,
    required String token,
  }) async {
    if (_activeSessionId == null) return false;

    final newStatus = !_isTakenOver;
    final res = await _api.takeover(
      baseUrl: baseUrl,
      token: token,
      sessionId: _activeSessionId!,
      takeover: newStatus,
    );

    if (res.success) {
      _isTakenOver = newStatus;
      await fetchMessages(baseUrl: baseUrl, token: token);
      notifyListeners();
      return true;
    }
    return false;
  }

  Future<String?> generateAiSuggestion({
    required String baseUrl,
    required String token,
  }) async {
    if (_activeSessionId == null) return null;

    _isGeneratingAi = true;
    notifyListeners();

    final res = await _api.suggestAiReply(
      baseUrl: baseUrl,
      token: token,
      sessionId: _activeSessionId!,
    );

    _isGeneratingAi = false;
    notifyListeners();

    if (res.success && res.data != null && res.data!.isNotEmpty) {
      return res.data;
    }
    return null;
  }

  Future<void> loadClientProfile({
    required String baseUrl,
    required String token,
    int? clientId,
    int? sessionId,
  }) async {
    final res = await _api.getClientDetails(
      baseUrl: baseUrl,
      token: token,
      clientId: clientId,
      sessionId: sessionId,
    );

    if (res.success && res.data != null) {
      _clientProfile = ClientProfile.fromJson(res.data!);
      notifyListeners();
    }
  }

  Future<void> loadCannedResponses({
    required String baseUrl,
    required String token,
  }) async {
    final res = await _api.getCannedResponses(baseUrl: baseUrl, token: token);
    if (res.success && res.data != null) {
      _cannedResponses = res.data!;
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _chatPollTimer?.cancel();
    super.dispose();
  }
}
