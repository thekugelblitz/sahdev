import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../providers/ticket_provider.dart';
import '../widgets/create_ticket_modal.dart';
import 'ticket_detail_screen.dart';

class TicketsScreen extends StatefulWidget {
  const TicketsScreen({super.key});

  @override
  State<TicketsScreen> createState() => _TicketsScreenState();
}

class _TicketsScreenState extends State<TicketsScreen> {
  final TextEditingController _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadTickets(refresh: true);
    });
  }

  void _loadTickets({bool refresh = false}) {
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl != null && auth.token != null) {
      context.read<TicketProvider>().fetchTickets(
            baseUrl: auth.baseUrl!,
            token: auth.token!,
            refresh: refresh,
          );
    }
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;
    final auth = context.watch<AuthProvider>();
    final ticketProv = context.watch<TicketProvider>();

    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(
                color: theme.colorScheme.primary.withOpacity(0.15),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Icon(Icons.confirmation_number_outlined, color: theme.colorScheme.primary, size: 20),
            ),
            const SizedBox(width: 10),
            const Text('WHMCS Tickets', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh tickets',
            onPressed: () => _loadTickets(refresh: true),
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(108),
          child: Column(
            children: [
              // Search input
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                child: SizedBox(
                  height: 44,
                  child: TextField(
                    controller: _searchController,
                    style: const TextStyle(fontSize: 14),
                    decoration: InputDecoration(
                      hintText: 'Search ticket ID, client, subject...',
                      prefixIcon: const Icon(Icons.search, size: 20),
                      suffixIcon: _searchController.text.isNotEmpty
                          ? IconButton(
                              icon: const Icon(Icons.clear, size: 18),
                              onPressed: () {
                                _searchController.clear();
                                if (auth.baseUrl != null && auth.token != null) {
                                  ticketProv.setSearchQuery('', baseUrl: auth.baseUrl!, token: auth.token!);
                                }
                              },
                            )
                          : null,
                      contentPadding: const EdgeInsets.symmetric(horizontal: 12),
                    ),
                    onSubmitted: (val) {
                      if (auth.baseUrl != null && auth.token != null) {
                        ticketProv.setSearchQuery(val, baseUrl: auth.baseUrl!, token: auth.token!);
                      }
                    },
                  ),
                ),
              ),
              // Status Filter Chips
              SizedBox(
                height: 48,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                  children: [
                    _buildFilterChip(
                      'Awaiting Reply',
                      'awaiting_reply',
                      ticketProv.counts['awaiting_reply'] ?? 0,
                      ticketProv,
                      auth,
                      theme,
                      badgeColor: const Color(0xFFEF4444),
                    ),
                    const SizedBox(width: 8),
                    if (ticketProv.statuses.isNotEmpty) ...[
                      ...ticketProv.statuses.map((st) {
                        final title = st['title']?.toString() ?? '';
                        final key = title.toLowerCase().replaceAll(RegExp(r'[ -]'), '_');
                        final count = ticketProv.counts[key] ?? 0;
                        final color = _parseHexColor(st['color']?.toString(), theme.colorScheme.primary);
                        return Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: _buildFilterChip(
                            title,
                            title,
                            count,
                            ticketProv,
                            auth,
                            theme,
                            badgeColor: color,
                          ),
                        );
                      }),
                    ] else ...[
                      _buildFilterChip('Open', 'open', ticketProv.counts['open'] ?? 0, ticketProv, auth, theme),
                      const SizedBox(width: 8),
                      _buildFilterChip('Customer-Reply', 'customer_reply', ticketProv.counts['customer_reply'] ?? 0, ticketProv, auth, theme, badgeColor: const Color(0xFFEF4444)),
                      const SizedBox(width: 8),
                      _buildFilterChip('Answered', 'answered', ticketProv.counts['answered'] ?? 0, ticketProv, auth, theme),
                      const SizedBox(width: 8),
                      _buildFilterChip('Closed', 'closed', ticketProv.counts['closed'] ?? 0, ticketProv, auth, theme),
                      const SizedBox(width: 8),
                    ],
                    _buildFilterChip('All', 'all', ticketProv.counts['total'] ?? 0, ticketProv, auth, theme),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        icon: const Icon(Icons.add_comment_outlined, size: 20),
        label: const Text('New Ticket', style: TextStyle(fontWeight: FontWeight.bold)),
        onPressed: () {
          HapticFeedback.lightImpact();
          CreateTicketModal.show(
            context,
            onTicketCreated: () => _loadTickets(refresh: true),
          );
        },
      ),
      body: ticketProv.isLoading && ticketProv.tickets.isEmpty
          ? const Center(child: CircularProgressIndicator())
          : ticketProv.errorMessage != null && ticketProv.tickets.isEmpty
              ? _buildErrorView(ticketProv.errorMessage!, theme)
              : ticketProv.tickets.isEmpty
                  ? _buildEmptyView(theme)
                  : RefreshIndicator(
                      onRefresh: () async => _loadTickets(refresh: true),
                      child: ListView.builder(
                        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                        padding: const EdgeInsets.only(left: 12, right: 12, top: 12, bottom: 80),
                        itemCount: ticketProv.tickets.length,
                        itemBuilder: (context, index) {
                          final ticket = ticketProv.tickets[index];
                          return _buildTicketCard(context, ticket, theme, isAmoled);
                        },
                      ),
                    ),
    );
  }

  Color _parseHexColor(String? hexString, Color defaultColor) {
    if (hexString == null || hexString.isEmpty) return defaultColor;
    try {
      String clean = hexString.replaceAll('#', '').trim();
      if (clean.length == 6) {
        clean = 'FF$clean';
      }
      return Color(int.parse('0x$clean'));
    } catch (_) {
      return defaultColor;
    }
  }

  Widget _buildFilterChip(
    String label,
    String key,
    int count,
    TicketProvider provider,
    AuthProvider auth,
    ThemeData theme, {
    Color? badgeColor,
  }) {
    final isSelected = provider.statusFilter.toLowerCase() == key.toLowerCase();
    final primary = theme.colorScheme.primary;

    return FilterChip(
      selected: isSelected,
      label: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(label, style: TextStyle(fontSize: 12, fontWeight: isSelected ? FontWeight.w700 : FontWeight.w500)),
          if (count > 0) ...[
            const SizedBox(width: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
              decoration: BoxDecoration(
                color: isSelected ? primary : (badgeColor ?? Colors.grey.shade800),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                '$count',
                style: TextStyle(
                  fontSize: 10,
                  fontWeight: FontWeight.bold,
                  color: isSelected ? Colors.black : Colors.white,
                ),
              ),
            ),
          ],
        ],
      ),
      onSelected: (_) {
        if (auth.baseUrl != null && auth.token != null) {
          provider.setStatusFilter(key, baseUrl: auth.baseUrl!, token: auth.token!);
        }
      },
      backgroundColor: theme.cardColor,
      selectedColor: primary.withOpacity(0.2),
      checkmarkColor: primary,
      side: BorderSide(
        color: isSelected ? primary : (theme.dividerColor),
      ),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
    );
  }

  Widget _buildTicketCard(
    BuildContext context,
    Map<String, dynamic> ticket,
    ThemeData theme,
    bool isAmoled,
  ) {
    final status = ticket['status']?.toString() ?? 'Open';
    final priority = ticket['priority']?.toString() ?? 'Medium';
    final tid = ticket['tid']?.toString() ?? '${ticket['id']}';
    final clientName = ticket['client_name']?.toString() ?? 'Client';
    final title = ticket['title']?.toString() ?? 'No Subject';
    final dept = ticket['department']?.toString() ?? 'Support';
    final lastReply = ticket['last_reply']?.toString() ?? '';
    final isAwaiting = ticket['is_awaiting_reply'] == true;

    final statusColor = _parseHexColor(
      ticket['status_color']?.toString(),
      isAwaiting ? const Color(0xFFEF4444) : const Color(0xFF10B981),
    );

    Color priorityColor;
    switch (priority.toLowerCase()) {
      case 'critical':
      case 'high':
        priorityColor = const Color(0xFFEF4444);
        break;
      case 'medium':
        priorityColor = const Color(0xFFF59E0B);
        break;
      default:
        priorityColor = const Color(0xFF38BDF8);
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () {
          Navigator.push(
            context,
            MaterialPageRoute(
              builder: (_) => TicketDetailScreen(ticketId: ticket['id'] as int),
            ),
          );
        },
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Top Row: #TID + Priority + Status Badge
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: isAmoled ? const Color(0xFF141A29) : theme.colorScheme.primary.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: theme.colorScheme.primary.withOpacity(0.3)),
                    ),
                    child: Text(
                      '#$tid',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                        color: theme.colorScheme.primary,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: priorityColor.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      priority,
                      style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: priorityColor),
                    ),
                  ),
                  const Spacer(),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: statusColor.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: statusColor.withOpacity(0.4)),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        CircleAvatar(radius: 3, backgroundColor: statusColor),
                        const SizedBox(width: 5),
                        Text(
                          status,
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: statusColor,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              // Subject Title
              Text(
                title,
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                  letterSpacing: -0.2,
                ),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 8),
              // Client & Department info
              Row(
                children: [
                  const Icon(Icons.person_outline, size: 14, color: Colors.grey),
                  const SizedBox(width: 4),
                  Text(
                    clientName,
                    style: const TextStyle(fontSize: 12, color: Colors.grey, fontWeight: FontWeight.w500),
                  ),
                  const SizedBox(width: 12),
                  const Icon(Icons.folder_open_outlined, size: 14, color: Colors.grey),
                  const SizedBox(width: 4),
                  Text(
                    dept,
                    style: const TextStyle(fontSize: 12, color: Colors.grey),
                  ),
                  const Spacer(),
                  Text(
                    lastReply,
                    style: const TextStyle(fontSize: 11, color: Colors.grey),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildEmptyView(ThemeData theme) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.inbox_outlined, size: 56, color: Colors.grey.shade600),
          const SizedBox(height: 12),
          const Text('No tickets found', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          const Text('Tickets matching this filter will appear here', style: TextStyle(fontSize: 13, color: Colors.grey)),
        ],
      ),
    );
  }

  Widget _buildErrorView(String msg, ThemeData theme) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.error_outline, size: 48, color: Colors.redAccent),
            const SizedBox(height: 12),
            Text(msg, textAlign: TextAlign.center, style: const TextStyle(fontSize: 14)),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: () => _loadTickets(refresh: true),
              child: const Text('Retry'),
            ),
          ],
        ),
      ),
    );
  }
}
