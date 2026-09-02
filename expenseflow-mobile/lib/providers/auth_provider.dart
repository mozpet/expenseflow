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
  final String? bankName;
  final String? bankAccountNo;
  final String? bankAccountHolder;
  final double? monthlyClaimLimit;

  AppUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    this.department,
    required this.wfhEnabled,
    required this.attendanceEnabled,
    this.bankName,
    this.bankAccountNo,
    this.bankAccountHolder,
    this.monthlyClaimLimit,
  });

  factory AppUser.fromJson(Map<String, dynamic> json) {
    double? parseLimit(dynamic v) {
      if (v == null) return null;
      if (v is num) return v.toDouble();
      return double.tryParse(v.toString());
    }

    return AppUser(
      id: json['id'] ?? 0,
      name: json['name'] ?? '-',
      email: json['email'] ?? '-',
      role: json['role'] ?? 'employee',
      department: json['department'] as String?,
      wfhEnabled: json['wfh_enabled'] == true,
      attendanceEnabled: json['attendance_enabled'] == true,
      bankName: json['bank_name'] as String?,
      bankAccountNo: json['bank_account_no'] as String?,
      bankAccountHolder: json['bank_account_holder'] as String?,
      monthlyClaimLimit: parseLimit(json['monthly_claim_limit']),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'email': email,
      'role': role,
      'department': department,
      'wfh_enabled': wfhEnabled,
      'attendance_enabled': attendanceEnabled,
      'bank_name': bankName,
      'bank_account_no': bankAccountNo,
      'bank_account_holder': bankAccountHolder,
      'monthly_claim_limit': monthlyClaimLimit,
    };
  }
}

class AuthProvider extends ChangeNotifier {
  AppUser? _user;
  bool _isLoading = false;
  String? _error;
  int? _retryAfter;

  AppUser? get user => _user;
  bool get isLoading => _isLoading;
  String? get error => _error;
  /// Detik tunggu saat rate-limit (429) — null jika bukan rate-limit.
  int? get retryAfter => _retryAfter;
  bool get isLoggedIn => _user != null;
  bool get wfhEnabled => _user?.wfhEnabled ?? false;

  /// Dipanggil saat app start: cek token & user cache tersimpan → auto login persistent.
  Future<bool> loadSession() async {
    final token = await ApiService.getToken();
    if (token == null || token.isEmpty) return false;

    // 1. Pulihkan user dari cache lokal jika ada (instan startup & offline support)
    final cachedUserMap = await ApiService.getCachedUser();
    if (cachedUserMap != null) {
      _user = AppUser.fromJson(cachedUserMap);
      notifyListeners();
      _initNotifications();
    }

    // 2. Sync data profil terbaru dari backend di background
    try {
      final res = await ApiService.me();
      final userMap = res['user'] as Map<String, dynamic>;
      _user = AppUser.fromJson(userMap);
      await ApiService.saveCachedUser(userMap);
      notifyListeners();
      _initNotifications();
      return true;
    } on ApiException catch (e) {
      // HANYA jika server mengembalikan 401 Unauthorized secara eksplisit (misal user dihapus / token dicabut admin)
      if (e.statusCode == 401) {
        await ApiService.clearToken();
        await ApiService.clearCachedUser();
        _user = null;
        notifyListeners();
        return false;
      }
      // Jika error karena jaringan/offline/timeout: JANGAN LOGOUT! Tetap gunakan sesi user yang ada.
      return _user != null;
    } catch (_) {
      // Error jaringan umum / timeout: Tetap login menggunakan cached session
      return _user != null;
    }
  }

  Future<bool> login(String email, String password) async {
    _isLoading = true;
    _error = null;
    _retryAfter = null;
    notifyListeners();

    try {
      final res = await ApiService.login(email, password);
      final token = res['token'] as String?;
      if (token == null) throw ApiException('Token tidak diterima dari server.');

      await ApiService.saveToken(token);
      final userMap = res['user'] as Map<String, dynamic>;
      _user = AppUser.fromJson(userMap);
      await ApiService.saveCachedUser(userMap);

      _isLoading = false;
      notifyListeners();
      // Request permission & daftarkan FCM token setelah login berhasil
      _initNotifications();
      return true;
    } on ApiException catch (e) {
      _error = e.message;
      _retryAfter = e.retryAfter; // rate-limit → detik tunggu (atau null)
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
