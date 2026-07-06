import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/notification_service.dart';

class AppUser {
  final int id;
  final String name;
  final String email;
  final String role;
  final String? department;
  final bool wfhEnabled;
  final bool attendanceEnabled;

  AppUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    this.department,
    required this.wfhEnabled,
    required this.attendanceEnabled,
  });

  factory AppUser.fromJson(Map<String, dynamic> json) {
    return AppUser(
      id: json['id'] ?? 0,
      name: json['name'] ?? '-',
      email: json['email'] ?? '-',
      role: json['role'] ?? 'employee',
      department: json['department'] as String?,
      wfhEnabled: json['wfh_enabled'] == true,
      attendanceEnabled: json['attendance_enabled'] == true,
    );
  }
}

class AuthProvider extends ChangeNotifier {
  AppUser? _user;
  bool _isLoading = false;
  String? _error;

  AppUser? get user => _user;
  bool get isLoading => _isLoading;
  String? get error => _error;
  bool get isLoggedIn => _user != null;
  bool get wfhEnabled => _user?.wfhEnabled ?? false;

  /// Dipanggil saat app start: cek token tersimpan → auto login.
  Future<bool> loadSession() async {
    final token = await ApiService.getToken();
    if (token == null || token.isEmpty) return false;

    try {
      final res = await ApiService.me();
      _user = AppUser.fromJson(res['user'] as Map<String, dynamic>);
      notifyListeners();
      // Request permission & daftarkan FCM token saat resume session
      _initNotifications();
      return true;
    } catch (_) {
      // token kadaluarsa / server mati → bersihkan
      await ApiService.clearToken();
      return false;
    }
  }

  Future<bool> login(String email, String password) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      final res = await ApiService.login(email, password);
      final token = res['token'] as String?;
      if (token == null) throw ApiException('Token tidak diterima dari server.');

      await ApiService.saveToken(token);
      _user = AppUser.fromJson(res['user'] as Map<String, dynamic>);
      _isLoading = false;
      notifyListeners();
      // Request permission & daftarkan FCM token setelah login berhasil
      _initNotifications();
      return true;
    } on ApiException catch (e) {
      _error = e.message;
      _isLoading = false;
      notifyListeners();
      return false;
    } catch (e) {
      _error = 'Terjadi kesalahan tak terduga.';
      _isLoading = false;
      notifyListeners();
      return false;
    }
  }

  Future<void> logout() async {
    await ApiService.logout();
    _user = null;
    notifyListeners();
  }

  void _initNotifications() {
    if (kIsWeb) return; // web: tidak pakai push notifikasi
    final notifSvc = NotificationService();
    notifSvc.requestPermission();
    notifSvc.registerFcmTokenIfAvailable();
  }
}
