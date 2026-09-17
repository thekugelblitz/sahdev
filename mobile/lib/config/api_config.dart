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

  /// Normalizes WHMCS Base URL to avoid trailing slashes
  static String sanitizeUrl(String url) {
    String clean = url.trim();
    while (clean.endsWith('/')) {
      clean = clean.substring(0, clean.length - 1);
    }
    return clean;
  }

  /// Builds the full endpoint URL for ajax.php
  static String buildEndpoint(String baseUrl, String action) {
    final sanitized = sanitizeUrl(baseUrl);
    return "$sanitized/modules/addons/sahdev/ajax.php?action=$action";
  }

  /// Fallback direct root endpoint if modules path is rewritten
  static String buildRootEndpoint(String baseUrl, String action) {
    final sanitized = sanitizeUrl(baseUrl);
    return "$sanitized/index.php?m=sahdev&action=$action";
  }
}
