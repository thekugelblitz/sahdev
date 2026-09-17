import 'package:flutter/foundation.dart';
import '../services/api_service.dart';

class TicketProvider extends ChangeNotifier {
  final ApiService _api = ApiService();

  List<Map<String, dynamic>> _tickets = [];
  Map<String, int> _counts = {
    'open': 0,
    'customer_reply': 0,
    'answered': 0,
    'closed': 0,
    'total': 0,
  };
  bool _isLoading = false;
  String? _errorMessage;
  String _statusFilter = 'open';
  String _searchQuery = '';
  int _currentPage = 1;
  int _totalTickets = 0;

  // Active Ticket View
  Map<String, dynamic>? _activeTicket;
  List<Map<String, dynamic>> _activeThread = [];
  List<dynamic> _departments = [];
  bool _isLoadingDetails = false;
  bool _isSubmittingReply = false;

  // Sahdev AI Copilot Analysis
  bool _isAiAnalyzing = false;
  Map<String, dynamic>? _aiAnalysis;

  // Getters
  List<Map<String, dynamic>> get tickets => _tickets;
  Map<String, int> get counts => _counts;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;
  String get statusFilter => _statusFilter;
  String get searchQuery => _searchQuery;
  int get currentPage => _currentPage;
  int get totalTickets => _totalTickets;

  Map<String, dynamic>? get activeTicket => _activeTicket;
  List<Map<String, dynamic>> get activeThread => _activeThread;
  List<dynamic> get departments => _departments;
  bool get isLoadingDetails => _isLoadingDetails;
  bool get isSubmittingReply => _isSubmittingReply;

  bool get isAiAnalyzing => _isAiAnalyzing;
  Map<String, dynamic>? get aiAnalysis => _aiAnalysis;

  Future<void> fetchTickets({
    required String baseUrl,
    required String token,
    bool refresh = false,
  }) async {
    if (refresh) {
      _currentPage = 1;
    }
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final res = await _api.getTickets(
        baseUrl: baseUrl,
        token: token,
        status: _statusFilter,
        search: _searchQuery,
        page: _currentPage,
        limit: 25,
      );

      if (res.success && res.data != null) {
        final data = res.data!;
        final rawList = data['tickets'] as List<dynamic>? ?? [];
        _tickets = rawList.map((e) => Map<String, dynamic>.from(e as Map)).toList();

        final rawCounts = data['counts'] as Map<String, dynamic>?;
        if (rawCounts != null) {
          _counts = {
            'open': (rawCounts['open'] as num?)?.toInt() ?? 0,
            'customer_reply': (rawCounts['customer_reply'] as num?)?.toInt() ?? 0,
            'answered': (rawCounts['answered'] as num?)?.toInt() ?? 0,
            'closed': (rawCounts['closed'] as num?)?.toInt() ?? 0,
            'total': (rawCounts['total'] as num?)?.toInt() ?? 0,
          };
        }
        _totalTickets = (data['total'] as num?)?.toInt() ?? _tickets.length;
      } else {
        _errorMessage = res.message ?? 'Failed to load tickets.';
      }
    } catch (e) {
      _errorMessage = e.toString();
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  void setStatusFilter(String status, {required String baseUrl, required String token}) {
    if (_statusFilter == status) return;
    _statusFilter = status;
    _currentPage = 1;
    fetchTickets(baseUrl: baseUrl, token: token, refresh: true);
  }

  void setSearchQuery(String query, {required String baseUrl, required String token}) {
    _searchQuery = query;
    _currentPage = 1;
    fetchTickets(baseUrl: baseUrl, token: token, refresh: true);
  }

  Future<void> fetchTicketDetails({
    required String baseUrl,
    required String token,
    required int ticketId,
  }) async {
    _isLoadingDetails = true;
    _aiAnalysis = null;
    notifyListeners();

    try {
      final res = await _api.getTicketDetails(
        baseUrl: baseUrl,
        token: token,
        ticketId: ticketId,
      );

      if (res.success && res.data != null) {
        final data = res.data!;
        _activeTicket = Map<String, dynamic>.from(data['ticket'] as Map);
        final rawThread = data['thread'] as List<dynamic>? ?? [];
        _activeThread = rawThread.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _departments = data['departments'] as List<dynamic>? ?? [];
      } else {
        _errorMessage = res.message ?? 'Failed to load ticket conversation.';
      }
    } catch (e) {
      _errorMessage = e.toString();
    } finally {
      _isLoadingDetails = false;
      notifyListeners();
    }
  }

  Future<bool> replyTicket({
    required String baseUrl,
    required String token,
    required int ticketId,
    required String message,
    bool isNote = false,
    String? status,
  }) async {
    _isSubmittingReply = true;
    notifyListeners();

    try {
      final res = await _api.replyTicket(
        baseUrl: baseUrl,
        token: token,
        ticketId: ticketId,
        message: message,
        isNote: isNote,
        status: status,
      );

      if (res.success) {
        // Optimistically insert message into active thread
        _activeThread.add({
          'id': res.data?['message_id'] ?? 0,
          'type': isNote ? 'note' : 'staff',
          'sender_name': res.data?['admin'] ?? 'Staff',
          'date': res.data?['date'] ?? 'Just now',
          'time_ago': 'Just now',
          'message': message,
          'is_staff': true,
          'is_note': isNote,
        });

        if (!isNote && status != null && _activeTicket != null) {
          _activeTicket!['status'] = status;
        }
        notifyListeners();
        return true;
      }
      return false;
    } catch (_) {
      return false;
    } finally {
      _isSubmittingReply = false;
      notifyListeners();
    }
  }

  Future<bool> analyzeTicketAi({
    required String baseUrl,
    required String token,
    required int ticketId,
    String tone = 'Professional',
  }) async {
    _isAiAnalyzing = true;
    _aiAnalysis = null;
    notifyListeners();

    try {
      final res = await _api.analyzeTicketAi(
        baseUrl: baseUrl,
        token: token,
        ticketId: ticketId,
        tone: tone,
      );

      if (res.success && res.data != null) {
        _aiAnalysis = Map<String, dynamic>.from(res.data!);
        return true;
      }
      return false;
    } catch (_) {
      return false;
    } finally {
      _isAiAnalyzing = false;
      notifyListeners();
    }
  }

  Future<bool> updateTicket({
    required String baseUrl,
    required String token,
    required int ticketId,
    String? status,
    String? priority,
    int? deptId,
  }) async {
    final res = await _api.updateTicketStatus(
      baseUrl: baseUrl,
      token: token,
      ticketId: ticketId,
      status: status,
      priority: priority,
      deptId: deptId,
    );

    if (res.success && _activeTicket != null) {
      if (status != null) _activeTicket!['status'] = status;
      if (priority != null) _activeTicket!['priority'] = priority;
      notifyListeners();
      return true;
    }
    return false;
  }

  void clearActiveTicket() {
    _activeTicket = null;
    _activeThread = [];
    _aiAnalysis = null;
    _errorMessage = null;
  }
}
