import 'package:flutter/material.dart';

class MarkdownEditorToolbar extends StatelessWidget {
  final TextEditingController controller;
  final FocusNode? focusNode;
  final VoidCallback? onChanged;

  const MarkdownEditorToolbar({
    super.key,
    required this.controller,
    this.focusNode,
    this.onChanged,
  });

  void _wrapSelection(String prefix, String suffix, {String placeholder = ''}) {
    final text = controller.text;
    final selection = controller.selection;
    final start = selection.start;
    final end = selection.end;

    if (start >= 0 && end >= 0 && start != end) {
      final selected = text.substring(start, end);
      final replacement = '$prefix$selected$suffix';
      final newText = text.replaceRange(start, end, replacement);
      controller.value = TextEditingValue(
        text: newText,
        selection: TextSelection(
          baseOffset: start + prefix.length,
          extentOffset: end + prefix.length,
        ),
      );
    } else {
      final insertPos = start >= 0 ? start : text.length;
      final replacement = '$prefix$placeholder$suffix';
      final newText = text.replaceRange(insertPos, insertPos, replacement);
      final selStart = insertPos + prefix.length;
      final selEnd = selStart + placeholder.length;
      controller.value = TextEditingValue(
        text: newText,
        selection: TextSelection(
          baseOffset: selStart,
          extentOffset: selEnd,
        ),
      );
    }
    focusNode?.requestFocus();
    onChanged?.call();
  }

  void _insertPrefix(String prefix) {
    final text = controller.text;
    final selection = controller.selection;
    final pos = selection.start >= 0 ? selection.start : text.length;

    String toInsert = prefix;
    if (pos > 0 && text[pos - 1] != '\n') {
      toInsert = '\n$prefix';
    }
    final newText = text.replaceRange(pos, pos, toInsert);
    controller.value = TextEditingValue(
      text: newText,
      selection: TextSelection.collapsed(offset: pos + toInsert.length),
    );
    focusNode?.requestFocus();
    onChanged?.call();
  }

  void _insertLink(BuildContext context) {
    final text = controller.text;
    final selection = controller.selection;
    final start = selection.start;
    final end = selection.end;

    String selectedText = '';
    if (start >= 0 && end >= 0 && start != end) {
      selectedText = text.substring(start, end);
    }

    final linkTitle = selectedText.isNotEmpty ? selectedText : 'link text';
    final replacement = '[$linkTitle](https://)';
    final insertStart = start >= 0 ? start : text.length;
    final insertEnd = end >= 0 ? end : insertStart;

    final newText = text.replaceRange(insertStart, insertEnd, replacement);
    // Select the url part: https://
    final urlStart = insertStart + linkTitle.length + 3;
    final urlEnd = urlStart + 8;

    controller.value = TextEditingValue(
      text: newText,
      selection: TextSelection(
        baseOffset: urlStart,
        extentOffset: urlEnd,
      ),
    );
    focusNode?.requestFocus();
    onChanged?.call();
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      height: 38,
      padding: const EdgeInsets.symmetric(horizontal: 4),
      decoration: BoxDecoration(
        color: isDark ? const Color(0xFF0F172A) : const Color(0xFFF1F5F9),
        border: Border(
          top: BorderSide(color: isDark ? const Color(0xFF1E293B) : const Color(0xFFE2E8F0)),
          bottom: BorderSide(color: isDark ? const Color(0xFF1E293B) : const Color(0xFFE2E8F0)),
        ),
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: [
            _buildBtn(
              icon: Icons.format_bold,
              tooltip: 'Bold (**text**)',
              onPressed: () => _wrapSelection('**', '**', placeholder: 'bold text'),
            ),
            _buildBtn(
              icon: Icons.format_italic,
              tooltip: 'Italic (*text*)',
              onPressed: () => _wrapSelection('*', '*', placeholder: 'italic text'),
            ),
            _buildBtn(
              icon: Icons.title,
              tooltip: 'Heading (### )',
              onPressed: () => _insertPrefix('### '),
            ),
            _buildDivider(isDark),
            _buildBtn(
              icon: Icons.code,
              tooltip: 'Inline Code (`code`)',
              onPressed: () => _wrapSelection('`', '`', placeholder: 'code'),
            ),
            _buildBtn(
              icon: Icons.terminal,
              tooltip: 'Code Block (```)',
              onPressed: () => _wrapSelection('```\n', '\n```', placeholder: 'code block'),
            ),
            _buildDivider(isDark),
            _buildBtn(
              icon: Icons.format_list_bulleted,
              tooltip: 'Bullet List (- )',
              onPressed: () => _insertPrefix('- '),
            ),
            _buildBtn(
              icon: Icons.format_list_numbered,
              tooltip: 'Numbered List (1. )',
              onPressed: () => _insertPrefix('1. '),
            ),
            _buildBtn(
              icon: Icons.format_quote,
              tooltip: 'Quote (> )',
              onPressed: () => _insertPrefix('> '),
            ),
            _buildDivider(isDark),
            _buildBtn(
              icon: Icons.link,
              tooltip: 'Insert Link ([text](url))',
              onPressed: () => _insertLink(context),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildBtn({
    required IconData icon,
    required String tooltip,
    required VoidCallback onPressed,
  }) {
    return IconButton(
      icon: Icon(icon, size: 17),
      tooltip: tooltip,
      splashRadius: 18,
      padding: const EdgeInsets.all(6),
      constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
      onPressed: onPressed,
    );
  }

  Widget _buildDivider(bool isDark) {
    return Container(
      width: 1,
      height: 18,
      margin: const EdgeInsets.symmetric(horizontal: 4),
      color: isDark ? const Color(0xFF334155) : const Color(0xFFCBD5E1),
    );
  }
}
