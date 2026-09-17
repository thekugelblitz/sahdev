import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config/theme_config.dart';
import '../providers/auth_provider.dart';
import '../providers/chat_provider.dart';
import '../widgets/canned_responses_sheet.dart';
import '../widgets/client_details_sheet.dart';
import '../widgets/message_bubble.dart';
import '../widgets/sneak_peek_bar.dart';

class ChatScreen extends StatefulWidget {
  final int sessionId;
  final String sessionUuid;
  final String clientName;
  final bool initialTakenOver;
  final int? clientId;

  const ChatScreen({
    super.key,
    required this.sessionId,
    required this.sessionUuid,
    required this.clientName,
    required this.initialTakenOver,
    this.clientId,
  });

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final _textController = TextEditingController();
  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final auth = Provider.of<AuthProvider>(context, listen: false);
      if (auth.baseUrl != null && auth.token != null) {
        Provider.of<ChatProvider>(context, listen: false).openSession(
          sessionId: widget.sessionId,
          baseUrl: auth.baseUrl!,
          token: auth.token!,
          initialTakenOver: widget.initialTakenOver,
          clientId: widget.clientId,
        );
      }
    });
  }

  @override
  void dispose() {
    _textController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _scrollToBottom() {
    if (_scrollController.hasClients) {
      _scrollController.animateTo(
        _scrollController.position.maxScrollExtent + 80,
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
      );
    }
  }

  Future<void> _sendMessage() async {
    final text = _textController.text.trim();
    if (text.isEmpty) return;

    final auth = Provider.of<AuthProvider>(context, listen: false);
    final chat = Provider.of<ChatProvider>(context, listen: false);

    _textController.clear();
    _scrollToBottom();

    final staffName = auth.adminUser?.name ?? 'You';
    final success = await chat.sendMessage(
      baseUrl: auth.baseUrl!,
      token: auth.token!,
      text: text,
      staffName: staffName,
    );

    if (success) {
      _scrollToBottom();
    } else if (mounted) {
      // Restore input text so staff member never loses their work
      _textController.text = text;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Row(
            children: [
              const Icon(Icons.error_outline, color: Colors.white, size: 18),
              const SizedBox(width: 8),
              Expanded(child: Text(chat.errorMessage ?? "Failed to send reply. Please check connection.")),
            ],
          ),
          backgroundColor: const Color(0xFFDC2626),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  void _showCannedResponses() {
    final chat = Provider.of<ChatProvider>(context, listen: false);
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => CannedResponsesSheet(
        responses: chat.cannedResponses,
        onSelect: (text) {
          _textController.text = text;
        },
      ),
    );
  }

  void _showClientProfile() {
    final chat = Provider.of<ChatProvider>(context, listen: false);
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => ClientDetailsSheet(profile: chat.clientProfile),
    );
  }

  Future<void> _requestAiSuggestion() async {
    final auth = Provider.of<AuthProvider>(context, listen: false);
    final chat = Provider.of<ChatProvider>(context, listen: false);

    final suggestion = await chat.generateAiSuggestion(
      baseUrl: auth.baseUrl!,
      token: auth.token!,
    );

    if (suggestion != null && mounted) {
      _textController.text = suggestion;
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = Provider.of<AuthProvider>(context);
    final chat = Provider.of<ChatProvider>(context);

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              widget.clientName,
              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
            ),
            Row(
              children: [
                Container(
                  width: 7,
                  height: 7,
                  decoration: BoxDecoration(
                    color: chat.isTakenOver ? ThemeConfig.statusTakenOver : ThemeConfig.statusAi,
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 5),
                Text(
                  chat.isTakenOver ? "Human Takeover Active" : "Autonomous AI Responding",
                  style: const TextStyle(fontSize: 11, color: Color(0xFFCBD5E1)),
                ),
              ],
            ),
          ],
        ),
        actions: [
          // 1-Tap Takeover / Release to AI Button
          TextButton.icon(
            style: TextButton.styleFrom(
              foregroundColor: Colors.white,
              backgroundColor: chat.isTakenOver
                  ? const Color(0xFF334155)
                  : ThemeConfig.statusTakenOver,
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
            ),
            icon: Icon(
              chat.isTakenOver ? Icons.pause_circle_outline : Icons.play_circle_fill,
              size: 14,
            ),
            label: Text(
              chat.isTakenOver ? "Release" : "Take Over",
              style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700),
            ),
            onPressed: () {
              chat.toggleTakeover(baseUrl: auth.baseUrl!, token: auth.token!);
            },
          ),
          // Client Profile Details
          IconButton(
            icon: const Icon(Icons.account_circle_outlined),
            tooltip: "Client WHMCS Info",
            onPressed: _showClientProfile,
          ),
        ],
      ),
      body: Column(
        children: [
          // Messages Thread
          Expanded(
            child: chat.isLoadingMessages && chat.messages.isEmpty
                ? const Center(child: CircularProgressIndicator())
                : ListView.builder(
                    controller: _scrollController,
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    itemCount: chat.messages.length,
                    itemBuilder: (ctx, i) {
                      return MessageBubble(message: chat.messages[i]);
                    },
                  ),
          ),

          // Live Sneak-Peek Keystrokes Bar
          SneakPeekBar(
            isTyping: chat.isClientTyping,
            text: chat.typingPreview,
          ),

          // Quick Action Bar (AI Suggest, Canned Macros)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: const BoxDecoration(
              color: Color(0xFFF1F5F9),
              border: Border(top: BorderSide(color: Color(0xFFE2E8F0))),
            ),
            child: Row(
              children: [
                // AI Co-Pilot Suggestion Button
                InkWell(
                  onTap: chat.isGeneratingAi ? null : _requestAiSuggestion,
                  borderRadius: BorderRadius.circular(20),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: const Color(0xFFEDE9FE),
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: const Color(0xFFDDD6FE)),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        if (chat.isGeneratingAi)
                          const SizedBox(
                            width: 12,
                            height: 12,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              valueColor: AlwaysStoppedAnimation(ThemeConfig.statusAi),
                            ),
                          )
                        else
                          const Icon(Icons.auto_awesome, size: 13, color: ThemeConfig.statusAi),
                        const SizedBox(width: 5),
                        const Text(
                          "AI Suggest",
                          style: TextStyle(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w700,
                            color: ThemeConfig.statusAi,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),

                const SizedBox(width: 8),

                // Canned Macros Button
                InkWell(
                  onTap: _showCannedResponses,
                  borderRadius: BorderRadius.circular(20),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: const Color(0xFFCBD5E1)),
                    ),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.flash_on, size: 13, color: Color(0xFF64748B)),
                        SizedBox(width: 4),
                        Text(
                          "Canned",
                          style: TextStyle(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w600,
                            color: Color(0xFF475569),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),

                const Spacer(),

                // Warning badge if responding in AI mode
                if (!chat.isTakenOver)
                  const Text(
                    "Sending takes over",
                    style: TextStyle(fontSize: 10.5, color: Color(0xFF94A3B8)),
                  ),
              ],
            ),
          ),

          // Message Input Bar
          Container(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 14),
            decoration: const BoxDecoration(
              color: Colors.white,
              border: Border(top: BorderSide(color: Color(0xFFE2E8F0))),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: TextField(
                    controller: _textController,
                    minLines: 1,
                    maxLines: 4,
                    decoration: InputDecoration(
                      hintText: "Reply as human staff...",
                      hintStyle: const TextStyle(fontSize: 14, color: Color(0xFF94A3B8)),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(22),
                        borderSide: const BorderSide(color: Color(0xFFCBD5E1)),
                      ),
                      enabledBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(22),
                        borderSide: const BorderSide(color: Color(0xFFCBD5E1)),
                      ),
                      focusedBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(22),
                        borderSide: const BorderSide(color: ThemeConfig.primary, width: 1.5),
                      ),
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Container(
                  decoration: const BoxDecoration(
                    color: ThemeConfig.primary,
                    shape: BoxShape.circle,
                  ),
                  child: IconButton(
                    icon: chat.isSending
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              valueColor: AlwaysStoppedAnimation(Colors.white),
                            ),
                          )
                        : const Icon(Icons.send_rounded, color: Colors.white, size: 20),
                    onPressed: chat.isSending ? null : _sendMessage,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
