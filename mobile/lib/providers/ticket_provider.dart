import 'package:flutter/foundation.dart';
import '../services/api_service.dart';

class TicketProvider extends ChangeNotifier {
  final ApiService _api = ApiService();

  List<Map<String, dynamic>> _tickets = [];
  List<Map<String, dynamic>> _statuses = [];
  List<Map<String, dynamic>> _staffList = [];
  Map<String, int> _counts = {
    'awaiting_reply': 0,
    'open': 0,
    'customer_reply': 0,
    'answered': 0,
    'closed': 0,
    'total': 0,
  };
  bool _isLoading = false;
  String? _errorMessage;
  String _statusFilter = 'awaiting_reply';
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
  List<Map<String, dynamic>> get statuses => _statuses;
  List<Map<String, dynamic>> get staffList => _staffList;
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

        final rawStatuses = data['statuses'] as List<dynamic>? ?? [];
        if (rawStatuses.isNotEmpty) {
          _statuses = rawStatuses.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        }

        final rawCounts = data['counts'] as Map<String, dynamic>?;
        if (rawCounts != null) {
          final newCounts = <String, int>{};
          rawCounts.forEach((k, v) {
            newCounts[k] = (v as num?)?.toInt() ?? 0;
          });
          _counts = newCounts;
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

        final rawStatuses = data['statuses'] as List<dynamic>? ?? [];
        if (rawStatuses.isNotEmpty) {
          _statuses = rawStatuses.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        }

        final rawStaff = data['staff_list'] as List<dynamic>? ?? [];
        if (rawStaff.isNotEmpty) {
          _staffList = rawStaff.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        }
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
    String intent = 'auto',
    int intensity = 3,
    String customInstruction = '',
    String model = '',
    String technicalContext = '',
    bool feedSummary = true,
    bool includeNotes = true,
    bool includeTools = true,
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
        intent: intent,
        intensity: intensity,
        customInstruction: customInstruction,
        model: model,
        technicalContext: technicalContext,
        feedSummary: feedSummary,
        includeNotes: includeNotes,
        includeTools: includeTools,
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

  Future<String?> rewriteDraftReply({
    required String baseUrl,
    required String token,
    required int ticketId,
    required String draft,
    String tone = 'Professional',
    int intensity = 3,
    String customInstruction = '',
    String technicalContext = '',
  }) async {
    try {
      final res = await _api.analyzeTicketAi(
        baseUrl: baseUrl,
        token: token,
        ticketId: ticketId,
        tone: tone,
        intensity: intensity,
        customInstruction: customInstruction,
        technicalContext: technicalContext,
        rewriteDraft: draft,
      );
      if (res.success && res.data != null) {
        return res.data!['client_reply']?.toString();
      }
    } catch (_) {}
    return null;
  }

  Future<bool> updateTicket({
    required String baseUrl,
    required String token,
    required int ticketId,
    String? status,
    String? priority,
    int? deptId,
    int? flag,
  }) async {
    final res = await _api.updateTicketStatus(
      baseUrl: baseUrl,
      token: token,
      ticketId: ticketId,
      status: status,
      priority: priority,
      deptId: deptId,
      flag: flag,
    );

    if (res.success && _activeTicket != null) {
      if (status != null) {
        _activeTicket!['status'] = status;
        final matchedSt = _statuses.firstWhere(
          (s) => s['title'] == status,
          orElse: () => {},
        );
        if (matchedSt.isNotEmpty) {
          _activeTicket!['status_color'] = matchedSt['color'];
          _activeTicket!['is_awaiting_reply'] = matchedSt['showawaiting'] == true;
        }
      }
      if (priority != null) _activeTicket!['priority'] = priority;
      if (deptId != null && _departments.isNotEmpty) {
        final d = _departments.cast<dynamic>().firstWhere(
              (e) => (e['id'] as num?)?.toInt() == deptId,
              orElse: () => null,
            );
        if (d != null) {
          _activeTicket!['department'] = d['name'];
          _activeTicket!['dept_id'] = deptId;
        }
      }
      if (flag != null) {
        _activeTicket!['flag'] = flag;
        if (flag == 0) {
          _activeTicket!['assigned_staff'] = 'Unassigned';
        } else {
          final s = _staffList.cast<Map<String, dynamic>?>().firstWhere(
                (e) => (e?['id'] as num?)?.toInt() == flag,
                orElse: () => null,
              );
          if (s != null) {
            final firstName = s['firstname']?.toString() ?? '';
            final lastName = s['lastname']?.toString() ?? '';
            final fullName = '$firstName $lastName'.trim();
            _activeTicket!['assigned_staff'] = fullName.isNotEmpty ? fullName : (s['name'] ?? 'Admin #$flag');
          }
        }
      }
      notifyListeners();
      return true;
    }
    return false;
  }

  Future<bool> closeTicket({
    required String baseUrl,
    required String token,
    required int ticketId,
  }) {
    return updateTicket(baseUrl: baseUrl, token: token, ticketId: ticketId, status: 'Closed');
  }

  Future<bool> markAnswered({
    required String baseUrl,
    required String token,
    required int ticketId,
  }) {
    return updateTicket(baseUrl: baseUrl, token: token, ticketId: ticketId, status: 'Answered');
  }

  Future<bool> assignStaff({
    required String baseUrl,
    required String token,
    required int ticketId,
    required int adminId,
  }) {
    return updateTicket(baseUrl: baseUrl, token: token, ticketId: ticketId, flag: adminId);
  }

  Future<Map<String, dynamic>?> createTicket({
    required String baseUrl,
    required String token,
    required int clientId,
    int? deptId,
    required String subject,
    required String message,
    String priority = 'Medium',
  }) async {
    try {
      final res = await _api.createTicket(
        baseUrl: baseUrl,
        token: token,
        clientId: clientId,
        deptId: deptId,
        subject: subject,
        message: message,
        priority: priority,
      );

      if (res.success && res.data != null) {
        await fetchTickets(baseUrl: baseUrl, token: token, refresh: true);
        return res.data;
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  void clearActiveTicket() {
    _activeTicket = null;
    _activeThread = [];
    _aiAnalysis = null;
    _errorMessage = null;
  }
}
