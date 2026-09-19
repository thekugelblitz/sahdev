import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../widgets/invoice_detail_modal.dart';
import 'clients_screen.dart';

class InvoicesScreen extends StatefulWidget {
  final String? initialFilter;
  final String? initialSearch;

  const InvoicesScreen({
    super.key,
    this.initialFilter,
    this.initialSearch,
  });

  @override
  State<InvoicesScreen> createState() => _InvoicesScreenState();
}

class _InvoicesScreenState extends State<InvoicesScreen> {
  final ApiService _api = ApiService();
  final TextEditingController _searchController = TextEditingController();

  List<Map<String, dynamic>> _invoices = [];
  bool _isLoading = false;
  String? _errorMessage;
  late String _statusFilter;
  int _page = 1;
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _statusFilter = widget.initialFilter ?? 'all';
    if (widget.initialSearch != null && widget.initialSearch!.isNotEmpty) {
      _searchController.text = widget.initialSearch!;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadInvoices();
    });
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _loadInvoices() async {
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final res = await _api.getInvoices(
        baseUrl: auth.baseUrl!,
        token: auth.token!,
        search: _searchController.text.trim(),
        status: _statusFilter,
        page: _page,
      );

      if (res.success && res.data != null) {
        final list = res.data!['invoices'] as List<dynamic>? ?? [];
        setState(() {
          _invoices = list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
          _total = (res.data!['total'] as num?)?.toInt() ?? _invoices.length;
        });
      } else {
        setState(() {
          _errorMessage = res.message ?? 'Failed to load invoices.';
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

  Future<void> _markAsPaid(int invoiceId, String invoiceNum) async {
    HapticFeedback.mediumImpact();
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Mark Invoice as Paid?'),
        content: Text('Are you sure you want to mark Invoice #$invoiceNum as Paid in WHMCS? This records the payment immediately.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF10B981), foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Confirm Paid'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      final res = await _api.markInvoicePaid(
        baseUrl: auth.baseUrl!,
        token: auth.token!,
        invoiceId: invoiceId,
      );

      if (res.success && mounted) {
        HapticFeedback.lightImpact();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Invoice #$invoiceNum marked as Paid!'),
            backgroundColor: const Color(0xFF10B981),
            behavior: SnackBarBehavior.floating,
          ),
        );
        _loadInvoices();
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res.message ?? 'Failed to mark invoice as paid.'),
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
    }
  }

  void _openInvoiceDetail(int invoiceId) {
    final auth = context.read<AuthProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    InvoiceDetailModal.show(
      context,
      invoiceId: invoiceId,
      baseUrl: auth.baseUrl!,
      token: auth.token!,
      onInvoiceUpdated: _loadInvoices,
    );
  }

  void _showClientProfile(int clientId, String clientName) {
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
      },
      onClientUpdated: _loadInvoices,
    );
  }

  void _copyToClipboard(String text, String label) {
    Clipboard.setData(ClipboardData(text: text));
    HapticFeedback.selectionClick();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('$label copied to clipboard'),
        duration: const Duration(seconds: 1),
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  Widget _buildStatusChip(String value, String label, {Color? activeColor}) {
    final isSelected = _statusFilter.toLowerCase() == value.toLowerCase();
    return FilterChip(
      label: Text(label, style: const TextStyle(fontSize: 12)),
      selected: isSelected,
      selectedColor: (activeColor ?? Theme.of(context).colorScheme.primary).withOpacity(0.2),
      checkmarkColor: activeColor ?? Theme.of(context).colorScheme.primary,
      onSelected: (selected) {
        if (selected) {
          HapticFeedback.selectionClick();
          setState(() {
            _statusFilter = value;
            _page = 1;
          });
          _loadInvoices();
        }
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

    // Calculate quick stats from loaded invoices
    double unpaidTotal = 0;
    int unpaidCount = 0;
    int paidCount = 0;
    for (final inv in _invoices) {
      final st = (inv['status'] ?? '').toString().toLowerCase();
      final tot = double.tryParse((inv['total'] ?? '0').toString().replaceAll(',', '')) ?? 0;
      if (st == 'unpaid') {
        unpaidTotal += tot;
        unpaidCount++;
      } else if (st == 'paid') {
        paidCount++;
      }
    }

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
              child: Icon(Icons.receipt_long_outlined, color: theme.colorScheme.primary, size: 20),
            ),
            const SizedBox(width: 10),
            const Text('WHMCS Invoices', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh Invoices',
            onPressed: () {
              HapticFeedback.selectionClick();
              _loadInvoices();
            },
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(102),
          child: Column(
            children: [
              // Search Input
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: SizedBox(
                  height: 40,
                  child: TextField(
                    controller: _searchController,
                    style: const TextStyle(fontSize: 14),
                    decoration: InputDecoration(
                      hintText: 'Search invoice #, client, amount...',
                      prefixIcon: const Icon(Icons.search, size: 20),
                      suffixIcon: _searchController.text.isNotEmpty
                          ? IconButton(
                              icon: const Icon(Icons.clear, size: 18),
                              onPressed: () {
                                _searchController.clear();
                                _loadInvoices();
                              },
                            )
                          : null,
                      contentPadding: const EdgeInsets.symmetric(horizontal: 12),
                    ),
                    onSubmitted: (_) {
                      HapticFeedback.selectionClick();
                      _loadInvoices();
                    },
                  ),
                ),
              ),
              const SizedBox(height: 6),
              // Filter Chips
              SizedBox(
                height: 38,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  children: [
                    _buildStatusChip('all', 'All Invoices'),
                    const SizedBox(width: 8),
                    _buildStatusChip('Unpaid', 'Unpaid', activeColor: const Color(0xFFF59E0B)),
                    const SizedBox(width: 8),
                    _buildStatusChip('Paid', 'Paid', activeColor: const Color(0xFF10B981)),
                    const SizedBox(width: 8),
                    _buildStatusChip('Overdue', 'Overdue', activeColor: const Color(0xFFEF4444)),
                    const SizedBox(width: 8),
                    _buildStatusChip('Cancelled', 'Cancelled', activeColor: const Color(0xFF6B7280)),
                  ],
                ),
              ),
              const SizedBox(height: 6),
            ],
          ),
        ),
      ),
      body: _isLoading && _invoices.isEmpty
          ? const Center(child: CircularProgressIndicator())
          : _errorMessage != null && _invoices.isEmpty
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.error_outline, size: 48, color: Colors.redAccent),
                      const SizedBox(height: 12),
                      Text(_errorMessage!, style: const TextStyle(color: Colors.grey)),
                      const SizedBox(height: 12),
                      ElevatedButton(onPressed: _loadInvoices, child: const Text('Retry')),
                    ],
                  ),
                )
              : _invoices.isEmpty
                  ? Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.receipt_outlined, size: 54, color: Colors.grey.shade400),
                          const SizedBox(height: 12),
                          const Text(
                            'No invoices found matching criteria',
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600, color: Colors.grey),
                          ),
                          const SizedBox(height: 8),
                          if (_searchController.text.isNotEmpty || _statusFilter != 'all')
                            TextButton.icon(
                              icon: const Icon(Icons.clear, size: 16),
                              label: const Text('Reset Filters'),
                              onPressed: () {
                                setState(() {
                                  _searchController.clear();
                                  _statusFilter = 'all';
                                });
                                _loadInvoices();
                              },
                            ),
                        ],
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _loadInvoices,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(12),
                        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                        itemCount: _invoices.length + 1,
                        itemBuilder: (context, index) {
                          if (index == 0) {
                            return _buildSummaryCard(unpaidTotal, unpaidCount, paidCount, theme, isAmoled);
                          }
                          final inv = _invoices[index - 1];
                          return _buildInvoiceCard(inv, theme, isAmoled);
                        },
                      ),
                    ),
    );
  }

  Widget _buildSummaryCard(double unpaidTotal, int unpaidCount, int paidCount, ThemeData theme, bool isAmoled) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: isAmoled ? const Color(0xFF0F172A) : const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: theme.dividerColor),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('UNPAID TOTAL', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.grey)),
                const SizedBox(height: 2),
                Text(
                  '\$${unpaidTotal.toStringAsFixed(2)}',
                  style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: Color(0xFFEF4444)),
                ),
                Text('$unpaidCount invoices pending', style: const TextStyle(fontSize: 11, color: Colors.grey)),
              ],
            ),
          ),
          Container(width: 1, height: 36, color: theme.dividerColor),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('LOADED SUMMARY', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.grey)),
                const SizedBox(height: 2),
                Text(
                  '$_total Invoices',
                  style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: theme.colorScheme.primary),
                ),
                Text('$paidCount paid in current view', style: const TextStyle(fontSize: 11, color: Colors.grey)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildInvoiceCard(Map<String, dynamic> inv, ThemeData theme, bool isAmoled) {
    final id = (inv['id'] as num?)?.toInt() ?? 0;
    final invoiceNum = inv['invoice_num']?.toString() ?? '$id';
    final clientName = inv['client_name']?.toString() ?? 'Client';
    final clientId = (inv['client_id'] as num?)?.toInt();
    final total = inv['total']?.toString() ?? '0.00';
    final date = inv['date']?.toString() ?? '';
    final dueDate = inv['due_date']?.toString() ?? '';
    final status = inv['status']?.toString() ?? 'Unpaid';
    final paymentMethod = inv['payment_method']?.toString() ?? '—';

    Color statusColor;
    switch (status.toLowerCase()) {
      case 'paid':
        statusColor = const Color(0xFF10B981);
        break;
      case 'unpaid':
        statusColor = const Color(0xFFF59E0B);
        break;
      case 'overdue':
        statusColor = const Color(0xFFEF4444);
        break;
      case 'cancelled':
        statusColor = const Color(0xFF6B7280);
        break;
      default:
        statusColor = const Color(0xFF8B5CF6);
    }

    final isUnpaidOrOverdue = status.toLowerCase() == 'unpaid' || status.toLowerCase() == 'overdue';

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () => _openInvoiceDetail(id),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Header Row: Invoice # + Status Badge
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(6),
                        decoration: BoxDecoration(
                          color: statusColor.withOpacity(0.12),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Icon(Icons.receipt_outlined, color: statusColor, size: 18),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        '#$invoiceNum',
                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
                      ),
                      IconButton(
                        icon: const Icon(Icons.copy, size: 14, color: Colors.grey),
                        tooltip: 'Copy invoice number',
                        constraints: const BoxConstraints(),
                        padding: const EdgeInsets.only(left: 4),
                        onPressed: () => _copyToClipboard(invoiceNum, 'Invoice #'),
                      ),
                    ],
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: statusColor.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: statusColor.withOpacity(0.4)),
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
                ],
              ),

              const SizedBox(height: 10),

              // Client & Amount Row
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: InkWell(
                      onTap: (clientId != null && clientId > 0)
                          ? () => _showClientProfile(clientId, clientName)
                          : null,
                      child: Row(
                        children: [
                          const Icon(Icons.person_outline, size: 15, color: Colors.grey),
                          const SizedBox(width: 6),
                          Flexible(
                            child: Text(
                              clientName,
                              style: TextStyle(
                                fontSize: 13,
                                fontWeight: FontWeight.w600,
                                color: (clientId != null && clientId > 0) ? theme.colorScheme.primary : null,
                                decoration: (clientId != null && clientId > 0) ? TextDecoration.underline : null,
                              ),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  Text(
                    '\$$total',
                    style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
                  ),
                ],
              ),

              const SizedBox(height: 8),

              // Dates & Payment Method Row
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.calendar_today_outlined, size: 13, color: Colors.grey),
                      const SizedBox(width: 4),
                      Text(
                        'Due: $dueDate',
                        style: TextStyle(
                          fontSize: 11.5,
                          color: isUnpaidOrOverdue ? const Color(0xFFEF4444) : Colors.grey,
                          fontWeight: isUnpaidOrOverdue ? FontWeight.bold : FontWeight.normal,
                        ),
                      ),
                      if (date.isNotEmpty) ...[
                        const SizedBox(width: 8),
                        Text('(Created: $date)', style: const TextStyle(fontSize: 10.5, color: Colors.grey)),
                      ],
                    ],
                  ),
                  if (paymentMethod.isNotEmpty && paymentMethod != '—')
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                      decoration: BoxDecoration(
                        color: Colors.grey.withOpacity(0.12),
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(
                        paymentMethod,
                        style: const TextStyle(fontSize: 10.5, color: Colors.grey),
                      ),
                    ),
                ],
              ),

              // Quick Action Row for Unpaid
              if (isUnpaidOrOverdue) ...[
                const SizedBox(height: 10),
                const Divider(height: 1),
                const SizedBox(height: 8),
                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    OutlinedButton.icon(
                      icon: const Icon(Icons.visibility_outlined, size: 14),
                      label: const Text('Details', style: TextStyle(fontSize: 12)),
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        minimumSize: const Size(0, 32),
                      ),
                      onPressed: () => _openInvoiceDetail(id),
                    ),
                    const SizedBox(width: 8),
                    ElevatedButton.icon(
                      icon: const Icon(Icons.check_circle_outline, size: 14),
                      label: const Text('Mark Paid', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF10B981),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        minimumSize: const Size(0, 32),
                      ),
                      onPressed: () => _markAsPaid(id, invoiceNum),
                    ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
