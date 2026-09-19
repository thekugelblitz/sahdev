import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../providers/ticket_provider.dart';
import '../screens/ticket_detail_screen.dart';

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
  final _clientIdController = TextEditingController();
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
      _clientIdController.text = widget.initialClientId.toString();
    }
  }

  @override
  void dispose() {
    _clientIdController.dispose();
    _subjectController.dispose();
    _messageController.dispose();
    super.dispose();
  }

  Future<void> _submitTicket() async {
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

    final clientId = int.tryParse(_clientIdController.text.trim());
    if (clientId == null || clientId <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a valid Client ID')),
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
        clientId: clientId,
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

              // Client ID Input
              TextFormField(
                controller: _clientIdController,
                keyboardType: TextInputType.number,
                enabled: widget.initialClientId == null,
                decoration: InputDecoration(
                  labelText: 'Client ID *',
                  hintText: 'e.g. 1042',
                  prefixIcon: const Icon(Icons.person_outline, size: 20),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                ),
                validator: (val) {
                  if (val == null || val.trim().isEmpty) return 'Client ID is required';
                  if (int.tryParse(val.trim()) == null) return 'Must be a valid number';
                  return null;
                },
              ),
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
