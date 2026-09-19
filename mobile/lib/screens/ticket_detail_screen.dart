import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../providers/ticket_provider.dart';
import '../widgets/canned_responses_sheet.dart';
import '../widgets/sahdev_ai_ticket_panel.dart';
import 'clients_screen.dart';

class TicketDetailScreen extends StatefulWidget {
  final int ticketId;

  const TicketDetailScreen({super.key, required this.ticketId});

  @override
  State<TicketDetailScreen> createState() => _TicketDetailScreenState();
}

class _TicketDetailScreenState extends State<TicketDetailScreen> {
  final TextEditingController _replyController = TextEditingController();
  final ScrollController _scrollController = ScrollController();
  bool _isStaffNote = false;
  String _selectedStatusAfterReply = 'Answered';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadTicketDetails();
    });
  }

  void _loadTicketDetails() {
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl != null && auth.token != null) {
      context.read<TicketProvider>().fetchTicketDetails(
            baseUrl: auth.baseUrl!,
            token: auth.token!,
            ticketId: widget.ticketId,
          );
    }
  }

  @override
  void dispose() {
    _replyController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  Color _parseHexColor(dynamic hexStr, {Color defaultColor = const Color(0xFF10B981)}) {
    if (hexStr == null) return defaultColor;
    String s = hexStr.toString().replaceAll('#', '').trim();
    if (s.length == 6) {
      s = 'FF$s';
    }
    if (s.length == 8) {
      final val = int.tryParse(s, radix: 16);
      if (val != null) return Color(val);
    }
    return defaultColor;
  }

  Future<void> _sendReply() async {
    final text = _replyController.text.trim();
    if (text.isEmpty) return;

    final auth = context.read<AuthProvider>();
    final prov = context.read<TicketProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    final success = await prov.replyTicket(
      baseUrl: auth.baseUrl!,
      token: auth.token!,
      ticketId: widget.ticketId,
      message: text,
      isNote: _isStaffNote,
      status: _isStaffNote ? null : _selectedStatusAfterReply,
    );

    if (success && mounted) {
      _replyController.clear();
      FocusScope.of(context).unfocus();
      // Scroll to bottom
      Future.delayed(const Duration(milliseconds: 200), () {
        if (_scrollController.hasClients) {
          _scrollController.animateTo(
            _scrollController.position.maxScrollExtent,
            duration: const Duration(milliseconds: 300),
            curve: Curves.easeOut,
          );
        }
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(_isStaffNote ? 'Private staff note added' : 'Ticket reply submitted successfully!'),
          backgroundColor: const Color(0xFF10B981),
          behavior: SnackBarBehavior.floating,
        ),
      );
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Failed to submit ticket reply. Please try again.'),
          backgroundColor: Color(0xFFEF4444),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  void _copyToClipboard(String text, String label) {
    Clipboard.setData(ClipboardData(text: text));
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('$label copied to clipboard'),
        duration: const Duration(seconds: 2),
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  void _showEditPropertiesModal() {
    final auth = context.read<AuthProvider>();
    final prov = context.read<TicketProvider>();
    final ticket = prov.activeTicket;
    if (auth.baseUrl == null || auth.token == null || ticket == null) return;

    String curStatus = ticket['status']?.toString() ?? 'Open';
    String curPriority = ticket['priority']?.toString() ?? 'Medium';
    int? curDeptId = (ticket['deptid'] as num?)?.toInt();
    int curFlag = (ticket['flag'] as num?)?.toInt() ?? 0;

    final statuses = prov.statuses;
    final departments = prov.departments;
    final staffList = prov.staffList;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        final theme = Theme.of(ctx);
        final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

        String selectedStatus = curStatus;
        String selectedPriority = curPriority;
        int? selectedDeptId = curDeptId;
        int selectedFlag = curFlag;
        bool isSaving = false;

        return StatefulBuilder(
          builder: (modalCtx, setModalState) {
            return Container(
              decoration: BoxDecoration(
                color: isAmoled ? const Color(0xFF090D17) : theme.cardColor,
                borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
                border: Border.all(color: theme.dividerColor),
              ),
              padding: const EdgeInsets.all(18),
              child: SafeArea(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Handle & Header
                    Center(
                      child: Container(
                        margin: const EdgeInsets.only(bottom: 12),
                        width: 44,
                        height: 4,
                        decoration: BoxDecoration(
                          color: Colors.grey.shade600,
                          borderRadius: BorderRadius.circular(2),
                        ),
                      ),
                    ),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Edit Ticket Properties',
                          style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close),
                          onPressed: () => Navigator.of(modalCtx).pop(),
                        ),
                      ],
                    ),
                    const Divider(height: 12),

                    // Status selection
                    const Text('Ticket Status', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey)),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: (statuses.isNotEmpty
                              ? statuses.map((s) => s['title']?.toString() ?? '').toList()
                              : ['Open', 'Answered', 'Customer-Reply', 'In Progress', 'On Hold', 'Closed'])
                          .map((statusTitle) {
                        final isSelected = selectedStatus.toLowerCase() == statusTitle.toLowerCase();
                        Color dotColor = const Color(0xFF10B981);
                        final sObj = statuses.firstWhere(
                          (element) => (element['title']?.toString() ?? '').toLowerCase() == statusTitle.toLowerCase(),
                          orElse: () => {},
                        );
                        if (sObj['color'] != null) {
                          dotColor = _parseHexColor(sObj['color']);
                        }

                        return FilterChip(
                          avatar: Container(
                            width: 10,
                            height: 10,
                            decoration: BoxDecoration(color: dotColor, shape: BoxShape.circle),
                          ),
                          label: Text(statusTitle, style: const TextStyle(fontSize: 12)),
                          selected: isSelected,
                          onSelected: (val) {
                            if (val) setModalState(() => selectedStatus = statusTitle);
                          },
                        );
                      }).toList(),
                    ),

                    const SizedBox(height: 14),

                    // Priority selection
                    const Text('Urgency / Priority', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey)),
                    const SizedBox(height: 8),
                    Row(
                      children: ['Low', 'Medium', 'High'].map((pri) {
                        final isSelected = selectedPriority.toLowerCase() == pri.toLowerCase();
                        Color priColor = pri == 'High'
                            ? const Color(0xFFEF4444)
                            : pri == 'Medium'
                                ? const Color(0xFFF59E0B)
                                : const Color(0xFF10B981);

                        return Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: ChoiceChip(
                            label: Text(pri, style: const TextStyle(fontSize: 12)),
                            selected: isSelected,
                            selectedColor: priColor.withOpacity(0.25),
                            onSelected: (val) {
                              if (val) setModalState(() => selectedPriority = pri);
                            },
                          ),
                        );
                      }).toList(),
                    ),

                    const SizedBox(height: 14),

                    // Department selection
                    if (departments.isNotEmpty) ...[
                      const Text('Department', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey)),
                      const SizedBox(height: 6),
                      DropdownButtonFormField<int>(
                        value: departments.any((d) => (d['id'] as num?)?.toInt() == selectedDeptId)
                            ? selectedDeptId
                            : (departments.first['id'] as num?)?.toInt(),
                        decoration: InputDecoration(
                          contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                        ),
                        isExpanded: true,
                        items: departments.map((d) {
                          final id = (d['id'] as num?)?.toInt() ?? 0;
                          final name = d['name']?.toString() ?? 'Department';
                          return DropdownMenuItem<int>(value: id, child: Text(name, style: const TextStyle(fontSize: 13)));
                        }).toList(),
                        onChanged: (val) {
                          if (val != null) setModalState(() => selectedDeptId = val);
                        },
                      ),
                      const SizedBox(height: 14),
                    ],

                    // Staff Assignment (Flag)
                    const Text('Assigned Staff (Flag)', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey)),
                    const SizedBox(height: 6),
                    DropdownButtonFormField<int>(
                      value: staffList.any((s) => (s['id'] as num?)?.toInt() == selectedFlag) ? selectedFlag : 0,
                      decoration: InputDecoration(
                        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                      isExpanded: true,
                      items: [
                        const DropdownMenuItem<int>(
                          value: 0,
                          child: Text('Unassigned (None)', style: TextStyle(fontSize: 13, color: Colors.grey)),
                        ),
                        ...staffList.map((s) {
                          final id = (s['id'] as num?)?.toInt() ?? 0;
                          final name = '${s['firstname'] ?? ''} ${s['lastname'] ?? ''}'.trim();
                          return DropdownMenuItem<int>(value: id, child: Text(name.isNotEmpty ? name : 'Admin #$id', style: const TextStyle(fontSize: 13)));
                        }),
                      ],
                      onChanged: (val) {
                        if (val != null) setModalState(() => selectedFlag = val);
                      },
                    ),

                    const SizedBox(height: 20),

                    // Save Button
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: theme.colorScheme.primary,
                          foregroundColor: Colors.black,
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        ),
                        onPressed: isSaving
                            ? null
                            : () async {
                                setModalState(() => isSaving = true);
                                final ok = await prov.updateTicket(
                                  baseUrl: auth.baseUrl!,
                                  token: auth.token!,
                                  ticketId: widget.ticketId,
                                  status: selectedStatus,
                                  priority: selectedPriority,
                                  deptId: selectedDeptId,
                                  flag: selectedFlag,
                                );
                                if (ok && mounted) {
                                  Navigator.of(modalCtx).pop();
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    const SnackBar(
                                      content: Text('Ticket properties updated successfully!'),
                                      backgroundColor: Color(0xFF10B981),
                                      behavior: SnackBarBehavior.floating,
                                    ),
                                  );
                                } else if (mounted) {
                                  setModalState(() => isSaving = false);
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    const SnackBar(
                                      content: Text('Failed to update ticket properties.'),
                                      backgroundColor: Color(0xFFEF4444),
                                      behavior: SnackBarBehavior.floating,
                                    ),
                                  );
                                }
                              },
                        child: isSaving
                            ? const SizedBox(
                                width: 20,
                                height: 20,
                                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.black),
                              )
                            : const Text('Save Properties', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  void _openClientProfile(int clientId, String clientName, String clientEmail) {
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    ClientProfileModal.show(
      context,
      clientId: clientId,
      baseUrl: auth.baseUrl!,
      token: auth.token!,
      initialSummary: {
        'id': clientId,
        'name': clientName,
        'email': clientEmail,
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;
    final ticketProv = context.watch<TicketProvider>();
    final ticket = ticketProv.activeTicket;

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              ticket != null ? '#${ticket['tid'] ?? widget.ticketId}' : 'Ticket #${widget.ticketId}',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
            ),
            if (ticket != null)
              Text(
                '${ticket['client_name'] ?? 'Client'} • ${ticket['department'] ?? 'Support'}',
                style: const TextStyle(fontSize: 11, color: Colors.grey),
              ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.tune),
            tooltip: 'Ticket Properties',
            onPressed: ticket != null ? _showEditPropertiesModal : null,
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
            onPressed: _loadTicketDetails,
          ),
        ],
      ),
      body: ticketProv.isLoadingDetails && ticket == null
          ? const Center(child: CircularProgressIndicator())
          : ticket == null
              ? Center(
                  child: Text(
                    ticketProv.errorMessage ?? 'Ticket not found.',
                    style: const TextStyle(color: Colors.redAccent),
                  ),
                )
              : Column(
                  children: [
                    // Header metadata card
                    _buildHeaderCard(ticket, theme, isAmoled),

                    // Quick Action Bar
                    _buildQuickActionBar(ticket, theme, isAmoled),

                    // Conversation thread + AI Analysis Card
                    Expanded(
                      child: ListView(
                        controller: _scrollController,
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        children: [
                          // Sahdev AI Ticket Intelligence Panel (Matches WHMCS Desktop)
                          SahdevAiTicketPanel(
                            ticketId: widget.ticketId,
                            replyController: _replyController,
                            onInsertReply: (reply) {
                              setState(() {
                                _replyController.text = reply;
                                _isStaffNote = false;
                              });
                            },
                          ),

                          const SizedBox(height: 12),

                          // Conversation thread items
                          ...ticketProv.activeThread.map((msg) => _buildThreadItem(msg, theme, isAmoled)),

                          const SizedBox(height: 16),
                        ],
                      ),
                    ),

                    // Reply Composer
                    _buildReplyComposer(theme, isAmoled),
                  ],
                ),
    );
  }

  Widget _buildHeaderCard(Map<String, dynamic> ticket, ThemeData theme, bool isAmoled) {
    final status = ticket['status']?.toString() ?? 'Open';
    final statusColor = _parseHexColor(ticket['status_color']);
    final priority = ticket['priority']?.toString() ?? 'Medium';
    final subject = ticket['subject']?.toString() ?? 'Support Ticket';
    final isAwaiting = ticket['is_awaiting_reply'] == true;
    final assignedStaff = ticket['assigned_staff']?.toString();
    final clientEmail = ticket['client_email']?.toString() ?? '';

    Color priColor = priority.toLowerCase() == 'high'
        ? const Color(0xFFEF4444)
        : priority.toLowerCase() == 'medium'
            ? const Color(0xFFF59E0B)
            : const Color(0xFF10B981);

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isAmoled ? const Color(0xFF080B12) : theme.cardColor,
        border: Border(bottom: BorderSide(color: theme.dividerColor)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  subject,
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const SizedBox(width: 8),
              // Status Badge (Interactive)
              InkWell(
                borderRadius: BorderRadius.circular(8),
                onTap: _showEditPropertiesModal,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusColor.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: statusColor.withOpacity(0.4)),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 7,
                        height: 7,
                        decoration: BoxDecoration(color: statusColor, shape: BoxShape.circle),
                      ),
                      const SizedBox(width: 5),
                      Text(
                        status,
                        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: statusColor),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          // Badges row: Awaiting reply, Priority, Staff Flag
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              if (isAwaiting)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF59E0B).withOpacity(0.15),
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: const Color(0xFFF59E0B).withOpacity(0.4)),
                  ),
                  child: const Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.mark_email_unread_outlined, size: 12, color: Color(0xFFF59E0B)),
                      SizedBox(width: 4),
                      Text(
                        'Awaiting Reply',
                        style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFFF59E0B)),
                      ),
                    ],
                  ),
                ),
              InkWell(
                borderRadius: BorderRadius.circular(6),
                onTap: _showEditPropertiesModal,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                  decoration: BoxDecoration(
                    color: priColor.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    'Priority: $priority',
                    style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: priColor),
                  ),
                ),
              ),
              if (assignedStaff != null && assignedStaff.isNotEmpty)
                InkWell(
                  borderRadius: BorderRadius.circular(6),
                  onTap: _showEditPropertiesModal,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                    decoration: BoxDecoration(
                      color: const Color(0xFF06B6D4).withOpacity(0.12),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.person_pin_outlined, size: 12, color: Color(0xFF06B6D4)),
                        const SizedBox(width: 4),
                        Text(
                          assignedStaff,
                          style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFF06B6D4)),
                        ),
                      ],
                    ),
                  ),
                ),
              if (clientEmail.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(
                    clientEmail,
                    style: const TextStyle(fontSize: 11, color: Colors.grey),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildQuickActionBar(Map<String, dynamic> ticket, ThemeData theme, bool isAmoled) {
    final prov = context.read<TicketProvider>();
    final auth = context.read<AuthProvider>();
    final curStatus = (ticket['status']?.toString() ?? '').toLowerCase();
    final isClosed = curStatus == 'closed';
    final isAnswered = curStatus == 'answered';
    final clientId = (ticket['userid'] as num?)?.toInt();
    final clientName = ticket['client_name']?.toString() ?? 'Client';
    final clientEmail = ticket['client_email']?.toString() ?? '';
    final tid = ticket['tid']?.toString() ?? '${widget.ticketId}';

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: isAmoled ? const Color(0xFF0D121F) : Colors.grey.shade50,
        border: Border(bottom: BorderSide(color: theme.dividerColor)),
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: [
            if (!isAnswered)
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ActionChip(
                  avatar: const Icon(Icons.check, size: 14, color: Color(0xFF10B981)),
                  label: const Text('Mark Answered', style: TextStyle(fontSize: 11)),
                  onPressed: () {
                    if (auth.baseUrl != null && auth.token != null) {
                      prov.markAnswered(baseUrl: auth.baseUrl!, token: auth.token!, ticketId: widget.ticketId);
                    }
                  },
                ),
              ),
            if (!isClosed)
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ActionChip(
                  avatar: const Icon(Icons.lock_outline, size: 14, color: Color(0xFF6B7280)),
                  label: const Text('Close Ticket', style: TextStyle(fontSize: 11)),
                  onPressed: () {
                    if (auth.baseUrl != null && auth.token != null) {
                      prov.closeTicket(baseUrl: auth.baseUrl!, token: auth.token!, ticketId: widget.ticketId);
                    }
                  },
                ),
              ),
            if (clientId != null && clientId > 0)
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ActionChip(
                  avatar: const Icon(Icons.person_outline, size: 14, color: Color(0xFF8B5CF6)),
                  label: const Text('Client Profile', style: TextStyle(fontSize: 11)),
                  onPressed: () => _openClientProfile(clientId, clientName, clientEmail),
                ),
              ),
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: ActionChip(
                avatar: const Icon(Icons.tune, size: 14, color: Color(0xFF06B6D4)),
                label: const Text('Properties', style: TextStyle(fontSize: 11)),
                onPressed: _showEditPropertiesModal,
              ),
            ),
            ActionChip(
              avatar: const Icon(Icons.copy, size: 14, color: Colors.grey),
              label: Text('#$tid', style: const TextStyle(fontSize: 11)),
              onPressed: () => _copyToClipboard(tid, 'Ticket ID'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildThreadItem(Map<String, dynamic> msg, ThemeData theme, bool isAmoled) {
    final isStaff = msg['is_staff'] == true;
    final isNote = msg['is_note'] == true;
    final sender = msg['sender_name']?.toString() ?? (isStaff ? 'Staff' : 'Client');
    final text = msg['message']?.toString() ?? '';
    final date = msg['date']?.toString() ?? '';

    Color borderColor;
    Color bgColor;
    if (isNote) {
      borderColor = const Color(0xFFF59E0B);
      bgColor = isAmoled ? const Color(0xFF1F1A08) : const Color(0xFFFEF3C7);
    } else if (isStaff) {
      borderColor = const Color(0xFF06B6D4);
      bgColor = isAmoled ? const Color(0xFF07151D) : const Color(0xFFECFEFF);
    } else {
      borderColor = theme.dividerColor;
      bgColor = isAmoled ? const Color(0xFF0D111A) : Colors.white;
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: borderColor.withOpacity(0.5)),
      ),
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                isNote
                    ? Icons.note_outlined
                    : isStaff
                        ? Icons.support_agent
                        : Icons.person_outline,
                size: 16,
                color: isNote
                    ? const Color(0xFFF59E0B)
                    : isStaff
                        ? const Color(0xFF06B6D4)
                        : Colors.grey,
              ),
              const SizedBox(width: 6),
              Text(
                sender,
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.bold,
                  color: isNote
                      ? const Color(0xFFF59E0B)
                      : isStaff
                          ? const Color(0xFF06B6D4)
                          : null,
                ),
              ),
              const Spacer(),
              Text(
                date,
                style: const TextStyle(fontSize: 11, color: Colors.grey),
              ),
            ],
          ),
          const SizedBox(height: 8),
          SelectableText(
            text,
            style: const TextStyle(fontSize: 14, height: 1.4),
          ),
        ],
      ),
    );
  }

  Widget _buildReplyComposer(ThemeData theme, bool isAmoled) {
    final ticketProv = context.watch<TicketProvider>();
    final statuses = ticketProv.statuses;

    // Available statuses for reply
    final statusList = statuses.isNotEmpty
        ? statuses.map((s) => s['title']?.toString() ?? '').where((s) => s.isNotEmpty).toList()
        : ['Answered', 'In Progress', 'Closed', 'On Hold'];

    if (!statusList.contains(_selectedStatusAfterReply) && statusList.isNotEmpty) {
      _selectedStatusAfterReply = statusList.contains('Answered') ? 'Answered' : statusList.first;
    }

    return Container(
      decoration: BoxDecoration(
        color: isAmoled ? const Color(0xFF060910) : theme.cardColor,
        border: Border(top: BorderSide(color: theme.dividerColor)),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      child: SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Row for Staff Note toggle + Status Selector + Macros
            Row(
              children: [
                // Note toggle
                FilterChip(
                  label: const Text('Internal Note', style: TextStyle(fontSize: 12)),
                  selected: _isStaffNote,
                  onSelected: (val) => setState(() => _isStaffNote = val),
                  selectedColor: const Color(0xFFF59E0B).withOpacity(0.2),
                  checkmarkColor: const Color(0xFFF59E0B),
                  side: BorderSide(
                    color: _isStaffNote ? const Color(0xFFF59E0B) : theme.dividerColor,
                  ),
                ),
                const SizedBox(width: 8),
                if (!_isStaffNote) ...[
                  // Dynamic status after reply
                  const Text('Status: ', style: TextStyle(fontSize: 12, color: Colors.grey)),
                  DropdownButton<String>(
                    value: _selectedStatusAfterReply,
                    underline: const SizedBox(),
                    isDense: true,
                    style: TextStyle(fontSize: 12, color: theme.textTheme.bodyMedium?.color),
                    items: statusList.map((st) {
                      return DropdownMenuItem(value: st, child: Text(st));
                    }).toList(),
                    onChanged: (val) {
                      if (val != null) setState(() => _selectedStatusAfterReply = val);
                    },
                  ),
                ],
                const Spacer(),
                IconButton(
                  icon: const Icon(Icons.bolt, size: 20),
                  tooltip: 'Canned responses',
                  onPressed: () {
                    showModalBottomSheet(
                      context: context,
                      isScrollControlled: true,
                      backgroundColor: Colors.transparent,
                      builder: (_) => CannedResponsesSheet(
                        onSelect: (macro) {
                          setState(() {
                            _replyController.text = macro;
                          });
                        },
                      ),
                    );
                  },
                ),
              ],
            ),
            const SizedBox(height: 6),
            // Message input & send button
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: TextField(
                    controller: _replyController,
                    minLines: 1,
                    maxLines: 5,
                    style: const TextStyle(fontSize: 14),
                    decoration: InputDecoration(
                      hintText: _isStaffNote ? 'Type private staff note...' : 'Type public reply to client...',
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Container(
                  decoration: BoxDecoration(
                    color: _isStaffNote ? const Color(0xFFF59E0B) : theme.colorScheme.primary,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: IconButton(
                    icon: ticketProv.isSubmittingReply
                        ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                        : const Icon(Icons.send_rounded, color: Colors.black, size: 20),
                    onPressed: ticketProv.isSubmittingReply ? null : _sendReply,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
