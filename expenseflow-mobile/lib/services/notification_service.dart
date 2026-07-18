import 'dart:io';
import 'dart:ui' show Color;

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart' show debugPrint, kIsWeb;
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:timezone/timezone.dart' as tz;
import 'package:timezone/data/latest.dart' as tz_data;

import 'api_service.dart';

/// ID notifikasi lokal:
///   10 = reminder checkout
///   11 = warning 5 menit sebelum auto-checkout
///   12 = konfirmasi auto-checkout
///   20 = overtime approved
///   21 = overtime rejected
class NotificationService {
  static final NotificationService _instance = NotificationService._();
  factory NotificationService() => _instance;
  NotificationService._();

  final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();

  bool _initialized = false;

  // ─── Init (panggil sekali di main) ──────────────────────────────────────
  Future<void> init() async {
    if (_initialized) return;
    if (kIsWeb) { _initialized = true; return; } // web: tidak perlu notifikasi lokal

    tz_data.initializeTimeZones();

    const androidSettings =
        AndroidInitializationSettings('@mipmap/ic_launcher');
    const darwinSettings = DarwinInitializationSettings(
      requestAlertPermission: false,
      requestBadgePermission: false,
      requestSoundPermission: false,
    );

    await _plugin.initialize(
      const InitializationSettings(
        android: androidSettings,
        iOS: darwinSettings,
        macOS: darwinSettings,
      ),
      onDidReceiveNotificationResponse: _onTap,
    );

    _initialized = true;
  }

  // ─── Request permission ──────────────────────────────────────────────────
  Future<void> requestPermission() async {
    if (kIsWeb) return;

    if (Platform.isAndroid) {
      final android = _plugin.resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin>();
      await android?.requestNotificationsPermission();
      await android?.requestExactAlarmsPermission();
      return;
    }

    if (Platform.isIOS) {
      final ios = _plugin.resolvePlatformSpecificImplementation<
          IOSFlutterLocalNotificationsPlugin>();
      await ios?.requestPermissions(alert: true, badge: true, sound: true);
    }
  }

  // ─── Android notification channels ──────────────────────────────────────
  static const _checkoutDetails = AndroidNotificationDetails(
    'checkout_reminder',
    'Reminder Checkout',
    channelDescription: 'Pengingat untuk melakukan checkout presensi',
    importance: Importance.high,
    priority: Priority.high,
    playSound: true,
    enableVibration: true,
  );

  static const _warningDetails = AndroidNotificationDetails(
    'checkout_warning',
    'Peringatan Auto-Checkout',
    channelDescription: 'Peringatan bahwa sistem akan segera auto-checkout',
    importance: Importance.max,
    priority: Priority.max,
    playSound: true,
    enableVibration: true,
    color: Color(0xFFF57C00),
  );

  static const _overtimeDetails = AndroidNotificationDetails(
    'overtime_status',
    'Status Lembur',
    channelDescription: 'Notifikasi status approval lembur dari HRD',
    importance: Importance.high,
    priority: Priority.high,
    playSound: true,
  );

  static const _darwinDetails = DarwinNotificationDetails(
    presentAlert: true,
    presentSound: true,
    presentBadge: false,
  );

  // ─── Schedule: reminder checkout ─────────────────────────────────────────
  Future<void> scheduleCheckoutReminder(String reminderAtIso) async {
    if (kIsWeb) return; // Web tidak support local notifications & tz.local
    if (!_initialized) await init();

    final time = DateTime.tryParse(reminderAtIso);
    if (time == null || time.isBefore(DateTime.now())) return;

    await _plugin.cancel(10);
    await _plugin.zonedSchedule(
      10,
      '⏰ Jangan Lupa Checkout!',
      'Kamu belum checkout. Segera checkout sebelum sistem auto-checkout.',
      tz.TZDateTime.from(time, tz.local),
      const NotificationDetails(android: _checkoutDetails, iOS: _darwinDetails),
      androidScheduleMode: AndroidScheduleMode.exactAllowWhileIdle,
      uiLocalNotificationDateInterpretation:
          UILocalNotificationDateInterpretation.absoluteTime,
      payload: 'checkout_reminder',
    );
  }

  // ─── Schedule: warning 5 menit sebelum auto-checkout ─────────────────────
  Future<void> scheduleAutoCheckoutWarning(String autoCheckoutAtIso) async {
    if (kIsWeb) return; // Web tidak support local notifications & tz.local
    if (!_initialized) await init();

    final autoTime = DateTime.tryParse(autoCheckoutAtIso);
    if (autoTime == null) return;

    final warningTime = autoTime.subtract(const Duration(minutes: 5));
    if (warningTime.isBefore(DateTime.now())) return;

    final jam =
        '${autoTime.toLocal().hour.toString().padLeft(2, '0')}:'
        '${autoTime.toLocal().minute.toString().padLeft(2, '0')}';

    await _plugin.cancel(11);
    await _plugin.zonedSchedule(
      11,
      '⚠️ Auto-Checkout dalam 5 Menit!',
      'Pukul $jam WIB sistem akan otomatis checkout jika kamu belum checkout.',
      tz.TZDateTime.from(warningTime, tz.local),
      const NotificationDetails(android: _warningDetails, iOS: _darwinDetails),
      androidScheduleMode: AndroidScheduleMode.exactAllowWhileIdle,
      uiLocalNotificationDateInterpretation:
          UILocalNotificationDateInterpretation.absoluteTime,
      payload: 'auto_checkout_warning',
    );
  }

  // ─── Cancel reminder setelah checkout ────────────────────────────────────
  Future<void> cancelCheckoutNotifications() async {
    if (kIsWeb) return;
    await _plugin.cancel(10);
    await _plugin.cancel(11);
  }

  // ─── Show notifikasi instan ───────────────────────────────────────────────
  Future<void> showInstant({
    required int id,
    required String title,
    required String body,
    String? payload,
    bool isOvertimeNotif = false,
  }) async {
    if (kIsWeb) return; // Web tidak support local notifications
    if (!_initialized) await init();
    await _plugin.show(
      id,
      title,
      body,
      NotificationDetails(
        android: isOvertimeNotif ? _overtimeDetails : _checkoutDetails,
        iOS: _darwinDetails,
      ),
      payload: payload,
    );
  }

  Future<void> showAutoCheckoutConfirm(String jam) async {
    await showInstant(
      id: 12,
      title: '🔔 Kamu Sudah Di-Checkout Otomatis',
      body: 'Sistem checkout pukul $jam WIB karena kamu lupa checkout. '
          'Lembur menunggu persetujuan HRD.',
      payload: 'auto_checkout_confirm',
    );
  }

  Future<void> showOvertimeApproved(String durasi, String tanggal) async {
    await showInstant(
      id: 20,
      title: '✅ Lembur Disetujui',
      body: 'Lembur $durasi pada $tanggal telah disetujui oleh HRD.',
      payload: 'overtime_approved',
      isOvertimeNotif: true,
    );
  }

  Future<void> showOvertimeRejected(String tanggal, String alasan) async {
    await showInstant(
      id: 21,
      title: '❌ Lembur Ditolak',
      body: 'Lembur pada $tanggal tidak disetujui. Alasan: $alasan',
      payload: 'overtime_rejected',
      isOvertimeNotif: true,
    );
  }

  // ─── FCM: daftar token & tangani pesan foreground ────────────────────────
  Future<void> registerFcmTokenIfAvailable() async {
    if (kIsWeb) return; // web: tidak pakai FCM push
    try {
      final messaging = FirebaseMessaging.instance;
      await messaging.requestPermission(alert: true, badge: true, sound: true);

      final token = await messaging.getToken();
      if (token != null) {
        await ApiService.registerFcmToken(token);
        debugPrint('[FCM] Token terdaftar.');
      }

      FirebaseMessaging.instance.onTokenRefresh.listen((t) {
        ApiService.registerFcmToken(t);
      });

      FirebaseMessaging.onMessage.listen(_onFcmForeground);
    } catch (e) {
      debugPrint('[FCM] Error: $e');
    }
  }

  Future<void> _onFcmForeground(RemoteMessage msg) async {
    final type  = msg.data['type'] as String? ?? '';
    final title = msg.notification?.title ?? '';
    final body  = msg.notification?.body  ?? '';
    if (title.isEmpty && body.isEmpty) return;

    await showInstant(
      id: type.startsWith('overtime_') ? 20 : 12,
      title: title,
      body: body,
      payload: type,
      isOvertimeNotif: type.startsWith('overtime_'),
    );
  }

  // ─── Polling status dari backend ──────────────────────────────────────────
  Future<Map<String, dynamic>?> checkAttendanceStatus() async {
    try {
      return await ApiService.attendanceStatus();
    } catch (_) {
      return null;
    }
  }

  void _onTap(NotificationResponse response) {
    debugPrint('[Notif] Tap: ${response.payload}');
  }
}
