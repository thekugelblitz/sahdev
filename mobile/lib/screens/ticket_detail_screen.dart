import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../providers/ticket_provider.dart';
import '../widgets/canned_responses_sheet.dart';
import '../widgets/sahdev_ai_ticket_panel.dart';

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

    if (success) {
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
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Failed to submit ticket reply. Please try again.'),
          backgroundColor: Color(0xFFEF4444),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
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
            icon: const Icon(Icons.refresh),
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
    final priority = ticket['priority']?.toString() ?? 'Medium';
    final subject = ticket['subject']?.toString() ?? 'Support Ticket';

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
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: const Color(0xFF10B981).withOpacity(0.15),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: const Color(0xFF10B981).withOpacity(0.3)),
                ),
                child: Text(
                  status,
                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF10B981)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              Text('Priority: $priority', style: const TextStyle(fontSize: 12, color: Colors.grey)),
              const SizedBox(width: 12),
              if (ticket['client_email'] != null && ticket['client_email'].toString().isNotEmpty)
                Expanded(
                  child: Text(
                    'Email: ${ticket['client_email']}',
                    style: const TextStyle(fontSize: 12, color: Colors.grey),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
            ],
          ),
        ],
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
                  // Status after reply
                  const Text('Status: ', style: TextStyle(fontSize: 12, color: Colors.grey)),
                  DropdownButton<String>(
                    value: _selectedStatusAfterReply,
                    underline: const SizedBox(),
                    isDense: true,
                    style: TextStyle(fontSize: 12, color: theme.textTheme.bodyMedium?.color),
                    items: const [
                      DropdownMenuItem(value: 'Answered', child: Text('Answered')),
                      DropdownMenuItem(value: 'In Progress', child: Text('In Progress')),
                      DropdownMenuItem(value: 'Closed', child: Text('Closed')),
                    ],
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
