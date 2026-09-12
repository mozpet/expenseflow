import 'dart:convert';
import 'dart:typed_data';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../config/api_config.dart';
import 'device_service.dart';
import 'api_cache_service.dart';

/// Exception standar untuk error dari API (membawa pesan dari backend).
class ApiException implements Exception {
  final String message;
  final int? statusCode;
  /// Payload JSON lengkap dari response error (jika tersedia).
  final Map<String, dynamic>? data;
  /// Detik tunggu saat rate-limit (429) — dari body `retry_after`.
  final int? retryAfter;
  ApiException(this.message, [this.statusCode, this.data, this.retryAfter]);

  @override
  String toString() => message;
}

/// Layer HTTP terpusat ke backend Laravel.
class ApiService {
  static const String _tokenKey = 'auth_token';
  static const String _userCacheKey = 'auth_user_cache';

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

  // ─── User Profile Cache (Persistent Session) ──────────────
  static Future<Map<String, dynamic>?> getCachedUser() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_userCacheKey);
    if (raw == null || raw.isEmpty) return null;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map<String, dynamic>) return decoded;
    } catch (_) {}
    return null;
  }

  static Future<void> saveCachedUser(Map<String, dynamic> userMap) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_userCacheKey, jsonEncode(userMap));
  }

  static Future<void> clearCachedUser() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_userCacheKey);
  }

  // ─── Header builder ───────────────────────────────────────
  static Future<Map<String, String>> _headers({bool auth = true}) async {
    final deviceId = await DeviceService.getDeviceId();
    final deviceName = await DeviceService.getDeviceName();
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Platform': 'mobile',
      'X-Device-Id': deviceId,
      'X-Device-Name': deviceName,
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
    bool forceRefresh = false,
  }) async {
    final isGet = method == 'GET';
    final ttl = isGet ? ApiCacheService.resolveTtl(path) : null;
    final cacheKey = isGet && ttl != null ? ApiCacheService.createCacheKey(path, query) : null;

    if (isGet && cacheKey != null && !forceRefresh) {
      final cached = ApiCacheService.get(cacheKey);
      if (cached != null) {
        return cached;
      }
      final inFlight = ApiCacheService.getInFlight(cacheKey);
      if (inFlight != null) {
        return inFlight;
      }
    }

    Future<Map<String, dynamic>> executeNetwork() async {
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
        if (isGet && cacheKey != null && ttl != null) {
          ApiCacheService.set(cacheKey, data, ttl);
        } else if (!isGet) {
          ApiCacheService.handleMutation(path);
        }
        return data;
      }

      // Ambil pesan error dari backend
      final msg = (data['message'] as String?) ??
          'Terjadi kesalahan (${res.statusCode}).';
      // Rate-limit (429): bawa retry_after (detik) untuk ditampilkan di UI.
      final retryAfter = data['retry_after_seconds'] is int
          ? data['retry_after_seconds'] as int
          : (data['retry_after'] is int
              ? data['retry_after'] as int
              : (res.headers['retry-after'] != null
                  ? int.tryParse(res.headers['retry-after']!)
                  : null));
      throw ApiException(msg, res.statusCode, data, retryAfter);
    }

    if (isGet && cacheKey != null) {
      final future = executeNetwork().whenComplete(() {
        ApiCacheService.clearInFlight(cacheKey);
      });
      ApiCacheService.setInFlight(cacheKey, future);
      return future;
    }

    return executeNetwork();
  }

  // ─── Generic GET ──────────────────────────────────────────
  static Future<Map<String, dynamic>> get(
    String path, {
    Map<String, String>? query,
    bool forceRefresh = false,
  }) async {
    return _request('GET', path, query: query, forceRefresh: forceRefresh);
  }

  // ─── Generic POST ─────────────────────────────────────────
  static Future<Map<String, dynamic>> post(
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) async {
    return _request('POST', path, body: body, query: query);
  }

  // ─── Auth ─────────────────────────────────────────────────
  static Future<Map<String, dynamic>> login(
      String email, String password) async {
    final deviceId = await DeviceService.getDeviceId();
    final deviceName = await DeviceService.getDeviceName();
    return _request('POST', '/login',
        auth: false,
        body: {
          'email': email,
          'password': password,
          'device_id': deviceId,
          'device_name': deviceName,
        });
  }

  static Future<Map<String, dynamic>> me({bool forceRefresh = false}) async {
    return _request('GET', '/me', forceRefresh: forceRefresh);
  }

  static Future<void> logout() async {
    try {
      await _request('POST', '/logout');
    } catch (_) {
      // walau gagal di server, tetap lanjut hapus token lokal
    }
    await clearToken();
    await clearCachedUser();
    ApiCacheService.clearAll();
  }

  // ─── Forgot Password OTP ───────────────────────────────────
  static Future<Map<String, dynamic>> sendForgotPasswordOtp(String email) async {
    return _request('POST', '/auth/forgot-password/send-otp',
        auth: false, body: {'email': email});
  }

  static Future<Map<String, dynamic>> verifyForgotPasswordOtp(
      String email, String otp) async {
    return _request('POST', '/auth/forgot-password/verify-otp',
        auth: false, body: {'email': email, 'otp': otp});
  }

  static Future<Map<String, dynamic>> resetPasswordWithOtp({
    required String email,
    required String resetToken,
    required String password,
    required String passwordConfirmation,
  }) async {
    return _request('POST', '/auth/forgot-password/reset',
        auth: false,
        body: {
          'email': email,
          'reset_token': resetToken,
          'password': password,
          'password_confirmation': passwordConfirmation,
        });
  }

  // ─── Presensi ─────────────────────────────────────────────
  static Future<Map<String, dynamic>> checkIn(
    double lat,
    double lng, {
    bool isMocked = false,
  }) async {
    final body = <String, dynamic>{
      'latitude': lat,
      'longitude': lng,
      'is_mocked': isMocked,
    };
    return _request('POST', '/attendance/check-in', body: body);
  }

  static Future<Map<String, dynamic>> checkOut(
    double lat,
    double lng, {
    bool isMocked = false,
  }) async {
    final body = <String, dynamic>{
      'latitude': lat,
      'longitude': lng,
      'is_mocked': isMocked,
    };
    return _request('POST', '/attendance/check-out', body: body);
  }

  static Future<Map<String, dynamic>> myAttendance({bool forceRefresh = false}) async {
    return _request('GET', '/attendance/my', forceRefresh: forceRefresh);
  }

  // ─── Struk / Receipt ──────────────────────────────────────
  /// Upload foto struk (multipart) dari bytes — jalan di web & mobile.
  /// Mendukung multi-foto (Slip EDC / Nota rincian) dan penautan laporan dinas.
  /// Backend dispatch OCR job otomatis dari foto utama.
  static Future<Map<String, dynamic>> uploadReceipt(
    Uint8List imageBytes,
    String fileName, {
    List<Uint8List>? additionalPhotos,
    List<String>? additionalFileNames,
    int? expenseReportId,
  }) async {
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

    // Lampiran foto tambahan (misal slip EDC / nota rincian)
    if (additionalPhotos != null && additionalPhotos.isNotEmpty) {
      for (int i = 0; i < additionalPhotos.length; i++) {
        final fName = (additionalFileNames != null && i < additionalFileNames.length)
            ? additionalFileNames[i]
            : 'lampiran_${i + 1}.jpg';
        req.files.add(
          http.MultipartFile.fromBytes(
            'additional_images[]',
            additionalPhotos[i],
            filename: fName,
          ),
        );
      }
    }

    if (expenseReportId != null) {
      req.fields['expense_report_id'] = expenseReportId.toString();
    }

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
    if (res.statusCode >= 200 && res.statusCode < 300) {
      ApiCacheService.handleMutation('/employee/receipts');
      return data;
    }
    final msg = (data['message'] as String?) ?? 'Terjadi kesalahan (${res.statusCode}).';
    final retryAfter = data['retry_after'] is int
        ? data['retry_after'] as int
        : null;
    throw ApiException(msg, res.statusCode, data, retryAfter);
  }

  /// Foto ulang struk draf (replace foto & re-run OCR)
  static Future<Map<String, dynamic>> retakeReceipt(
    int receiptId,
    Uint8List imageBytes,
    String fileName,
  ) async {
    final token = await getToken();
    final uri = Uri.parse('${ApiConfig.baseUrl}/employee/receipts/$receiptId/retake');
    final req = http.MultipartRequest('POST', uri)
      ..headers['Accept'] = 'application/json'
      ..headers['X-Platform'] = 'mobile';
    if (token != null && token.isNotEmpty) {
      req.headers['Authorization'] = 'Bearer $token';
    }
    req.files.add(
      http.MultipartFile.fromBytes('image', imageBytes, filename: fileName),
    );

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
    if (res.statusCode >= 200 && res.statusCode < 300) {
      ApiCacheService.handleMutation('/employee/receipts');
      return data;
    }
    final msg = (data['message'] as String?) ?? 'Gagal memperbarui foto struk (${res.statusCode}).';
    throw ApiException(msg, res.statusCode, data);
  }

  /// Hapus draft struk
  static Future<Map<String, dynamic>> deleteReceipt(int receiptId) async {
    final res = await _request('DELETE', '/employee/receipts/$receiptId');
    ApiCacheService.handleMutation('/employee/receipts');
    return res;
  }

  // ─── Laporan Pengeluaran Dinas (Expense Reports) ───────────
  static Future<List<dynamic>> getExpenseReports({bool forceRefresh = false}) async {
    final res = await _request('GET', '/employee/expense-reports', forceRefresh: forceRefresh);
    if (res['data'] is List) {
      return res['data'] as List<dynamic>;
    }
    if (res['reports'] is List) {
      return res['reports'] as List<dynamic>;
    }
    return [];
  }

  static Future<Map<String, dynamic>> createExpenseReport({
    required String title,
    String? purpose,
    String? startDate,
    String? endDate,
    List<int>? receiptIds,
  }) async {
    final body = <String, dynamic>{'title': title};
    if (purpose != null) body['purpose'] = purpose;
    if (startDate != null) body['start_date'] = startDate;
    if (endDate != null) body['end_date'] = endDate;
    if (receiptIds != null) body['receipt_ids'] = receiptIds;
    return _request('POST', '/employee/expense-reports', body: body);
  }

  static Future<Map<String, dynamic>> getExpenseReportDetail(int reportId, {bool forceRefresh = false}) async {
    return _request('GET', '/employee/expense-reports/$reportId', forceRefresh: forceRefresh);
  }

  static Future<Map<String, dynamic>> submitExpenseReport(int reportId) async {
    return _request('POST', '/employee/expense-reports/$reportId/submit');
  }

  static Future<Map<String, dynamic>> deleteExpenseReport(int reportId) async {
    return _request('DELETE', '/employee/expense-reports/$reportId');
  }

  static Future<Map<String, dynamic>> addReceiptsToReport(int reportId, List<int> receiptIds) async {
    return _request('POST', '/employee/expense-reports/$reportId/receipts', body: {'receipt_ids': receiptIds});
  }

  static Future<Map<String, dynamic>> removeReceiptFromReport(int reportId, int receiptId) async {
    return _request('DELETE', '/employee/expense-reports/$reportId/receipts/$receiptId');
  }

  static Future<Map<String, dynamic>> getReceipt(int id, {bool forceRefresh = false}) async {
    return _request('GET', '/employee/receipts/$id', forceRefresh: forceRefresh);
  }

  static Future<Map<String, dynamic>> myReceipts({bool forceRefresh = false}) async {
    return _request('GET', '/employee/receipts', forceRefresh: forceRefresh);
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

  // ─── Izin / Cuti ──────────────────────────────────────────
  static Future<Map<String, dynamic>> leaveBalance({bool forceRefresh = false}) async {
    return _request('GET', '/attendance/leave-balance', forceRefresh: forceRefresh);
  }

  static Future<Map<String, dynamic>> myLeaves({bool forceRefresh = false}) async {
    return _request('GET', '/attendance/my-leaves', forceRefresh: forceRefresh);
  }

  // Preview hitungan hari EFEKTIF pengajuan (backend skip libur/off-day/bentrok/wfh).
  // Response: { total_days, calendar_days, effective_dates[], skipped_dates[] }
  static Future<Map<String, dynamic>> leavePreview({
    required String startDate,
    required String endDate,
    String? leaveType,
  }) async {
    final query = <String, String>{
      'start_date': startDate,
      'end_date': endDate,
    };
    if (leaveType != null && leaveType.isNotEmpty) {
      query['leave_type'] = leaveType;
    }
    return _request('GET', '/attendance/leave-preview', query: query);
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
    if (res.statusCode >= 200 && res.statusCode < 300) {
      ApiCacheService.handleMutation('/attendance/leave-request');
      return data;
    }
    final msg = (data['message'] as String?) ?? 'Terjadi kesalahan (${res.statusCode}).';
    final retryAfter = data['retry_after'] is int
        ? data['retry_after'] as int
        : null;
    throw ApiException(msg, res.statusCode, data, retryAfter);
  }


  // ─── Cuti Bersama ───────────────────────────────────────────
  /// Daftar cuti bersama mendatang + status pilihan karyawan login.
  static Future<Map<String, dynamic>> collectiveLeaves({bool forceRefresh = false}) async {
    return _request('GET', '/attendance/collective-leaves', forceRefresh: forceRefresh);
  }

  /// Pilih ikut / tidak ikut cuti bersama.
  static Future<Map<String, dynamic>> respondCollectiveLeave(
    int holidayId,
    String response,
  ) async {
    return _request(
      'POST',
      '/attendance/collective-leave/$holidayId/respond',
      body: {'response': response},
    );
  }

  /// Dismiss / tandai notifikasi pembatalan cuti sebagai dibaca.
  static Future<void> dismissCancellation(String id) async {
    await _request('POST', '/attendance/dismiss-cancellation/$id');
  }

  // ─── Presensi — status & auto-checkout ──────────────────────
  /// Cek status presensi hari ini + jadwal auto-checkout dari backend.
  /// Response: {checked_in, checked_out, attendance, scheduled_auto_checkout_at, overtime_approval}
  static Future<Map<String, dynamic>> attendanceStatus({bool forceRefresh = false}) async {
    return _request('GET', '/attendance/status', forceRefresh: forceRefresh);
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
  static Future<Map<String, dynamic>> myOvertimeApprovals({int page = 1, bool forceRefresh = false}) async {
    return _request('GET', '/attendance/my-overtime',
        query: {'page': page.toString()}, forceRefresh: forceRefresh);
  }

  /// Klaim / ajukan lembur dengan deskripsi pekerjaan.
  static Future<Map<String, dynamic>> claimOvertime(
      int attendanceId, String reason) async {
    return _request('POST', '/attendance/$attendanceId/claim-overtime',
        body: {'reason': reason});
  }

  /// Batalkan / tolak klaim lembur untuk hari tertentu.
  static Future<Map<String, dynamic>> declineOvertime(int attendanceId) async {
    return _request('POST', '/attendance/$attendanceId/decline-overtime');
  }
}
