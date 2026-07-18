import 'dart:convert';
import 'dart:typed_data';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../config/api_config.dart';

/// Exception standar untuk error dari API (membawa pesan dari backend).
class ApiException implements Exception {
  final String message;
  final int? statusCode;
  ApiException(this.message, [this.statusCode]);

  @override
  String toString() => message;
}

/// Layer HTTP terpusat ke backend Laravel.
class ApiService {
  static const String _tokenKey = 'auth_token';

  // ─── Token storage ────────────────────────────────────────
  static Future<String?> getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_tokenKey);
  }

  static Future<void> saveToken(String token) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_tokenKey, token);
  }

  static Future<void> clearToken() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
  }

  // ─── Header builder ───────────────────────────────────────
  static Future<Map<String, String>> _headers({bool auth = true}) async {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Platform': 'mobile',
    };
    if (auth) {
      final token = await getToken();
      if (token != null && token.isNotEmpty) {
        headers['Authorization'] = 'Bearer $token';
      }
    }
    return headers;
  }

  // ─── Core request handler ─────────────────────────────────
  static Future<Map<String, dynamic>> _request(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
    bool auth = true,
  }) async {
    var uri = Uri.parse('${ApiConfig.baseUrl}$path');
    if (query != null && query.isNotEmpty) {
      uri = uri.replace(queryParameters: query);
    }

    final headers = await _headers(auth: auth);

    http.Response res;
    try {
      switch (method) {
        case 'POST':
          res = await http
              .post(uri, headers: headers, body: jsonEncode(body ?? {}))
              .timeout(const Duration(seconds: 20));
          break;
        case 'PATCH':
          res = await http
              .patch(uri, headers: headers, body: jsonEncode(body ?? {}))
              .timeout(const Duration(seconds: 20));
          break;
        case 'DELETE':
          res = await http
              .delete(uri, headers: headers)
              .timeout(const Duration(seconds: 20));
          break;
        case 'GET':
        default:
          res = await http
              .get(uri, headers: headers)
              .timeout(const Duration(seconds: 20));
          break;
      }
    } catch (e) {
      throw ApiException(
          'Tidak dapat terhubung ke server. Pastikan backend menyala.');
    }

    Map<String, dynamic> data = {};
    if (res.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(res.body);
        if (decoded is Map<String, dynamic>) data = decoded;
      } catch (_) {
        // body bukan JSON (mis. HTML error) — biarkan data kosong
      }
    }

    if (res.statusCode >= 200 && res.statusCode < 300) {
      return data;
    }

    // Ambil pesan error dari backend
    final msg = (data['message'] as String?) ??
        'Terjadi kesalahan (${res.statusCode}).';
    throw ApiException(msg, res.statusCode);
  }

  // ─── Generic GET ──────────────────────────────────────────
  static Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) async {
    return _request('GET', path, query: query);
  }

  // ─── Auth ─────────────────────────────────────────────────
  static Future<Map<String, dynamic>> login(
      String email, String password) async {
    return _request('POST', '/login',
        auth: false, body: {'email': email, 'password': password});
  }

  static Future<Map<String, dynamic>> me() async {
    return _request('GET', '/me');
  }

  static Future<void> logout() async {
    try {
      await _request('POST', '/logout');
    } catch (_) {
      // walau gagal di server, tetap lanjut hapus token lokal
    }
    await clearToken();
  }

  // ─── Presensi ─────────────────────────────────────────────
  static Future<Map<String, dynamic>> checkIn(
      double lat, double lng) async {
    return _request('POST', '/attendance/check-in',
        body: {'latitude': lat, 'longitude': lng});
  }

  static Future<Map<String, dynamic>> checkOut(
      double lat, double lng) async {
    return _request('POST', '/attendance/check-out',
        body: {'latitude': lat, 'longitude': lng});
  }

  static Future<Map<String, dynamic>> myAttendance() async {
    return _request('GET', '/attendance/my');
  }

  // ─── Struk / Receipt ──────────────────────────────────────
  /// Upload foto struk (multipart) dari bytes — jalan di web & mobile.
  /// Backend dispatch OCR job otomatis.
  static Future<Map<String, dynamic>> uploadReceipt(
    Uint8List imageBytes,
    String fileName,
  ) async {
    final token = await getToken();
    final uri = Uri.parse('${ApiConfig.baseUrl}/employee/receipts');
    final req = http.MultipartRequest('POST', uri)
      ..headers['Accept'] = 'application/json'
      ..headers['X-Platform'] = 'mobile';
    if (token != null && token.isNotEmpty) {
      req.headers['Authorization'] = 'Bearer $token';
    }
    req.files.add(
      http.MultipartFile.fromBytes('image', imageBytes, filename: fileName),
    );
    // Kategori default — karyawan ganti sebelum submit via updateClaim
    req.fields['category'] = 'Lain-lain / Operasional';

    http.Response res;
    try {
      final streamed = await req.send().timeout(const Duration(seconds: 60));
      res = await http.Response.fromStream(streamed);
    } catch (e) {
      throw ApiException('Tidak dapat terhubung ke server. Pastikan backend menyala.');
    }

    Map<String, dynamic> data = {};
    if (res.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(res.body);
        if (decoded is Map<String, dynamic>) data = decoded;
      } catch (_) {}
    }
    if (res.statusCode >= 200 && res.statusCode < 300) return data;
    final msg = (data['message'] as String?) ?? 'Terjadi kesalahan (${res.statusCode}).';
    throw ApiException(msg, res.statusCode);
  }

  static Future<Map<String, dynamic>> getReceipt(int id) async {
    return _request('GET', '/employee/receipts/$id');
  }

  static Future<Map<String, dynamic>> myReceipts() async {
    return _request('GET', '/employee/receipts');
  }

  static Future<Map<String, dynamic>> updateClaim(
    int id, {
    required String category,
    String? notes,
    double? claimedAmount,
    double? totalAmount,
    String? receiptDate,
    String? vendorName,
  }) async {
    final body = <String, dynamic>{'category': category};
    if (notes != null) body['notes'] = notes;
    if (claimedAmount != null) body['claimed_amount'] = claimedAmount;
    if (totalAmount != null) body['total_amount'] = totalAmount;
    if (receiptDate != null) body['receipt_date'] = receiptDate;
    if (vendorName != null) body['vendor_name'] = vendorName;
    return _request('PATCH', '/employee/receipts/$id/claim', body: body);
  }

  static Future<Map<String, dynamic>> submitReceipt(int id) async {
    return _request('POST', '/employee/receipts/$id/submit');
  }

  static Future<Map<String, dynamic>> deleteReceipt(int id) async {
    return _request('DELETE', '/employee/receipts/$id');
  }

  // ─── Izin / Cuti ──────────────────────────────────────────
  static Future<Map<String, dynamic>> leaveBalance() async {
    return _request('GET', '/attendance/leave-balance');
  }

  static Future<Map<String, dynamic>> myLeaves() async {
    return _request('GET', '/attendance/my-leaves');
  }

  static Future<Map<String, dynamic>> requestLeave({
    required String leaveType,
    required String startDate,
    required String endDate,
    required String reason,
    Uint8List? documentBytes,
    String? documentFileName,
  }) async {
    // Tanpa lampiran → JSON biasa.
    if (documentBytes == null) {
      return _request('POST', '/attendance/leave-request', body: {
        'leave_type': leaveType,
        'start_date': startDate,
        'end_date': endDate,
        'reason': reason,
      });
    }

    // Dengan lampiran surat dokter → multipart.
    final token = await getToken();
    final uri = Uri.parse('${ApiConfig.baseUrl}/attendance/leave-request');
    final req = http.MultipartRequest('POST', uri)
      ..headers['Accept'] = 'application/json'
      ..headers['X-Platform'] = 'mobile';
    if (token != null && token.isNotEmpty) {
      req.headers['Authorization'] = 'Bearer $token';
    }
    req.fields['leave_type'] = leaveType;
    req.fields['start_date'] = startDate;
    req.fields['end_date']   = endDate;
    req.fields['reason']     = reason;
    req.files.add(http.MultipartFile.fromBytes(
      'document',
      documentBytes,
      filename: documentFileName ?? 'surat_dokter.jpg',
    ));

    http.Response res;
    try {
      final streamed = await req.send().timeout(const Duration(seconds: 60));
      res = await http.Response.fromStream(streamed);
    } catch (e) {
      throw ApiException('Tidak dapat terhubung ke server. Pastikan backend menyala.');
    }

    Map<String, dynamic> data = {};
    if (res.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(res.body);
        if (decoded is Map<String, dynamic>) data = decoded;
      } catch (_) {}
    }
    if (res.statusCode >= 200 && res.statusCode < 300) return data;
    final msg = (data['message'] as String?) ?? 'Terjadi kesalahan (${res.statusCode}).';
    throw ApiException(msg, res.statusCode);
  }

  static Future<Map<String, dynamic>> holidays(int year) async {
    return _request('GET', '/attendance/holidays',
        query: {'year': year.toString()});
  }

  // ─── Presensi — status & auto-checkout ──────────────────────
  /// Cek status presensi hari ini + jadwal auto-checkout dari backend.
  /// Response: {checked_in, checked_out, attendance, scheduled_auto_checkout_at, overtime_approval}
  static Future<Map<String, dynamic>> attendanceStatus() async {
    return _request('GET', '/attendance/status');
  }

  // ─── FCM token ────────────────────────────────────────────────
  /// Kirim FCM token device ke backend agar bisa menerima push notification.
  static Future<void> registerFcmToken(String token) async {
    try {
      await _request('POST', '/attendance/fcm-token',
          body: {'fcm_token': token});
    } catch (_) {
      // Gagal kirim token tidak perlu crash — silent
    }
  }

  // ─── Overtime approvals ─────────────────────────────────────
  /// Riwayat status lembur karyawan yang login (pending/approved/rejected).
  static Future<Map<String, dynamic>> myOvertimeApprovals({int page = 1}) async {
    return _request('GET', '/attendance/my-overtime',
        query: {'page': page.toString()});
  }
}
