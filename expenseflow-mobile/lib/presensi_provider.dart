import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'models/attendance_model.dart';
import 'models/leave_model.dart';
import 'services/api_service.dart';
import 'services/notification_service.dart';

export 'models/attendance_model.dart';
export 'models/leave_model.dart';

class PresensiProvider extends ChangeNotifier {
  // Flag dari backend (diisi setelah login): true = boleh presensi WFH via app
  bool wfhEnabled = false;

  final List<PresensiRecord> _records = [];
  final List<LeaveRequestRecord> _leaveRequests = [];
  final List<LeaveBalanceRecord> _leaveBalances = [];
  Map<String, dynamic>? _leaveResetInfo;
  final List<CollectiveLeaveRecord> _collectiveLeaves = [];
  final List<LeaveCancellationRecord> _leaveCancellations = [];
  final List<OfficeArea> _offices = [];
  OfficeArea? _primaryOffice;
  bool _radiusEnabled = false;

  String? _todayMasuk;
  String? _todayPulang;
  int _todayOvertimeMinutes = 0;
  int _todayLateMinutes = 0;
  bool _loadingHistory = false;
  bool _loadingBalance = false;
  bool _loadingLeaves = false;
  bool _loadingCollectiveLeaves = false;

  List<PresensiRecord> get records => List.unmodifiable(_records);
  List<LeaveRequestRecord> get leaveRequests =>
      List.unmodifiable(_leaveRequests);
  List<LeaveBalanceRecord> get leaveBalances =>
      List.unmodifiable(_leaveBalances);
  Map<String, dynamic>? get leaveResetInfo => _leaveResetInfo;
  List<CollectiveLeaveRecord> get collectiveLeaves =>
      List.unmodifiable(_collectiveLeaves);
  List<LeaveCancellationRecord> get leaveCancellations =>
      List.unmodifiable(_leaveCancellations);
  List<OfficeArea> get offices => List.unmodifiable(_offices);
  OfficeArea? get primaryOffice => _primaryOffice;
  bool get radiusEnabled => _radiusEnabled;
  bool get isRadiusEnforced => _radiusEnabled;
  bool get isWfhMode => wfhEnabled && !_radiusEnabled;
  CollectiveLeaveRecord? get activeCollectiveLeaveBanner {
    for (final item in _collectiveLeaves) {
      if (item.showBanner && item.collectiveStatus == 'pending') return item;
    }
    return null;
  }
  
  List<CollectiveLeaveRecord> get activeCollectiveLeaveBanners {
    return _collectiveLeaves.where((item) => item.showBanner && item.collectiveStatus == 'pending').toList();
  }

  int get unreadNotificationCount =>
      activeCollectiveLeaveBanners.length + _leaveCancellations.length;
  String? get todayMasuk => _todayMasuk;
  String? get todayPulang => _todayPulang;
  int get todayOvertimeMinutes => _todayOvertimeMinutes;
  int get todayLateMinutes => _todayLateMinutes;
  bool get loadingHistory => _loadingHistory;
  bool get loadingBalance => _loadingBalance;
  bool get loadingLeaves => _loadingLeaves;
  bool get loadingCollectiveLeaves => _loadingCollectiveLeaves;

  bool get canCheckIn => _todayMasuk == null;
  bool get canCheckOut => _todayMasuk != null && _todayPulang == null;
  String get todayTotalJamKerja =>
      hitungDurasiKerja(_todayMasuk ?? '-', _todayPulang ?? '-');

  String get todayDateFormatted {
    final now = DateTime.now();
    const days = [
      'Minggu',
      'Senin',
      'Selasa',
      'Rabu',
      'Kamis',
      'Jumat',
      'Sabtu',
    ];
    const months = [
      'Januari',
      'Februari',
      'Maret',
      'April',
      'Mei',
      'Juni',
      'Juli',
      'Agustus',
      'September',
      'Oktober',
      'November',
      'Desember',
    ];
    final dayName = days[now.weekday % 7];
    final monthName = months[now.month - 1];
    return '$dayName, ${now.day} $monthName ${now.year}';
  }

  // ─── Presensi check-in/out ke API ─────────────────────────
  /// Kirim koordinat ke backend. Lempar ApiException bila gagal.
  Future<void> simpanPresensi(double lat, double lng) async {
    if (canCheckIn) {
      final res = await ApiService.checkIn(lat, lng);
      final att = res['attendance'] as Map<String, dynamic>?;
      _todayMasuk = _extractTime(att?['check_in_time']) ?? _nowTime();
      _records.insert(
        0,
        PresensiRecord(
          date: todayDateFormatted,
          masukTime: _todayMasuk!,
          pulangTime: '-',
        ),
      );

      // Jadwalkan notifikasi reminder & peringatan auto-checkout
      // Backend mengirim reminder_at dan auto_checkout_at dalam ISO format
      final reminderAt = res['reminder_at'] as String?;
      final autoCheckoutAt = res['auto_checkout_at'] as String?;
      final notifSvc = NotificationService();
      if (reminderAt != null) {
        await notifSvc.scheduleCheckoutReminder(reminderAt);
      }
      if (autoCheckoutAt != null) {
        await notifSvc.scheduleAutoCheckoutWarning(autoCheckoutAt);
      }

      notifyListeners();
    } else if (canCheckOut) {
      final res = await ApiService.checkOut(lat, lng);
      final att = res['attendance'] as Map<String, dynamic>?;
      _todayPulang = _extractTime(att?['check_out_time']) ?? _nowTime();
      _todayOvertimeMinutes = (att?['overtime_minutes'] as num?)?.toInt() ?? 0;
      if (_records.isNotEmpty && _records.first.date == todayDateFormatted) {
        _records[0] = _records[0].copyWith(
          pulangTime: _todayPulang!,
          overtimeMinutes: _todayOvertimeMinutes,
        );
      }

      // Batalkan semua notifikasi reminder setelah checkout berhasil
      await NotificationService().cancelCheckoutNotifications();

      notifyListeners();
    }
  }

  // ─── Cek status backend untuk deteksi auto-checkout & status WFH ────────
  /// Dipanggil saat app dibuka (resume), tab presensi dibuka, atau saat soft reload.
  /// Memperbarui flag wfh_enabled, status check-in/out hari ini, dan auto-checkout.
  Future<void> syncStatusFromBackend() async {
    final notifSvc = NotificationService();
    final status = await notifSvc.checkAttendanceStatus();
    if (status == null) return;

    // Sinkronkan flag WFH dan Radius dari backend (menentukan mode presensi).
    final newWfhEnabled = status['wfh_enabled'] == true;
    final newRadiusEnabled = status['radius_enabled'] == true;
    bool needNotify = false;
    if (newWfhEnabled != wfhEnabled) {
      wfhEnabled = newWfhEnabled;
      needNotify = true;
    }
    if (newRadiusEnabled != _radiusEnabled) {
      _radiusEnabled = newRadiusEnabled;
      needNotify = true;
    }
    if (needNotify) {
      notifyListeners();
    }

    // Sinkronkan daftar kantor dan radius
    if (status['offices'] is List) {
      _offices
        ..clear()
        ..addAll(
          (status['offices'] as List)
              .map((e) => OfficeArea.fromJson(e as Map<String, dynamic>))
              .where((o) => o.latitude != 0.0 && o.longitude != 0.0),
        );
    }
    if (status['office'] is Map) {
      _primaryOffice =
          OfficeArea.fromJson(status['office'] as Map<String, dynamic>);
      if (_offices.isEmpty &&
          _primaryOffice!.latitude != 0.0 &&
          _primaryOffice!.longitude != 0.0) {
        _offices.add(_primaryOffice!);
      }
    }

    final att = status['attendance'] as Map<String, dynamic>?;
    if (att == null) {
      // Jika backend tidak memiliki record presensi hari ini, reset status lokal hari ini
      if (_todayMasuk != null || _todayPulang != null) {
        _todayMasuk = null;
        _todayPulang = null;
        _todayOvertimeMinutes = 0;
        _todayLateMinutes = 0;
        notifyListeners();
      }
      return;
    }

    final checkedIn = status['checked_in'] == true;
    final checkedOut = status['checked_out'] == true;
    final isAutoCheckout = att['is_auto_checkout'] == true;

    final overtimeApproval =
        status['overtime_approval'] as Map<String, dynamic>?;

    // Selalu sinkronkan state lokal dengan backend (baik sudah checkout maupun masih berjalan)
    if (checkedIn) {
      final newMasuk = _extractTime(att['check_in_time']);
      final newPulang = _extractTime(att['check_out_time']);

      bool changed = false;
      if (_todayMasuk != newMasuk) {
        _todayMasuk = newMasuk;
        changed = true;
      }
      if (_todayPulang != newPulang) {
        _todayPulang = newPulang;
        changed = true;
      }

      final newOvertime =
          (overtimeApproval?['overtime_minutes'] as num?)?.toInt() ??
          (att['overtime_minutes'] as num?)?.toInt() ??
          0;
      if (_todayOvertimeMinutes != newOvertime) {
        _todayOvertimeMinutes = newOvertime;
        changed = true;
      }

      if (changed) {
        if (_records.isNotEmpty) {
          _records[0] = _records[0].copyWith(
            masukTime: _todayMasuk ?? '-',
            pulangTime: _todayPulang ?? '-',
            overtimeMinutes: _todayOvertimeMinutes,
          );
        }
        notifyListeners();
      }

      // Jika backend menyatakan sudah checkout, batalkan reminder
      if (checkedOut) {
        await notifSvc.cancelCheckoutNotifications();
        if (isAutoCheckout) {
          await notifSvc.showAutoCheckoutConfirm(_todayPulang ?? _nowTime());
        }
      }
    }

    // Cek status overtime approval (approved/rejected oleh HRD)
    if (overtimeApproval != null) {
      final approvalStatus = overtimeApproval['status'] as String?;
      final overtimeMins =
          (overtimeApproval['overtime_minutes'] as num?)?.toInt() ?? 0;
      final reviewedAt = overtimeApproval['reviewed_at'];

      // Hanya notifikasi jika baru saja di-review (dalam 5 menit terakhir)
      if (reviewedAt != null &&
          (approvalStatus == 'approved' || approvalStatus == 'rejected')) {
        final reviewTime = DateTime.tryParse(reviewedAt.toString());
        final isRecent =
            reviewTime != null &&
            DateTime.now().difference(reviewTime).inMinutes <= 5;
        if (isRecent) {
          final durasi = _formatMinutes(overtimeMins);
          final tanggal = att['date'] != null
              ? _dateOnly(att['date'])
              : 'hari ini';
          if (approvalStatus == 'approved') {
            await notifSvc.showOvertimeApproved(durasi, tanggal);
          } else {
            final notes = overtimeApproval['notes'] as String? ?? '';
            await notifSvc.showOvertimeRejected(tanggal, notes);
          }
        }
      }
    }
  }

  // ─── Helper: format menit ke "Xj Ym" ──────────────────────
  String _formatMinutes(int minutes) {
    if (minutes <= 0) return '0j';
    final j = minutes ~/ 60;
    final m = minutes % 60;
    if (j == 0) return '${m}m';
    if (m == 0) return '${j}j';
    return '${j}j ${m}m';
  }

  // ─── Fetch riwayat presensi (termasuk sinkronisasi status WFH live) ────
  Future<void> fetchMyAttendance() async {
    _loadingHistory = true;
    notifyListeners();
    try {
      // Jalankan pemuatan riwayat presensi dan sinkronisasi status WFH secara bersamaan
      await Future.wait([
        _loadMyAttendanceData(),
        syncStatusFromBackend(),
      ]);
    } catch (e, st) {
      debugPrint('[PresensiProvider] fetchMyAttendance error: $e');
      debugPrint('$st');
    } finally {
      _loadingHistory = false;
      notifyListeners();
    }
  }

  Future<void> _loadMyAttendanceData() async {
    final res = await ApiService.myAttendance();
    if (res.containsKey('wfh_enabled')) {
      final newWfhEnabled = res['wfh_enabled'] == true;
      if (newWfhEnabled != wfhEnabled) {
        wfhEnabled = newWfhEnabled;
      }
    }
    if (res.containsKey('radius_enabled')) {
      _radiusEnabled = res['radius_enabled'] == true;
    }
    final list = (res['data'] as List?) ?? [];
    _records
      ..clear()
      ..addAll(
        list.map((e) {
          final m = e as Map<String, dynamic>;
          final overtimeApproval =
              m['overtime_approval'] as Map<String, dynamic>?;
          return PresensiRecord(
            id: (m['id'] as num?)?.toInt() ?? 0,
            date: _formatDate(m['date']),
            masukTime: _extractTime(m['check_in_time']) ?? '-',
            pulangTime: _extractTime(m['check_out_time']) ?? '-',
            checkInType: (m['check_in_type'] ?? '').toString(),
            overtimeMinutes:
                (overtimeApproval?['overtime_minutes'] as num?)?.toInt() ??
                (m['overtime_minutes'] as num?)?.toInt() ??
                0,
            isHoliday: m['is_holiday'] == true || m['is_holiday'] == 1,
            isAutoCheckout:
                m['is_auto_checkout'] == true || m['is_auto_checkout'] == 1,
            lateMinutes: _calculateLateMinutes(
              m['check_in_time'],
              m['status'],
            ),
            overtimeStatus: overtimeApproval?['status'] as String?,
          );
        }),
      );
    // Set status hari ini bila ada record tanggal hari ini
    final todayIso = DateTime.now().toIso8601String().substring(0, 10);
    bool foundToday = false;
    for (final e in list) {
      final m = e as Map<String, dynamic>;
      if ((m['date'] ?? '').toString().startsWith(todayIso)) {
        foundToday = true;
        _todayMasuk = _extractTime(m['check_in_time']);
        _todayPulang = _extractTime(m['check_out_time']);
        final oa = m['overtime_approval'] as Map<String, dynamic>?;
        _todayOvertimeMinutes =
            (oa?['overtime_minutes'] as num?)?.toInt() ??
            (m['overtime_minutes'] as num?)?.toInt() ??
            0;
        _todayLateMinutes = _calculateLateMinutes(
          m['check_in_time'],
          m['status'],
        );
      }
    }
    if (!foundToday) {
      _todayMasuk = null;
      _todayPulang = null;
      _todayOvertimeMinutes = 0;
      _todayLateMinutes = 0;
    }
  }

  // ─── Fetch daftar overtime approval (untuk halaman riwayat lembur tersendiri) ──
  /// Mengembalikan list raw dari endpoint my-overtime.
  /// overtimeStatus di PresensiRecord sudah diisi dari myAttendance() —
  /// method ini hanya dipakai jika ada halaman dedicated riwayat lembur.
  Future<List<Map<String, dynamic>>> fetchOvertimeApprovals({
    int page = 1,
  }) async {
    try {
      final res = await ApiService.myOvertimeApprovals(page: page);
      return (res['data'] as List? ?? []).cast<Map<String, dynamic>>();
    } catch (e, st) {
      debugPrint('[PresensiProvider] fetchOvertimeApprovals error: $e');
      debugPrint('$st');
      return [];
    }
  }

  // ─── Fetch saldo cuti ─────────────────────────────────────
  Future<void> fetchLeaveBalance() async {
    _loadingBalance = true;
    notifyListeners();
    try {
      final res = await ApiService.leaveBalance();
      final list = (res['balances'] as List?) ?? [];
      _leaveResetInfo = res['reset_info'] as Map<String, dynamic>?;
      _leaveBalances
        ..clear()
        ..addAll(
          list.map((e) {
            final m = e as Map<String, dynamic>;
            return LeaveBalanceRecord(
              leaveType: (m['leave_type'] ?? '').toString(),
              quota: (m['quota'] ?? 0) as int,
              used: (m['used'] ?? 0) as int,
            );
          }),
        );
    } catch (_) {}
    _loadingBalance = false;
    notifyListeners();
  }

  // ─── Fetch riwayat izin/cuti ──────────────────────────────
  Future<void> fetchLeaveRequests() async {
    _loadingLeaves = true;
    notifyListeners();
    try {
      final res = await ApiService.myLeaves();
      final list = (res['leaves'] as List?) ?? [];
      _leaveRequests
        ..clear()
        ..addAll(
          list.map((e) {
            final m = e as Map<String, dynamic>;
            return LeaveRequestRecord(
              id: (m['id'] ?? 0) as int,
              leaveType: (m['leave_type'] ?? '').toString(),
              // Backend mengirim ISO ("2026-06-26T00:00:00..."), ambil tanggalnya saja
              startDate: _dateOnly(m['start_date']),
              endDate: _dateOnly(m['end_date']),
              totalDays: (m['total_days'] ?? 0) as int,
              reason: (m['reason'] ?? '').toString(),
              status: (m['status'] ?? 'pending').toString(),
              rejectionReason: m['rejection_reason'] as String?,
            );
          }),
        );
    } catch (_) {}
    _loadingLeaves = false;
    notifyListeners();
  }

  // ─── Preview hari EFEKTIF pengajuan (badge "Total N hari") ────
  /// Meminta hitungan backend utk rentang tanggal. Backend melewatkan
  /// libur nasional/perusahaan/cabang, cuti bersama accepted, cuti pribadi
  /// yang sudah diajukan, libur mingguan kantor & off-day shift.
  /// Return null bila gagal (mobile fallback ke hitungan kalender sederhana).
  Future<Map<String, dynamic>?> fetchLeavePreview({
    required String startDate,
    required String endDate,
  }) async {
    try {
      return await ApiService.leavePreview(startDate: startDate, endDate: endDate);
    } catch (_) {
      return null;
    }
  }

  // ─── Ajukan izin/cuti ke API ──────────────────────────────
  /// Kirim pengajuan. Lempar ApiException bila gagal.
  Future<void> submitLeave({
    required String leaveType,
    required String startDate,
    required String endDate,
    required int totalDays,
    required String reason,
    Uint8List? documentBytes,
    String? documentFileName,
  }) async {
    final res = await ApiService.requestLeave(
      leaveType: leaveType,
      startDate: startDate,
      endDate: endDate,
      reason: reason,
      documentBytes: documentBytes,
      documentFileName: documentFileName,
    );
    final leave = res['leave'] as Map<String, dynamic>?;
    _leaveRequests.insert(
      0,
      LeaveRequestRecord(
        id: (leave?['id'] ?? 0) as int,
        leaveType: leaveType,
        startDate: startDate,
        endDate: endDate,
        totalDays: totalDays,
        reason: reason,
        status: (leave?['status'] ?? 'pending').toString(),
      ),
    );
    notifyListeners();
  }


  // ─── Fetch cuti bersama mendatang & pesan pembatalan ─────────
  Future<void> fetchCollectiveLeaves() async {
    _loadingCollectiveLeaves = true;
    notifyListeners();
    try {
      final res = await ApiService.collectiveLeaves();
      final list = (res['collective_leaves'] as List?) ?? [];
      final canList = (res['cancellations'] as List?) ?? [];

      _collectiveLeaves
        ..clear()
        ..addAll(
          list.map((e) => CollectiveLeaveRecord.fromJson(
                (e as Map).cast<String, dynamic>(),
              )),
        );

      _leaveCancellations
        ..clear()
        ..addAll(
          canList.map((e) => LeaveCancellationRecord.fromJson(
                (e as Map).cast<String, dynamic>(),
              )),
        );
    } catch (e, st) {
      debugPrint('[PresensiProvider] fetchCollectiveLeaves error: $e');
      debugPrint('$st');
    }
    _loadingCollectiveLeaves = false;
    notifyListeners();
  }

  /// Dismiss / tutup notifikasi pembatalan cuti bersama atau cuti mandiri
  Future<void> dismissCancellation(String id) async {
    _leaveCancellations.removeWhere((item) => item.id == id);
    notifyListeners();
    try {
      await ApiService.dismissCancellation(id);
    } catch (e) {
      debugPrint('[PresensiProvider] dismissCancellation error: $e');
    }
  }

  /// Response cuti bersama: accepted = ikut, declined = tidak ikut.
  Future<void> respondCollectiveLeave(int holidayId, String response) async {
    final res = await ApiService.respondCollectiveLeave(holidayId, response);
    final status = (res['collective_status'] ?? response).toString();
    final remaining = (res['remaining_quota'] as num?)?.toInt();

    final idx = _collectiveLeaves.indexWhere((e) => e.id == holidayId);
    if (idx >= 0) {
      final old = _collectiveLeaves[idx];
      _collectiveLeaves[idx] = CollectiveLeaveRecord(
        id: old.id,
        date: old.date,
        name: old.name,
        totalDays: old.totalDays,
        collectiveStatus: status,
        remainingQuota: remaining ?? old.remainingQuota,
        policy: old.policy,
        showBanner: false,
      );
    }

    // LIVE UPDATE: setelah user merespon 1 cuti bersama, saldo cuti berubah.
    // Sinkronkan sisa saldo ke SEMUA banner cuti bersama lain yang masih pending
    // agar tombol "Ya, Saya Ikut" & peringatan saldo habis langsung ter-update
    // tanpa perlu refresh manual.
    if (remaining != null) {
      for (var i = 0; i < _collectiveLeaves.length; i++) {
        final item = _collectiveLeaves[i];
        if (item.id == holidayId) continue;
        if (item.collectiveStatus != 'pending') continue;
        _collectiveLeaves[i] = CollectiveLeaveRecord(
          id: item.id,
          date: item.date,
          name: item.name,
          totalDays: item.totalDays,
          collectiveStatus: item.collectiveStatus,
          remainingQuota: remaining,
          policy: item.policy,
          showBanner: item.showBanner,
        );
      }
    }

    await fetchLeaveBalance();
    notifyListeners();
  }

  // ─── Utility Methods ────────────────────────────────────────────────────────

  static int _calculateLateMinutes(String? checkInStr, String? status) {
    if (status != 'late' || checkInStr == null) return 0;
    try {
      final dt = DateTime.parse(checkInStr).toLocal();
      // Assume default start time is 08:00 for calculation if we don't have shift info
      final defaultStart = DateTime(dt.year, dt.month, dt.day, 8, 0);
      if (dt.isAfter(defaultStart)) {
        return dt.difference(defaultStart).inMinutes;
      }
    } catch (_) {}
    return 0;
  }

  // ─── Helper ───────────────────────────────────────────────
  /// Ambil "HH:mm" dari string datetime backend (ISO atau "Y-m-d H:i:s").
  String? _extractTime(dynamic raw) {
    if (raw == null) return null;
    final s = raw.toString();
    if (s.isEmpty) return null;
    final dt = DateTime.tryParse(s);
    if (dt != null) {
      final local = dt.toLocal();
      return '${local.hour.toString().padLeft(2, '0')}:${local.minute.toString().padLeft(2, '0')}';
    }
    // fallback: cari pola HH:mm di dalam string
    final match = RegExp(r'(\d{2}):(\d{2})').firstMatch(s);
    return match?.group(0);
  }

  /// Format tanggal dari backend (ISO "YYYY-MM-DD" atau datetime lengkap)
  /// menjadi "22 Juni 2026". Kembalikan string asli jika parsing gagal.
  String _formatDate(dynamic raw) {
    if (raw == null) return '-';
    final s = raw.toString();
    if (s.isEmpty) return '-';
    final dt = DateTime.tryParse(s);
    if (dt == null) return s;
    return DateFormat('d MMMM yyyy', 'id').format(dt);
  }

  String _nowTime() {
    final now = DateTime.now();
    return '${now.hour.toString().padLeft(2, '0')}:${now.minute.toString().padLeft(2, '0')}';
  }

  /// Ambil "YYYY-MM-DD" dari string tanggal backend (ISO atau sudah Y-m-d).
  String _dateOnly(dynamic raw) {
    if (raw == null) return '';
    final s = raw.toString();
    return s.length >= 10 ? s.substring(0, 10) : s;
  }
}
