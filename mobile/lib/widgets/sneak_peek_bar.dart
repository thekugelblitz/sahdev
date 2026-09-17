import 'package:flutter/material.dart';

class SneakPeekBar extends StatelessWidget {
  final bool isTyping;
  final String text;

  const SneakPeekBar({
    super.key,
    required this.isTyping,
    required this.text,
  });

  @override
  Widget build(BuildContext context) {
    if (!isTyping || text.isEmpty) {
      return const SizedBox.shrink();
    }

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: const BoxDecoration(
        color: Color(0xFFEFF6FF),
        border: Border(
          top: BorderSide(color: Color(0xFFBFDBFE)),
          bottom: BorderSide(color: Color(0xFFBFDBFE)),
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          const SizedBox(
            width: 12,
            height: 12,
            child: CircularProgressIndicator(
              strokeWidth: 2,
              valueColor: AlwaysStoppedAnimation(Color(0xFF2563EB)),
            ),
          ),
          const SizedBox(width: 8),
          const Text(
            "Sneak Peek: ",
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: Color(0xFF1E40AF),
            ),
          ),
          Expanded(
            child: Text(
              '"$text"',
              style: const TextStyle(
                fontSize: 12.5,
                fontStyle: FontStyle.italic,
                color: Color(0xFF1E3A8A),
              ),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}
