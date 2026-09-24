import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'config/theme_config.dart';
import 'providers/auth_provider.dart';
import 'providers/queue_provider.dart';
import 'providers/chat_provider.dart';
import 'providers/ticket_provider.dart';
import 'providers/theme_provider.dart';
import 'services/notification_service.dart';
import 'services/audio_service.dart';
import 'screens/splash_screen.dart';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'services/fcm_service.dart';

import 'services/notification_router.dart';

final GlobalKey<NavigatorState> appNavigatorKey = NotificationRouter.navigatorKey;

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // 1. Wire routing callbacks before initializing services
  NotificationService.onNotificationTapped = (payload) {
    NotificationRouter.onNotificationOpened(payload);
  };

  FcmService.onNotificationNavigate = (eventType, data) {
    NotificationRouter.onNotificationOpened(data);
  };

  // 2. Initialize Android local notification channels
  try {
    await NotificationService().init();
  } catch (e) {
    debugPrint("NotificationService init error: $e");
  }

  // 3. Register FCM top-level background handler
  try {
    FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
  } catch (e) {
    debugPrint("FCM background handler registration error: $e");
  }

  // 4. Initialize Firebase & FCM service
  try {
    await FcmService().init();
  } catch (e) {
    debugPrint("FcmService init error: $e");
  }

  runApp(const SahdevMobileApp());
}

class SahdevMobileApp extends StatefulWidget {
  const SahdevMobileApp({super.key});

  @override
  State<SahdevMobileApp> createState() => _SahdevMobileAppState();
}

class _SahdevMobileAppState extends State<SahdevMobileApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      AudioService().handleAppResumed();
    }
  }

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
