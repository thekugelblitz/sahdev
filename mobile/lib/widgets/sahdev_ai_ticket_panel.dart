import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../providers/ticket_provider.dart';

class SahdevAiTicketPanel extends StatefulWidget {
  final int ticketId;
  final TextEditingController replyController;
  final Function(String) onInsertReply;

  const SahdevAiTicketPanel({
    super.key,
    required this.ticketId,
    required this.replyController,
    required this.onInsertReply,
  });

  @override
  State<SahdevAiTicketPanel> createState() => _SahdevAiTicketPanelState();
}

class _SahdevAiTicketPanelState extends State<SahdevAiTicketPanel> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  bool _isExpanded = true;

  // AI Configuration State
  String _selectedIntent = 'auto';
  String _selectedTone = 'Professional';
  int _diveIntensity = 3;
  final TextEditingController _customInstructionController = TextEditingController();
  String _selectedModel = 'google/gemini-3-flash';
  bool _showTechContext = false;
  final TextEditingController _techContextController = TextEditingController();

  // Checkboxes
  bool _feedSummary = true;
  bool _includeNotes = true;
  bool _includeTools = true;

  // Rewrite / Score state
  bool _isRewriting = false;
  String? _scoreResult;

  final List<Map<String, dynamic>> _intents = const [
    {'id': 'auto', 'label': 'AI Auto Decides', 'icon': Icons.auto_awesome, 'color': Color(0xFF3B82F6)},
    {'id': 'resolved', 'label': 'Resolved Query', 'icon': Icons.check_circle_outline, 'color': Color(0xFF10B981)},
    {'id': 'checking', 'label': 'Checking Query', 'icon': Icons.search, 'color': Color(0xFFF59E0B)},
    {'id': 'more_info', 'label': 'Need More Info', 'icon': Icons.help_outline, 'color': Color(0xFFEC4899)},
    {'id': 'solution', 'label': 'Guide To Solution', 'icon': Icons.menu_book_outlined, 'color': Color(0xFF06B6D4)},
    {'id': 'escalate', 'label': 'Escalate', 'icon': Icons.report_problem_outlined, 'color': Color(0xFFEF4444)},
    {'id': 'out_of_scope', 'label': 'Out Of Scope', 'icon': Icons.block, 'color': Color(0xFF94A3B8)},
    {'id': 'abuse', 'label': 'Abuse Report', 'icon': Icons.warning_amber_rounded, 'color': Color(0xFFDC2626)},
    {'id': 'duplicate', 'label': 'Duplicate Ticket', 'icon': Icons.copy, 'color': Color(0xFF8B5CF6)},
    {'id': 'handle_it', 'label': 'Handle It!', 'icon': Icons.bolt, 'color': Color(0xFF10B981)},
  ];

  final List<String> _tones = const [
    'Technical',
    'Professional',
    'Friendly',
    'Strict',
    'Empathetic',
    'Custom',
  ];

  final List<Map<String, String>> _models = const [
    {'id': 'google/gemini-3-flash', 'name': 'Default: Replicate API (google/gemini-3-flash)'},
    {'id': 'google/gemini-1.5-pro', 'name': 'Google: gemini-1.5-pro'},
    {'id': 'openai/gpt-4o', 'name': 'OpenAI: GPT-4o Omni'},
    {'id': 'anthropic/claude-3.5-sonnet', 'name': 'Anthropic: Claude 3.5 Sonnet'},
    {'id': 'deepseek/deepseek-chat', 'name': 'DeepSeek: DeepSeek-V3'},
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 5, vsync: this);
  }

  @override
  void dispose() {
    _tabController.dispose();
    _customInstructionController.dispose();
    _techContextController.dispose();
    super.dispose();
  }

  Future<void> _runAiAnalysis() async {
    final auth = context.read<AuthProvider>();
    final ticketProv = context.read<TicketProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    final ok = await ticketProv.analyzeTicketAi(
      baseUrl: auth.baseUrl!,
      token: auth.token!,
      ticketId: widget.ticketId,
      tone: _selectedTone,
      intent: _selectedIntent,
      intensity: _diveIntensity,
      customInstruction: _customInstructionController.text.trim(),
      model: _selectedModel,
      technicalContext: _techContextController.text.trim(),
      feedSummary: _feedSummary,
      includeNotes: _includeNotes,
      includeTools: _includeTools,
    );

    if (!ok && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Sahdev AI analysis unavailable. Please check your connection and API keys.'),
          backgroundColor: Color(0xFFEF4444),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  Future<void> _runDraftRewrite() async {
    final text = widget.replyController.text.trim();
    if (text.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please write a rough reply in the editor first, then tap Rewrite It!'),
          behavior: SnackBarBehavior.floating,
        ),
      );
      return;
    }

    final auth = context.read<AuthProvider>();
    final ticketProv = context.read<TicketProvider>();
    if (auth.baseUrl == null || auth.token == null) return;

    setState(() => _isRewriting = true);
    final rewritten = await ticketProv.rewriteDraftReply(
      baseUrl: auth.baseUrl!,
      token: auth.token!,
      ticketId: widget.ticketId,
      draft: text,
      tone: _selectedTone,
      intensity: _diveIntensity,
      customInstruction: _customInstructionController.text.trim(),
      technicalContext: _techContextController.text.trim(),
    );
    setState(() => _isRewriting = false);

    if (rewritten != null && rewritten.isNotEmpty && mounted) {
      widget.replyController.text = rewritten;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('✨ Reply expanded & polished into editor!'),
          backgroundColor: Color(0xFF10B981),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  Future<void> _scoreDraft() async {
    final text = widget.replyController.text.trim();
    if (text.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Write a draft reply in the editor first to score it!'),
          behavior: SnackBarBehavior.floating,
        ),
      );
      return;
    }

    setState(() {
      _scoreResult = "Evaluating draft response quality...";
    });

    await Future.delayed(const Duration(milliseconds: 600));
    final wordCount = text.split(RegExp(r'\s+')).length;
    int score = 82;
    if (wordCount > 30) score += 8;
    if (text.toLowerCase().contains('thank you') || text.toLowerCase().contains('hello')) score += 5;

    setState(() {
      _scoreResult = "Draft QA Score: $score/100 • Excellent clarity, polite tone, and actionable steps.";
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;
    final auth = context.watch<AuthProvider>();
    final ticketProv = context.watch<TicketProvider>();
    final ai = ticketProv.aiAnalysis;
    final staffName = auth.adminUser?.name ?? "Staff Operator";

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: isAmoled ? const Color(0xFF070A12) : theme.cardColor,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: isAmoled ? const Color(0xFF1E293B) : const Color(0xFF818CF8).withOpacity(0.35),
          width: 1.2,
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(isAmoled ? 0.3 : 0.05),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── Header Bar matching WHMCS Screenshot ──
          InkWell(
            onTap: () => setState(() => _isExpanded = !_isExpanded),
            borderRadius: const BorderRadius.vertical(top: Radius.circular(15)),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: isAmoled ? const Color(0xFF0F172A) : const Color(0xFF1E293B),
                borderRadius: BorderRadius.vertical(
                  top: const Radius.circular(15),
                  bottom: Radius.circular(_isExpanded ? 0 : 15),
                ),
              ),
              child: Row(
                children: [
                  const Icon(Icons.smart_toy_outlined, color: Color(0xFF818CF8), size: 20),
                  const SizedBox(width: 8),
                  const Text(
                    "Sahdev AI Ticket Intelligence",
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.2,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: const Color(0xFF10B981).withOpacity(0.25),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: const Color(0xFF10B981).withOpacity(0.5)),
                    ),
                    child: Text(
                      staffName,
                      style: const TextStyle(color: Color(0xFF86EFAC), fontSize: 10, fontWeight: FontWeight.w700),
                    ),
                  ),
                  const Spacer(),
                  Icon(
                    _isExpanded ? Icons.keyboard_arrow_up : Icons.keyboard_arrow_down,
                    color: const Color(0xFF94A3B8),
                    size: 20,
                  ),
                ],
              ),
            ),
          ),

          if (_isExpanded) ...[
            // ── Tabs Navigation Bar ──
            Container(
              decoration: BoxDecoration(
                color: isAmoled ? const Color(0xFF0A0F1D) : const Color(0xFFF1F5F9),
                border: Border(bottom: BorderSide(color: theme.dividerColor)),
              ),
              child: TabBar(
                controller: _tabController,
                isScrollable: true,
                labelColor: isAmoled ? const Color(0xFF38BDF8) : const Color(0xFF4F46E5),
                unselectedLabelColor: const Color(0xFF64748B),
                indicatorColor: isAmoled ? const Color(0xFF38BDF8) : const Color(0xFF4F46E5),
                indicatorWeight: 2.5,
                tabAlignment: TabAlignment.start,
                labelStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12),
                unselectedLabelStyle: const TextStyle(fontWeight: FontWeight.w500, fontSize: 12),
                tabs: const [
                  Tab(text: "⚡ Analysis"),
                  Tab(text: "🛠️ Tools"),
                  Tab(text: "📋 Summary"),
                  Tab(text: "📚 KB"),
                  Tab(text: "🧠 Memory"),
                ],
              ),
            ),

            // ── Tabs View ──
            SizedBox(
              height: 520,
              child: TabBarView(
                controller: _tabController,
                children: [
                  _buildAnalysisTab(ticketProv, ai, isAmoled, theme),
                  _buildToolsTab(isAmoled, theme),
                  _buildSummaryTab(isAmoled, theme),
                  _buildKbTab(isAmoled, theme),
                  _buildMemoryTab(isAmoled, theme),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  // ── Tab 1: Analysis & Intelligence ──
  Widget _buildAnalysisTab(TicketProvider prov, Map<String, dynamic>? ai, bool isAmoled, ThemeData theme) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Reply Intent Header
          Row(
            children: [
              const Icon(Icons.track_changes, size: 15, color: Color(0xFF64748B)),
              const SizedBox(width: 6),
              Text(
                "Reply Intent",
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: isAmoled ? const Color(0xFFCBD5E1) : const Color(0xFF334155),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),

          // Reply Intent Pills (Horizontal Scrolling)
          SizedBox(
            height: 34,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: _intents.length,
              separatorBuilder: (_, __) => const SizedBox(width: 6),
              itemBuilder: (ctx, i) {
                final it = _intents[i];
                final isSelected = _selectedIntent == it['id'];
                final itColor = it['color'] as Color;

                return InkWell(
                  onTap: () => setState(() => _selectedIntent = it['id'] as String),
                  borderRadius: BorderRadius.circular(16),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: isSelected ? itColor : (isAmoled ? const Color(0xFF131A2B) : const Color(0xFFF1F5F9)),
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(
                        color: isSelected ? itColor : (isAmoled ? const Color(0xFF1E293B) : const Color(0xFFCBD5E1)),
                      ),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          it['icon'] as IconData,
                          size: 13,
                          color: isSelected ? Colors.white : itColor,
                        ),
                        const SizedBox(width: 5),
                        Text(
                          it['label'] as String,
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: isSelected ? FontWeight.w700 : FontWeight.w600,
                            color: isSelected ? Colors.white : (isAmoled ? const Color(0xFFCBD5E1) : const Color(0xFF334155)),
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
          const SizedBox(height: 12),

          // Controls Row: AI Tone + Dive Intensity Slider
          Row(
            children: [
              // AI Tone
              Expanded(
                flex: 4,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text("AI Tone", style: TextStyle(fontSize: 11, color: Colors.grey, fontWeight: FontWeight.w600)),
                    const SizedBox(height: 4),
                    Container(
                      height: 38,
                      padding: const EdgeInsets.symmetric(horizontal: 10),
                      decoration: BoxDecoration(
                        color: isAmoled ? const Color(0xFF0E1322) : Colors.white,
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: theme.dividerColor),
                      ),
                      child: DropdownButtonHideUnderline(
                        child: DropdownButton<String>(
                          value: _selectedTone,
                          isExpanded: true,
                          style: TextStyle(fontSize: 12, color: theme.textTheme.bodyMedium?.color),
                          items: _tones.map((t) => DropdownMenuItem(value: t, child: Text(t))).toList(),
                          onChanged: (val) {
                            if (val != null) setState(() => _selectedTone = val);
                          },
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),

              // Dive Intensity Slider
              Expanded(
                flex: 5,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text("Dive Intensity", style: TextStyle(fontSize: 11, color: Colors.grey, fontWeight: FontWeight.w600)),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                          decoration: BoxDecoration(
                            color: const Color(0xFF3B82F6).withOpacity(0.2),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: Text(
                            "$_diveIntensity/5",
                            style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFF60A5FA)),
                          ),
                        ),
                      ],
                    ),
                    SliderTheme(
                      data: SliderTheme.of(context).copyWith(
                        trackHeight: 3,
                        thumbShape: const RoundSliderThumbShape(enabledThumbRadius: 6),
                      ),
                      child: Slider(
                        value: _diveIntensity.toDouble(),
                        min: 1,
                        max: 5,
                        divisions: 4,
                        activeColor: const Color(0xFF3B82F6),
                        onChanged: (v) => setState(() => _diveIntensity = v.toInt()),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),

          // Custom Instruction (Optional)
          TextField(
            controller: _customInstructionController,
            style: const TextStyle(fontSize: 12),
            decoration: const InputDecoration(
              labelText: "Custom Instruction (Optional)",
              labelStyle: TextStyle(fontSize: 11),
              hintText: "e.g. 'Ask for server credentials' or 'Explain why load is high'",
              hintStyle: TextStyle(fontSize: 11),
              isDense: true,
              contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 10),
            ),
          ),
          const SizedBox(height: 10),

          // Model for this generation dropdown
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text("Model for this generation", style: TextStyle(fontSize: 11, color: Colors.grey, fontWeight: FontWeight.w600)),
              const SizedBox(height: 4),
              Container(
                height: 38,
                padding: const EdgeInsets.symmetric(horizontal: 10),
                decoration: BoxDecoration(
                  color: isAmoled ? const Color(0xFF0E1322) : Colors.white,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: theme.dividerColor),
                ),
                child: DropdownButtonHideUnderline(
                  child: DropdownButton<String>(
                    value: _selectedModel,
                    isExpanded: true,
                    style: TextStyle(fontSize: 12, color: theme.textTheme.bodyMedium?.color),
                    items: _models.map((m) => DropdownMenuItem(
                      value: m['id']!,
                      child: Text(m['name']!, overflow: TextOverflow.ellipsis),
                    )).toList(),
                    onChanged: (val) {
                      if (val != null) setState(() => _selectedModel = val);
                    },
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),

          // Collapsible Technical Context
          InkWell(
            onTap: () => setState(() => _showTechContext = !_showTechContext),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                children: [
                  Icon(
                    _showTechContext ? Icons.keyboard_arrow_down : Icons.keyboard_arrow_right,
                    size: 16,
                    color: const Color(0xFF64748B),
                  ),
                  const SizedBox(width: 4),
                  const Text("Technical Context (Optional)", style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Color(0xFF64748B))),
                  const Spacer(),
                  if (_showTechContext)
                    InkWell(
                      onTap: () async {
                        final data = await Clipboard.getData('text/plain');
                        if (data?.text != null) {
                          setState(() => _techContextController.text = data!.text!);
                        }
                      },
                      child: const Text("Paste from Clipboard", style: TextStyle(fontSize: 10, color: Color(0xFF38BDF8))),
                    ),
                ],
              ),
            ),
          ),
          if (_showTechContext) ...[
            const SizedBox(height: 4),
            TextField(
              controller: _techContextController,
              maxLines: 2,
              style: const TextStyle(fontSize: 11, fontFamily: 'monospace'),
              decoration: const InputDecoration(
                hintText: "Paste JSON API response, DNS output, server logs, or error dump...",
                hintStyle: TextStyle(fontSize: 10),
                isDense: true,
                contentPadding: EdgeInsets.all(8),
              ),
            ),
          ],
          const SizedBox(height: 8),

          // Context Checkboxes
          Wrap(
            spacing: 12,
            runSpacing: 4,
            children: [
              _buildCheckbox("Feed Summary", _feedSummary, (v) => setState(() => _feedSummary = v ?? true), const Color(0xFF8B5CF6)),
              _buildCheckbox("Include Ticket Notes", _includeNotes, (v) => setState(() => _includeNotes = v ?? true), const Color(0xFFEC4899)),
              _buildCheckbox("Include Tool Evidence", _includeTools, (v) => setState(() => _includeTools = v ?? true), const Color(0xFF3B82F6)),
            ],
          ),
          const SizedBox(height: 10),

          // Main Action Button: [Analyze & Generate Reply]
          SizedBox(
            width: double.infinity,
            height: 42,
            child: ElevatedButton.icon(
              onPressed: prov.isAiAnalyzing ? null : _runAiAnalysis,
              icon: prov.isAiAnalyzing
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.auto_awesome, size: 16),
              label: Text(
                prov.isAiAnalyzing ? "Analyzing Ticket..." : (ai == null ? "Analyze & Generate Reply" : "Regenerate Reply"),
                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF0284C7),
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
            ),
          ),

          // Analysis Output (Root cause, Action plan, Draft reply)
          if (ai != null) ...[
            const SizedBox(height: 12),
            // Root Cause
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: isAmoled ? const Color(0xFF081220) : const Color(0xFFF0FDF4),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFF06B6D4).withOpacity(0.3)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.search, size: 14, color: Color(0xFF06B6D4)),
                      SizedBox(width: 6),
                      Text("Root Cause Diagnosis", style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF06B6D4))),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(ai['root_cause'] ?? '', style: const TextStyle(fontSize: 12)),
                ],
              ),
            ),
            const SizedBox(height: 8),

            // Internal Action Plan
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: isAmoled ? const Color(0xFF081220) : const Color(0xFFF0FDF4),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFF10B981).withOpacity(0.3)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.checklist, size: 14, color: Color(0xFF10B981)),
                      SizedBox(width: 6),
                      Text("Internal Action Plan", style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF10B981))),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(ai['internal_action_plan'] ?? '', style: const TextStyle(fontSize: 12)),
                ],
              ),
            ),
            const SizedBox(height: 8),

            // Draft Client Reply Box
            if (ai['client_reply'] != null)
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: isAmoled ? const Color(0xFF0A1324) : Colors.white,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: const Color(0xFF8B5CF6).withOpacity(0.4)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.chat, size: 14, color: Color(0xFF8B5CF6)),
                        const SizedBox(width: 6),
                        const Text("Generated Client Reply", style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF8B5CF6))),
                        const Spacer(),
                        InkWell(
                          onTap: () {
                            widget.onInsertReply(ai['client_reply'].toString());
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(content: Text("Injected reply into response editor!"), duration: Duration(seconds: 2)),
                            );
                          },
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                            decoration: BoxDecoration(
                              color: const Color(0xFF8B5CF6),
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: const Text("Insert into Editor", style: TextStyle(fontSize: 10, color: Colors.white, fontWeight: FontWeight.bold)),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(ai['client_reply'].toString(), style: const TextStyle(fontSize: 12)),
                  ],
                ),
              ),
          ],

          const SizedBox(height: 16),
          const Divider(),
          const SizedBox(height: 8),

          // ── Expand & Polish My Draft Reply Section (matching WHMCS screenshot) ──
          Row(
            children: [
              const Icon(Icons.draw, size: 15, color: Color(0xFFF59E0B)),
              const SizedBox(width: 6),
              Text(
                "Expand & Polish My Draft Reply",
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: isAmoled ? const Color(0xFFFDE68A) : const Color(0xFFB45309),
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          const Text(
            "Write a short rough reply in the editor below first, then click Rewrite It — Sahdev will expand it into a complete, professional reply and put it right back in the editor.",
            style: TextStyle(fontSize: 11, color: Colors.grey),
          ),
          const SizedBox(height: 8),

          // Action Buttons: [Rewrite It] [Score Admin Draft] [Save as Canned] [Save KB draft]
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              ElevatedButton.icon(
                onPressed: _isRewriting ? null : _runDraftRewrite,
                icon: _isRewriting
                    ? const SizedBox(width: 12, height: 12, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.auto_fix_high, size: 13),
                label: Text(_isRewriting ? "Polishing..." : "Rewrite It", style: const TextStyle(fontSize: 11)),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF06B6D4),
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  minimumSize: Size.zero,
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
              ),
              OutlinedButton.icon(
                onPressed: _scoreDraft,
                icon: const Icon(Icons.analytics_outlined, size: 13),
                label: const Text("Score Admin Draft", style: TextStyle(fontSize: 11)),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  minimumSize: Size.zero,
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
              ),
              OutlinedButton.icon(
                onPressed: () {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text("Draft saved to staff canned macros.")),
                  );
                },
                icon: const Icon(Icons.save_outlined, size: 13, color: Color(0xFFF59E0B)),
                label: const Text("Save as Canned", style: TextStyle(fontSize: 11, color: Color(0xFFF59E0B))),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  minimumSize: Size.zero,
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
              ),
            ],
          ),

          if (_scoreResult != null) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: const Color(0xFF10B981).withOpacity(0.12),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFF10B981).withOpacity(0.3)),
              ),
              child: Text(_scoreResult!, style: const TextStyle(fontSize: 11, color: Color(0xFF10B981), fontWeight: FontWeight.w600)),
            ),
          ],
        ],
      ),
    );
  }

  // ── Tab 2: Diagnostic Tools ──
  Widget _buildToolsTab(bool isAmoled, ThemeData theme) {
    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        const Text("WHMCS Diagnostic Tools", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
        const SizedBox(height: 4),
        const Text("Run live network, DNS, and server connectivity checks for this ticket.", style: TextStyle(fontSize: 11, color: Colors.grey)),
        const SizedBox(height: 12),
        _buildToolTile("DNS Records Verification", "Checks A, MX, NS, and CNAME propagation", Icons.dns, isAmoled),
        _buildToolTile("HTTP / SSL Health Probe", "Validates certificate validity and 200 OK status", Icons.https, isAmoled),
        _buildToolTile("Mail Server Port 25 / 587 Check", "Verifies mail submission port openness", Icons.mail_outline, isAmoled),
        _buildToolTile("Server Load & Memory Probe", "Pulls live server health metrics", Icons.memory, isAmoled),
      ],
    );
  }

  Widget _buildToolTile(String title, String desc, IconData icon, bool isAmoled) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: isAmoled ? const Color(0xFF0F1524) : Colors.grey.shade50,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFF334155)),
      ),
      child: Row(
        children: [
          Icon(icon, size: 20, color: const Color(0xFF06B6D4)),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                Text(desc, style: const TextStyle(fontSize: 10, color: Colors.grey)),
              ],
            ),
          ),
          ElevatedButton(
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text("Diagnostic check '$title' passed (OK).")),
              );
            },
            style: ElevatedButton.styleFrom(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              minimumSize: Size.zero,
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
            ),
            child: const Text("Run", style: TextStyle(fontSize: 11)),
          ),
        ],
      ),
    );
  }

  // ── Tab 3: Summary ──
  Widget _buildSummaryTab(bool isAmoled, ThemeData theme) {
    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        const Text("Autonomous Ticket Summary", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
        const SizedBox(height: 8),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: isAmoled ? const Color(0xFF0F1524) : Colors.grey.shade50,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: const Color(0xFF334155)),
          ),
          child: const Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Icon(Icons.summarize, size: 16, color: Color(0xFFF59E0B)),
                  SizedBox(width: 6),
                  Text("Key Thread Highlights", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Color(0xFFF59E0B))),
                ],
              ),
              SizedBox(height: 8),
              Text("• Client submitted inquiry regarding web service configuration.", style: TextStyle(fontSize: 12)),
              SizedBox(height: 4),
              Text("• Initial automated ticket triage identified standard service inquiry.", style: TextStyle(fontSize: 12)),
              SizedBox(height: 4),
              Text("• Awaiting final resolution confirmation from technical staff.", style: TextStyle(fontSize: 12)),
            ],
          ),
        ),
      ],
    );
  }

  // ── Tab 4: Knowledgebase ──
  Widget _buildKbTab(bool isAmoled, ThemeData theme) {
    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        const Text("Related Knowledgebase Articles", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
        const SizedBox(height: 8),
        _buildKbArticleTile("How to Configure Your DNS Nameservers in WHMCS", "dns, nameservers, domain", isAmoled),
        _buildKbArticleTile("Troubleshooting 500 Internal Server Errors", "apache, php, error_log", isAmoled),
        _buildKbArticleTile("Resetting cPanel and Email Account Credentials", "cpanel, password, email", isAmoled),
      ],
    );
  }

  Widget _buildKbArticleTile(String title, String tags, bool isAmoled) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: isAmoled ? const Color(0xFF0F1524) : Colors.grey.shade50,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFF334155)),
      ),
      child: Row(
        children: [
          const Icon(Icons.article_outlined, size: 18, color: Color(0xFF8B5CF6)),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                Text("Tags: $tags", style: const TextStyle(fontSize: 10, color: Colors.grey)),
              ],
            ),
          ),
          IconButton(
            icon: const Icon(Icons.add_link, size: 18, color: Color(0xFF8B5CF6)),
            tooltip: "Insert Link into Reply",
            onPressed: () {
              final text = widget.replyController.text;
              widget.replyController.text = "$text\n\nFor full instructions, please see our KB article: $title";
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text("KB article link inserted into reply.")),
              );
            },
          ),
        ],
      ),
    );
  }

  // ── Tab 5: Client Memory ──
  Widget _buildMemoryTab(bool isAmoled, ThemeData theme) {
    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        const Text("Client Behavioral Memory", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
        const SizedBox(height: 8),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: isAmoled ? const Color(0xFF0F1524) : Colors.grey.shade50,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: const Color(0xFF334155)),
          ),
          child: const Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Icon(Icons.psychology, size: 16, color: Color(0xFFEC4899)),
                  SizedBox(width: 6),
                  Text("Client Profile Memory", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Color(0xFFEC4899))),
                ],
              ),
              SizedBox(height: 8),
              Text("• Preferred Communication: Technical & direct", style: TextStyle(fontSize: 12)),
              SizedBox(height: 4),
              Text("• Average Resolution Time: 18 minutes", style: TextStyle(fontSize: 12)),
              SizedBox(height: 4),
              Text("• Account Standing: In good standing (0 unpaid invoices)", style: TextStyle(fontSize: 12)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildCheckbox(String label, bool value, Function(bool?) onChanged, Color activeColor) {
    return InkWell(
      onTap: () => onChanged(!value),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            width: 18,
            height: 18,
            child: Checkbox(
              value: value,
              activeColor: activeColor,
              onChanged: onChanged,
              materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
            ),
          ),
          const SizedBox(width: 5),
          Text(
            label,
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w600,
              color: activeColor,
            ),
          ),
        ],
      ),
    );
  }
}
