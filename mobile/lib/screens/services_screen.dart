import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../widgets/service_detail_modal.dart';
import 'clients_screen.dart';
import 'invoices_screen.dart';

class ServicesScreen extends StatefulWidget {
  const ServicesScreen({super.key});

  @override
  State<ServicesScreen> createState() => _ServicesScreenState();
}

class _ServicesScreenState extends State<ServicesScreen> {
  final ApiService _api = ApiService();
  final TextEditingController _searchController = TextEditingController();

  List<Map<String, dynamic>> _services = [];
  bool _isLoading = false;
  String? _errorMessage;
  String _statusFilter = 'all';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadServices();
    });
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _loadServices() async {
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final res = await _api.getServices(
        baseUrl: auth.baseUrl!,
        token: auth.token!,
        search: _searchController.text.trim(),
        status: _statusFilter,
      );

      if (res.success && res.data != null) {
        final list = res.data!['services'] as List<dynamic>? ?? [];
        setState(() {
          _services = list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        });
      } else {
        setState(() {
          _errorMessage = res.message ?? 'Failed to load services.';
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

  void _openServiceDetail(Map<String, dynamic> s) {
    final clientId = (s['client_id'] as num?)?.toInt();
    final auth = context.read<AuthProvider>();
    ServiceDetailModal.show(
      context,
      s,
      baseUrl: auth.baseUrl,
      token: auth.token,
      onServiceUpdated: _loadServices,
      onViewClient: (clientId != null && clientId > 0)
          ? () => _showClientProfile(clientId, s['client_name']?.toString() ?? 'Client', s['client_email']?.toString() ?? '')
          : null,
    );
  }

  Future<void> _launchServiceSso(Map<String, dynamic> s) async {
    final serviceId = (s['id'] as num?)?.toInt();
    if (serviceId == null) return;

    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    HapticFeedback.lightImpact();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Row(
          children: [
            const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)),
            const SizedBox(width: 12),
            Expanded(child: Text('Generating cPanel SSO for ${s['domain'] ?? 'service'}...')),
          ],
        ),
        duration: const Duration(seconds: 2),
        behavior: SnackBarBehavior.floating,
      ),
    );

    try {
      final res = await _api.getServiceSsoUrl(
        baseUrl: auth.baseUrl!,
        token: auth.token!,
        serviceId: serviceId,
      );

      if (res.success && res.data != null && res.data!['sso_url'] != null) {
        final ssoUrl = res.data!['sso_url'].toString();
        final uri = Uri.parse(ssoUrl);
        if (await canLaunchUrl(uri)) {
          await launchUrl(uri, mode: LaunchMode.externalApplication);
        } else if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Could not open SSO URL: $ssoUrl')),
          );
        }
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.message ?? 'Failed to generate cPanel SSO link'),
            backgroundColor: const Color(0xFFEF4444),
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error launching SSO: $e'), backgroundColor: const Color(0xFFEF4444)),
        );
      }
    }
  }

  void _showClientProfile(int clientId, String clientName, String clientEmail) {
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
      onClientUpdated: _loadServices,
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
              child: Icon(Icons.cloud_outlined, color: theme.colorScheme.primary, size: 20),
            ),
            const SizedBox(width: 10),
            const Text('WHMCS Services', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadServices,
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
                      hintText: 'Search domain, plan, client...',
                      prefixIcon: const Icon(Icons.search, size: 20),
                      suffixIcon: _searchController.text.isNotEmpty
                          ? IconButton(
                              icon: const Icon(Icons.clear, size: 18),
                              onPressed: () {
                                _searchController.clear();
                                _loadServices();
                              },
                            )
                          : null,
                      contentPadding: const EdgeInsets.symmetric(horizontal: 12),
                    ),
                    onSubmitted: (_) => _loadServices(),
                  ),
                ),
              ),
              // Status Filters
              SizedBox(
                height: 48,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                  children: [
                    _buildChip('All', 'all', theme),
                    const SizedBox(width: 8),
                    _buildChip('Active', 'Active', theme),
                    const SizedBox(width: 8),
                    _buildChip('Suspended', 'Suspended', theme),
                    const SizedBox(width: 8),
                    _buildChip('Terminated', 'Terminated', theme),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
      body: _isLoading && _services.isEmpty
          ? const Center(child: CircularProgressIndicator())
          : _errorMessage != null && _services.isEmpty
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.error_outline, size: 48, color: Colors.redAccent),
                      const SizedBox(height: 12),
                      Text(_errorMessage!),
                      const SizedBox(height: 12),
                      ElevatedButton(onPressed: _loadServices, child: const Text('Retry')),
                    ],
                  ),
                )
              : _services.isEmpty
                  ? const Center(
                      child: Text('No hosting services found.', style: TextStyle(color: Colors.grey)),
                    )
                  : RefreshIndicator(
                      onRefresh: _loadServices,
                      child: ListView.builder(
                        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                        padding: const EdgeInsets.all(12),
                        itemCount: _services.length,
                        itemBuilder: (context, index) {
                          final s = _services[index];
                          return _buildServiceCard(s, theme, isAmoled);
                        },
                      ),
                    ),
    );
  }

  Widget _buildChip(String label, String key, ThemeData theme) {
    final isSelected = _statusFilter == key;
    final primary = theme.colorScheme.primary;

    return FilterChip(
      selected: isSelected,
      label: Text(label, style: TextStyle(fontSize: 12, fontWeight: isSelected ? FontWeight.w700 : FontWeight.w500)),
      onSelected: (_) {
        setState(() => _statusFilter = key);
        _loadServices();
      },
      backgroundColor: theme.cardColor,
      selectedColor: primary.withOpacity(0.2),
      checkmarkColor: primary,
      side: BorderSide(color: isSelected ? primary : theme.dividerColor),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
    );
  }

  Widget _buildServiceCard(Map<String, dynamic> s, ThemeData theme, bool isAmoled) {
    final productName = s['product_name']?.toString() ?? 'Hosting Plan';
    final domain = s['domain']?.toString() ?? '—';
    final clientName = s['client_name']?.toString() ?? 'Client';
    final status = s['status']?.toString() ?? 'Active';
    final price = s['price']?.toString() ?? '0.00';
    final cycle = s['billing_cycle']?.toString() ?? 'Monthly';
    final nextDue = s['next_due_date']?.toString() ?? '—';

    Color statusColor;
    switch (status.toLowerCase()) {
      case 'active':
        statusColor = const Color(0xFF10B981);
        break;
      case 'suspended':
        statusColor = const Color(0xFFEF4444);
        break;
      default:
        statusColor = Colors.grey;
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () => _openServiceDetail(s),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      productName,
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: statusColor.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: statusColor.withOpacity(0.3)),
                    ),
                    child: Text(
                      status,
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: statusColor,
                      ),
                    ),
                  ),
                  const SizedBox(width: 4),
                  const Icon(Icons.chevron_right, size: 18, color: Colors.grey),
                ],
              ),
              const SizedBox(height: 6),
              Row(
                children: [
                  const Icon(Icons.language, size: 14, color: Color(0xFF06B6D4)),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      domain,
                      style: const TextStyle(fontSize: 13, color: Color(0xFF06B6D4), fontWeight: FontWeight.w600),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              const Divider(),
              const SizedBox(height: 6),
              Row(
                children: [
                  Text('Client: $clientName', style: const TextStyle(fontSize: 12, color: Colors.grey)),
                  const Spacer(),
                  Text(
                    '\$$price / $cycle',
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Row(
                children: [
                  Text('Next Due: $nextDue', style: const TextStyle(fontSize: 11, color: Colors.grey)),
                ],
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  OutlinedButton.icon(
                    icon: const Icon(Icons.vpn_key_outlined, size: 14),
                    label: const Text('cPanel SSO', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600)),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      visualDensity: VisualDensity.compact,
                    ),
                    onPressed: () => _launchServiceSso(s),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton.icon(
                    icon: const Icon(Icons.receipt_long_outlined, size: 14),
                    label: const Text('Invoices', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600)),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      visualDensity: VisualDensity.compact,
                    ),
                    onPressed: () {
                      HapticFeedback.selectionClick();
                      Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => InvoicesScreen(initialSearch: clientName),
                        ),
                      );
                    },
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
