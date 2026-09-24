import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../providers/ticket_provider.dart';
import '../screens/ticket_detail_screen.dart';
import '../services/api_service.dart';

class CreateTicketModal extends StatefulWidget {
  final int? initialClientId;
  final String? initialClientName;
  final VoidCallback? onTicketCreated;

  const CreateTicketModal({
    super.key,
    this.initialClientId,
    this.initialClientName,
    this.onTicketCreated,
  });

  static void show(
    BuildContext context, {
    int? initialClientId,
    String? initialClientName,
    VoidCallback? onTicketCreated,
  }) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => CreateTicketModal(
        initialClientId: initialClientId,
        initialClientName: initialClientName,
        onTicketCreated: onTicketCreated,
      ),
    );
  }

  @override
  State<CreateTicketModal> createState() => _CreateTicketModalState();
}

class _CreateTicketModalState extends State<CreateTicketModal> {
  final _formKey = GlobalKey<FormState>();
  final ApiService _api = ApiService();

  // Dynamic Client Search State
  final _searchClientController = TextEditingController();
  Timer? _debounceTimer;
  bool _isSearchingClients = false;
  String? _clientSearchError;
  String _lastSearchQuery = '';
  List<Map<String, dynamic>> _clientSearchResults = [];
  Map<String, dynamic>? _selectedClient;
  String? _clientValidationError;

  final _subjectController = TextEditingController();
  final _messageController = TextEditingController();

  int? _selectedDeptId;
  String _selectedPriority = 'Medium';
  bool _isSubmitting = false;

  final List<Map<String, dynamic>> _defaultDepts = [
    {'id': 1, 'name': 'Technical Support'},
    {'id': 2, 'name': 'Billing & Accounts'},
    {'id': 3, 'name': 'Sales & Inquiries'},
  ];

  final List<String> _priorities = ['Low', 'Medium', 'High', 'Critical'];

  @override
  void initState() {
    super.initState();
    if (widget.initialClientId != null && widget.initialClientId! > 0) {
      _selectedClient = {
        'id': widget.initialClientId,
        'name': widget.initialClientName ?? 'Client #${widget.initialClientId}',
      };
    }
  }

  @override
  void dispose() {
    _debounceTimer?.cancel();
    _searchClientController.dispose();
    _subjectController.dispose();
    _messageController.dispose();
    super.dispose();
  }

  void _onClientSearchChanged(String query) {
    _debounceTimer?.cancel();
    final trimmed = query.trim();
    if (trimmed.isEmpty) {
      setState(() {
        _isSearchingClients = false;
        _clientSearchError = null;
        _clientSearchResults = [];
        _lastSearchQuery = '';
      });
      return;
    }

    _debounceTimer = Timer(const Duration(milliseconds: 350), () {
      _performClientSearch(trimmed);
    });
  }

  Future<void> _performClientSearch(String query) async {
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    setState(() {
      _isSearchingClients = true;
      _clientSearchError = null;
      _lastSearchQuery = query;
    });

    try {
      final res = await _api.getClients(
        baseUrl: auth.baseUrl!,
        token: auth.token!,
        search: query,
        limit: 10,
      );

      if (!mounted) return;

      if (res.success && res.data != null) {
        final rawList = res.data!['clients'] as List<dynamic>? ?? [];
        setState(() {
          _clientSearchResults = rawList.map((e) => Map<String, dynamic>.from(e as Map)).toList();
          _isSearchingClients = false;
        });
      } else {
        setState(() {
          _clientSearchError = res.message ?? 'Failed to search clients';
          _clientSearchResults = [];
          _isSearchingClients = false;
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _clientSearchError = 'Network error: $e';
        _clientSearchResults = [];
        _isSearchingClients = false;
      });
    }
  }

  void _selectClient(Map<String, dynamic> client) {
    setState(() {
      _selectedClient = client;
      _clientSearchResults = [];
      _searchClientController.clear();
      _clientSearchError = null;
      _clientValidationError = null;
    });
  }

  Future<void> _submitTicket() async {
    final selectedId = (_selectedClient?['id'] as num?)?.toInt();
    if (selectedId == null || selectedId <= 0) {
      setState(() => _clientValidationError = 'Please select a client');
      HapticFeedback.vibrate();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please search and select a client first'),
          backgroundColor: Color(0xFFEF4444),
          behavior: SnackBarBehavior.floating,
        ),
      );
      return;
    }

    if (!_formKey.currentState!.validate()) {
      HapticFeedback.vibrate();
      return;
    }

    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Authentication error: not logged in')),
      );
      return;
    }

    HapticFeedback.lightImpact();
    setState(() => _isSubmitting = true);

    try {
      final ticketProv = context.read<TicketProvider>();
      final result = await ticketProv.createTicket(
        baseUrl: auth.baseUrl!,
        token: auth.token!,
        clientId: selectedId,
        deptId: _selectedDeptId,
        subject: _subjectController.text.trim(),
        message: _messageController.text.trim(),
        priority: _selectedPriority,
      );

      if (result != null && mounted) {
        HapticFeedback.mediumImpact();
        Navigator.of(context).pop();

        final tid = result['tid'] ?? result['ticket_id'] ?? '';
        final ticketId = (result['ticket_id'] as num?)?.toInt();

        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Ticket created successfully! #$tid'),
            backgroundColor: const Color(0xFF10B981),
            behavior: SnackBarBehavior.floating,
          ),
        );

        widget.onTicketCreated?.call();

        if (ticketId != null && ticketId > 0 && mounted) {
          Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => TicketDetailScreen(ticketId: ticketId),
            ),
          );
        }
      } else if (mounted) {
        HapticFeedback.vibrate();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(ticketProv.errorMessage ?? 'Failed to create ticket. Check client ID or server connection.'),
            backgroundColor: const Color(0xFFEF4444),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error: $e'), backgroundColor: const Color(0xFFEF4444)),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _isSubmitting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;
    final ticketProv = context.watch<TicketProvider>();

    // Merge departments if available
    final depts = ticketProv.departments.isNotEmpty
        ? ticketProv.departments.map((d) => Map<String, dynamic>.from(d as Map)).toList()
        : _defaultDepts;

    if (_selectedDeptId == null && depts.isNotEmpty) {
      _selectedDeptId = (depts.first['id'] as num?)?.toInt() ?? 1;
    }

    final selectedId = (_selectedClient?['id'] as num?)?.toInt();
    final clientName = _selectedClient?['name']?.toString() ?? (selectedId != null ? 'Client #$selectedId' : '');
    final clientEmail = _selectedClient?['email']?.toString() ?? '';
    final clientCompany = _selectedClient?['company']?.toString() ?? '';

    return Container(
      decoration: BoxDecoration(
        color: isAmoled ? const Color(0xFF0D1117) : theme.scaffoldBackgroundColor,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
        border: Border(
          top: BorderSide(color: theme.colorScheme.primary.withOpacity(0.3), width: 1.5),
        ),
      ),
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 14,
        bottom: MediaQuery.of(context).viewInsets.bottom + 24,
      ),
      child: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Drag handle
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: Colors.grey.withOpacity(0.4),
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              const SizedBox(height: 14),

              // Title Row
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.primary.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Icon(Icons.add_comment_outlined, color: theme.colorScheme.primary, size: 22),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Open Support Ticket',
                          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
                        ),
                        if (widget.initialClientName != null)
                          Text(
                            'For: ${widget.initialClientName}',
                            style: TextStyle(fontSize: 12, color: theme.colorScheme.primary, fontWeight: FontWeight.w600),
                          )
                        else
                          const Text(
                            'Create a new ticket in WHMCS on behalf of client',
                            style: TextStyle(fontSize: 12, color: Colors.grey),
                          ),
                      ],
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.of(context).pop(),
                  ),
                ],
              ),
              const SizedBox(height: 18),

              // Dynamic Client Selector / Search Component
              if (_selectedClient != null) ...[
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: theme.colorScheme.primary.withOpacity(0.08),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: theme.colorScheme.primary.withOpacity(0.35)),
                  ),
                  child: Row(
                    children: [
                      CircleAvatar(
                        radius: 18,
                        backgroundColor: theme.colorScheme.primary.withOpacity(0.2),
                        child: Text(
                          clientName.isNotEmpty ? clientName[0].toUpperCase() : 'C',
                          style: TextStyle(fontWeight: FontWeight.bold, color: theme.colorScheme.primary),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Flexible(
                                  child: Text(
                                    clientName,
                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                                const SizedBox(width: 6),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                                  decoration: BoxDecoration(
                                    color: theme.colorScheme.primary.withOpacity(0.18),
                                    borderRadius: BorderRadius.circular(6),
                                  ),
                                  child: Text(
                                    '#$selectedId',
                                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: theme.colorScheme.primary),
                                  ),
                                ),
                              ],
                            ),
                            if (clientEmail.isNotEmpty || clientCompany.isNotEmpty) ...[
                              const SizedBox(height: 2),
                              Text(
                                [if (clientCompany.isNotEmpty && clientCompany != 'Individual') clientCompany, if (clientEmail.isNotEmpty) clientEmail].join(' • '),
                                style: const TextStyle(fontSize: 12, color: Colors.grey),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ],
                          ],
                        ),
                      ),
                      TextButton.icon(
                        onPressed: () {
                          setState(() {
                            _selectedClient = null;
                            _clientSearchResults = [];
                            _searchClientController.clear();
                          });
                        },
                        icon: const Icon(Icons.swap_horiz, size: 16),
                        label: const Text('Change', style: TextStyle(fontSize: 12)),
                        style: TextButton.styleFrom(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          minimumSize: Size.zero,
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        ),
                      ),
                    ],
                  ),
                ),
              ] else ...[
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    TextFormField(
                      controller: _searchClientController,
                      onChanged: _onClientSearchChanged,
                      decoration: InputDecoration(
                        labelText: 'Client *',
                        hintText: 'Search by client name, email, company, or ID...',
                        prefixIcon: const Icon(Icons.person_search_outlined, size: 20),
                        suffixIcon: _isSearchingClients
                            ? const Padding(
                                padding: EdgeInsets.all(12),
                                child: SizedBox(
                                  width: 16,
                                  height: 16,
                                  child: CircularProgressIndicator(strokeWidth: 2),
                                ),
                              )
                            : (_searchClientController.text.isNotEmpty
                                ? IconButton(
                                    icon: const Icon(Icons.clear, size: 18),
                                    onPressed: () {
                                      _searchClientController.clear();
                                      _onClientSearchChanged('');
                                    },
                                  )
                                : null),
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                        errorText: _clientValidationError,
                        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                      ),
                    ),
                    if (_isSearchingClients) ...[
                      const SizedBox(height: 8),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 4),
                        child: Row(
                          children: const [
                            SizedBox(
                              width: 14,
                              height: 14,
                              child: CircularProgressIndicator(strokeWidth: 1.8),
                            ),
                            SizedBox(width: 8),
                            Text(
                              'Searching clients...',
                              style: TextStyle(fontSize: 12, color: Colors.grey),
                            ),
                          ],
                        ),
                      ),
                    ],
                    if (_clientSearchError != null) ...[
                      const SizedBox(height: 8),
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: Colors.red.withOpacity(0.1),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: Colors.red.withOpacity(0.3)),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.error_outline, color: Colors.red, size: 18),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                _clientSearchError!,
                                style: const TextStyle(fontSize: 12, color: Colors.red),
                              ),
                            ),
                            TextButton(
                              onPressed: () => _performClientSearch(_lastSearchQuery),
                              child: const Text('Retry', style: TextStyle(fontSize: 12, color: Colors.red, fontWeight: FontWeight.bold)),
                            ),
                          ],
                        ),
                      ),
                    ],
                    if (!_isSearchingClients && _clientSearchError == null && _lastSearchQuery.isNotEmpty && _clientSearchResults.isEmpty) ...[
                      const SizedBox(height: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        decoration: BoxDecoration(
                          color: isAmoled ? const Color(0xFF141A29) : theme.cardColor,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: theme.dividerColor),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.info_outline, size: 16, color: Colors.grey),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                'No clients found matching "$_lastSearchQuery"',
                                style: const TextStyle(fontSize: 12, color: Colors.grey),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                    if (_clientSearchResults.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Container(
                        constraints: const BoxConstraints(maxHeight: 200),
                        decoration: BoxDecoration(
                          color: isAmoled ? const Color(0xFF141A29) : theme.cardColor,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: theme.colorScheme.primary.withOpacity(0.3)),
                        ),
                        child: ListView.separated(
                          shrinkWrap: true,
                          padding: EdgeInsets.zero,
                          itemCount: _clientSearchResults.length,
                          separatorBuilder: (_, __) => Divider(height: 1, color: theme.dividerColor.withOpacity(0.5)),
                          itemBuilder: (context, idx) {
                            final client = _clientSearchResults[idx];
                            final name = client['name']?.toString() ?? 'Client #${client['id']}';
                            final id = client['id']?.toString() ?? '';
                            final email = client['email']?.toString() ?? '';
                            final company = client['company']?.toString() ?? '';
                            return ListTile(
                              dense: true,
                              leading: CircleAvatar(
                                radius: 14,
                                backgroundColor: theme.colorScheme.primary.withOpacity(0.15),
                                child: Text(
                                  name.isNotEmpty ? name[0].toUpperCase() : 'C',
                                  style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: theme.colorScheme.primary),
                                ),
                              ),
                              title: Row(
                                children: [
                                  Flexible(
                                    child: Text(
                                      name,
                                      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                  ),
                                  const SizedBox(width: 6),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                                    decoration: BoxDecoration(
                                      color: theme.colorScheme.primary.withOpacity(0.15),
                                      borderRadius: BorderRadius.circular(4),
                                    ),
                                    child: Text(
                                      '#$id',
                                      style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: theme.colorScheme.primary),
                                    ),
                                  ),
                                ],
                              ),
                              subtitle: Text(
                                [if (company.isNotEmpty && company != 'Individual') company, if (email.isNotEmpty) email].join(' • '),
                                style: const TextStyle(fontSize: 11, color: Colors.grey),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                              onTap: () => _selectClient(client),
                            );
                          },
                        ),
                      ),
                    ],
                  ],
                ),
              ],
              const SizedBox(height: 14),

              // Department Dropdown
              DropdownButtonFormField<int>(
                value: _selectedDeptId,
                decoration: InputDecoration(
                  labelText: 'Department *',
                  prefixIcon: const Icon(Icons.folder_open_outlined, size: 20),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                ),
                items: depts.map((d) {
                  final id = (d['id'] as num?)?.toInt() ?? 1;
                  final name = d['name']?.toString() ?? 'Department #$id';
                  return DropdownMenuItem<int>(
                    value: id,
                    child: Text(name, style: const TextStyle(fontSize: 14)),
                  );
                }).toList(),
                onChanged: (val) {
                  if (val != null) setState(() => _selectedDeptId = val);
                },
              ),
              const SizedBox(height: 14),

              // Priority Selector Chips
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Priority', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Colors.grey)),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 8,
                    children: _priorities.map((p) {
                      final isSelected = _selectedPriority == p;
                      Color pColor;
                      switch (p.toLowerCase()) {
                        case 'critical':
                        case 'high':
                          pColor = const Color(0xFFEF4444);
                          break;
                        case 'medium':
                          pColor = const Color(0xFFF59E0B);
                          break;
                        default:
                          pColor = const Color(0xFF38BDF8);
                      }
                      return ChoiceChip(
                        label: Text(p, style: TextStyle(fontSize: 12, fontWeight: isSelected ? FontWeight.bold : FontWeight.normal)),
                        selected: isSelected,
                        selectedColor: pColor.withOpacity(0.25),
                        side: BorderSide(color: isSelected ? pColor : theme.dividerColor),
                        onSelected: (val) {
                          if (val) setState(() => _selectedPriority = p);
                        },
                      );
                    }).toList(),
                  ),
                ],
              ),
              const SizedBox(height: 14),

              // Subject Input
              TextFormField(
                controller: _subjectController,
                decoration: InputDecoration(
                  labelText: 'Subject *',
                  hintText: 'Brief summary of the issue or inquiry',
                  prefixIcon: const Icon(Icons.subject, size: 20),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                ),
                validator: (val) => (val == null || val.trim().isEmpty) ? 'Subject is required' : null,
              ),
              const SizedBox(height: 14),

              // Message Body Input
              TextFormField(
                controller: _messageController,
                maxLines: 5,
                decoration: InputDecoration(
                  labelText: 'Message Body *',
                  hintText: 'Describe the issue or details in full...',
                  alignLabelWithHint: true,
                  prefixIcon: const Padding(
                    padding: EdgeInsets.only(bottom: 80),
                    child: Icon(Icons.message_outlined, size: 20),
                  ),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                ),
                validator: (val) => (val == null || val.trim().isEmpty) ? 'Message is required' : null,
              ),
              const SizedBox(height: 20),

              // Action Buttons
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: _isSubmitting ? null : () => Navigator.of(context).pop(),
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 13),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      child: const Text('Cancel'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    flex: 2,
                    child: ElevatedButton.icon(
                      onPressed: _isSubmitting ? null : _submitTicket,
                      icon: _isSubmitting
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                            )
                          : const Icon(Icons.send_rounded, size: 18),
                      label: Text(
                        _isSubmitting ? 'Opening...' : 'Create Ticket',
                        style: const TextStyle(fontWeight: FontWeight.bold),
                      ),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: theme.colorScheme.primary,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 13),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
