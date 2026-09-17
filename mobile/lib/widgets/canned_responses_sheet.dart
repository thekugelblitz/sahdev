import 'package:flutter/material.dart';

class CannedResponsesSheet extends StatefulWidget {
  final List<dynamic> responses;
  final ValueChanged<String> onSelect;

  const CannedResponsesSheet({
    super.key,
    required this.responses,
    required this.onSelect,
  });

  @override
  State<CannedResponsesSheet> createState() => _CannedResponsesSheetState();
}

class _CannedResponsesSheetState extends State<CannedResponsesSheet> {
  String _filter = '';

  @override
  Widget build(BuildContext context) {
    final filtered = widget.responses.where((r) {
      if (_filter.isEmpty) return true;
      final q = _filter.toLowerCase();
      final title = (r['title'] ?? '').toString().toLowerCase();
      final shortcut = (r['shortcut'] ?? '').toString().toLowerCase();
      final text = (r['text'] ?? '').toString().toLowerCase();
      return title.contains(q) || shortcut.contains(q) || text.contains(q);
    }).toList();

    return Container(
      height: MediaQuery.of(context).size.height * 0.55,
      padding: const EdgeInsets.only(top: 12),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      child: Column(
        children: [
          // Drag handle
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: const Color(0xFFCBD5E1),
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
                const Text(
                  "Canned Responses",
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: Color(0xFF0F172A),
                  ),
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
              decoration: InputDecoration(
                hintText: "Search macros (/hi, /dns, /ticket)...",
                prefixIcon: const Icon(Icons.search, size: 18),
                contentPadding: const EdgeInsets.symmetric(vertical: 10),
                isDense: true,
                fillColor: const Color(0xFFF1F5F9),
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
                                color: Color(0xFF0F172A),
                              ),
                            ),
                            if (item['shortcut'] != null) ...[
                              const SizedBox(width: 8),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                decoration: BoxDecoration(
                                  color: const Color(0xFFE2E8F0),
                                  borderRadius: BorderRadius.circular(4),
                                ),
                                child: Text(
                                  item['shortcut'],
                                  style: const TextStyle(
                                    fontSize: 11,
                                    fontFamily: 'monospace',
                                    fontWeight: FontWeight.w600,
                                    color: Color(0xFF475569),
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
                              color: Color(0xFF64748B),
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
