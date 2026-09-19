import 'package:flutter/material.dart';

class CannedResponsesSheet extends StatefulWidget {
  final List<dynamic>? responses;
  final ValueChanged<String> onSelect;

  static const List<Map<String, dynamic>> defaultResponses = [
    {
      'id': 1,
      'title': 'Standard Greeting',
      'shortcut': '/hi',
      'text': 'Hello! Thank you for reaching out to support. How may I assist you today?',
    },
    {
      'id': 2,
      'title': 'Investigating Account',
      'shortcut': '/wait',
      'text': 'I am reviewing your account and service configuration right now. Please allow me just 1-2 minutes.',
    },
    {
      'id': 3,
      'title': 'DNS & Propagation',
      'shortcut': '/dns',
      'text': 'DNS changes typically take 1 to 24 hours to propagate globally. You can monitor the status at whatsmydns.net.',
    },
    {
      'id': 4,
      'title': 'Ticket Escalation',
      'shortcut': '/escalate',
      'text': 'I have opened a priority ticket with our engineering team for this. You will receive an email update shortly.',
    },
    {
      'id': 5,
      'title': 'Closing & Follow-up',
      'shortcut': '/bye',
      'text': 'Is there anything else I can help you with today? Thank you for choosing us!',
    },
  ];

  const CannedResponsesSheet({
    super.key,
    this.responses,
    required this.onSelect,
  });

  @override
  State<CannedResponsesSheet> createState() => _CannedResponsesSheetState();
}

class _CannedResponsesSheetState extends State<CannedResponsesSheet> {
  String _filter = '';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

    final sourceList = (widget.responses != null && widget.responses!.isNotEmpty)
        ? widget.responses!
        : CannedResponsesSheet.defaultResponses;

    final filtered = sourceList.where((r) {
      if (_filter.isEmpty) return true;
      final q = _filter.toLowerCase();
      final title = (r['title'] ?? '').toString().toLowerCase();
      final shortcut = (r['shortcut'] ?? '').toString().toLowerCase();
      final text = (r['text'] ?? '').toString().toLowerCase();
      return title.contains(q) || shortcut.contains(q) || text.contains(q);
    }).toList();

    return Container(
      height: MediaQuery.of(context).size.height * 0.6,
      padding: const EdgeInsets.only(top: 12),
      decoration: BoxDecoration(
        color: isAmoled ? const Color(0xFF090D17) : theme.cardColor,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
        border: Border.all(color: theme.dividerColor),
      ),
      child: Column(
        children: [
          // Drag handle
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: Colors.grey.shade600,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(height: 12),

          // Header
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    Icon(Icons.bolt, color: theme.colorScheme.primary, size: 20),
                    const SizedBox(width: 8),
                    const Text(
                      "Canned Quick Responses",
                      style: TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
                IconButton(
                  icon: const Icon(Icons.close, size: 20),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),

          // Search bar
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: TextField(
              decoration: const InputDecoration(
                hintText: "Search macros (/hi, /dns, /ticket)...",
                prefixIcon: Icon(Icons.search, size: 18),
                contentPadding: EdgeInsets.symmetric(vertical: 10),
                isDense: true,
              ),
              onChanged: (val) => setState(() => _filter = val),
            ),
          ),

          // List
          Expanded(
            child: filtered.isEmpty
                ? const Center(
                    child: Text(
                      "No matching canned responses",
                      style: TextStyle(color: Color(0xFF94A3B8)),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                    itemCount: filtered.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (ctx, i) {
                      final item = filtered[i];
                      return ListTile(
                        contentPadding: const EdgeInsets.symmetric(vertical: 4, horizontal: 8),
                        title: Row(
                          children: [
                            Text(
                              item['title'] ?? '',
                              style: const TextStyle(
                                fontWeight: FontWeight.w600,
                                fontSize: 14,
                              ),
                            ),
                            if (item['shortcut'] != null) ...[
                              const SizedBox(width: 8),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                decoration: BoxDecoration(
                                  color: theme.colorScheme.primary.withOpacity(0.15),
                                  borderRadius: BorderRadius.circular(4),
                                ),
                                child: Text(
                                  item['shortcut'],
                                  style: TextStyle(
                                    fontSize: 11,
                                    fontFamily: 'monospace',
                                    fontWeight: FontWeight.w700,
                                    color: theme.colorScheme.primary,
                                  ),
                                ),
                              ),
                            ],
                          ],
                        ),
                        subtitle: Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Text(
                            item['text'] ?? '',
                            style: const TextStyle(
                              fontSize: 12.5,
                              color: Colors.grey,
                            ),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        onTap: () {
                          Navigator.pop(context);
                          widget.onSelect(item['text'] ?? '');
                        },
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}
