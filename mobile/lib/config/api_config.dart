class ApiConfig {
  static const String defaultPollIntervalSeconds = "3";
  static const String appName = "Sahdev Live Support";
  static const String appVersion = "1.0.0";

  // Storage Keys
  static const String keyWhmcsUrl = "whmcs_url";
  static const String keyMobileToken = "mobile_token";
  static const String keyAdminUser = "admin_user_json";
  static const String keyIsOnline = "is_staff_online";
  static const String keySoundEnabled = "sound_enabled";
  static const String keyAlertMode = "alert_mode"; // 'ringing' or 'chime'
  static const String keyPollInterval = "poll_interval_secs";
  static const String keyWorkingEndpoint = "working_endpoint_url";

  /// Normalizes WHMCS Base URL to avoid trailing slashes, admin suffixes, and force secure protocol
  static String sanitizeUrl(String url) {
    String clean = url.trim();
    if (clean.isEmpty) return clean;

    // Ensure protocol scheme is present; default to https
    if (!clean.startsWith('http://') && !clean.startsWith('https://')) {
      clean = 'https://$clean';
    }

    // Strip trailing slashes
    while (clean.endsWith('/')) {
      clean = clean.substring(0, clean.length - 1);
    }

    // Strip admin directory paths if pasted by staff (e.g. /admin, /admin/addonmodules.php)
    clean = clean.replaceAll(RegExp(r'/admin/?$', caseSensitive: false), '');
    clean = clean.replaceAll(RegExp(r'/admin/.*$', caseSensitive: false), '');
    clean = clean.replaceAll(RegExp(r'/modules/addons/sahdev/?.*$', caseSensitive: false), '');
    clean = clean.replaceAll(RegExp(r'/index\.php\?.*$', caseSensitive: false), '');
    clean = clean.replaceAll(RegExp(r'/index\.php$', caseSensitive: false), '');

    while (clean.endsWith('/')) {
      clean = clean.substring(0, clean.length - 1);
    }

    return clean;
  }

  /// Returns candidate endpoints in order of reliability across WHMCS environments:
  /// 1. Native WHMCS front-controller router: /index.php?m=sahdev&action=...
  /// 2. Native WHMCS ajax router: /index.php?m=sahdev&sahdev_act=ajax_handler&action=...
  /// 3. Direct addon file: /modules/addons/sahdev/ajax.php?action=...
  static List<String> candidateEndpoints(String baseUrl, String action, [String extraQuery = '']) {
    final clean = sanitizeUrl(baseUrl);
    final query = extraQuery.isNotEmpty ? (extraQuery.startsWith('&') ? extraQuery : '&$extraQuery') : '';

    return [
      "$clean/index.php?m=sahdev&action=$action$query",
      "$clean/index.php?m=sahdev&sahdev_act=ajax_handler&action=$action$query",
      "$clean/modules/addons/sahdev/ajax.php?action=$action$query",
    ];
  }

  /// Builds the primary default endpoint URL
  static String buildEndpoint(String baseUrl, String action, [String extraQuery = '']) {
    final clean = sanitizeUrl(baseUrl);
    final query = extraQuery.isNotEmpty ? (extraQuery.startsWith('&') ? extraQuery : '&$extraQuery') : '';
    return "$clean/index.php?m=sahdev&action=$action$query";
  }

  /// Direct modules fallback endpoint
  static String buildDirectEndpoint(String baseUrl, String action, [String extraQuery = '']) {
    final clean = sanitizeUrl(baseUrl);
    final query = extraQuery.isNotEmpty ? (extraQuery.startsWith('&') ? extraQuery : '&$extraQuery') : '';
    return "$clean/modules/addons/sahdev/ajax.php?action=$action$query";
  }
}
