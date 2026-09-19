import 'package:flutter/material.dart';
import '../config/theme_config.dart';
import '../models/chat_message.dart';

class MessageBubble extends StatelessWidget {
  final ChatMessage message;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;

  const MessageBubble({
    super.key,
    required this.message,
    this.onEdit,
    this.onDelete,
  });

  void _showContextMenu(BuildContext context) {
    if (!message.isStaff || message.isDeleted || message.isSending) return;

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        final isDark = Theme.of(ctx).brightness == Brightness.dark;
        return Container(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
          decoration: BoxDecoration(
            color: isDark ? const Color(0xFF0F172A) : Colors.white,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
            border: Border.all(color: isDark ? const Color(0xFF1E293B) : const Color(0xFFE2E8F0)),
          ),
          child: SafeArea(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 36,
                  height: 4,
                  margin: const EdgeInsets.only(bottom: 12),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade600,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                ListTile(
                  leading: const Icon(Icons.edit_outlined, color: Color(0xFF0284C7)),
                  title: const Text('Edit Message', style: TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: const Text('Update text silently or notify client', style: TextStyle(fontSize: 12)),
                  onTap: () {
                    Navigator.pop(ctx);
                    onEdit?.call();
                  },
                ),
                ListTile(
                  leading: const Icon(Icons.delete_outline, color: Color(0xFFEF4444)),
                  title: const Text('Delete Message', style: TextStyle(fontWeight: FontWeight.w600, color: Color(0xFFEF4444))),
                  subtitle: const Text('Remove message from client widget', style: TextStyle(fontSize: 12)),
                  onTap: () {
                    Navigator.pop(ctx);
                    onDelete?.call();
                  },
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    if (message.isSystem) {
      return _buildSystemEvent(message);
    }

    final isStaff = message.isStaff;
    final isAi = message.isAi;
    final isDark = Theme.of(context).brightness == Brightness.dark;

    if (message.isDeleted) {
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
        child: Align(
          alignment: isStaff ? Alignment.centerRight : Alignment.centerLeft,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: BoxDecoration(
              color: isDark ? const Color(0xFF1E293B).withOpacity(0.5) : const Color(0xFFF1F5F9),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: isDark ? const Color(0xFF334155) : const Color(0xFFE2E8F0)),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.block, size: 13, color: Colors.grey.shade500),
                const SizedBox(width: 6),
                Text(
                  message.isSilent ? 'Message deleted (silently)' : 'This message was deleted',
                  style: TextStyle(
                    fontSize: 12,
                    fontStyle: FontStyle.italic,
                    color: Colors.grey.shade500,
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
      child: Column(
        crossAxisAlignment: isStaff ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          // Sender Header with Real Name & Badge
          Padding(
            padding: EdgeInsets.only(
              left: isStaff ? 0 : 4,
              right: isStaff ? 4 : 0,
              bottom: 3,
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (isStaff) ...[
                  const Icon(Icons.support_agent, size: 13, color: Color(0xFF0284C7)),
                  const SizedBox(width: 4),
                  Text(
                    message.senderName.isNotEmpty ? message.senderName : "Staff Specialist",
                    style: const TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: Color(0xFF0284C7),
                      letterSpacing: 0.2,
                    ),
                  ),
                ] else if (isAi) ...[
                  const Icon(Icons.auto_awesome, size: 12, color: ThemeConfig.statusAi),
                  const SizedBox(width: 4),
                  Text(
                    message.senderName.isNotEmpty ? message.senderName : "Sahdev AI",
                    style: const TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: ThemeConfig.statusAi,
                    ),
                  ),
                ] else ...[
                  const Icon(Icons.person_outline, size: 12, color: Color(0xFF94A3B8)),
                  const SizedBox(width: 4),
                  Text(
                    message.senderName.isNotEmpty ? message.senderName : "Visitor",
                    style: TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w600,
                      color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B),
                    ),
                  ),
                ],
              ],
            ),
          ),

          // Message Container with Long Press for Staff
          GestureDetector(
            onLongPress: isStaff ? () => _showContextMenu(context) : null,
            child: Container(
              constraints: BoxConstraints(
                maxWidth: MediaQuery.of(context).size.width * 0.80,
              ),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: isStaff
                    ? ThemeConfig.primary
                    : isAi
                        ? (isDark ? const Color(0xFF1E1B4B) : const Color(0xFFF5F3FF))
                        : (isDark ? const Color(0xFF111827) : Colors.white),
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
                          ? (isDark ? const Color(0xFF4338CA) : const Color(0xFFDDD6FE))
                          : (isDark ? const Color(0xFF1F2937) : const Color(0xFFE2E8F0)),
                ),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withOpacity(0.04),
                    blurRadius: 6,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SelectableText(
                    message.text,
                    style: TextStyle(
                      fontSize: 14,
                      color: isStaff
                          ? Colors.white
                          : (isDark ? const Color(0xFFF1F5F9) : const Color(0xFF0F172A)),
                      height: 1.38,
                    ),
                  ),
                  const SizedBox(height: 5),
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      Text(
                        message.timeFormat.isNotEmpty ? message.timeFormat : 'Just now',
                        style: TextStyle(
                          fontSize: 10,
                          color: isStaff
                              ? Colors.white.withOpacity(0.75)
                              : (isDark ? const Color(0xFF64748B) : const Color(0xFF94A3B8)),
                        ),
                      ),
                      if (message.isEdited) ...[
                        const SizedBox(width: 4),
                        Text(
                          '(edited)',
                          style: TextStyle(
                            fontSize: 9.5,
                            fontStyle: FontStyle.italic,
                            color: isStaff
                                ? Colors.white.withOpacity(0.75)
                                : (isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B)),
                          ),
                        ),
                      ],
                      if (isStaff) ...[
                        const SizedBox(width: 4),
                        if (message.isSending)
                          SizedBox(
                            width: 10,
                            height: 10,
                            child: CircularProgressIndicator(
                              strokeWidth: 1.5,
                              valueColor: AlwaysStoppedAnimation<Color>(Colors.white.withOpacity(0.8)),
                            ),
                          )
                        else
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
          ),
        ],
      ),
    );
  }

  Widget _buildSystemEvent(ChatMessage message) {
    return Center(
      child: Container(
        margin: const EdgeInsets.symmetric(vertical: 8, horizontal: 20),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
        decoration: BoxDecoration(
          color: const Color(0xFF0F172A).withOpacity(0.08),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: const Color(0xFF334155).withOpacity(0.2)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.info_outline, size: 13, color: Color(0xFF64748B)),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                message.text,
                style: const TextStyle(
                  fontSize: 11,
                  color: Color(0xFF64748B),
                  fontWeight: FontWeight.w500,
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
