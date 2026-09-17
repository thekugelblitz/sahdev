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
      _messages = rawList.map((m) => ChatMessage.fromJson(m)).toList();

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

  Future<bool> sendMessage({
    required String baseUrl,
    required String token,
    required String text,
  }) async {
    if (_activeSessionId == null || text.trim().isEmpty) return false;

    _isSending = true;
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
      await fetchMessages(baseUrl: baseUrl, token: token);
      return true;
    }
    notifyListeners();
    return false;
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
