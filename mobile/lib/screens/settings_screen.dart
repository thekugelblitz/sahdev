import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config/theme_config.dart';
import '../providers/auth_provider.dart';
import '../services/audio_service.dart';
import 'login_screen.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final auth = Provider.of<AuthProvider>(context);
    final audio = AudioService();

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      appBar: AppBar(
        title: const Text("Operator Settings"),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // Operator Profile Card
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Row(
              children: [
                Container(
                  width: 50,
                  height: 50,
                  decoration: const BoxDecoration(
                    color: ThemeConfig.darkBg,
                    shape: BoxShape.circle,
                  ),
                  child: Center(
                    child: Text(
                      auth.adminUser?.name.isNotEmpty == true
                          ? auth.adminUser!.name.substring(0, 1).toUpperCase()
                          : 'S',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w700,
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
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w700,
                          color: Color(0xFF0F172A),
                        ),
                      ),
                      Text(
                        auth.adminUser?.email ?? "",
                        style: const TextStyle(fontSize: 13, color: Color(0xFF64748B)),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        auth.baseUrl ?? "",
                        style: const TextStyle(
                          fontSize: 11,
                          color: Color(0xFF94A3B8),
                          fontFamily: 'monospace',
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),

          const SizedBox(height: 20),

          // Presence & Status
          const Text(
            "PRESENCE & DISPATCH",
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: Color(0xFF94A3B8),
              letterSpacing: 0.5,
            ),
          ),
          const SizedBox(height: 8),
          Container(
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Column(
              children: [
                SwitchListTile(
                  title: const Text(
                    "Online & Available for Chats",
                    style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                  ),
                  subtitle: Text(
                    auth.isOnline
                        ? "Actively listening for visitor summons"
                        : "Paused — background polling suspended",
                    style: const TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                  ),
                  value: auth.isOnline,
                  activeColor: ThemeConfig.statusOnline,
                  onChanged: (val) {
                    auth.toggleOnline(val);
                  },
                ),
              ],
            ),
          ),

          const SizedBox(height: 20),

          // Audio Alert Settings
          const Text(
            "AUDIO & NOTIFICATIONS",
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: Color(0xFF94A3B8),
              letterSpacing: 0.5,
            ),
          ),
          const SizedBox(height: 8),
          Container(
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Column(
              children: [
                RadioListTile<String>(
                  title: const Text(
                    "Persistent Ringing Alarm",
                    style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                  ),
                  subtitle: const Text(
                    "Rings continuously with vibration until you answer the summon",
                    style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                  ),
                  value: 'ringing',
                  groupValue: auth.alertMode,
                  onChanged: (val) {
                    if (val != null) auth.setAlertMode(val);
                  },
                ),
                const Divider(height: 1),
                RadioListTile<String>(
                  title: const Text(
                    "Single Chime Notification",
                    style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                  ),
                  subtitle: const Text(
                    "Plays a single chime alert when human support is requested",
                    style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                  ),
                  value: 'chime',
                  groupValue: auth.alertMode,
                  onChanged: (val) {
                    if (val != null) auth.setAlertMode(val);
                  },
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.volume_up_outlined, color: ThemeConfig.primary),
                  title: const Text(
                    "Test Alert Sound",
                    style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                  ),
                  trailing: TextButton(
                    onPressed: () {
                      if (auth.alertMode == 'ringing') {
                        audio.startAlarmRing(maxDurationSeconds: 4);
                      } else {
                        audio.playChime();
                      }
                    },
                    child: const Text("Play Sound"),
                  ),
                ),
              ],
            ),
          ),

          const SizedBox(height: 30),

          // Logout Button
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(
                foregroundColor: ThemeConfig.statusUrgent,
                side: const BorderSide(color: Color(0xFFFECACA)),
                backgroundColor: const Color(0xFFFEF2F2),
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              icon: const Icon(Icons.logout),
              label: const Text(
                "Disconnect Device & Logout",
                style: TextStyle(fontWeight: FontWeight.w700),
              ),
              onPressed: () async {
                final confirm = await showDialog<bool>(
                  context: context,
                  builder: (ctx) => AlertDialog(
                    title: const Text("Confirm Logout"),
                    content: const Text("Are you sure you want to disconnect this mobile device?"),
                    actions: [
                      TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text("Cancel")),
                      ElevatedButton(
                        style: ElevatedButton.styleFrom(backgroundColor: ThemeConfig.statusUrgent),
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
          ),
        ],
      ),
    );
  }
}
