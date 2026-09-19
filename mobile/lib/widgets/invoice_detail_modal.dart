import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../services/api_service.dart';

class InvoiceDetailModal extends StatefulWidget {
  final int invoiceId;
  final String baseUrl;
  final String token;
  final VoidCallback? onInvoiceUpdated;

  const InvoiceDetailModal({
    super.key,
    required this.invoiceId,
    required this.baseUrl,
    required this.token,
    this.onInvoiceUpdated,
  });

  static void show(
    BuildContext context, {
    required int invoiceId,
    required String baseUrl,
    required String token,
    VoidCallback? onInvoiceUpdated,
  }) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => InvoiceDetailModal(
        invoiceId: invoiceId,
        baseUrl: baseUrl,
        token: token,
        onInvoiceUpdated: onInvoiceUpdated,
      ),
    );
  }

  @override
  State<InvoiceDetailModal> createState() => _InvoiceDetailModalState();
}

class _InvoiceDetailModalState extends State<InvoiceDetailModal> {
  final ApiService _api = ApiService();
  bool _isLoading = true;
  bool _isActionInProgress = false;
  String? _errorMessage;
  Map<String, dynamic>? _invoiceData;

  @override
  void initState() {
    super.initState();
    _fetchDetails();
  }

  Future<void> _fetchDetails() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final res = await _api.getInvoiceDetails(
        baseUrl: widget.baseUrl,
        token: widget.token,
        invoiceId: widget.invoiceId,
      );

      if (res.success && res.data != null && mounted) {
        setState(() {
          _invoiceData = res.data!;
          _isLoading = false;
        });
      } else if (mounted) {
        setState(() {
          _errorMessage = res.message ?? 'Failed to load invoice details.';
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _errorMessage = e.toString();
          _isLoading = false;
        });
      }
    }
  }

  Future<void> _markAsPaid() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Mark Invoice as Paid?'),
        content: Text(
          'Are you sure you want to mark Invoice #${_invoiceData?['invoice']?['invoicenum'] ?? widget.invoiceId} as Paid in WHMCS? This will record payment and trigger WHMCS invoice paid notifications.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF10B981),
              foregroundColor: Colors.white,
            ),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Mark Paid'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    setState(() => _isActionInProgress = true);

    try {
      final res = await _api.markInvoicePaid(
        baseUrl: widget.baseUrl,
        token: widget.token,
        invoiceId: widget.invoiceId,
      );

      if (res.success && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.message ?? 'Invoice marked as Paid successfully!'),
            backgroundColor: const Color(0xFF10B981),
            behavior: SnackBarBehavior.floating,
          ),
        );
        widget.onInvoiceUpdated?.call();
        _fetchDetails();
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.message ?? 'Failed to update invoice status.'),
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
      if (mounted) setState(() => _isActionInProgress = false);
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

  Color _getStatusColor(String status) {
    switch (status.toLowerCase()) {
      case 'paid':
        return const Color(0xFF10B981);
      case 'unpaid':
        return const Color(0xFFEF4444);
      case 'cancelled':
        return const Color(0xFF6B7280);
      case 'refunded':
        return const Color(0xFF8B5CF6);
      case 'collections':
        return const Color(0xFFF59E0B);
      default:
        return const Color(0xFF3B82F6);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

    final invoice = _invoiceData?['invoice'] as Map<String, dynamic>?;
    final items = (_invoiceData?['items'] as List<dynamic>?) ?? [];

    final status = invoice?['status']?.toString() ?? 'Unpaid';
    final isPaid = status.toLowerCase() == 'paid';
    final invoiceNum = invoice?['invoicenum']?.toString() ?? '${widget.invoiceId}';
    final clientName = invoice?['client_name']?.toString() ?? 'Client';
    final total = invoice?['total']?.toString() ?? '0.00';
    final subtotal = invoice?['subtotal']?.toString() ?? '0.00';
    final tax = invoice?['tax']?.toString() ?? '0.00';
    final credit = invoice?['credit']?.toString() ?? '0.00';
    final date = invoice?['date']?.toString() ?? '—';
    final dueDate = invoice?['due_date']?.toString() ?? (invoice?['duedate']?.toString() ?? '—');
    final paymentMethod = invoice?['payment_method']?.toString() ?? (invoice?['paymentmethod']?.toString() ?? '—');
    final paidDate = invoice?['date_paid']?.toString() ?? (invoice?['datepaid']?.toString() ?? '');
    final statusColor = _getStatusColor(status);

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
                  children: [
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: statusColor.withOpacity(0.15),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Icon(Icons.receipt_long_rounded, color: statusColor, size: 26),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Text(
                                'Invoice #$invoiceNum',
                                style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
                              ),
                              const SizedBox(width: 8),
                              IconButton(
                                icon: const Icon(Icons.copy, size: 16),
                                constraints: const BoxConstraints(),
                                padding: EdgeInsets.zero,
                                onPressed: () => _copyToClipboard(invoiceNum, 'Invoice #'),
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
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
                              Expanded(
                                child: Text(
                                  clientName,
                                  style: const TextStyle(fontSize: 12, color: Colors.grey),
                                  overflow: TextOverflow.ellipsis,
                                ),
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

              // Content
              Expanded(
                child: _isLoading
                    ? const Center(child: CircularProgressIndicator())
                    : _errorMessage != null
                        ? Center(
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                const Icon(Icons.error_outline, size: 40, color: Colors.redAccent),
                                const SizedBox(height: 8),
                                Text(_errorMessage!),
                                const SizedBox(height: 8),
                                ElevatedButton(onPressed: _fetchDetails, child: const Text('Retry')),
                              ],
                            ),
                          )
                        : ListView(
                            controller: scrollController,
                            padding: const EdgeInsets.all(18),
                            children: [
                              // Total Banner
                              Container(
                                padding: const EdgeInsets.all(16),
                                decoration: BoxDecoration(
                                  color: isAmoled ? const Color(0xFF111726) : const Color(0xFFF8FAFC),
                                  borderRadius: BorderRadius.circular(14),
                                  border: Border.all(color: theme.dividerColor),
                                ),
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        const Text('TOTAL AMOUNT DUE',
                                            style: TextStyle(fontSize: 10, color: Colors.grey, fontWeight: FontWeight.bold)),
                                        const SizedBox(height: 4),
                                        Text(
                                          '\$$total',
                                          style: TextStyle(
                                            fontSize: 26,
                                            fontWeight: FontWeight.w900,
                                            color: isPaid ? const Color(0xFF10B981) : const Color(0xFFEF4444),
                                          ),
                                        ),
                                      ],
                                    ),
                                    if (!isPaid)
                                      ElevatedButton.icon(
                                        icon: _isActionInProgress
                                            ? const SizedBox(
                                                width: 16,
                                                height: 16,
                                                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                              )
                                            : const Icon(Icons.check_circle_outline, size: 16),
                                        label: const Text('Mark Paid'),
                                        style: ElevatedButton.styleFrom(
                                          backgroundColor: const Color(0xFF10B981),
                                          foregroundColor: Colors.white,
                                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                                        ),
                                        onPressed: _isActionInProgress ? null : _markAsPaid,
                                      ),
                                  ],
                                ),
                              ),

                              const SizedBox(height: 16),

                              // Key Dates and Payment Info
                              Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: isAmoled ? const Color(0xFF10141E) : Colors.grey.shade50,
                                  borderRadius: BorderRadius.circular(12),
                                  border: Border.all(color: theme.dividerColor),
                                ),
                                child: Column(
                                  children: [
                                    _buildDetailRow('Invoice Date', date),
                                    const Divider(height: 8, thickness: 0.5),
                                    _buildDetailRow('Due Date', dueDate, isBold: true),
                                    if (paidDate.isNotEmpty && paidDate != '0000-00-00 00:00:00' && paidDate != '—') ...[
                                      const Divider(height: 8, thickness: 0.5),
                                      _buildDetailRow('Date Paid', paidDate),
                                    ],
                                    const Divider(height: 8, thickness: 0.5),
                                    _buildDetailRow('Payment Method', paymentMethod),
                                  ],
                                ),
                              ),

                              const SizedBox(height: 18),

                              // Line items header
                              Row(
                                children: [
                                  const Icon(Icons.format_list_bulleted, size: 16, color: Color(0xFF8B5CF6)),
                                  const SizedBox(width: 8),
                                  Text(
                                    'Line Items (${items.length})',
                                    style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Color(0xFF8B5CF6)),
                                  ),
                                ],
                              ),
                              const SizedBox(height: 8),

                              // Line Items List
                              Container(
                                decoration: BoxDecoration(
                                  color: isAmoled ? const Color(0xFF10141E) : Colors.grey.shade50,
                                  borderRadius: BorderRadius.circular(12),
                                  border: Border.all(color: theme.dividerColor),
                                ),
                                child: Column(
                                  children: items.isEmpty
                                      ? [
                                          const Padding(
                                            padding: EdgeInsets.all(14),
                                            child: Text('No itemized lines recorded.', style: TextStyle(color: Colors.grey)),
                                          )
                                        ]
                                      : items.map((it) {
                                          final itMap = Map<String, dynamic>.from(it as Map);
                                          final desc = itMap['description']?.toString() ?? 'Item';
                                          final amt = itMap['amount']?.toString() ?? '0.00';

                                          return Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                                            decoration: BoxDecoration(
                                              border: Border(bottom: BorderSide(color: theme.dividerColor, width: 0.5)),
                                            ),
                                            child: Row(
                                              crossAxisAlignment: CrossAxisAlignment.start,
                                              children: [
                                                Expanded(
                                                  child: Text(
                                                    desc,
                                                    style: const TextStyle(fontSize: 13, height: 1.3),
                                                  ),
                                                ),
                                                const SizedBox(width: 12),
                                                Text(
                                                  '\$$amt',
                                                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                                                ),
                                              ],
                                            ),
                                          );
                                        }).toList(),
                                ),
                              ),

                              const SizedBox(height: 14),

                              // Subtotal, Tax, Credit breakdown
                              Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: isAmoled ? const Color(0xFF10141E) : Colors.grey.shade50,
                                  borderRadius: BorderRadius.circular(12),
                                  border: Border.all(color: theme.dividerColor),
                                ),
                                child: Column(
                                  children: [
                                    _buildDetailRow('Subtotal', '\$$subtotal'),
                                    if (tax != '0.00') ...[
                                      const Divider(height: 8, thickness: 0.5),
                                      _buildDetailRow('Tax', '\$$tax'),
                                    ],
                                    if (credit != '0.00') ...[
                                      const Divider(height: 8, thickness: 0.5),
                                      _buildDetailRow('Credit Applied', '-\$$credit', isBold: true),
                                    ],
                                    const Divider(height: 8, thickness: 0.5),
                                    _buildDetailRow('Grand Total', '\$$total', isBold: true),
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

  Widget _buildDetailRow(String label, String value, {bool isBold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(fontSize: 13, color: Colors.grey)),
          Text(
            value,
            style: TextStyle(fontSize: 13, fontWeight: isBold ? FontWeight.bold : FontWeight.w500),
          ),
        ],
      ),
    );
  }
}
