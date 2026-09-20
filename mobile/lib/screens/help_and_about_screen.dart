import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config/theme_config.dart';
import '../providers/auth_provider.dart';
import '../widgets/sahdev_logo.dart';

class HelpAndAboutScreen extends StatefulWidget {
  const HelpAndAboutScreen({super.key});

  @override
  State<HelpAndAboutScreen> createState() => _HelpAndAboutScreenState();
}

class _HelpAndAboutScreenState extends State<HelpAndAboutScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  final TextEditingController _searchController = TextEditingController();
  String _searchQuery = '';

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 4, vsync: this);
  }

  @override
  void dispose() {
    _tabController.dispose();
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;
    final auth = context.watch<AuthProvider>();

    return Scaffold(
      appBar: AppBar(
        title: const Text(
          "Help, FAQ & About",
          style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
        ),
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          labelColor: const Color(0xFF2DD4BF),
          unselectedLabelColor: const Color(0xFF94A3B8),
          indicatorColor: const Color(0xFF2DD4BF),
          indicatorWeight: 3,
          labelStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
          tabs: const [
            Tab(icon: Icon(Icons.info_outline, size: 18), text: "About"),
            Tab(icon: Icon(Icons.history, size: 18), text: "Changelog"),
            Tab(icon: Icon(Icons.quiz_outlined, size: 18), text: "FAQ & Help"),
            Tab(icon: Icon(Icons.menu_book_outlined, size: 18), text: "Glossary"),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          _buildAboutTab(auth, theme, isAmoled),
          _buildChangelogTab(theme, isAmoled),
          _buildFaqTab(theme, isAmoled),
          _buildGlossaryTab(theme, isAmoled),
        ],
      ),
    );
  }

  // ── TAB 1: ABOUT SAHDEV ───────────────────────────────────────────────
  Widget _buildAboutTab(AuthProvider auth, ThemeData theme, bool isAmoled) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Brand Hero Card
        Card(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 16),
            child: Column(
              children: [
                const SahdevLogo(size: 80, showGlow: true),
                const SizedBox(height: 16),
                const Text(
                  "Sahdev AI Support Console",
                  style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, letterSpacing: -0.3),
                ),
                const SizedBox(height: 4),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: const Color(0xFF2DD4BF).withOpacity(0.12),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: const Color(0xFF2DD4BF).withOpacity(0.3)),
                  ),
                  child: const Text(
                    "v4.0.0 (Core v2.0) • Mobile App v1.0.2+2",
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: Color(0xFF2DD4BF),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                const Text(
                  "Autonomous Live Chat & Support Ticket Intelligence for WHMCS",
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 13, color: Color(0xFF94A3B8)),
                ),
              ],
            ),
          ),
        ),

        const SizedBox(height: 16),

        // Developer & Organization Details
        _buildSectionTitle("DEVELOPER & PUBLISHER"),
        Card(
          child: Column(
            children: [
              _buildInfoTile(
                Icons.business_rounded,
                "Developer",
                "HostingSpell LLP.",
                const Color(0xFF38BDF8),
              ),
              const Divider(height: 1),
              _buildInfoTile(
                Icons.code_rounded,
                "Architecture",
                "Google Gemini • FCM v1 • Capsule ORM",
                const Color(0xFF818CF8),
              ),
              const Divider(height: 1),
              _buildInfoTile(
                Icons.security_rounded,
                "Data Privacy",
                "Enterprise PII Scrubbed • Zero LLM Training",
                const Color(0xFF10B981),
              ),
              const Divider(height: 1),
              _buildInfoTile(
                Icons.link_rounded,
                "Connected WHMCS",
                auth.baseUrl ?? "Not connected",
                const Color(0xFFF59E0B),
              ),
            ],
          ),
        ),

        const SizedBox(height: 16),

        // System Health & Capabilities
        _buildSectionTitle("SYSTEM CAPABILITIES"),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _buildCapabilityRow(Icons.check_circle, "High-Priority FCM v1 Push with Phone Wake", true),
                const SizedBox(height: 10),
                _buildCapabilityRow(Icons.check_circle, "Real-time Client Live Chat & Visitor Sneak-Peek", true),
                const SizedBox(height: 10),
                _buildCapabilityRow(Icons.check_circle, "AI Root Cause, Responsibility & Action Plan", true),
                const SizedBox(height: 10),
                _buildCapabilityRow(Icons.check_circle, "Client Name & Company Push Formatting", true),
                const SizedBox(height: 10),
                _buildCapabilityRow(Icons.check_circle, "12 Custom Alert Chimes & Alarm Tones", true),
              ],
            ),
          ),
        ),
      ],
    );
  }

  // ── TAB 2: CHANGELOG ──────────────────────────────────────────────────
  Widget _buildChangelogTab(ThemeData theme, bool isAmoled) {
    final releases = [
      {
        'version': 'v4.0.0',
        'date': 'September 2026',
        'badge': 'LATEST',
        'badgeColor': const Color(0xFF10B981),
        'items': [
          'The Cognitive Cortex Matrix: New brand identity and pixel-perfect vector logo system.',
          'Client Name in Push Notifications: Automatically extracts and displays actual client and company names in mobile alerts.',
          'Help, FAQ & Knowledge Center: Integrated documentation and AI glossary directly inside app.',
          'Simplified, context-rich UI/UX across mobile shell and WHMCS ticket panels.',
        ]
      },
      {
        'version': 'v3.5.0',
        'date': 'August 2026',
        'badge': 'MAJOR',
        'badgeColor': const Color(0xFF38BDF8),
        'items': [
          'Full-featured Mobile Live Support App with Tawk.to style real-time visitor chat.',
          'FCM HTTP v1 background ringing alerts with screen wake on incoming summons.',
          'Visitor Sneak-Peek: Live keystroke visibility while clients are typing.',
          '12 high-priority alert sound library with custom audio player.',
        ]
      },
      {
        'version': 'v3.0.0',
        'date': 'July 2026',
        'badge': 'FEATURE',
        'badgeColor': const Color(0xFF818CF8),
        'items': [
          'Tools Execution API integration (server diagnostics, DNS tests, and uptime checks).',
          'Server Incident Watch: Automatic outage detection and client impact notifications.',
          'Automated Cron Insights and ticket tag cloud classification.',
        ]
      },
      {
        'version': 'v2.0.0',
        'date': 'June 2026',
        'badge': 'CORE',
        'badgeColor': const Color(0xFFF59E0B),
        'items': [
          'Account Context Enrichment: Pulls active services, open invoices, domains, and client notes.',
          'Enterprise PII Scrubbing: Automatic redaction of credit cards, passwords, and IPs.',
          'Sentiment Analysis & Urgency Scoring (1-10 scale).',
          'AI Quality Scorer & Staff Performance telemetry.',
        ]
      },
      {
        'version': 'v1.0.0',
        'date': 'May 2026',
        'badge': 'INITIAL',
        'badgeColor': const Color(0xFF64748B),
        'items': [
          'Initial release of Sahdev AI Ticket Intelligence for WHMCS.',
          'Google Gemini LLM integration for technical support analysis.',
          'Root Cause, Responsibility, Risk Level, and Action Plan generation.',
          '1-Click insertion of AI suggested replies into TinyMCE editor.',
        ]
      },
    ];

    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: releases.length,
      itemBuilder: (context, index) {
        final rel = releases[index];
        return Card(
          margin: const EdgeInsets.only(bottom: 14),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(
                      rel['version'] as String,
                      style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: (rel['badgeColor'] as Color).withOpacity(0.15),
                        borderRadius: BorderRadius.circular(4),
                        border: Border.all(color: rel['badgeColor'] as Color),
                      ),
                      child: Text(
                        rel['badge'] as String,
                        style: TextStyle(
                          fontSize: 10,
                          fontWeight: FontWeight.w800,
                          color: rel['badgeColor'] as Color,
                        ),
                      ),
                    ),
                    const Spacer(),
                    Text(
                      rel['date'] as String,
                      style: const TextStyle(fontSize: 12, color: Color(0xFF94A3B8)),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                ...((rel['items'] as List<String>).map((item) => Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text("• ", style: TextStyle(color: Color(0xFF2DD4BF), fontWeight: FontWeight.bold)),
                      Expanded(
                        child: Text(
                          item,
                          style: const TextStyle(fontSize: 13, height: 1.4),
                        ),
                      ),
                    ],
                  ),
                ))),
              ],
            ),
          ),
        );
      },
    );
  }

  // ── TAB 3: FAQ & HELP ─────────────────────────────────────────────────
  Widget _buildFaqTab(ThemeData theme, bool isAmoled) {
    final faqs = [
      {
        'q': 'How does Sahdev protect client data and PII?',
        'a': 'Sahdev features an automated PII Scrubber that removes credit card numbers, passwords, phone numbers, and IPv4/IPv6 addresses before ticket text or chat messages are sent to the AI model. Additionally, context queries run via strictly read-only Capsule ORM with a 1MB attachment cap.'
      },
      {
        'q': 'Why does my phone not ring when a visitor summons help?',
        'a': 'Ensure background battery optimization is set to "Unrestricted" for Sahdev Mobile in Android Settings. On Xiaomi/Huawei/Oppo devices, also enable "Autostart" and "Display pop-up windows while in the background".'
      },
      {
        'q': 'How does the Client Name appear in notifications?',
        'a': 'When a client opens a ticket or submits a reply, Sahdev queries WHMCS user records in real-time. If the client has a registered account, their full name and company name are displayed directly in the notification title.'
      },
      {
        'q': 'What happens if the primary AI model (e.g. Gemini) is down?',
        'a': 'Sahdev includes multi-provider fallback. If your primary provider experiences a rate limit or outage, Sahdev seamlessly re-routes the analysis to your configured fallback provider (e.g. OpenRouter, LM Studio) without failing the request.'
      },
      {
        'q': 'Can staff edit AI suggested replies before sending them?',
        'a': 'Yes! Sahdev always operates in "Staff-Assisted" mode by default. Suggested replies are placed into the ticket editor or mobile compose box so staff can review, edit, and approve before sending to the client.'
      },
      {
        'q': 'What is the difference between Dive Intensity 1 and 5?',
        'a': 'Intensity 1 produces a quick, high-level summary and brief reply. Intensity 5 performs an exhaustive multi-message log parse, inspecting attached logs, DNS health, and historical server context.'
      },
    ];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Search bar
        TextField(
          controller: _searchController,
          onChanged: (val) => setState(() => _searchQuery = val.toLowerCase()),
          decoration: InputDecoration(
            hintText: "Search questions & topics...",
            prefixIcon: const Icon(Icons.search, size: 20),
            suffixIcon: _searchQuery.isNotEmpty
                ? IconButton(
                    icon: const Icon(Icons.clear, size: 18),
                    onPressed: () {
                      _searchController.clear();
                      setState(() => _searchQuery = '');
                    },
                  )
                : null,
            filled: true,
            fillColor: isAmoled ? const Color(0xFF141A29) : Colors.grey.shade100,
            contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
          ),
        ),
        const SizedBox(height: 16),

        ...faqs.where((f) => f['q']!.toLowerCase().contains(_searchQuery) || f['a']!.toLowerCase().contains(_searchQuery)).map((faq) {
          return Card(
            margin: const EdgeInsets.only(bottom: 10),
            child: ExpansionTile(
              leading: const Icon(Icons.help_outline, color: Color(0xFF2DD4BF), size: 22),
              title: Text(
                faq['q']!,
                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
              ),
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                  child: Text(
                    faq['a']!,
                    style: const TextStyle(fontSize: 13, height: 1.5, color: Color(0xFF94A3B8)),
                  ),
                ),
              ],
            ),
          );
        }),
      ],
    );
  }

  // ── TAB 4: GLOSSARY ───────────────────────────────────────────────────
  Widget _buildGlossaryTab(ThemeData theme, bool isAmoled) {
    final terms = [
      {
        'term': 'Root Cause',
        'category': 'Analysis',
        'desc': 'The underlying technical failure identified by Sahdev after deconstructing ticket logs, errors, and conversation history.'
      },
      {
        'term': 'Responsibility',
        'category': 'Triage',
        'desc': 'Classification indicating whether the resolution lies with the Client (user error), Host (infrastructure fault), or a 3rd Party (e.g. ISP, registrar).'
      },
      {
        'term': 'Risk Level',
        'category': 'Severity',
        'desc': 'Assessment of operational danger (Low, Medium, High, Critical) based on potential data loss, service outage, or client escalation risk.'
      },
      {
        'term': 'Internal Action Plan',
        'category': 'Workflow',
        'desc': 'A technical step-by-step checklist prepared exclusively for internal support engineers before replying to the customer.'
      },
      {
        'term': 'PII Scrubbing',
        'category': 'Security',
        'desc': 'Automated regex sanitation that strips credit cards, passwords, and sensitive credentials before transmitting data to external LLMs.'
      },
      {
        'term': 'Context Enrichment',
        'category': 'Data Engine',
        'desc': 'Automated retrieval of client active packages, billing status, domain records, and recent server logs to provide high-precision context.'
      },
      {
        'term': 'FCM HTTP v1',
        'category': 'Mobile Push',
        'desc': 'Modern Firebase Cloud Messaging protocol that delivers high-priority wake alerts to staff phones even from deep sleep states.'
      },
      {
        'term': 'Sneak-Peek',
        'category': 'Live Chat',
        'desc': 'Live keystroke transmission allowing support operators to read what a customer is typing in real time before they press send.'
      },
      {
        'term': 'Capsule ORM Isolation',
        'category': 'Architecture',
        'desc': 'Strictly read-only database queries ensuring AI operations never alter WHMCS core billing or ticket tables directly.'
      },
    ];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: terms.map((item) {
        return Card(
          margin: const EdgeInsets.only(bottom: 10),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      item['term']!,
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: Color(0xFF2DD4BF)),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                        color: Colors.grey.withOpacity(0.15),
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(
                        item['category']!,
                        style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: Color(0xFF94A3B8)),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  item['desc']!,
                  style: const TextStyle(fontSize: 13, height: 1.4, color: Color(0xFFCBD5E1)),
                ),
              ],
            ),
          ),
        );
      }).toList(),
    );
  }

  Widget _buildSectionTitle(String title) {
    return Padding(
      padding: const EdgeInsets.only(left: 4, bottom: 8),
      child: Text(
        title,
        style: const TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          color: Color(0xFF94A3B8),
          letterSpacing: 0.6,
        ),
      ),
    );
  }

  Widget _buildInfoTile(IconData icon, String title, String subtitle, Color color) {
    return ListTile(
      leading: Container(
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(
          color: color.withOpacity(0.15),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Icon(icon, color: color, size: 20),
      ),
      title: Text(title, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
      subtitle: Text(subtitle, style: const TextStyle(fontSize: 12, color: Color(0xFF94A3B8))),
    );
  }

  Widget _buildCapabilityRow(IconData icon, String text, bool enabled) {
    return Row(
      children: [
        Icon(icon, size: 18, color: enabled ? const Color(0xFF10B981) : Colors.grey),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            text,
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: enabled ? null : Colors.grey,
            ),
          ),
        ),
      ],
    );
  }
}
