/// Pure Dart HTML entity decoding utility.
/// Resolves HTML entity pollution (&quot;, &#039;, &amp;, &lt;, &gt;, etc.)
/// commonly leaked from WHMCS init.php input sanitization.
class HtmlUtils {
  static final Map<String, String> _namedEntities = {
    '&quot;': '"',
    '&#039;': "'",
    '&apos;': "'",
    '&amp;': '&',
    '&lt;': '<',
    '&gt;': '>',
    '&nbsp;': ' ',
    '&copy;': '©',
    '&reg;': '®',
    '&trade;': '™',
    '&bull;': '•',
    '&hellip;': '…',
    '&mdash;': '—',
    '&ndash;': '–',
    '&euro;': '€',
    '&pound;': '£',
    '&yen;': '¥',
    '&cent;': '¢',
    '&deg;': '°',
  };

  static final RegExp _numericDecEntity = RegExp(r'&#([0-9]+);');
  static final RegExp _numericHexEntity = RegExp(r'&#x([0-9a-fA-F]+);');

  /// Unescapes all HTML entities in [text], recursively up to 3 passes
  /// to eliminate compound escaping (e.g. `&amp;quot;` -> `&quot;` -> `"`).
  static String unescape(String? text) {
    if (text == null || text.isEmpty) return '';

    String result = text;
    for (int pass = 0; pass < 3; pass++) {
      String prev = result;

      // 1. Replace named entities
      _namedEntities.forEach((entity, replacement) {
        result = result.replaceAll(entity, replacement);
      });

      // 2. Replace decimal numeric entities (e.g. &#65; -> 'A')
      result = result.replaceAllMapped(_numericDecEntity, (match) {
        try {
          final code = int.parse(match.group(1)!);
          return String.fromCharCode(code);
        } catch (_) {
          return match.group(0)!;
        }
      });

      // 3. Replace hex numeric entities (e.g. &#x41; -> 'A')
      result = result.replaceAllMapped(_numericHexEntity, (match) {
        try {
          final code = int.parse(match.group(1)!, radix: 16);
          return String.fromCharCode(code);
        } catch (_) {
          return match.group(0)!;
        }
      });

      if (result == prev) break;
    }

    return result;
  }
}
