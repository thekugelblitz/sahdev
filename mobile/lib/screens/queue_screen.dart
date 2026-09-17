import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config/theme_config.dart';
import '../providers/auth_provider.dart';
import '../providers/queue_provider.dart';
import '../services/background_service.dart';
import '../widgets/session_card.dart';
import 'chat_screen.dart';
import 'settings_screen.dart';

class QueueScreen extends StatefulWidget {
  const QueueScreen({super.key});

  @override
  State<QueueScreen> createState() => _QueueScreenState();
}

class _QueueScreenState extends State<QueueScreen> {
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final auth = Provider.of<AuthProvider>(context, listen: false);
      if (auth.baseUrl != null && auth.token != null) {
        Provider.of<QueueProvider>(context, listen: false).startPolling(
          baseUrl: auth.baseUrl!,
          token: auth.token!,
        );
      }
    });
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  void _openChat(int sessionId, String uuid, String clientName, bool isTakenOver, int? clientId) {
    final queue = Provider.of<QueueProvider>(context, listen: false);
    queue.acknowledgeAlert();
    BackgroundService().silenceCurrentAlert(sessionId);

    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ChatScreen(
          sessionId: sessionId,
          sessionUuid: uuid,
          clientName: clientName,
          initialTakenOver: isTakenOver,
          clientId: clientId,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = Provider.of<AuthProvider>(context);
    final queue = Provider.of<QueueProvider>(context);

    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            Container(
              width: 10,
              height: 10,
              decoration: BoxDecoration(
                color: auth.isOnline ? ThemeConfig.statusOnline : Colors.grey,
                shape: BoxShape.circle,
              ),
            ),
            const SizedBox(width: 8),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  auth.adminUser?.name ?? "Staff Operator",
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
                ),
                Text(
                  auth.isOnline ? "Online & Listening" : "Offline / Muted",
                  style: TextStyle(
                    fontSize: 11,
                    color: auth.isOnline ? const Color(0xFF86EFAC) : const Color(0xFF94A3B8),
                  ),
                ),
              ],
            ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.settings_outlined),
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const SettingsScreen()),
              );
            },
          ),
        ],
      ),
      body: Column(
        children: [
          // Urgent Summon Banner if any customer clicked "Talk to Human"
          if (queue.urgentSummonsCount > 0)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              color: ThemeConfig.statusUrgent,
              child: Row(
                children: [
                  const Icon(Icons.notifications_active, color: Colors.white, size: 20),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      "${queue.urgentSummonsCount} visitor(s) summoned live support!",
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        fontSize: 13,
                      ),
                    ),
                  ),
                  InkWell(
                    onTap: () {
                      BackgroundService().silenceCurrentAlert();
                      queue.acknowledgeAlert();
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(
                          content: Text("Alert sound silenced."),
                          duration: Duration(seconds: 2),
                          behavior: SnackBarBehavior.floating,
                        ),
                      );
                    },
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      margin: const EdgeInsets.only(right: 8),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.25),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: Colors.white.withOpacity(0.5)),
                      ),
                      child: const Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.volume_off, size: 12, color: Colors.white),
                          SizedBox(width: 4),
                          Text(
                            "Silence",
                            style: TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w700,
                              fontSize: 11,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  InkWell(
                    onTap: () {
                      queue.setFilter('summoned', baseUrl: auth.baseUrl!, token: auth.token!);
                    },
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: const Text(
                        "View",
                        style: TextStyle(
                          color: ThemeConfig.statusUrgent,
                          fontWeight: FontWeight.w800,
                          fontSize: 11,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),

          // Search Field
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: "Search visitors, emails, domains...",
                prefixIcon: const Icon(Icons.search, size: 18),
                suffixIcon: _searchController.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear, size: 16),
                        onPressed: () {
                          _searchController.clear();
                          queue.setSearchQuery('');
                        },
                      )
                    : null,
                isDense: true,
                contentPadding: const EdgeInsets.symmetric(vertical: 10),
              ),
              onChanged: (val) => queue.setSearchQuery(val),
            ),
          ),

          // Filter Tab Pills
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
            child: Row(
              children: [
                _buildFilterChip("All", "all", queue, auth),
                _buildFilterChip(
                  "Summoned ${queue.urgentSummonsCount > 0 ? '(${queue.urgentSummonsCount})' : ''}",
                  "summoned",
                  queue,
                  auth,
                  isAlert: queue.urgentSummonsCount > 0,
                ),
                _buildFilterChip("Active AI", "active", queue, auth),
                _buildFilterChip("Taken Over", "taken_over", queue, auth),
                _buildFilterChip("My Chats", "my_chats", queue, auth),
                _buildFilterChip("Closed", "closed", queue, auth),
              ],
            ),
          ),

          const SizedBox(height: 6),

          // Visitor Queue Session Cards
          Expanded(
            child: queue.isLoading && queue.sessions.isEmpty
                ? const Center(child: CircularProgressIndicator())
                : queue.sessions.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              Icons.chat_bubble_outline,
                              size: 48,
                              color: Colors.grey.shade400,
                            ),
                            const SizedBox(height: 12),
                            const Text(
                              "No chat sessions found",
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w600,
                                color: Color(0xFF64748B),
                              ),
                            ),
                            const SizedBox(height: 4),
                            const Text(
                              "Incoming visitor chats will appear here automatically",
                              style: TextStyle(fontSize: 12, color: Color(0xFF94A3B8)),
                            ),
                          ],
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: () async {
                          if (auth.baseUrl != null && auth.token != null) {
                            await queue.fetchQueue(
                              baseUrl: auth.baseUrl!,
                              token: auth.token!,
                              isInitial: false,
                            );
                          }
                        },
                        child: ListView.builder(
                          padding: const EdgeInsets.only(bottom: 24),
                          itemCount: queue.sessions.length,
                          itemBuilder: (ctx, i) {
                            final session = queue.sessions[i];
                            return SessionCard(
                              session: session,
                              onTap: () {
                                _openChat(
                                  session.id,
                                  session.uuid,
                                  session.client.name,
                                  session.isTakenOver,
                                  session.client.id,
                                );
                              },
                            );
                          },
                        ),
                      ),
          ),
        ],
      ),
    );
  }

  Widget _buildFilterChip(
    String label,
    String filterKey,
    QueueProvider queue,
    AuthProvider auth, {
    bool isAlert = false,
  }) {
    final isSelected = queue.activeFilter == filterKey;
    final theme = Theme.of(context);
    final primary = theme.colorScheme.primary;

    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: FilterChip(
        label: Text(label),
        selected: isSelected,
        labelStyle: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: isSelected
              ? Colors.black
              : isAlert
                  ? ThemeConfig.statusUrgent
                  : theme.textTheme.bodyMedium?.color,
        ),
        backgroundColor: isAlert ? const Color(0xFF3B0D14) : theme.cardColor,
        selectedColor: isAlert ? ThemeConfig.statusUrgent : primary,
        checkmarkColor: Colors.black,
        side: BorderSide(
          color: isAlert
              ? ThemeConfig.statusUrgent
              : isSelected
                  ? primary
                  : theme.dividerColor,
        ),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        onSelected: (_) {
          if (auth.baseUrl != null && auth.token != null) {
            queue.setFilter(filterKey, baseUrl: auth.baseUrl!, token: auth.token!);
          }
        },
      ),
    );
  }
}
