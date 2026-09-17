import 'package:flutter/material.dart';
import '../config/theme_config.dart';
import '../models/chat_message.dart';

class MessageBubble extends StatelessWidget {
  final ChatMessage message;

  const MessageBubble({super.key, required this.message});

  @override
  Widget build(BuildContext context) {
    if (message.isSystem) {
      return _buildSystemEvent(message);
    }

    final isStaff = message.isStaff;
    final isAi = message.isAi;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      child: Column(
        crossAxisAlignment: isStaff ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          // Sender Header (for non-staff or AI)
          if (!isStaff)
            Padding(
              padding: const EdgeInsets.only(left: 4, bottom: 2),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (isAi) ...[
                    const Icon(Icons.auto_awesome, size: 12, color: ThemeConfig.statusAi),
                    const SizedBox(width: 4),
                    Text(
                      message.senderName.isNotEmpty ? message.senderName : "Sahdev AI",
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        color: ThemeConfig.statusAi,
                      ),
                    ),
                  ] else ...[
                    Text(
                      message.senderName.isNotEmpty ? message.senderName : "Visitor",
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFF64748B),
                      ),
                    ),
                  ],
                ],
              ),
            ),

          // Message Container
          Container(
            constraints: BoxConstraints(
              maxWidth: MediaQuery.of(context).size.width * 0.78,
            ),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: isStaff
                  ? ThemeConfig.primary
                  : isAi
                      ? const Color(0xFFF5F3FF)
                      : Colors.white,
              borderRadius: BorderRadius.only(
                topLeft: const Radius.circular(14),
                topRight: const Radius.circular(14),
                bottomLeft: Radius.circular(isStaff ? 14 : 2),
                bottomRight: Radius.circular(isStaff ? 2 : 14),
              ),
              border: Border.all(
                color: isStaff
                    ? ThemeConfig.primary
                    : isAi
                        ? const Color(0xFFDDD6FE)
                        : const Color(0xFFE2E8F0),
              ),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(0.02),
                  blurRadius: 4,
                  offset: const Offset(0, 1),
                ),
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  message.text,
                  style: TextStyle(
                    fontSize: 14,
                    color: isStaff ? Colors.white : const Color(0xFF0F172A),
                    height: 1.35,
                  ),
                ),
                const SizedBox(height: 4),
                Row(
                  mainAxisSize: MainAxisSize.min,
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    Text(
                      message.timeFormat,
                      style: TextStyle(
                        fontSize: 10,
                        color: isStaff ? Colors.white.withOpacity(0.75) : const Color(0xFF94A3B8),
                      ),
                    ),
                    if (isStaff) ...[
                      const SizedBox(width: 4),
                      Icon(
                        Icons.done_all,
                        size: 13,
                        color: Colors.white.withOpacity(0.85),
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSystemEvent(ChatMessage message) {
    return Center(
      child: Container(
        margin: const EdgeInsets.symmetric(vertical: 8, horizontal: 20),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
        decoration: BoxDecoration(
          color: const Color(0xFFF1F5F9),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: const Color(0xFFE2E8F0)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.info_outline, size: 12, color: Color(0xFF64748B)),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                message.text,
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w500,
                  color: Color(0xFF475569),
                ),
                textAlign: TextAlign.center,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
