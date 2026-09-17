import 'package:flutter/material.dart';
import '../config/theme_config.dart';
import '../models/client_profile.dart';

class ClientDetailsSheet extends StatelessWidget {
  final ClientProfile? profile;

  const ClientDetailsSheet({super.key, required this.profile});

  @override
  Widget build(BuildContext context) {
    if (profile == null) {
      return Container(
        height: 250,
        padding: const EdgeInsets.all(24),
        child: const Center(
          child: CircularProgressIndicator(),
        ),
      );
    }

    final client = profile!.client;
    final summary = profile!.summary;
    final services = profile!.services;
    final invoices = profile!.invoices;
    final meta = profile!.visitorMeta;

    return Container(
      height: MediaQuery.of(context).size.height * 0.70,
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      child: Column(
        children: [
          // Drag handle
          Container(
            margin: const EdgeInsets.only(top: 12),
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: const Color(0xFFCBD5E1),
              borderRadius: BorderRadius.circular(2),
            ),
          ),

          // Header
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: profile!.isRegistered ? ThemeConfig.statusOnline : const Color(0xFF64748B),
                    shape: BoxShape.circle,
                  ),
                  child: Center(
                    child: Text(
                      client.name.isNotEmpty ? client.name.substring(0, 1).toUpperCase() : 'V',
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        fontSize: 18,
                      ),
                    ),
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
                              client.name,
                              style: const TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.w700,
                                color: Color(0xFF0F172A),
                              ),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          if (profile!.isRegistered) ...[
                            const SizedBox(width: 6),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                              decoration: BoxDecoration(
                                color: const Color(0xFFDCFCE7),
                                borderRadius: BorderRadius.circular(4),
                              ),
                              child: const Text(
                                "Client",
                                style: TextStyle(
                                  color: Color(0xFF166534),
                                  fontSize: 10,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                          ],
                        ],
                      ),
                      Text(
                        client.email,
                        style: const TextStyle(fontSize: 13, color: Color(0xFF64748B)),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),

          const Divider(height: 1),

          // Body
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // Quick Metric Badges
                Row(
                  children: [
                    _buildStatCard(
                      label: "Active Services",
                      value: summary.servicesCount.toString(),
                      icon: Icons.dns_outlined,
                      color: ThemeConfig.primary,
                    ),
                    const SizedBox(width: 8),
                    _buildStatCard(
                      label: "Unpaid Invoices",
                      value: summary.unpaidInvoices.toString(),
                      icon: Icons.receipt_long_outlined,
                      color: summary.unpaidInvoices > 0 ? ThemeConfig.statusUrgent : const Color(0xFF64748B),
                    ),
                    const SizedBox(width: 8),
                    _buildStatCard(
                      label: "Open Tickets",
                      value: summary.openTicketsCount.toString(),
                      icon: Icons.confirmation_number_outlined,
                      color: summary.openTicketsCount > 0 ? ThemeConfig.statusWarning : const Color(0xFF64748B),
                    ),
                  ],
                ),

                const SizedBox(height: 18),

                // Active Services List
                if (services.isNotEmpty) ...[
                  const Text(
                    "Active Hosting & Domains",
                    style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
                  ),
                  const SizedBox(height: 8),
                  ...services.map((s) => Container(
                        margin: const EdgeInsets.only(bottom: 8),
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF8FAFC),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: const Color(0xFFE2E8F0)),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  s.domain.isNotEmpty ? s.domain : s.productName,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w600,
                                    fontSize: 13,
                                  ),
                                ),
                                Text(
                                  "${s.productName} • Due: ${s.nextDueDate}",
                                  style: const TextStyle(fontSize: 11, color: Color(0xFF64748B)),
                                ),
                              ],
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                              decoration: BoxDecoration(
                                color: s.status == 'Active' ? const Color(0xFFDCFCE7) : const Color(0xFFFEE2E2),
                                borderRadius: BorderRadius.circular(4),
                              ),
                              child: Text(
                                s.status,
                                style: TextStyle(
                                  color: s.status == 'Active' ? const Color(0xFF166534) : const Color(0xFF991B1B),
                                  fontSize: 10,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                          ],
                        ),
                      )),
                  const SizedBox(height: 12),
                ],

                // Unpaid Invoices
                if (invoices.isNotEmpty) ...[
                  const Text(
                    "Unpaid Invoices",
                    style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
                  ),
                  const SizedBox(height: 8),
                  ...invoices.map((inv) => Container(
                        margin: const EdgeInsets.only(bottom: 8),
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: const Color(0xFFFEF2F2),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: const Color(0xFFFECACA)),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(
                              "Invoice #${inv.invoiceNum} (Due: ${inv.dueDate})",
                              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF991B1B)),
                            ),
                            Text(
                              "\$${inv.total}",
                              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF991B1B)),
                            ),
                          ],
                        ),
                      )),
                  const SizedBox(height: 12),
                ],

                // Visitor Meta (for guest visitors)
                if (meta != null) ...[
                  const Text(
                    "Visitor Environment",
                    style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: Color(0xFF0F172A)),
                  ),
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF8FAFC),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: const Color(0xFFE2E8F0)),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _metaRow("IP Address", meta['ip'] ?? 'Unknown'),
                        _metaRow("Source Website", meta['source_domain'] ?? 'WHMCS'),
                        _metaRow("Landing Page", meta['source_page'] ?? '/'),
                        _metaRow("Browser", meta['user_agent'] ?? 'Web/Mobile'),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStatCard({
    required String label,
    required String value,
    required IconData icon,
    required Color color,
  }) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: const Color(0xFFF8FAFC),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: const Color(0xFFE2E8F0)),
        ),
        child: Column(
          children: [
            Icon(icon, size: 18, color: color),
            const SizedBox(height: 4),
            Text(
              value,
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: color),
            ),
            Text(
              label,
              style: const TextStyle(fontSize: 10, color: Color(0xFF64748B)),
              textAlign: TextAlign.center,
              maxLines: 1,
            ),
          ],
        ),
      ),
    );
  }

  Widget _metaRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 100,
            child: Text(
              label,
              style: const TextStyle(fontSize: 11.5, color: Color(0xFF64748B)),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, color: Color(0xFF0F172A)),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}
