import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

class ServiceDetailModal extends StatelessWidget {
  final Map<String, dynamic> service;
  final VoidCallback? onViewClient;

  const ServiceDetailModal({
    super.key,
    required this.service,
    this.onViewClient,
  });

  static void show(BuildContext context, Map<String, dynamic> service, {VoidCallback? onViewClient}) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => ServiceDetailModal(service: service, onViewClient: onViewClient),
    );
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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

    final productName = service['product_name']?.toString() ?? 'Hosting Service';
    final domain = service['domain']?.toString() ?? '—';
    final status = service['status']?.toString() ?? (service['domainstatus']?.toString() ?? 'Active');
    final clientName = service['client_name']?.toString() ?? 'Client';
    final clientEmail = service['client_email']?.toString() ?? '';
    final price = service['price']?.toString() ?? (service['amount']?.toString() ?? '0.00');
    final cycle = service['billing_cycle']?.toString() ?? (service['billingcycle']?.toString() ?? 'Monthly');
    final nextDue = service['next_due_date']?.toString() ?? (service['nextduedate']?.toString() ?? '—');
    final regDate = service['reg_date']?.toString() ?? (service['regdate']?.toString() ?? '—');
    final paymentMethod = service['payment_method']?.toString() ?? (service['paymentmethod']?.toString() ?? '—');
    final dedicatedIp = service['dedicated_ip']?.toString() ?? (service['dedicatedip']?.toString() ?? '');
    final username = service['username']?.toString() ?? '';
    final serverName = service['server_name']?.toString() ?? '';

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

    return DraggableScrollableSheet(
      initialChildSize: 0.8,
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
                                const Text('DOMAIN NAME', style: TextStyle(fontSize: 10, color: Colors.grey, fontWeight: FontWeight.bold)),
                                const SizedBox(height: 2),
                                Text(
                                  domain,
                                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Color(0xFF3B82F6)),
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

                    const SizedBox(height: 16),

                    // Quick Actions
                    Row(
                      children: [
                        if (dedicatedIp.isNotEmpty)
                          Expanded(
                            child: OutlinedButton.icon(
                              icon: const Icon(Icons.copy, size: 15),
                              label: const Text('Copy IP', style: TextStyle(fontSize: 12)),
                              onPressed: () => _copyToClipboard(context, dedicatedIp, 'IP Address'),
                            ),
                          ),
                        if (dedicatedIp.isNotEmpty && onViewClient != null) const SizedBox(width: 10),
                        if (onViewClient != null)
                          Expanded(
                            child: ElevatedButton.icon(
                              icon: const Icon(Icons.person, size: 15),
                              label: const Text('Client Profile', style: TextStyle(fontSize: 12)),
                              onPressed: () {
                                Navigator.of(context).pop();
                                onViewClient!();
                              },
                            ),
                          ),
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
