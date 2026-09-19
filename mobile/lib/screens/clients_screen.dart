import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../widgets/invoice_detail_modal.dart';
import '../widgets/service_detail_modal.dart';
import 'ticket_detail_screen.dart';

class ClientsScreen extends StatefulWidget {
  const ClientsScreen({super.key});

  @override
  State<ClientsScreen> createState() => _ClientsScreenState();
}

class _ClientsScreenState extends State<ClientsScreen> {
  final ApiService _api = ApiService();
  final TextEditingController _searchController = TextEditingController();

  List<Map<String, dynamic>> _clients = [];
  bool _isLoading = false;
  String? _errorMessage;
  String _statusFilter = 'all';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadClients();
    });
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _loadClients() async {
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final res = await _api.getClients(
        baseUrl: auth.baseUrl!,
        token: auth.token!,
        search: _searchController.text.trim(),
        status: _statusFilter,
      );

      if (res.success && res.data != null) {
        final list = res.data!['clients'] as List<dynamic>? ?? [];
        setState(() {
          _clients = list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        });
      } else {
        setState(() {
          _errorMessage = res.message ?? 'Failed to load clients.';
        });
      }
    } catch (e) {
      setState(() {
        _errorMessage = e.toString();
      });
    } finally {
      setState(() {
        _isLoading = false;
      });
    }
  }

  void _showClientProfile(Map<String, dynamic> clientSummary) {
    final clientId = clientSummary['id'] as int;
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    ClientProfileModal.show(
      context,
      clientId: clientId,
      baseUrl: auth.baseUrl!,
      token: auth.token!,
      initialSummary: clientSummary,
      onClientUpdated: _loadClients,
    );
  }

  Widget _buildStatusChip(String value, String label) {
    final isSelected = _statusFilter.toLowerCase() == value.toLowerCase();
    return FilterChip(
      label: Text(label, style: const TextStyle(fontSize: 12)),
      selected: isSelected,
      onSelected: (selected) {
        if (selected) {
          setState(() => _statusFilter = value);
          _loadClients();
        }
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

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
              child: Icon(Icons.people_alt_outlined, color: theme.colorScheme.primary, size: 20),
            ),
            const SizedBox(width: 10),
            const Text('WHMCS Clients', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadClients,
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(100),
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: SizedBox(
                  height: 40,
                  child: TextField(
                    controller: _searchController,
                    style: const TextStyle(fontSize: 14),
                    decoration: InputDecoration(
                      hintText: 'Search clients by name, email, company...',
                      prefixIcon: const Icon(Icons.search, size: 20),
                      suffixIcon: _searchController.text.isNotEmpty
                          ? IconButton(
                              icon: const Icon(Icons.clear, size: 18),
                              onPressed: () {
                                _searchController.clear();
                                _loadClients();
                              },
                            )
                          : null,
                      contentPadding: const EdgeInsets.symmetric(horizontal: 12),
                    ),
                    onSubmitted: (_) => _loadClients(),
                  ),
                ),
              ),
              const SizedBox(height: 6),
              SizedBox(
                height: 38,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  children: [
                    _buildStatusChip('all', 'All Clients'),
                    const SizedBox(width: 8),
                    _buildStatusChip('Active', 'Active'),
                    const SizedBox(width: 8),
                    _buildStatusChip('Inactive', 'Inactive'),
                    const SizedBox(width: 8),
                    _buildStatusChip('Closed', 'Closed'),
                  ],
                ),
              ),
              const SizedBox(height: 6),
            ],
          ),
        ),
      ),
      body: _isLoading && _clients.isEmpty
          ? const Center(child: CircularProgressIndicator())
          : _errorMessage != null && _clients.isEmpty
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.error_outline, size: 48, color: Colors.redAccent),
                      const SizedBox(height: 12),
                      Text(_errorMessage!),
                      const SizedBox(height: 12),
                      ElevatedButton(onPressed: _loadClients, child: const Text('Retry')),
                    ],
                  ),
                )
              : _clients.isEmpty
                  ? const Center(
                      child: Text('No clients found matching your query.', style: TextStyle(color: Colors.grey)),
                    )
                  : RefreshIndicator(
                      onRefresh: _loadClients,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(12),
                        itemCount: _clients.length,
                        itemBuilder: (context, index) {
                          final c = _clients[index];
                          return _buildClientCard(c, theme, isAmoled);
                        },
                      ),
                    ),
    );
  }

  Widget _buildClientCard(Map<String, dynamic> c, ThemeData theme, bool isAmoled) {
    final name = c['name']?.toString() ?? 'Client';
    final company = c['company']?.toString() ?? '';
    final email = c['email']?.toString() ?? '';
    final status = c['status']?.toString() ?? 'Active';
    final servicesCount = c['active_services'] ?? 0;
    final ticketsCount = c['open_tickets'] ?? 0;
    final unpaidInvoices = c['unpaid_invoices'] ?? 0;

    final isActive = status.toLowerCase() == 'active';

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () => _showClientProfile(c),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Top Row: Avatar + Name + Status Pill
              Row(
                children: [
                  CircleAvatar(
                    radius: 20,
                    backgroundColor: theme.colorScheme.primary.withOpacity(0.15),
                    child: Text(
                      name.isNotEmpty ? name[0].toUpperCase() : 'C',
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        color: theme.colorScheme.primary,
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          name,
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                        ),
                        if (company.isNotEmpty && company != 'Individual')
                          Text(
                            company,
                            style: const TextStyle(fontSize: 12, color: Colors.grey),
                          ),
                      ],
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: (isActive ? const Color(0xFF10B981) : Colors.grey).withOpacity(0.15),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(
                        color: (isActive ? const Color(0xFF10B981) : Colors.grey).withOpacity(0.3),
                      ),
                    ),
                    child: Text(
                      status,
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: isActive ? const Color(0xFF10B981) : Colors.grey,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              // Email
              Row(
                children: [
                  const Icon(Icons.email_outlined, size: 14, color: Colors.grey),
                  const SizedBox(width: 6),
                  Text(email, style: const TextStyle(fontSize: 12, color: Colors.grey)),
                ],
              ),
              const SizedBox(height: 12),
              // Metrics Row
              Row(
                children: [
                  _buildMetricBadge('Services', '$servicesCount Active', const Color(0xFF06B6D4)),
                  const SizedBox(width: 8),
                  _buildMetricBadge('Tickets', '$ticketsCount Open', const Color(0xFFF59E0B)),
                  const SizedBox(width: 8),
                  _buildMetricBadge('Invoices', '$unpaidInvoices Unpaid', unpaidInvoices > 0 ? const Color(0xFFEF4444) : const Color(0xFF10B981)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildMetricBadge(String label, String value, Color color) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        decoration: BoxDecoration(
          color: color.withOpacity(0.1),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: color.withOpacity(0.25)),
        ),
        child: Column(
          children: [
            Text(label, style: const TextStyle(fontSize: 10, color: Colors.grey)),
            const SizedBox(height: 2),
            Text(
              value,
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: color),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }
}

class ClientProfileModal extends StatefulWidget {
  final int clientId;
  final String baseUrl;
  final String token;
  final Map<String, dynamic> initialSummary;
  final VoidCallback? onClientUpdated;

  const ClientProfileModal({
    super.key,
    required this.clientId,
    required this.baseUrl,
    required this.token,
    required this.initialSummary,
    this.onClientUpdated,
  });

  static void show(
    BuildContext context, {
    required int clientId,
    required String baseUrl,
    required String token,
    Map<String, dynamic>? initialSummary,
    VoidCallback? onClientUpdated,
  }) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => ClientProfileModal(
        clientId: clientId,
        baseUrl: baseUrl,
        token: token,
        initialSummary: initialSummary ?? {'id': clientId},
        onClientUpdated: onClientUpdated,
      ),
    );
  }

  @override
  State<ClientProfileModal> createState() => _ClientProfileModalState();
}

class _ClientProfileModalState extends State<ClientProfileModal> {
  final ApiService _api = ApiService();
  bool _isLoading = true;
  Map<String, dynamic>? _profile;

  @override
  void initState() {
    super.initState();
    _loadProfile();
  }

  Future<void> _loadProfile() async {
    try {
      final res = await _api.getClientProfile(
        baseUrl: widget.baseUrl,
        token: widget.token,
        clientId: widget.clientId,
      );
      if (res.success && res.data != null && mounted) {
        setState(() {
          _profile = res.data!;
          _isLoading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _launchUrlAction(String urlStr) async {
    try {
      final uri = Uri.parse(urlStr);
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      }
    } catch (_) {}
  }

  void _copyContact(BuildContext context, String email, String phone) {
    final text = 'Email: $email\nPhone: $phone';
    Clipboard.setData(ClipboardData(text: text));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Client contact copied to clipboard'),
        duration: Duration(seconds: 2),
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  Future<void> _showAddNoteDialog() async {
    final noteController = TextEditingController();
    final added = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Add Staff Note'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Enter private internal staff note for this client (WHMCS tblnotes):',
              style: TextStyle(fontSize: 12, color: Colors.grey),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: noteController,
              minLines: 3,
              maxLines: 6,
              decoration: const InputDecoration(
                hintText: 'Note details...',
                border: OutlineInputBorder(),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Add Note'),
          ),
        ],
      ),
    );

    if (added == true && noteController.text.trim().isNotEmpty) {
      final res = await _api.addClientNote(
        baseUrl: widget.baseUrl,
        token: widget.token,
        clientId: widget.clientId,
        note: noteController.text.trim(),
      );

      if (res.success && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Staff note added successfully!'),
            backgroundColor: Color(0xFF10B981),
            behavior: SnackBarBehavior.floating,
          ),
        );
        _loadProfile();
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.message ?? 'Failed to add note.'),
            backgroundColor: const Color(0xFFEF4444),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

    final client = _profile?['client'] ?? widget.initialSummary;
    final services = (_profile?['services'] as List<dynamic>?) ?? [];
    final tickets = (_profile?['tickets'] as List<dynamic>?) ?? [];
    final invoices = (_profile?['invoices'] as List<dynamic>?) ?? [];
    final notes = (_profile?['notes'] as List<dynamic>?) ?? [];

    final name = client['name']?.toString() ?? 'Client Profile';
    final email = client['email']?.toString() ?? '';
    final phone = client['phone']?.toString() ?? '—';
    final address = client['address']?.toString() ?? '';
    final credit = client['credit']?.toString() ?? '0.00';
    final unpaidTotal = client['unpaid_total']?.toString() ?? '0.00';
    final status = client['status']?.toString() ?? 'Active';
    final isActive = status.toLowerCase() == 'active';

    return DraggableScrollableSheet(
      initialChildSize: 0.88,
      maxChildSize: 0.96,
      minChildSize: 0.5,
      builder: (_, scrollController) {
        return Container(
          decoration: BoxDecoration(
            color: isAmoled ? const Color(0xFF090D17) : theme.cardColor,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
            border: Border.all(color: theme.dividerColor),
          ),
          child: Column(
            children: [
              // Grab handle
              Center(
                child: Container(
                  margin: const EdgeInsets.symmetric(vertical: 10),
                  width: 44,
                  height: 4,
                  decoration: BoxDecoration(
                    color: Colors.grey.shade600,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),

              Expanded(
                child: ListView(
                  controller: scrollController,
                  padding: const EdgeInsets.all(18),
                  children: [
                    // Top Header Row
                    Row(
                      children: [
                        CircleAvatar(
                          radius: 28,
                          backgroundColor: theme.colorScheme.primary.withOpacity(0.15),
                          child: Text(
                            name.isNotEmpty ? name[0].toUpperCase() : 'C',
                            style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: theme.colorScheme.primary),
                          ),
                        ),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                name,
                                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
                              ),
                              if (email.isNotEmpty)
                                Text(
                                  email,
                                  style: const TextStyle(fontSize: 13, color: Colors.grey),
                                ),
                              const SizedBox(height: 4),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                decoration: BoxDecoration(
                                  color: (isActive ? const Color(0xFF10B981) : Colors.grey).withOpacity(0.15),
                                  borderRadius: BorderRadius.circular(8),
                                  border: Border.all(
                                    color: (isActive ? const Color(0xFF10B981) : Colors.grey).withOpacity(0.3),
                                  ),
                                ),
                                child: Text(
                                  status.toUpperCase(),
                                  style: TextStyle(
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold,
                                    color: isActive ? const Color(0xFF10B981) : Colors.grey,
                                  ),
                                ),
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

                    const SizedBox(height: 14),

                    // Quick Action Buttons (Call, Email, Copy)
                    Row(
                      children: [
                        if (email.isNotEmpty && email != 'Not provided')
                          Expanded(
                            child: ElevatedButton.icon(
                              icon: const Icon(Icons.email_outlined, size: 15),
                              label: const Text('Email', style: TextStyle(fontSize: 12)),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFF8B5CF6),
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(vertical: 8),
                              ),
                              onPressed: () => _launchUrlAction('mailto:$email'),
                            ),
                          ),
                        if (email.isNotEmpty && phone.isNotEmpty && phone != '—') const SizedBox(width: 8),
                        if (phone.isNotEmpty && phone != '—')
                          Expanded(
                            child: ElevatedButton.icon(
                              icon: const Icon(Icons.phone_outlined, size: 15),
                              label: const Text('Call', style: TextStyle(fontSize: 12)),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFF10B981),
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(vertical: 8),
                              ),
                              onPressed: () => _launchUrlAction('tel:$phone'),
                            ),
                          ),
                        const SizedBox(width: 8),
                        OutlinedButton(
                          onPressed: () => _copyContact(context, email, phone),
                          style: OutlinedButton.styleFrom(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                          ),
                          child: const Icon(Icons.copy, size: 16),
                        ),
                      ],
                    ),

                    const SizedBox(height: 14),

                    // Account Financial Summary
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: isAmoled ? const Color(0xFF111726) : Colors.grey.shade50,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: theme.dividerColor),
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text('Credit Balance', style: TextStyle(fontSize: 11, color: Colors.grey)),
                                const SizedBox(height: 2),
                                Text(
                                  '\$$credit',
                                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Color(0xFF10B981)),
                                ),
                              ],
                            ),
                          ),
                          Container(width: 1, height: 30, color: theme.dividerColor),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text('Unpaid Invoices', style: TextStyle(fontSize: 11, color: Colors.grey)),
                                const SizedBox(height: 2),
                                Text(
                                  '\$$unpaidTotal',
                                  style: TextStyle(
                                    fontSize: 14,
                                    fontWeight: FontWeight.bold,
                                    color: unpaidTotal != '0.00' ? const Color(0xFFEF4444) : Colors.grey,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),

                    if (address.isNotEmpty) ...[
                      const SizedBox(height: 10),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Icon(Icons.location_on_outlined, size: 14, color: Colors.grey),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              address,
                              style: const TextStyle(fontSize: 12, color: Colors.grey),
                            ),
                          ),
                        ],
                      ),
                    ],

                    const SizedBox(height: 16),
                    const Divider(),
                    const SizedBox(height: 12),

                    // Products & Services Section
                    Row(
                      children: [
                        const Icon(Icons.dns_outlined, size: 18, color: Color(0xFF06B6D4)),
                        const SizedBox(width: 8),
                        Text(
                          'Hosting & Services (${services.length})',
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    if (services.isEmpty)
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 8),
                        child: Text('No active hosting services.', style: TextStyle(fontSize: 13, color: Colors.grey)),
                      )
                    else
                      ...services.map((s) {
                        final sMap = Map<String, dynamic>.from(s as Map);
                        final sDomain = sMap['domain']?.toString() ?? '—';
                        final sStatus = sMap['domainstatus']?.toString() ?? 'Active';
                        final sIsActive = sStatus.toLowerCase() == 'active';

                        return Container(
                          margin: const EdgeInsets.only(bottom: 8),
                          decoration: BoxDecoration(
                            color: isAmoled ? const Color(0xFF111726) : Colors.grey.shade100,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: theme.dividerColor),
                          ),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(10),
                            onTap: () {
                              ServiceDetailModal.show(
                                context,
                                {
                                  ...sMap,
                                  'client_name': name,
                                  'client_email': email,
                                },
                                baseUrl: widget.baseUrl,
                                token: widget.token,
                                onServiceUpdated: _loadProfile,
                              );
                            },
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Row(
                                children: [
                                  const Icon(Icons.cloud_outlined, size: 20, color: Color(0xFF06B6D4)),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          sMap['product_name']?.toString() ?? 'Service',
                                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                        ),
                                        const SizedBox(height: 2),
                                        Text(sDomain, style: const TextStyle(fontSize: 12, color: Colors.grey)),
                                      ],
                                    ),
                                  ),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                    decoration: BoxDecoration(
                                      color: (sIsActive ? const Color(0xFF10B981) : const Color(0xFFEF4444)).withOpacity(0.15),
                                      borderRadius: BorderRadius.circular(6),
                                    ),
                                    child: Text(
                                      sStatus,
                                      style: TextStyle(
                                        fontSize: 10,
                                        fontWeight: FontWeight.bold,
                                        color: sIsActive ? const Color(0xFF10B981) : const Color(0xFFEF4444),
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: 6),
                                  const Icon(Icons.chevron_right, size: 18, color: Colors.grey),
                                ],
                              ),
                            ),
                          ),
                        );
                      }),

                    const SizedBox(height: 16),
                    const Divider(),
                    const SizedBox(height: 12),

                    // Tickets Section
                    Row(
                      children: [
                        const Icon(Icons.confirmation_number_outlined, size: 18, color: Color(0xFFF59E0B)),
                        const SizedBox(width: 8),
                        Text(
                          'Support Tickets (${tickets.length})',
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    if (tickets.isEmpty)
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 8),
                        child: Text('No tickets found for this client.', style: TextStyle(fontSize: 13, color: Colors.grey)),
                      )
                    else
                      ...tickets.map((t) {
                        final tMap = Map<String, dynamic>.from(t as Map);
                        final tId = (tMap['id'] as num?)?.toInt();
                        final tStatus = tMap['status']?.toString() ?? 'Open';

                        return Container(
                          margin: const EdgeInsets.only(bottom: 8),
                          decoration: BoxDecoration(
                            color: isAmoled ? const Color(0xFF111726) : Colors.grey.shade100,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: theme.dividerColor),
                          ),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(10),
                            onTap: () {
                              if (tId != null) {
                                Navigator.of(context).push(
                                  MaterialPageRoute(
                                    builder: (_) => TicketDetailScreen(ticketId: tId),
                                  ),
                                );
                              }
                            },
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Row(
                                children: [
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          '#${tMap['tid'] ?? tId}: ${tMap['title'] ?? 'Ticket'}',
                                          style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                                          maxLines: 2,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                        if (tMap['lastreply'] != null) ...[
                                          const SizedBox(height: 3),
                                          Text(
                                            'Last reply: ${tMap['lastreply']}',
                                            style: const TextStyle(fontSize: 11, color: Colors.grey),
                                          ),
                                        ],
                                      ],
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                    decoration: BoxDecoration(
                                      color: const Color(0xFF10B981).withOpacity(0.15),
                                      borderRadius: BorderRadius.circular(6),
                                    ),
                                    child: Text(
                                      tStatus,
                                      style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFF10B981)),
                                    ),
                                  ),
                                  const SizedBox(width: 6),
                                  const Icon(Icons.chevron_right, size: 18, color: Colors.grey),
                                ],
                              ),
                            ),
                          ),
                        );
                      }),

                    const SizedBox(height: 16),
                    const Divider(),
                    const SizedBox(height: 12),

                    // Invoices Section
                    Row(
                      children: [
                        const Icon(Icons.receipt_long_outlined, size: 18, color: Color(0xFF10B981)),
                        const SizedBox(width: 8),
                        Text(
                          'Invoices (${invoices.length})',
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    if (invoices.isEmpty)
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 8),
                        child: Text('No invoices recorded.', style: TextStyle(fontSize: 13, color: Colors.grey)),
                      )
                    else
                      ...invoices.map((inv) {
                        final invMap = Map<String, dynamic>.from(inv as Map);
                        final invStatus = invMap['status']?.toString() ?? 'Unpaid';
                        final isPaid = invStatus.toLowerCase() == 'paid';
                        final numStr = invMap['invoicenum']?.toString() ?? invMap['id']?.toString() ?? '';
                        final total = invMap['total']?.toString() ?? '0.00';
                        final due = invMap['duedate']?.toString() ?? '—';
                        final invId = (invMap['id'] as num?)?.toInt();

                        return Container(
                          margin: const EdgeInsets.only(bottom: 8),
                          decoration: BoxDecoration(
                            color: isAmoled ? const Color(0xFF111726) : Colors.grey.shade100,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: theme.dividerColor),
                          ),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(10),
                            onTap: () {
                              if (invId != null) {
                                InvoiceDetailModal.show(
                                  context,
                                  invoiceId: invId,
                                  baseUrl: widget.baseUrl,
                                  token: widget.token,
                                  onInvoiceUpdated: _loadProfile,
                                );
                              }
                            },
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Row(
                                children: [
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text('Invoice #$numStr', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                                        const SizedBox(height: 2),
                                        Text('Due: $due', style: const TextStyle(fontSize: 11, color: Colors.grey)),
                                      ],
                                    ),
                                  ),
                                  Text('\$$total', style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
                                  const SizedBox(width: 8),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                    decoration: BoxDecoration(
                                      color: (isPaid ? const Color(0xFF10B981) : const Color(0xFFEF4444)).withOpacity(0.15),
                                      borderRadius: BorderRadius.circular(6),
                                    ),
                                    child: Text(
                                      invStatus,
                                      style: TextStyle(
                                        fontSize: 10,
                                        fontWeight: FontWeight.bold,
                                        color: isPaid ? const Color(0xFF10B981) : const Color(0xFFEF4444),
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: 6),
                                  const Icon(Icons.chevron_right, size: 18, color: Colors.grey),
                                ],
                              ),
                            ),
                          ),
                        );
                      }),

                    const SizedBox(height: 16),
                    const Divider(),
                    const SizedBox(height: 12),

                    // Staff Notes Section
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.sticky_note_2_outlined, size: 18, color: Color(0xFFF59E0B)),
                            const SizedBox(width: 8),
                            Text(
                              'Staff Notes (${notes.length})',
                              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                            ),
                          ],
                        ),
                        TextButton.icon(
                          icon: const Icon(Icons.add, size: 16),
                          label: const Text('Add Note', style: TextStyle(fontSize: 12)),
                          onPressed: _showAddNoteDialog,
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    if (notes.isEmpty)
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 8),
                        child: Text('No internal staff notes recorded.', style: TextStyle(fontSize: 13, color: Colors.grey)),
                      )
                    else
                      ...notes.map((n) {
                        final nMap = Map<String, dynamic>.from(n as Map);
                        final admin = nMap['admin_name']?.toString() ?? (nMap['admin']?.toString() ?? 'Staff');
                        final date = nMap['date']?.toString() ?? (nMap['created']?.toString() ?? '');
                        final note = nMap['note']?.toString() ?? '';

                        return Container(
                          margin: const EdgeInsets.only(bottom: 8),
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: isAmoled ? const Color(0xFF161305) : const Color(0xFFFEF3C7).withOpacity(0.4),
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: const Color(0xFFF59E0B).withOpacity(0.3)),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                children: [
                                  Text(admin, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Color(0xFFF59E0B))),
                                  Text(date, style: const TextStyle(fontSize: 11, color: Colors.grey)),
                                ],
                              ),
                              const SizedBox(height: 6),
                              SelectableText(note, style: const TextStyle(fontSize: 13, height: 1.35)),
                            ],
                          ),
                        );
                      }),

                    const SizedBox(height: 24),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
