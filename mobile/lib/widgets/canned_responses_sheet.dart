import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../providers/chat_provider.dart';
import '../services/api_service.dart';

class CannedResponsesSheet extends StatefulWidget {
  final List<dynamic>? responses;
  final ValueChanged<String> onSelect;
  final VoidCallback? onUpdated;

  static const List<Map<String, dynamic>> defaultResponses = [
    {
      'id': 1,
      'title': 'Standard Greeting',
      'shortcut': '/hi',
      'content': 'Hello! Thank you for reaching out to support. How may I assist you today?',
    },
    {
      'id': 2,
      'title': 'Investigating Account',
      'shortcut': '/wait',
      'content': 'I am reviewing your account and service configuration right now. Please allow me just 1-2 minutes.',
    },
    {
      'id': 3,
      'title': 'DNS & Propagation',
      'shortcut': '/dns',
      'content': 'DNS changes typically take 1 to 24 hours to propagate globally. You can monitor the status at whatsmydns.net.',
    },
    {
      'id': 4,
      'title': 'Ticket Escalation',
      'shortcut': '/escalate',
      'content': 'I have opened a priority ticket with our engineering team for this. You will receive an email update shortly.',
    },
    {
      'id': 5,
      'title': 'Closing & Follow-up',
      'shortcut': '/bye',
      'content': 'Is there anything else I can help you with today? Thank you for choosing us!',
    },
  ];

  const CannedResponsesSheet({
    super.key,
    this.responses,
    required this.onSelect,
    this.onUpdated,
  });

  @override
  State<CannedResponsesSheet> createState() => _CannedResponsesSheetState();
}

class _CannedResponsesSheetState extends State<CannedResponsesSheet> {
  final ApiService _api = ApiService();
  String _filter = '';
  late List<dynamic> _items;

  @override
  void initState() {
    super.initState();
    _initItems();
  }

  void _initItems() {
    if (widget.responses != null && widget.responses!.isNotEmpty) {
      _items = List<dynamic>.from(widget.responses!);
    } else {
      _items = List<dynamic>.from(CannedResponsesSheet.defaultResponses);
    }
  }

  void _showCreateEditDialog({Map<String, dynamic>? existing}) {
    final auth = Provider.of<AuthProvider>(context, listen: false);
    final isEditing = existing != null;
    final titleController = TextEditingController(text: existing?['title']?.toString() ?? '');
    final shortcutController = TextEditingController(text: existing?['shortcut']?.toString() ?? '/');
    final contentController = TextEditingController(
      text: (existing?['content'] ?? existing?['text'])?.toString() ?? '',
    );
    final categoryController = TextEditingController(text: existing?['category']?.toString() ?? 'General');

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isEditing ? 'Edit Canned Response' : 'New Canned Response',
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: titleController,
                decoration: const InputDecoration(
                  labelText: 'Title / Label',
                  hintText: 'e.g. Standard Greeting',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: shortcutController,
                decoration: const InputDecoration(
                  labelText: 'Shortcut',
                  hintText: 'e.g. /hi, /wait, /dns',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: categoryController,
                decoration: const InputDecoration(
                  labelText: 'Category',
                  hintText: 'e.g. General, Hosting, Billing',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: contentController,
                maxLines: 4,
                decoration: const InputDecoration(
                  labelText: 'Response Text',
                  hintText: 'The full text to insert when applied...',
                  border: OutlineInputBorder(),
                ),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(
            onPressed: () async {
              final title = titleController.text.trim();
              var shortcut = shortcutController.text.trim();
              final content = contentController.text.trim();
              final category = categoryController.text.trim();

              if (title.isEmpty || content.isEmpty) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Title and Response Text are required.')),
                );
                return;
              }

              if (shortcut.isNotEmpty && !shortcut.startsWith('/')) {
                shortcut = '/$shortcut';
              }

              Navigator.pop(ctx);

              if (auth.baseUrl != null && auth.token != null) {
                if (isEditing) {
                  final id = int.tryParse(existing['id'].toString()) ?? 0;
                  final res = await _api.updateCannedResponse(
                    baseUrl: auth.baseUrl!,
                    token: auth.token!,
                    id: id,
                    title: title,
                    shortcut: shortcut,
                    content: content,
                    category: category.isNotEmpty ? category : 'General',
                  );
                  if (res.success && mounted) {
                    setState(() {
                      final idx = _items.indexWhere((e) => (e['id']?.toString() ?? '') == id.toString());
                      if (idx != -1) {
                        _items[idx] = {
                          'id': id,
                          'title': title,
                          'shortcut': shortcut,
                          'content': content,
                          'category': category,
                        };
                      }
                    });
                    _syncWithChatProvider(auth);
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Canned response updated successfully')),
                    );
                  }
                } else {
                  final res = await _api.createCannedResponse(
                    baseUrl: auth.baseUrl!,
                    token: auth.token!,
                    title: title,
                    shortcut: shortcut,
                    content: content,
                    category: category.isNotEmpty ? category : 'General',
                  );
                  if (res.success && mounted) {
                    final newId = res.data?['id'] ?? DateTime.now().millisecondsSinceEpoch;
                    setState(() {
                      _items.insert(0, {
                        'id': newId,
                        'title': title,
                        'shortcut': shortcut,
                        'content': content,
                        'category': category,
                      });
                    });
                    _syncWithChatProvider(auth);
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Canned response created successfully')),
                    );
                  }
                }
              }
            },
            child: Text(isEditing ? 'Save' : 'Create'),
          ),
        ],
      ),
    );
  }

  void _confirmDelete(Map<String, dynamic> item) {
    final auth = Provider.of<AuthProvider>(context, listen: false);
    final id = int.tryParse(item['id']?.toString() ?? '') ?? 0;
    final title = item['title']?.toString() ?? 'this response';

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete Canned Response', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        content: Text('Are you sure you want to delete "$title"?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFEF4444), foregroundColor: Colors.white),
            onPressed: () async {
              Navigator.pop(ctx);
              if (auth.baseUrl != null && auth.token != null && id > 0) {
                final res = await _api.deleteCannedResponse(
                  baseUrl: auth.baseUrl!,
                  token: auth.token!,
                  id: id,
                );
                if (res.success && mounted) {
                  setState(() {
                    _items.removeWhere((e) => (e['id']?.toString() ?? '') == id.toString());
                  });
                  _syncWithChatProvider(auth);
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Canned response deleted')),
                  );
                }
              }
            },
            child: const Text('Delete'),
          ),
        ],
      ),
    );
  }

  void _syncWithChatProvider(AuthProvider auth) {
    try {
      final chat = Provider.of<ChatProvider>(context, listen: false);
      chat.loadCannedResponses(baseUrl: auth.baseUrl!, token: auth.token!);
    } catch (_) {}
    widget.onUpdated?.call();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;

    final filtered = _items.where((r) {
      if (_filter.isEmpty) return true;
      final q = _filter.toLowerCase();
      final title = (r['title'] ?? '').toString().toLowerCase();
      final shortcut = (r['shortcut'] ?? '').toString().toLowerCase();
      final text = (r['content'] ?? r['text'] ?? '').toString().toLowerCase();
      return title.contains(q) || shortcut.contains(q) || text.contains(q);
    }).toList();

    return Container(
      height: MediaQuery.of(context).size.height * 0.7,
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

          // Header with Create Button
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
                Row(
                  children: [
                    IconButton(
                      icon: const Icon(Icons.add_circle_outline, size: 22, color: Color(0xFF10B981)),
                      tooltip: 'New Canned Response',
                      onPressed: () => _showCreateEditDialog(),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close, size: 20),
                      onPressed: () => Navigator.pop(context),
                    ),
                  ],
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
                      final item = filtered[i] is Map<String, dynamic>
                          ? filtered[i] as Map<String, dynamic>
                          : Map<String, dynamic>.from(filtered[i]);

                      final title = item['title']?.toString() ?? '';
                      final shortcut = item['shortcut']?.toString() ?? '';
                      final text = (item['content'] ?? item['text'])?.toString() ?? '';

                      return ListTile(
                        contentPadding: const EdgeInsets.symmetric(vertical: 2, horizontal: 8),
                        title: Row(
                          children: [
                            Expanded(
                              child: Text(
                                title,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w600,
                                  fontSize: 14,
                                ),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            if (shortcut.isNotEmpty) ...[
                              const SizedBox(width: 8),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                decoration: BoxDecoration(
                                  color: theme.colorScheme.primary.withOpacity(0.15),
                                  borderRadius: BorderRadius.circular(4),
                                ),
                                child: Text(
                                  shortcut,
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
                            text,
                            style: const TextStyle(
                              fontSize: 12.5,
                              color: Colors.grey,
                            ),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            IconButton(
                              icon: const Icon(Icons.edit_outlined, size: 18),
                              tooltip: 'Edit',
                              onPressed: () => _showCreateEditDialog(existing: item),
                            ),
                            IconButton(
                              icon: const Icon(Icons.delete_outline, size: 18, color: Color(0xFFEF4444)),
                              tooltip: 'Delete',
                              onPressed: () => _confirmDelete(item),
                            ),
                          ],
                        ),
                        onTap: () {
                          Navigator.pop(context);
                          widget.onSelect(text);
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
