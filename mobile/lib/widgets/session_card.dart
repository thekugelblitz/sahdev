import 'package:flutter/material.dart';
import '../config/theme_config.dart';
import '../models/chat_session.dart';

class SessionCard extends StatelessWidget {
  final ChatSession session;
  final VoidCallback onTap;

  const SessionCard({
    super.key,
    required this.session,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final isUrgent = session.isUrgentSummon;
    final isTakenOver = session.isTakenOver;
    final isTyping = session.typing.isTyping;
    final typingPreview = session.typing.preview;

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
      decoration: BoxDecoration(
        color: isUrgent ? const Color(0xFFFFF1F2) : Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: isUrgent
              ? ThemeConfig.statusUrgent
              : isTakenOver
                  ? ThemeConfig.statusTakenOver.withOpacity(0.4)
                  : const Color(0xFFE2E8F0),
          width: isUrgent ? 1.8 : 1,
        ),
        boxShadow: [
          BoxShadow(
            color: isUrgent
                ? ThemeConfig.statusUrgent.withOpacity(0.08)
                : Colors.black.withOpacity(0.03),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Top Row: Avatar, Name, Domain, Status Badge
                Row(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    // Avatar circle
                    Container(
                      width: 42,
                      height: 42,
                      decoration: BoxDecoration(
                        color: isUrgent
                            ? ThemeConfig.statusUrgent
                            : isTakenOver
                                ? ThemeConfig.statusTakenOver
                                : ThemeConfig.primary,
                        shape: BoxShape.circle,
                      ),
                      child: Center(
                        child: Text(
                          session.client.name.isNotEmpty
                              ? session.client.name.substring(0, 1).toUpperCase()
                              : 'V',
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w700,
                            fontSize: 18,
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),

                    // Client details
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Flexible(
                                child: Text(
                                  session.client.name,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w700,
                                    fontSize: 15,
                                    color: Color(0xFF0F172A),
                                  ),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              if (session.client.isRegistered) ...[
                                const SizedBox(width: 4),
                                const Icon(
                                  Icons.verified,
                                  size: 14,
                                  color: Color(0xFF10B981),
                                ),
                              ],
                            ],
                          ),
                          const SizedBox(height: 2),
                          Row(
                            children: [
                              const Icon(Icons.language, size: 12, color: Color(0xFF94A3B8)),
                              const SizedBox(width: 4),
                              Flexible(
                                child: Text(
                                  session.source.domain,
                                  style: const TextStyle(
                                    fontSize: 12,
                                    color: Color(0xFF64748B),
                                  ),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),

                    // Status Pill
                    _buildStatusPill(session),
                  ],
                ),

                const SizedBox(height: 10),

                // Urgent Summon Banner if requested
                if (isUrgent)
                  Container(
                    margin: const EdgeInsets.only(bottom: 8),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: ThemeConfig.statusUrgent.withOpacity(0.12),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.warning_amber_rounded, size: 14, color: ThemeConfig.statusUrgent),
                        SizedBox(width: 5),
                        Text(
                          "SUMMONED HUMAN SUPPORT",
                          style: TextStyle(
                            color: ThemeConfig.statusUrgent,
                            fontSize: 11,
                            fontWeight: FontWeight.w800,
                            letterSpacing: 0.3,
                          ),
                        ),
                      ],
                    ),
                  ),

                // Sneak-peek Keystroke Bar if visitor is typing!
                if (isTyping && typingPreview.isNotEmpty)
                  Container(
                    margin: const EdgeInsets.only(bottom: 8),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: const Color(0xFFEEF2FF),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: const Color(0xFFC7D2FE)),
                    ),
                    child: Row(
                      children: [
                        const SizedBox(
                          width: 12,
                          height: 12,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            valueColor: AlwaysStoppedAnimation(Color(0xFF6366F1)),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: RichText(
                            text: TextSpan(
                              children: [
                                const TextSpan(
                                  text: "Typing sneak-peek: ",
                                  style: TextStyle(
                                    fontSize: 12,
                                    fontWeight: FontWeight.w600,
                                    color: Color(0xFF4338CA),
                                  ),
                                ),
                                TextSpan(
                                  text: '"$typingPreview"',
                                  style: const TextStyle(
                                    fontSize: 12,
                                    fontStyle: FontStyle.italic,
                                    color: Color(0xFF312E81),
                                  ),
                                ),
                              ],
                            ),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                  ),

                // Last Message & Time Ago
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        session.lastMessage?.text ?? 'No messages yet',
                        style: TextStyle(
                          fontSize: 13,
                          color: session.unreadCount > 0
                              ? const Color(0xFF0F172A)
                              : const Color(0xFF64748B),
                          fontWeight: session.unreadCount > 0
                              ? FontWeight.w600
                              : FontWeight.normal,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    if (session.lastMessage?.timeAgo != null) ...[
                      const SizedBox(width: 8),
                      Text(
                        session.lastMessage!.timeAgo!,
                        style: const TextStyle(
                          fontSize: 11,
                          color: Color(0xFF94A3B8),
                        ),
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildStatusPill(ChatSession session) {
    if (session.isUrgentSummon) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: ThemeConfig.statusUrgent,
          borderRadius: BorderRadius.circular(12),
        ),
        child: const Text(
          "URGENT",
          style: TextStyle(color: Colors.white, fontSize: 10.5, fontWeight: FontWeight.w700),
        ),
      );
    }
    if (session.isTakenOver) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: ThemeConfig.statusTakenOver,
          borderRadius: BorderRadius.circular(12),
        ),
        child: const Text(
          "Human Staff",
          style: TextStyle(color: Colors.white, fontSize: 10.5, fontWeight: FontWeight.w700),
        ),
      );
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFCBD5E1)),
      ),
      child: const Text(
        "AI Autopilot",
        style: TextStyle(color: Color(0xFF475569), fontSize: 10.5, fontWeight: FontWeight.w600),
      ),
    );
  }
}
