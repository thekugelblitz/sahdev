import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';

class ServiceDetailModal extends StatefulWidget {
  final Map<String, dynamic> service;
  final VoidCallback? onViewClient;
  final VoidCallback? onServiceUpdated;
  final String? baseUrl;
  final String? token;

  const ServiceDetailModal({
    super.key,
    required this.service,
    this.onViewClient,
    this.onServiceUpdated,
    this.baseUrl,
    this.token,
  });

  static void show(
    BuildContext context,
    Map<String, dynamic> service, {
    VoidCallback? onViewClient,
    VoidCallback? onServiceUpdated,
    String? baseUrl,
    String? token,
  }) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => ServiceDetailModal(
        service: service,
        onViewClient: onViewClient,
        onServiceUpdated: onServiceUpdated,
        baseUrl: baseUrl,
        token: token,
      ),
    );
  }

  @override
  State<ServiceDetailModal> createState() => _ServiceDetailModalState();
}

class _ServiceDetailModalState extends State<ServiceDetailModal> {
  final ApiService _api = ApiService();
  late Map<String, dynamic> _currentService;
  bool _isUpdatingStatus = false;

  @override
  void initState() {
    super.initState();
    _currentService = Map<String, dynamic>.from(widget.service);
  }

  Future<void> _launchDomain(BuildContext context, String domain) async {
    if (domain.isEmpty || domain == '—') return;
    String url = domain;
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
      url = 'https://$url';
    }
    try {
      final uri = Uri.parse(url);
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      } else {
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Could not open $url')),
          );
        }
      }
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error opening link: $e')),
        );
      }
    }
  }

  void _copyToClipboard(BuildContext context, String text, String label) {
    if (text.isEmpty || text == '—') return;
    Clipboard.setData(ClipboardData(text: text));
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('$label copied to clipboard'),
        duration: const Duration(seconds: 2),
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  Future<void> _updateServiceStatus(String targetStatus) async {
    final serviceId = (_currentService['id'] as num?)?.toInt();
    if (serviceId == null) return;

    final auth = context.read<AuthProvider>();
    final bUrl = widget.baseUrl ?? auth.baseUrl;
    final tok = widget.token ?? auth.token;
    if (bUrl == null || tok == null) return;

    String? suspendReason;
    if (targetStatus.toLowerCase() == 'suspended') {
      final reasonController = TextEditingController(text: 'Overdue Invoice or Administrative Action');
      final confirm = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Suspend Service'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Enter reason for suspending this service in WHMCS:'),
              const SizedBox(height: 10),
              TextField(
                controller: reasonController,
                decoration: const InputDecoration(
                  labelText: 'Suspension Reason',
                  border: OutlineInputBorder(),
                ),
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFEF4444), foregroundColor: Colors.white),
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Suspend Now'),
            ),
          ],
        ),
      );
      if (confirm != true) return;
      suspendReason = reasonController.text.trim();
    } else {
      final confirm = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text('Set Service to $targetStatus?'),
          content: Text('Are you sure you want to change status to "$targetStatus" in WHMCS?'),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
            ElevatedButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Confirm'),
            ),
          ],
        ),
      );
      if (confirm != true) return;
    }

    setState(() => _isUpdatingStatus = true);

    try {
      final res = await _api.updateServiceStatus(
        baseUrl: bUrl,
        token: tok,
        serviceId: serviceId,
        status: targetStatus,
        reason: suspendReason,
      );

      if (res.success && mounted) {
        setState(() {
          _currentService['domainstatus'] = targetStatus;
          _currentService['status'] = targetStatus;
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.message ?? 'Service status updated to $targetStatus'),
            backgroundColor: const Color(0xFF10B981),
            behavior: SnackBarBehavior.floating,
          ),
        );
        widget.onServiceUpdated?.call();
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.message ?? 'Failed to update service status'),
            backgroundColor: const Color(0xFFEF4444),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Error: $e'),
            backgroundColor: const Color(0xFFEF4444),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _isUpdatingStatus = false);
    }
  }

  void _showChangeStatusDialog() {
    final currentStatus = (_currentService['status']?.toString() ??
        (_currentService['domainstatus']?.toString() ?? 'Active')).toLowerCase();

    final options = ['Active', 'Suspended', 'Pending', 'Terminated', 'Cancelled'];

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        final theme = Theme.of(context);
        final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

        return Container(
          decoration: BoxDecoration(
            color: isAmoled ? const Color(0xFF090D17) : theme.cardColor,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
            border: Border.all(color: theme.dividerColor),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Change WHMCS Service Status',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 12),
              ...options.map((opt) {
                final isSelected = opt.toLowerCase() == currentStatus;
                return ListTile(
                  title: Text(opt, style: TextStyle(fontWeight: isSelected ? FontWeight.bold : FontWeight.normal)),
                  trailing: isSelected ? const Icon(Icons.check, color: Color(0xFF10B981)) : null,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  onTap: () {
                    Navigator.of(ctx).pop();
                    if (!isSelected) {
                      _updateServiceStatus(opt);
                    }
                  },
                );
              }),
            ],
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

    final productName = _currentService['product_name']?.toString() ?? 'Hosting Service';
    final domain = _currentService['domain']?.toString() ?? '—';
    final status = _currentService['status']?.toString() ?? (_currentService['domainstatus']?.toString() ?? 'Active');
    final clientName = _currentService['client_name']?.toString() ?? 'Client';
    final clientEmail = _currentService['client_email']?.toString() ?? '';
    final price = _currentService['price']?.toString() ?? (_currentService['amount']?.toString() ?? '0.00');
    final cycle = _currentService['billing_cycle']?.toString() ?? (_currentService['billingcycle']?.toString() ?? 'Monthly');
    final nextDue = _currentService['next_due_date']?.toString() ?? (_currentService['nextduedate']?.toString() ?? '—');
    final regDate = _currentService['reg_date']?.toString() ?? (_currentService['regdate']?.toString() ?? '—');
    final paymentMethod = _currentService['payment_method']?.toString() ?? (_currentService['paymentmethod']?.toString() ?? '—');
    final dedicatedIp = _currentService['dedicated_ip']?.toString() ?? (_currentService['dedicatedip']?.toString() ?? '');
    final username = _currentService['username']?.toString() ?? '';
    final serverName = _currentService['server_name']?.toString() ?? '';

    Color statusColor;
    switch (status.toLowerCase()) {
      case 'active':
        statusColor = const Color(0xFF10B981);
        break;
      case 'suspended':
        statusColor = const Color(0xFFEF4444);
        break;
      case 'terminated':
        statusColor = const Color(0xFF6B7280);
        break;
      case 'pending':
        statusColor = const Color(0xFFF59E0B);
        break;
      default:
        statusColor = const Color(0xFF8B5CF6);
    }

    final isSuspended = status.toLowerCase() == 'suspended';
    final isActive = status.toLowerCase() == 'active';

    return DraggableScrollableSheet(
      initialChildSize: 0.82,
      maxChildSize: 0.95,
      minChildSize: 0.45,
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

              // Header
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: theme.colorScheme.primary.withOpacity(0.15),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Icon(Icons.dns_rounded, color: theme.colorScheme.primary, size: 28),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            productName,
                            style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
                          ),
                          const SizedBox(height: 4),
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                  color: statusColor.withOpacity(0.15),
                                  borderRadius: BorderRadius.circular(8),
                                  border: Border.all(color: statusColor.withOpacity(0.35)),
                                ),
                                child: Text(
                                  status.toUpperCase(),
                                  style: TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.bold,
                                    color: statusColor,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 8),
                              if (username.isNotEmpty)
                                Text(
                                  'User: $username',
                                  style: const TextStyle(fontSize: 12, color: Colors.grey),
                                ),
                            ],
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
              ),

              const Divider(height: 1),

              // Scrollable Body
              Expanded(
                child: ListView(
                  controller: scrollController,
                  padding: const EdgeInsets.all(18),
                  children: [
                    // Domain Highlight Banner
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: isAmoled ? const Color(0xFF111726) : const Color(0xFFEFF6FF),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: const Color(0xFF3B82F6).withOpacity(0.3)),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.language, color: Color(0xFF3B82F6), size: 24),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text('DOMAIN NAME',
                                    style: TextStyle(fontSize: 10, color: Colors.grey, fontWeight: FontWeight.bold)),
                                const SizedBox(height: 2),
                                Text(
                                  domain,
                                  style: const TextStyle(
                                      fontSize: 15, fontWeight: FontWeight.bold, color: Color(0xFF3B82F6)),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ],
                            ),
                          ),
                          if (domain != '—') ...[
                            IconButton(
                              icon: const Icon(Icons.copy, size: 18),
                              tooltip: 'Copy domain',
                              onPressed: () => _copyToClipboard(context, domain, 'Domain'),
                            ),
                            IconButton(
                              icon: const Icon(Icons.open_in_new, size: 18, color: Color(0xFF3B82F6)),
                              tooltip: 'Open website',
                              onPressed: () => _launchDomain(context, domain),
                            ),
                          ],
                        ],
                      ),
                    ),

                    const SizedBox(height: 14),

                    // WHMCS Service Management Action Bar
                    Row(
                      children: [
                        if (isActive)
                          Expanded(
                            child: ElevatedButton.icon(
                              icon: _isUpdatingStatus
                                  ? const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                                  : const Icon(Icons.pause_circle_outline, size: 15),
                              label: const Text('Suspend', style: TextStyle(fontSize: 12)),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFFEF4444),
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(vertical: 8),
                              ),
                              onPressed: _isUpdatingStatus ? null : () => _updateServiceStatus('Suspended'),
                            ),
                          )
                        else if (isSuspended)
                          Expanded(
                            child: ElevatedButton.icon(
                              icon: _isUpdatingStatus
                                  ? const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                                  : const Icon(Icons.play_circle_outline, size: 15),
                              label: const Text('Unsuspend', style: TextStyle(fontSize: 12)),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFF10B981),
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(vertical: 8),
                              ),
                              onPressed: _isUpdatingStatus ? null : () => _updateServiceStatus('Active'),
                            ),
                          ),
                        const SizedBox(width: 8),
                        OutlinedButton.icon(
                          icon: const Icon(Icons.tune, size: 15),
                          label: const Text('Status', style: TextStyle(fontSize: 12)),
                          style: OutlinedButton.styleFrom(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                          ),
                          onPressed: _isUpdatingStatus ? null : _showChangeStatusDialog,
                        ),
                        if (widget.onViewClient != null) ...[
                          const SizedBox(width: 8),
                          OutlinedButton.icon(
                            icon: const Icon(Icons.person, size: 15),
                            label: const Text('Client', style: TextStyle(fontSize: 12)),
                            style: OutlinedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                            ),
                            onPressed: () {
                              Navigator.of(context).pop();
                              widget.onViewClient!();
                            },
                          ),
                        ],
                      ],
                    ),

                    const SizedBox(height: 18),

                    // Section: Billing & Plan
                    _buildSectionHeader('Billing & Renewal Details', Icons.credit_card),
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: isAmoled ? const Color(0xFF10141E) : Colors.grey.shade50,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: theme.dividerColor),
                      ),
                      child: Column(
                        children: [
                          _buildDetailRow('Recurring Price', '\$$price / $cycle', isBold: true),
                          _buildDivider(),
                          _buildDetailRow('Next Due Date', nextDue),
                          _buildDivider(),
                          _buildDetailRow('Registration Date', regDate),
                          _buildDivider(),
                          _buildDetailRow('Payment Method', paymentMethod),
                        ],
                      ),
                    ),

                    const SizedBox(height: 18),

                    // Section: Hosting & Server Info
                    _buildSectionHeader('Technical Specifications', Icons.computer),
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: isAmoled ? const Color(0xFF10141E) : Colors.grey.shade50,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: theme.dividerColor),
                      ),
                      child: Column(
                        children: [
                          if (dedicatedIp.isNotEmpty) ...[
                            _buildDetailRow(
                              'Dedicated IP',
                              dedicatedIp,
                              action: IconButton(
                                icon: const Icon(Icons.copy, size: 14),
                                onPressed: () => _copyToClipboard(context, dedicatedIp, 'IP'),
                                constraints: const BoxConstraints(),
                                padding: EdgeInsets.zero,
                              ),
                            ),
                            _buildDivider(),
                          ],
                          if (username.isNotEmpty) ...[
                            _buildDetailRow(
                              'Account Username',
                              username,
                              action: IconButton(
                                icon: const Icon(Icons.copy, size: 14),
                                onPressed: () => _copyToClipboard(context, username, 'Username'),
                                constraints: const BoxConstraints(),
                                padding: EdgeInsets.zero,
                              ),
                            ),
                            _buildDivider(),
                          ],
                          if (serverName.isNotEmpty) ...[
                            _buildDetailRow('Server', serverName),
                            _buildDivider(),
                          ],
                          _buildDetailRow('Product Type', productName),
                        ],
                      ),
                    ),

                    const SizedBox(height: 18),

                    // Section: Client Info
                    _buildSectionHeader('Client Overview', Icons.person_outline),
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: isAmoled ? const Color(0xFF10141E) : Colors.grey.shade50,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: theme.dividerColor),
                      ),
                      child: Column(
                        children: [
                          _buildDetailRow('Client Name', clientName, isBold: true),
                          if (clientEmail.isNotEmpty) ...[
                            _buildDivider(),
                            _buildDetailRow('Email', clientEmail),
                          ],
                        ],
                      ),
                    ),

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

  Widget _buildSectionHeader(String title, IconData icon) {
    return Row(
      children: [
        Icon(icon, size: 16, color: const Color(0xFF8B5CF6)),
        const SizedBox(width: 8),
        Text(
          title,
          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Color(0xFF8B5CF6)),
        ),
      ],
    );
  }

  Widget _buildDetailRow(String label, String value, {bool isBold = false, Widget? action}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(fontSize: 13, color: Colors.grey)),
          const SizedBox(width: 12),
          Expanded(
            child: Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                Flexible(
                  child: Text(
                    value,
                    style: TextStyle(fontSize: 13, fontWeight: isBold ? FontWeight.bold : FontWeight.w500),
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.end,
                  ),
                ),
                if (action != null) ...[
                  const SizedBox(width: 6),
                  action,
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildDivider() {
    return const Divider(height: 10, thickness: 0.5);
  }
}
