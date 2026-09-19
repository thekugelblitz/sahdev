import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../config/theme_config.dart';
import '../providers/auth_provider.dart';
import '../providers/theme_provider.dart';
import '../services/audio_service.dart';
import '../services/background_service.dart';
import '../services/fcm_service.dart';
import 'login_screen.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  final AudioService _audio = AudioService();
  String? _previewingSoundKey;
  bool _notifySummons = true;
  bool _notifyVisitors = true;
  bool _notifyChats = true;
  bool _notifyTickets = true;
  bool _notifySystem = true;
  String _alertMode = 'ringing';

  @override
  void initState() {
    super.initState();
    _loadNotificationPreferences();
  }

  Future<void> _loadNotificationPreferences() async {
    final prefs = await SharedPreferences.getInstance();
    if (mounted) {
      setState(() {
        _notifySummons = prefs.getBool('pref_notify_summons') ?? true;
        _notifyVisitors = prefs.getBool('pref_notify_visitors') ?? true;
        _notifyChats = prefs.getBool('pref_notify_chats') ?? true;
        _notifyTickets = prefs.getBool('pref_notify_tickets') ?? true;
        _notifySystem = prefs.getBool('pref_notify_system') ?? true;
        _alertMode = prefs.getString('pref_alert_mode') ?? 'ringing';
      });
    }
  }

  Future<void> _setPreference(String key, dynamic value) async {
    final prefs = await SharedPreferences.getInstance();
    if (value is bool) {
      await prefs.setBool(key, value);
    } else if (value is String) {
      await prefs.setString(key, value);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isAmoled = theme.scaffoldBackgroundColor == Colors.black;
    final auth = context.watch<AuthProvider>();
    final themeProv = context.watch<ThemeProvider>();

    return Scaffold(
      appBar: AppBar(
        title: const Text("App Settings & Alerts", style: TextStyle(fontWeight: FontWeight.w800)),
      ),
      body: ListView(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        children: [
          // Operator Profile Card
          _buildProfileCard(auth, theme, isAmoled),

          const SizedBox(height: 18),

          // AMOLED Theme Selector Card
          _buildSectionHeader("DISPLAY & THEME"),
          _buildThemeCard(themeProv, theme, isAmoled),

          const SizedBox(height: 18),

          // Presence Card
          _buildSectionHeader("PRESENCE & DISPATCH"),
          _buildPresenceCard(auth, theme),

          const SizedBox(height: 18),

          // Notification Sound Library Card (12 Sounds)
          _buildSectionHeader("ALERT SOUND LIBRARY (12 SOUNDS)"),
          _buildSoundPickerCard(theme, isAmoled),

          const SizedBox(height: 18),

          // Push Notifications & Firebase Toggles
          _buildSectionHeader("PUSH NOTIFICATIONS & FIREBASE (FCM)"),
          _buildPushNotificationTogglesCard(theme, isAmoled),

          const SizedBox(height: 18),

          // Alert Duration & Vibration Card
          _buildSectionHeader("PLAYBACK DURATION & VIBRATION"),
          _buildDurationAndVibrationCard(theme, isAmoled),

          const SizedBox(height: 18),

          // Battery Optimization Card
          _buildSectionHeader("RELIABILITY & BACKGROUND EXECUTION"),
          _buildBatteryOptimizationCard(theme, isAmoled),

          const SizedBox(height: 24),

          // Logout Button
          _buildLogoutButton(context, auth),

          const SizedBox(height: 30),
        ],
      ),
    );
  }

  Widget _buildSectionHeader(String title) {
    return Padding(
      padding: const EdgeInsets.only(left: 4, bottom: 8),
      child: Text(
        title,
        style: const TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          color: Color(0xFF94A3B8),
          letterSpacing: 0.6,
        ),
      ),
    );
  }

  Widget _buildProfileCard(AuthProvider auth, ThemeData theme, bool isAmoled) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                color: isAmoled ? const Color(0xFF141A29) : theme.colorScheme.primary.withOpacity(0.15),
                shape: BoxShape.circle,
                border: Border.all(color: theme.colorScheme.primary.withOpacity(0.4)),
              ),
              child: Center(
                child: Text(
                  auth.adminUser?.name.isNotEmpty == true ? auth.adminUser!.name[0].toUpperCase() : 'S',
                  style: TextStyle(
                    color: theme.colorScheme.primary,
                    fontSize: 22,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    auth.adminUser?.name ?? "Staff Operator",
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    auth.adminUser?.email ?? "",
                    style: const TextStyle(fontSize: 13, color: Colors.grey),
                  ),
                  const SizedBox(height: 4),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: isAmoled ? const Color(0xFF0F1524) : Colors.grey.shade200,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      auth.baseUrl ?? "",
                      style: const TextStyle(fontSize: 11, color: Colors.grey, fontFamily: 'monospace'),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildThemeCard(ThemeProvider themeProv, ThemeData theme, bool isAmoled) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Column(
          children: [
            RadioListTile<String>(
              title: const Row(
                children: [
                  Text("AMOLED True Black", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  SizedBox(width: 8),
                  Text("⚡ Radiant Neon", style: TextStyle(fontSize: 11, color: Color(0xFF06B6D4), fontWeight: FontWeight.w600)),
                ],
              ),
              subtitle: const Text("Pure #000000 pitch black for maximum battery savings and high contrast", style: TextStyle(fontSize: 12, color: Colors.grey)),
              value: 'amoled',
              groupValue: themeProv.currentTheme,
              onChanged: (val) {
                if (val != null) themeProv.setTheme(val);
              },
            ),
            const Divider(),
            RadioListTile<String>(
              title: const Text("Modern Dark (Slate)", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              subtitle: const Text("Subtle deep slate blue background", style: TextStyle(fontSize: 12, color: Colors.grey)),
              value: 'dark',
              groupValue: themeProv.currentTheme,
              onChanged: (val) {
                if (val != null) themeProv.setTheme(val);
              },
            ),
            const Divider(),
            RadioListTile<String>(
              title: const Text("Clean Light", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              subtitle: const Text("Bright daylight mode with crisp cards", style: TextStyle(fontSize: 12, color: Colors.grey)),
              value: 'light',
              groupValue: themeProv.currentTheme,
              onChanged: (val) {
                if (val != null) themeProv.setTheme(val);
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildPresenceCard(AuthProvider auth, ThemeData theme) {
    return Card(
      child: SwitchListTile(
        title: const Text("Online & Available for Chats", style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
        subtitle: Text(
          auth.isOnline ? "Actively listening for incoming visitor summons" : "Paused — background polling suspended",
          style: const TextStyle(fontSize: 12, color: Colors.grey),
        ),
        value: auth.isOnline,
        activeColor: const Color(0xFF10B981),
        onChanged: (val) => auth.toggleOnline(val),
      ),
    );
  }

  Widget _buildSoundPickerCard(ThemeData theme, bool isAmoled) {
    return Card(
      child: Column(
        children: AudioService.availableSounds.map((sound) {
          final isSelected = _audio.selectedSoundKey == sound.key;
          final isPreviewing = _previewingSoundKey == sound.key;

          return Column(
            children: [
              ListTile(
                leading: Text(sound.icon, style: const TextStyle(fontSize: 22)),
                title: Row(
                  children: [
                    Text(
                      sound.title,
                      style: TextStyle(
                        fontSize: 14,
                        fontWeight: isSelected ? FontWeight.w800 : FontWeight.w600,
                        color: isSelected ? theme.colorScheme.primary : null,
                      ),
                    ),
                    if (isSelected) ...[
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                        decoration: BoxDecoration(
                          color: theme.colorScheme.primary.withOpacity(0.2),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          "ACTIVE",
                          style: TextStyle(fontSize: 9, fontWeight: FontWeight.bold, color: theme.colorScheme.primary),
                        ),
                      ),
                    ],
                  ],
                ),
                subtitle: Text(sound.description, style: const TextStyle(fontSize: 12, color: Colors.grey)),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    IconButton(
                      icon: Icon(
                        isPreviewing ? Icons.stop_circle_outlined : Icons.play_circle_outline,
                        color: isPreviewing ? const Color(0xFFEF4444) : theme.colorScheme.primary,
                        size: 26,
                      ),
                      tooltip: "Preview Sound",
                      onPressed: () async {
                        setState(() => _previewingSoundKey = sound.key);
                        await _audio.previewSound(sound.key);
                        await Future.delayed(const Duration(milliseconds: 1800));
                        if (mounted) setState(() => _previewingSoundKey = null);
                      },
                    ),
                    Radio<String>(
                      value: sound.key,
                      groupValue: _audio.selectedSoundKey,
                      activeColor: theme.colorScheme.primary,
                      onChanged: (val) async {
                        if (val != null) {
                          await _audio.setSoundKey(val);
                          setState(() {});
                          await _audio.previewSound(val);
                        }
                      },
                    ),
                  ],
                ),
                onTap: () async {
                  await _audio.setSoundKey(sound.key);
                  setState(() {});
                  await _audio.previewSound(sound.key);
                },
              ),
              if (sound != AudioService.availableSounds.last) const Divider(height: 1),
            ],
          );
        }).toList(),
      ),
    );
  }

  Widget _buildDurationAndVibrationCard(ThemeData theme, bool isAmoled) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Sound Playback Duration
            const Row(
              children: [
                Icon(Icons.timer_outlined, size: 18, color: Color(0xFF06B6D4)),
                SizedBox(width: 8),
                Text("Sound Alert Playback Duration", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              ],
            ),
            const SizedBox(height: 6),
            const Text("Controls how long the audio repeats when summoned until dismissed", style: TextStyle(fontSize: 12, color: Colors.grey)),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _buildDurationChip(5, "5s", theme),
                _buildDurationChip(10, "10s", theme),
                _buildDurationChip(15, "15s", theme),
                _buildDurationChip(30, "30s (Default)", theme),
                _buildDurationChip(60, "60s", theme),
                _buildDurationChip(0, "Continuous Loop", theme),
              ],
            ),

            const SizedBox(height: 16),
            const Divider(),
            const SizedBox(height: 12),

            // Vibration Pattern
            const Row(
              children: [
                Icon(Icons.vibration, size: 18, color: Color(0xFFF59E0B)),
                SizedBox(width: 8),
                Text("Vibration Pattern", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              ],
            ),
            const SizedBox(height: 6),
            const Text("Haptic vibration cadence when an alert is fired", style: TextStyle(fontSize: 12, color: Colors.grey)),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _buildVibrationChip("heavy", "Heavy Urgency", theme),
                _buildVibrationChip("double", "Double Alert", theme),
                _buildVibrationChip("gentle", "Gentle Pulse", theme),
                _buildVibrationChip("sos", "SOS Morse Code", theme),
                _buildVibrationChip("none", "Silent / Off", theme),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDurationChip(int seconds, String label, ThemeData theme) {
    final isSelected = _audio.alertDurationSeconds == seconds;
    final primary = theme.colorScheme.primary;

    return ChoiceChip(
      selected: isSelected,
      label: Text(label, style: TextStyle(fontSize: 12, fontWeight: isSelected ? FontWeight.bold : FontWeight.normal)),
      selectedColor: primary.withOpacity(0.25),
      onSelected: (_) async {
        await _audio.setAlertDuration(seconds);
        setState(() {});
      },
    );
  }

  Widget _buildVibrationChip(String pattern, String label, ThemeData theme) {
    final isSelected = _audio.vibrationPattern == pattern;
    final primary = theme.colorScheme.primary;

    return ChoiceChip(
      selected: isSelected,
      label: Text(label, style: TextStyle(fontSize: 12, fontWeight: isSelected ? FontWeight.bold : FontWeight.normal)),
      selectedColor: primary.withOpacity(0.25),
      onSelected: (_) async {
        await _audio.setVibrationPattern(pattern);
        setState(() {});
      },
    );
  }

  Widget _buildBatteryOptimizationCard(ThemeData theme, bool isAmoled) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.battery_charging_full, size: 20, color: Color(0xFF10B981)),
                SizedBox(width: 8),
                Text("Allow in Battery Optimization", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              ],
            ),
            const SizedBox(height: 8),
            const Text(
              "Android automatically puts background apps to sleep when your phone is locked. To ensure sound alerts play instantly when a customer summons support, exclude Sahdev Support from battery limits.",
              style: TextStyle(fontSize: 12, color: Colors.grey, height: 1.4),
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF10B981),
                  foregroundColor: Colors.black,
                  padding: const EdgeInsets.symmetric(vertical: 12),
                ),
                icon: const Icon(Icons.shield_outlined, size: 18),
                label: const Text("Open Battery Exemption Settings", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                onPressed: () async {
                  final ok = await BackgroundService.requestIgnoreBatteryOptimizations();
                  if (!ok && mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text("Opened app settings. Please toggle 'Allow unrestricted background battery'."),
                        duration: Duration(seconds: 4),
                      ),
                    );
                  }
                },
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildPushNotificationTogglesCard(ThemeData theme, bool isAmoled) {
    final fcmToken = FcmService().fcmToken;
    final hasFcm = fcmToken != null && fcmToken.isNotEmpty;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Row(
                  children: [
                    Icon(Icons.notifications_active, size: 20, color: Color(0xFFF59E0B)),
                    SizedBox(width: 8),
                    Text("Push Notification Channels", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  ],
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: hasFcm ? const Color(0xFF10B981).withOpacity(0.15) : Colors.orange.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(hasFcm ? Icons.check_circle : Icons.warning_amber_rounded, size: 12, color: hasFcm ? const Color(0xFF10B981) : Colors.orange),
                      const SizedBox(width: 4),
                      Text(
                        hasFcm ? "FCM Active" : "FCM Pending",
                        style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: hasFcm ? const Color(0xFF10B981) : Colors.orange),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            const Text(
              "Control which support events wake your phone via native Google Cloud push messaging.",
              style: TextStyle(fontSize: 12, color: Colors.grey, height: 1.4),
            ),
            const Divider(height: 20),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: const Text("Urgent Human Summons", style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              subtitle: const Text("Visitor clicks 'Talk to Human' on website live chat", style: TextStyle(fontSize: 11)),
              value: _notifySummons,
              activeColor: const Color(0xFFF59E0B),
              onChanged: (val) {
                setState(() => _notifySummons = val);
                _setPreference('pref_notify_summons', val);
              },
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: const Text("New Website Visitors", style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              subtitle: const Text("A new visitor opens chat widget or starts browsing", style: TextStyle(fontSize: 11)),
              value: _notifyVisitors,
              activeColor: const Color(0xFF06B6D4),
              onChanged: (val) {
                setState(() => _notifyVisitors = val);
                _setPreference('pref_notify_visitors', val);
              },
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: const Text("Active Chat Messages", style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              subtitle: const Text("New incoming messages on live visitor sessions", style: TextStyle(fontSize: 11)),
              value: _notifyChats,
              activeColor: const Color(0xFF3B82F6),
              onChanged: (val) {
                setState(() => _notifyChats = val);
                _setPreference('pref_notify_chats', val);
              },
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: const Text("WHMCS Support Tickets", style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              subtitle: const Text("New tickets and customer replies in WHMCS", style: TextStyle(fontSize: 11)),
              value: _notifyTickets,
              activeColor: const Color(0xFF10B981),
              onChanged: (val) {
                setState(() => _notifyTickets = val);
                _setPreference('pref_notify_tickets', val);
              },
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: const Text("System & AI Status Alerts", style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              subtitle: const Text("AI fallback events and critical API notices", style: TextStyle(fontSize: 11)),
              value: _notifySystem,
              activeColor: const Color(0xFF8B5CF6),
              onChanged: (val) {
                setState(() => _notifySystem = val);
                _setPreference('pref_notify_system', val);
              },
            ),
            const Divider(height: 16),
            const Text(
              "SUMMON ALERT STYLE",
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF94A3B8), letterSpacing: 0.5),
            ),
            const SizedBox(height: 4),
            RadioListTile<String>(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: const Text("Continuous Ringing Alarm", style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              subtitle: const Text("Rings like an urgent incoming call until opened or silenced", style: TextStyle(fontSize: 11)),
              value: 'ringing',
              groupValue: _alertMode,
              activeColor: const Color(0xFFF59E0B),
              onChanged: (val) {
                if (val != null) {
                  setState(() => _alertMode = val);
                  _setPreference('pref_alert_mode', val);
                  context.read<AuthProvider>().setAlertMode(val);
                }
              },
            ),
            RadioListTile<String>(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: const Text("Single Notification Chime", style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              subtitle: const Text("Plays one sound alert without ongoing ringing", style: TextStyle(fontSize: 11)),
              value: 'chime',
              groupValue: _alertMode,
              activeColor: const Color(0xFFF59E0B),
              onChanged: (val) {
                if (val != null) {
                  setState(() => _alertMode = val);
                  _setPreference('pref_alert_mode', val);
                  context.read<AuthProvider>().setAlertMode(val);
                }
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildLogoutButton(BuildContext context, AuthProvider auth) {
    return SizedBox(
      width: double.infinity,
      child: OutlinedButton.icon(
        style: OutlinedButton.styleFrom(
          foregroundColor: const Color(0xFFEF4444),
          side: const BorderSide(color: Color(0xFFFECACA)),
          padding: const EdgeInsets.symmetric(vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
        icon: const Icon(Icons.logout),
        label: const Text("Disconnect Device & Logout", style: TextStyle(fontWeight: FontWeight.w700)),
        onPressed: () async {
          final confirm = await showDialog<bool>(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text("Confirm Logout"),
              content: const Text("Are you sure you want to disconnect this mobile device from WHMCS?"),
              actions: [
                TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text("Cancel")),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFEF4444)),
                  onPressed: () => Navigator.pop(ctx, true),
                  child: const Text("Logout"),
                ),
              ],
            ),
          );

          if (confirm == true && context.mounted) {
            await auth.logout();
            if (context.mounted) {
              Navigator.of(context).pushAndRemoveUntil(
                MaterialPageRoute(builder: (_) => const LoginScreen()),
                (_) => false,
              );
            }
          }
        },
      ),
    );
  }
}
