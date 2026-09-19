import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'config/theme_config.dart';
import 'providers/auth_provider.dart';
import 'providers/queue_provider.dart';
import 'providers/chat_provider.dart';
import 'providers/ticket_provider.dart';
import 'providers/theme_provider.dart';
import 'services/notification_service.dart';
import 'screens/splash_screen.dart';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'services/fcm_service.dart';

final GlobalKey<NavigatorState> appNavigatorKey = GlobalKey<NavigatorState>();

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // 1. Initialize Android local notification channels
  try {
    await NotificationService().init();
  } catch (e) {
    debugPrint("NotificationService init error: $e");
  }

  // 2. Register FCM top-level background handler
  try {
    FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
  } catch (e) {
    debugPrint("FCM background handler registration error: $e");
  }

  // 3. Initialize Firebase & FCM service
  try {
    await FcmService().init();
  } catch (e) {
    debugPrint("FcmService init error: $e");
  }

  runApp(const SahdevMobileApp());
}

class SahdevMobileApp extends StatelessWidget {
  const SahdevMobileApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => ThemeProvider()..init()),
        ChangeNotifierProvider(create: (_) => AuthProvider()),
        ChangeNotifierProvider(create: (_) => QueueProvider()),
        ChangeNotifierProvider(create: (_) => ChatProvider()),
        ChangeNotifierProvider(create: (_) => TicketProvider()),
      ],
      child: Consumer<ThemeProvider>(
        builder: (context, themeProv, _) {
          return MaterialApp(
            navigatorKey: appNavigatorKey,
            title: 'Sahdev Support',
            debugShowCheckedModeBanner: false,
            theme: ThemeConfig.lightTheme,
            darkTheme: themeProv.isAmoled ? ThemeConfig.amoledTheme : ThemeConfig.darkTheme,
            themeMode: themeProv.currentTheme == 'light' ? ThemeMode.light : ThemeMode.dark,
            home: const SplashScreen(),
          );
        },
      ),
    );
  }
}
