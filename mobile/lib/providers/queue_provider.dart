import 'dart:async';
import 'package:flutter/foundation.dart';
import '../models/chat_session.dart';
import '../services/api_service.dart';
import '../services/audio_service.dart';

class QueueProvider extends ChangeNotifier {
  final ApiService _api = ApiService();
  final AudioService _audio = AudioService();

  List<ChatSession> _sessions = [];
  int _urgentSummonsCount = 0;
  String _activeFilter = 'all'; // 'all', 'summoned', 'active', 'taken_over', 'my_chats', 'closed'
  String _searchQuery = '';
  bool _isLoading = false;
  Timer? _pollTimer;

  List<ChatSession> get sessions {
    if (_searchQuery.isEmpty) return _sessions;
    final q = _searchQuery.toLowerCase();
    return _sessions.where((s) {
      return s.client.name.toLowerCase().contains(q) ||
          s.client.email.toLowerCase().contains(q) ||
          s.source.domain.toLowerCase().contains(q) ||
          s.title.toLowerCase().contains(q);
    }).toList();
  }

  int get urgentSummonsCount => _urgentSummonsCount;
  String get activeFilter => _activeFilter;
  String get searchQuery => _searchQuery;
  bool get isLoading => _isLoading;

  void setFilter(String filter, {required String baseUrl, required String token}) {
    _activeFilter = filter;
    notifyListeners();
    fetchQueue(baseUrl: baseUrl, token: token, isInitial: true);
  }

  void setSearchQuery(String q) {
    _searchQuery = q.trim();
    notifyListeners();
  }

  void startPolling({required String baseUrl, required String token, int intervalSeconds = 2}) {
    _pollTimer?.cancel();
    fetchQueue(baseUrl: baseUrl, token: token, isInitial: true);
    _pollTimer = Timer.periodic(Duration(seconds: intervalSeconds), (_) {
      fetchQueue(baseUrl: baseUrl, token: token, isInitial: false);
    });
  }

  void stopPolling() {
    _pollTimer?.cancel();
    _pollTimer = null;
  }

  Future<void> fetchQueue({
    required String baseUrl,
    required String token,
    bool isInitial = false,
  }) async {
    if (isInitial && _sessions.isEmpty) {
      _isLoading = true;
      notifyListeners();
    }

    final res = await _api.pollQueue(
      baseUrl: baseUrl,
      token: token,
      filter: _activeFilter,
    );

    if (isInitial) {
      _isLoading = false;
    }

    if (res.success && res.data != null) {
      final data = res.data!;
      _urgentSummonsCount = data['urgent_summons_count'] ?? 0;

      final rawList = (data['sessions'] as List?) ?? [];
      _sessions = rawList.map((j) => ChatSession.fromJson(j)).toList();

      notifyListeners();
    }
  }

  /// Stop ringing once staff opens or interacts with chat
  void acknowledgeAlert() {
    _audio.stopAlertRing();
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    super.dispose();
  }
}
