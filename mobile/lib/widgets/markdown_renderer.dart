import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';
import '../utils/html_utils.dart';

class MarkdownRenderer extends StatelessWidget {
  final String text;
  final TextStyle? style;
  final bool selectable;

  const MarkdownRenderer({
    super.key,
    required this.text,
    this.style,
    this.selectable = true,
  });

  @override
  Widget build(BuildContext context) {
    final unescaped = HtmlUtils.unescape(text);
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    final defaultStyle = style ??
        TextStyle(
          fontSize: 14,
          height: 1.5,
          color: isDark ? const Color(0xFFF1F5F9) : const Color(0xFF1E293B),
        );

    final blocks = _parseBlocks(unescaped);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: blocks.map((b) => _renderBlock(context, b, defaultStyle, isDark)).toList(),
    );
  }

  List<_MdBlock> _parseBlocks(String input) {
    final List<_MdBlock> blocks = [];
    final lines = input.split('\n');
    int i = 0;

    while (i < lines.length) {
      final line = lines[i];

      // Code block start
      if (line.trim().startsWith('```')) {
        final lang = line.trim().substring(3).trim();
        final codeLines = <String>[];
        i++;
        while (i < lines.length && !lines[i].trim().startsWith('```')) {
          codeLines.add(lines[i]);
          i++;
        }
        blocks.add(_MdBlock(
          type: _BlockType.codeBlock,
          content: codeLines.join('\n'),
          extra: lang,
        ));
        i++;
        continue;
      }

      // Horizontal rule
      if (RegExp(r'^(-{3,}|\*{3,}|_{3,})$').hasMatch(line.trim())) {
        blocks.add(_MdBlock(type: _BlockType.divider, content: ''));
        i++;
        continue;
      }

      // Headings
      if (line.startsWith('#')) {
        int level = 0;
        while (level < line.length && line[level] == '#') {
          level++;
        }
        if (level <= 6 && level < line.length && line[level] == ' ') {
          blocks.add(_MdBlock(
            type: _BlockType.heading,
            content: line.substring(level + 1).trim(),
            extra: level.toString(),
          ));
          i++;
          continue;
        }
      }

      // Blockquote
      if (line.startsWith('>')) {
        final quoteLines = <String>[line.substring(1).trimLeft()];
        i++;
        while (i < lines.length && lines[i].startsWith('>')) {
          quoteLines.add(lines[i].substring(1).trimLeft());
          i++;
        }
        blocks.add(_MdBlock(
          type: _BlockType.quote,
          content: quoteLines.join('\n'),
        ));
        continue;
      }

      // Unordered list
      if (RegExp(r'^\s*[-*+]\s+').hasMatch(line)) {
        final listItems = <String>[line.replaceFirst(RegExp(r'^\s*[-*+]\s+'), '')];
        i++;
        while (i < lines.length && RegExp(r'^\s*[-*+]\s+').hasMatch(lines[i])) {
          listItems.add(lines[i].replaceFirst(RegExp(r'^\s*[-*+]\s+'), ''));
          i++;
        }
        blocks.add(_MdBlock(
          type: _BlockType.unorderedList,
          content: '',
          listItems: listItems,
        ));
        continue;
      }

      // Ordered list
      if (RegExp(r'^\s*\d+\.\s+').hasMatch(line)) {
        final listItems = <String>[line.replaceFirst(RegExp(r'^\s*\d+\.\s+'), '')];
        i++;
        while (i < lines.length && RegExp(r'^\s*\d+\.\s+').hasMatch(lines[i])) {
          listItems.add(lines[i].replaceFirst(RegExp(r'^\s*\d+\.\s+'), ''));
          i++;
        }
        blocks.add(_MdBlock(
          type: _BlockType.orderedList,
          content: '',
          listItems: listItems,
        ));
        continue;
      }

      // Empty line
      if (line.trim().isEmpty) {
        i++;
        continue;
      }

      // Regular paragraph (group contiguous non-empty lines)
      final paraLines = <String>[line];
      i++;
      while (i < lines.length &&
          lines[i].trim().isNotEmpty &&
          !lines[i].startsWith('#') &&
          !lines[i].startsWith('>') &&
          !lines[i].trim().startsWith('```') &&
          !RegExp(r'^\s*[-*+]\s+').hasMatch(lines[i]) &&
          !RegExp(r'^\s*\d+\.\s+').hasMatch(lines[i]) &&
          !RegExp(r'^(-{3,}|\*{3,}|_{3,})$').hasMatch(lines[i].trim())) {
        paraLines.add(lines[i]);
        i++;
      }
      blocks.add(_MdBlock(
        type: _BlockType.paragraph,
        content: paraLines.join('\n'),
      ));
    }

    return blocks;
  }

  Widget _renderBlock(
    BuildContext context,
    _MdBlock block,
    TextStyle baseStyle,
    bool isDark,
  ) {
    switch (block.type) {
      case _BlockType.heading:
        final level = int.tryParse(block.extra ?? '1') ?? 1;
        double fontSize = 18;
        FontWeight weight = FontWeight.w700;
        if (level == 1) fontSize = 22;
        if (level == 2) fontSize = 19;
        if (level == 3) fontSize = 17;
        if (level >= 4) fontSize = 15;

        final headingStyle = baseStyle.copyWith(
          fontSize: fontSize,
          fontWeight: weight,
          color: isDark ? Colors.white : const Color(0xFF0F172A),
        );
        return Padding(
          padding: const EdgeInsets.only(top: 8, bottom: 4),
          child: _renderInline(context, block.content, headingStyle, isDark),
        );

      case _BlockType.codeBlock:
        return Container(
          margin: const EdgeInsets.symmetric(vertical: 6),
          decoration: BoxDecoration(
            color: isDark ? const Color(0xFF0F172A) : const Color(0xFF1E293B),
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: isDark ? const Color(0xFF334155) : const Color(0xFF475569)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (block.extra != null && block.extra!.isNotEmpty)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.black.withOpacity(0.25),
                    borderRadius: const BorderRadius.vertical(top: Radius.circular(7)),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        block.extra!,
                        style: const TextStyle(
                          fontSize: 11,
                          color: Color(0xFF94A3B8),
                          fontFamily: 'monospace',
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      InkWell(
                        onTap: () {
                          Clipboard.setData(ClipboardData(text: block.content));
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text('Code copied to clipboard'),
                              duration: Duration(seconds: 1),
                              behavior: SnackBarBehavior.floating,
                            ),
                          );
                        },
                        child: const Row(
                          children: [
                            Icon(Icons.copy, size: 12, color: Color(0xFF94A3B8)),
                            SizedBox(width: 4),
                            Text('Copy', style: TextStyle(fontSize: 11, color: Color(0xFF94A3B8))),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              Padding(
                padding: const EdgeInsets.all(10),
                child: SelectableText(
                  block.content,
                  style: const TextStyle(
                    fontFamily: 'monospace',
                    fontSize: 12.5,
                    height: 1.4,
                    color: Color(0xFFE2E8F0),
                  ),
                ),
              ),
            ],
          ),
        );

      case _BlockType.quote:
        return Container(
          margin: const EdgeInsets.symmetric(vertical: 4),
          padding: const EdgeInsets.only(left: 12, top: 4, bottom: 4, right: 8),
          decoration: BoxDecoration(
            border: Border(
              left: BorderSide(
                color: isDark ? const Color(0xFF8B5CF6) : const Color(0xFF6366F1),
                width: 3.5,
              ),
            ),
          ),
          child: _renderInline(
            context,
            block.content,
            baseStyle.copyWith(
              fontStyle: FontStyle.italic,
              color: isDark ? const Color(0xFFCBD5E1) : const Color(0xFF475569),
            ),
            isDark,
          ),
        );

      case _BlockType.unorderedList:
        return Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: (block.listItems ?? []).map((item) {
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.only(top: 6, right: 8),
                      child: Container(
                        width: 5,
                        height: 5,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B),
                        ),
                      ),
                    ),
                    Expanded(child: _renderInline(context, item, baseStyle, isDark)),
                  ],
                ),
              );
            }).toList(),
          ),
        );

      case _BlockType.orderedList:
        int num = 1;
        return Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: (block.listItems ?? []).map((item) {
              final idx = num++;
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(
                      width: 20,
                      child: Text(
                        '$idx.',
                        style: baseStyle.copyWith(
                          fontWeight: FontWeight.bold,
                          color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B),
                        ),
                      ),
                    ),
                    const SizedBox(width: 4),
                    Expanded(child: _renderInline(context, item, baseStyle, isDark)),
                  ],
                ),
              );
            }).toList(),
          ),
        );

      case _BlockType.divider:
        return Divider(
          height: 16,
          thickness: 1,
          color: isDark ? const Color(0xFF334155) : const Color(0xFFE2E8F0),
        );

      case _BlockType.paragraph:
      default:
        return Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: _renderInline(context, block.content, baseStyle, isDark),
        );
    }
  }

  Widget _renderInline(BuildContext context, String content, TextStyle baseStyle, bool isDark) {
    final spans = _parseInlineSpans(context, content, baseStyle, isDark);
    if (selectable) {
      return SelectableText.rich(
        TextSpan(children: spans),
        style: baseStyle,
      );
    } else {
      return Text.rich(
        TextSpan(children: spans),
        style: baseStyle,
      );
    }
  }

  List<InlineSpan> _parseInlineSpans(
    BuildContext context,
    String text,
    TextStyle baseStyle,
    bool isDark,
  ) {
    final List<InlineSpan> spans = [];

    // Regex matching:
    // 1. Links: [text](url)
    // 2. Bold+Italic: ***text***
    // 3. Bold: **text** or __text__
    // 4. Italic: *text* or _text_
    // 5. Inline Code: `code`
    // 6. Raw URLs: https?://\S+
    final inlineRegex = RegExp(
      r'(\[(.*?)\]\((https?:\/\/[^\s\)]+)\))|'
      r'(\*\*\*(.*?)\*\*\*)|'
      r'(\*\*(.*?)\*\*|__(.*?)__)|'
      r'(\*(.*?)\*|_(.*?)_)|'
      r'(`([^`]+)`)|'
      r'(https?:\/\/[^\s\)]+)',
    );

    int lastIndex = 0;
    for (final match in inlineRegex.allMatches(text)) {
      if (match.start > lastIndex) {
        spans.add(TextSpan(
          text: text.substring(lastIndex, match.start),
          style: baseStyle,
        ));
      }

      final matchStr = match.group(0)!;

      // Link [text](url)
      if (match.group(1) != null) {
        final linkText = match.group(2) ?? '';
        final url = match.group(3) ?? '';
        spans.add(
          TextSpan(
            text: linkText.isNotEmpty ? linkText : url,
            style: baseStyle.copyWith(
              color: const Color(0xFF38BDF8),
              decoration: TextDecoration.underline,
            ),
            recognizer: TapGestureRecognizer()..onTap = () => _launchUrl(url),
          ),
        );
      }
      // Bold+Italic ***text***
      else if (match.group(4) != null) {
        spans.add(TextSpan(
          text: match.group(5),
          style: baseStyle.copyWith(
            fontWeight: FontWeight.bold,
            fontStyle: FontStyle.italic,
          ),
        ));
      }
      // Bold **text** or __text__
      else if (match.group(6) != null) {
        final boldContent = match.group(7) ?? match.group(8) ?? '';
        spans.add(TextSpan(
          text: boldContent,
          style: baseStyle.copyWith(fontWeight: FontWeight.bold),
        ));
      }
      // Italic *text* or _text_
      else if (match.group(9) != null) {
        final italicContent = match.group(10) ?? match.group(11) ?? '';
        spans.add(TextSpan(
          text: italicContent,
          style: baseStyle.copyWith(fontStyle: FontStyle.italic),
        ));
      }
      // Inline Code `code`
      else if (match.group(12) != null) {
        final codeContent = match.group(13) ?? '';
        spans.add(WidgetSpan(
          alignment: PlaceholderAlignment.middle,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            decoration: BoxDecoration(
              color: isDark ? const Color(0xFF1E293B) : const Color(0xFFE2E8F0),
              borderRadius: BorderRadius.circular(4),
              border: Border.all(
                color: isDark ? const Color(0xFF334155) : const Color(0xFFCBD5E1),
                width: 0.5,
              ),
            ),
            child: Text(
              codeContent,
              style: TextStyle(
                fontFamily: 'monospace',
                fontSize: baseStyle.fontSize != null ? baseStyle.fontSize! * 0.9 : 12.5,
                color: isDark ? const Color(0xFFF43F5E) : const Color(0xFFBE123C),
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ));
      }
      // Raw URL
      else if (match.group(14) != null) {
        final url = matchStr;
        spans.add(
          TextSpan(
            text: url,
            style: baseStyle.copyWith(
              color: const Color(0xFF38BDF8),
              decoration: TextDecoration.underline,
            ),
            recognizer: TapGestureRecognizer()..onTap = () => _launchUrl(url),
          ),
        );
      }

      lastIndex = match.end;
    }

    if (lastIndex < text.length) {
      spans.add(TextSpan(
        text: text.substring(lastIndex),
        style: baseStyle,
      ));
    }

    return spans;
  }

  Future<void> _launchUrl(String url) async {
    try {
      final uri = Uri.parse(url);
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      }
    } catch (_) {}
  }
}

enum _BlockType {
  paragraph,
  heading,
  codeBlock,
  quote,
  unorderedList,
  orderedList,
  divider,
}

class _MdBlock {
  final _BlockType type;
  final String content;
  final String? extra;
  final List<String>? listItems;

  _MdBlock({
    required this.type,
    required this.content,
    this.extra,
    this.listItems,
  });
}
