import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/queue_provider.dart';
import '../providers/ticket_provider.dart';
import '../services/notification_router.dart';
import 'queue_screen.dart';
import 'tickets_screen.dart';
import 'clients_screen.dart';
import 'services_screen.dart';
import 'settings_screen.dart';

class HomeShell extends StatefulWidget {
  final int initialTab;

  const HomeShell({super.key, this.initialTab = 0});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  late int _currentIndex;

  final List<Widget> _pages = const [
    QueueScreen(),
    TicketsScreen(),
    ClientsScreen(),
    ServicesScreen(),
    SettingsScreen(),
  ];

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialTab;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      NotificationRouter.checkAndRoutePending(context);
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;
    final queue = context.watch<QueueProvider>();
    final ticket = context.watch<TicketProvider>();

    final urgentSummons = queue.urgentSummonsCount;
    final openTickets = ticket.counts['customer_reply'] ?? 0;

    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: _pages,
      ),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: isAmoled ? Colors.black : theme.cardColor,
          border: Border(
            top: BorderSide(color: isAmoled ? const Color(0xFF1A2233) : theme.dividerColor, width: 1),
          ),
        ),
        child: BottomNavigationBar(
          currentIndex: _currentIndex,
          onTap: (index) => setState(() => _currentIndex = index),
          backgroundColor: isAmoled ? Colors.black : theme.cardColor,
          selectedItemColor: theme.colorScheme.primary,
          unselectedItemColor: const Color(0xFF64748B),
          type: BottomNavigationBarType.fixed,
          elevation: 0,
          selectedFontSize: 11,
          unselectedFontSize: 11,
          selectedLabelStyle: const TextStyle(fontWeight: FontWeight.w700),
          items: [
            BottomNavigationBarItem(
              icon: Badge(
                isLabelVisible: urgentSummons > 0,
                label: Text('$urgentSummons'),
                backgroundColor: const Color(0xFFEF4444),
                child: const Icon(Icons.chat_bubble_outline),
              ),
              activeIcon: Badge(
                isLabelVisible: urgentSummons > 0,
                label: Text('$urgentSummons'),
                backgroundColor: const Color(0xFFEF4444),
                child: const Icon(Icons.chat_bubble),
              ),
              label: 'Live Chat',
            ),
            BottomNavigationBarItem(
              icon: Badge(
                isLabelVisible: openTickets > 0,
                label: Text('$openTickets'),
                backgroundColor: const Color(0xFFF59E0B),
                child: const Icon(Icons.confirmation_number_outlined),
              ),
              activeIcon: Badge(
                isLabelVisible: openTickets > 0,
                label: Text('$openTickets'),
                backgroundColor: const Color(0xFFF59E0B),
                child: const Icon(Icons.confirmation_number),
              ),
              label: 'Tickets',
            ),
            const BottomNavigationBarItem(
              icon: Icon(Icons.people_alt_outlined),
              activeIcon: Icon(Icons.people_alt),
              label: 'Clients',
            ),
            const BottomNavigationBarItem(
              icon: Icon(Icons.cloud_outlined),
              activeIcon: Icon(Icons.cloud),
              label: 'Services',
            ),
            const BottomNavigationBarItem(
              icon: Icon(Icons.settings_outlined),
              activeIcon: Icon(Icons.settings),
              label: 'Settings',
            ),
          ],
        ),
      ),
    );
  }
}
