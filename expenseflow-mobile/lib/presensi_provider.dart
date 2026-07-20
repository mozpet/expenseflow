import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'services/api_service.dart';
import 'services/notification_service.dart';

class PresensiRecord {
  final int id; // attendance ID — untuk mapping overtime approval
  final String date;
  final String masukTime;
  final String pulangTime;
  final String? checkInType; // 'wfh', 'onsite', 'field'
  final int overtimeMinutes;
  final bool isHoliday;
  final bool isAutoCheckout;
  // null = belum ada lembur / belum diproses; 'pending'/'approved'/'rejected'
  final String? overtimeStatus;

  PresensiRecord({
    this.id = 0,
    required this.date,
    required this.masukTime,
    required this.pulangTime,
    this.checkInType,
    this.overtimeMinutes = 0,
    this.isHoliday = false,
    this.isAutoCheckout = false,
    this.overtimeStatus,
  });

  PresensiRecord copyWith({
    int? id,
    String? date,
    String? masukTime,
    String? pulangTime,
    String? checkInType,
    int? overtimeMinutes,
    bool? isHoliday,
    bool? isAutoCheckout,
    String? overtimeStatus,
  }) {
    return PresensiRecord(
      id: id ?? this.id,
      date: date ?? this.date,
      masukTime: masukTime ?? this.masukTime,
      pulangTime: pulangTime ?? this.pulangTime,
      checkInType: checkInType ?? this.checkInType,
      overtimeMinutes: overtimeMinutes ?? this.overtimeMinutes,
      isHoliday: isHoliday ?? this.isHoliday,
      isAutoCheckout: isAutoCheckout ?? this.isAutoCheckout,
      overtimeStatus: overtimeStatus ?? this.overtimeStatus,
    );
  }

  String get totalJamKerja => _hitungDurasi(masukTime, pulangTime);

  String get totalLembur {
    if (overtimeMinutes <= 0) return '';
    final j = overtimeMinutes ~/ 60;
    final m = overtimeMinutes % 60;
    if (j == 0) return '${m}m';
    if (m == 0) return '${j}j';
    return '${j}j ${m}m';
  }
}

/// Hitung durasi kerja dari "HH:mm" masuk ke "HH:mm" pulang.
/// Kembalikan format "Xj Ym" atau "-" jika data tidak lengkap.
String _hitungDurasi(String masuk, String pulang) {
  if (masuk == '-' || pulang == '-') return '-';
  final mp = masuk.split(':');
  final pp = pulang.split(':');
  if (mp.length < 2 || pp.length < 2) return '-';
  final masukMenit = (int.tryParse(mp[0]) ?? 0) * 60 + (int.tryParse(mp[1]) ?? 0);
  final pulangMenit = (int.tryParse(pp[0]) ?? 0) * 60 + (int.tryParse(pp[1]) ?? 0);
  var diff = pulangMenit - masukMenit;
  // Shift lintas tengah malam (mis. masuk 23:00, pulang 07:00): tambah 24 jam.
  if (diff < 0) diff += 24 * 60;
  if (diff == 0) return '-';
  final jam = diff ~/ 60;
  final menit = diff % 60;
  if (menit == 0) return '${jam}j';
  return '${jam}j ${menit}m';
}

class LeaveRequestRecord {
  final int id;
  final String leaveType; // wfh | izin | sakit | cuti
  final String startDate;
  final String endDate;
  final int totalDays;
  final String reason;
  final String status; // pending | approved | rejected
  final String? rejectionReason;

  LeaveRequestRecord({
    required this.id,
    required this.leaveType,
    required this.startDate,
    required this.endDate,
    required this.totalDays,
    required this.reason,
    required this.status,
    this.rejectionReason,
  });
}

class LeaveBalanceRecord {
  final String leaveType;
  final int quota;
  final int used;
  int get remaining => quota - used;

  LeaveBalanceRecord({
    required this.leaveType,
    required this.quota,
    required this.used,
  });
}

class HolidayRecord {
  final int id;
  final String date;
  final String name;
  final bool isNational;

  HolidayRecord({
    required this.id,
    required this.date,
    required this.name,
    required this.isNational,
  });
}

class PresensiProvider extends ChangeNotifier {
  // Flag dari backend (diisi setelah login): true = boleh presensi WFH via app
  bool wfhEnabled = false;

  final List<PresensiRecord> _records = [];
  final List<LeaveRequestRecord> _leaveRequests = [];
  final List<LeaveBalanceRecord> _leaveBalances = [];
  final List<HolidayRecord> _holidays = [];

  String? _todayMasuk;
  String? _todayPulang;
  int _todayOvertimeMinutes = 0;
  bool _loadingHistory = false;
  bool _loadingBalance = false;
  bool _loadingLeaves = false;
  bool _loadingHolidays = false;

  List<PresensiRecord> get records => List.unmodifiable(_records);
  List<LeaveRequestRecord> get leaveRequests => List.unmodifiable(_leaveRequests);
  List<LeaveBalanceRecord> get leaveBalances => List.unmodifiable(_leaveBalances);
  List<HolidayRecord> get holidays => List.unmodifiable(_holidays);
  String? get todayMasuk => _todayMasuk;
  String? get todayPulang => _todayPulang;
  int get todayOvertimeMinutes => _todayOvertimeMinutes;
  bool get loadingHistory => _loadingHistory;
  bool get loadingBalance => _loadingBalance;
  bool get loadingLeaves => _loadingLeaves;
  bool get loadingHolidays => _loadingHolidays;

  bool get canCheckIn => _todayMasuk == null;
  bool get canCheckOut => _todayMasuk != null && _todayPulang == null;
  String get todayTotalJamKerja =>
      _hitungDurasi(_todayMasuk ?? '-', _todayPulang ?? '-');

  String get todayDateFormatted {
    final now = DateTime.now();
    const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    const months = [
      'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
      'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
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
            pulangTime: '-'),
      );

      // Jadwalkan notifikasi reminder & peringatan auto-checkout
      // Backend mengirim reminder_at dan auto_checkout_at dalam ISO format
      final reminderAt     = res['reminder_at'] as String?;
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

  // ─── Cek status backend untuk deteksi auto-checkout ────────
  /// Dipanggil saat app dibuka (resume) atau halaman presensi dibuka.
  /// Jika backend mencatat auto-checkout, update state lokal & tampilkan notifikasi.
  Future<void> syncStatusFromBackend() async {
    final notifSvc = NotificationService();
    final status = await notifSvc.checkAttendanceStatus();
    if (status == null) return;

    final att = status['attendance'] as Map<String, dynamic>?;
    if (att == null) return;

    final checkedIn    = status['checked_in'] == true;
    final checkedOut   = status['checked_out'] == true;
    final isAutoCheckout = att['is_auto_checkout'] == true;

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
      
      final newOvertime = (att['overtime_minutes'] as num?)?.toInt() ?? 0;
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
    final overtimeApproval = status['overtime_approval'] as Map<String, dynamic>?;
    if (overtimeApproval != null) {
      final approvalStatus = overtimeApproval['status'] as String?;
      final overtimeMins   = (overtimeApproval['overtime_minutes'] as num?)?.toInt() ?? 0;
      final reviewedAt     = overtimeApproval['reviewed_at'];

      // Hanya notifikasi jika baru saja di-review (dalam 5 menit terakhir)
      if (reviewedAt != null && (approvalStatus == 'approved' || approvalStatus == 'rejected')) {
        final reviewTime = DateTime.tryParse(reviewedAt.toString());
        final isRecent = reviewTime != null &&
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

  // ─── Fetch riwayat presensi ───────────────────────────────
  Future<void> fetchMyAttendance() async {
    _loadingHistory = true;
    notifyListeners();
    try {
      final res = await ApiService.myAttendance();
      final list = (res['data'] as List?) ?? [];
      _records
        ..clear()
        ..addAll(list.map((e) {
          final m = e as Map<String, dynamic>;
          final overtimeApproval = m['overtime_approval'] as Map<String, dynamic>?;
          return PresensiRecord(
            id: (m['id'] as num?)?.toInt() ?? 0,
            date: _formatDate(m['date']),
            masukTime: _extractTime(m['check_in_time']) ?? '-',
            pulangTime: _extractTime(m['check_out_time']) ?? '-',
            checkInType: (m['check_in_type'] ?? '').toString(),
            overtimeMinutes: (m['overtime_minutes'] as num?)?.toInt() ?? 0,
            isHoliday: m['is_holiday'] == true || m['is_holiday'] == 1,
            isAutoCheckout: m['is_auto_checkout'] == true || m['is_auto_checkout'] == 1,
            overtimeStatus: overtimeApproval?['status'] as String?,
          );
        }));
      // Set status hari ini bila ada record tanggal hari ini
      final todayIso = DateTime.now().toIso8601String().substring(0, 10);
      for (final e in list) {
        final m = e as Map<String, dynamic>;
        if ((m['date'] ?? '').toString().startsWith(todayIso)) {
          _todayMasuk = _extractTime(m['check_in_time']);
          _todayPulang = _extractTime(m['check_out_time']);
          _todayOvertimeMinutes = (m['overtime_minutes'] as num?)?.toInt() ?? 0;
        }
      }
      // Muat status approval lembur dan pasang ke masing-masing record
      await _loadOvertimeStatuses();
    } catch (_) {
      // diamkan — UI tetap tampil dengan data yang ada
    }
    _loadingHistory = false;
    notifyListeners();
  }

  // ─── Muat status overtime approval & pasang ke records ────────────────
  Future<void> _loadOvertimeStatuses() async {
    try {
      final res = await ApiService.myOvertimeApprovals();
      final approvals = (res['data'] as List?) ?? [];
      // Map: attendance_id -> status
      final statusMap = <int, String>{};
      for (final e in approvals) {
        final m = e as Map<String, dynamic>;
        final attId = (m['attendance_id'] as num?)?.toInt() ?? 0;
        final status = (m['status'] ?? '').toString();
        if (attId > 0) statusMap[attId] = status;
      }
      if (statusMap.isEmpty) return;
      for (var i = 0; i < _records.length; i++) {
        final r = _records[i];
        if (r.id > 0 && statusMap.containsKey(r.id)) {
          _records[i] = r.copyWith(overtimeStatus: statusMap[r.id]);
        }
      }
    } catch (_) {
      // gagal ambil status — tidak perlu crash
    }
  }

  // ─── Fetch saldo cuti ─────────────────────────────────────
  Future<void> fetchLeaveBalance() async {
    _loadingBalance = true;
    notifyListeners();
    try {
      final res = await ApiService.leaveBalance();
      final list = (res['balances'] as List?) ?? [];
      _leaveBalances
        ..clear()
        ..addAll(list.map((e) {
          final m = e as Map<String, dynamic>;
          return LeaveBalanceRecord(
            leaveType: (m['leave_type'] ?? '').toString(),
            quota: (m['quota'] ?? 0) as int,
            used: (m['used'] ?? 0) as int,
          );
        }));
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
        ..addAll(list.map((e) {
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
        }));
    } catch (_) {}
    _loadingLeaves = false;
    notifyListeners();
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

  // ─── Fetch kalender libur ─────────────────────────────────
  Future<void> fetchHolidays(int year) async {
    _loadingHolidays = true;
    notifyListeners();
    try {
      final res = await ApiService.holidays(year);
      final list = (res['holidays'] as List?) ?? [];
      _holidays
        ..clear()
        ..addAll(list.map((e) {
          final m = e as Map<String, dynamic>;
          return HolidayRecord(
            id: (m['id'] ?? 0) as int,
            date: _dateOnly(m['date']),
            name: (m['name'] ?? '').toString(),
            isNational: m['is_national'] == true || m['is_national'] == 1,
          );
        }));
      // Urutkan dari tanggal terkecil
      _holidays.sort((a, b) => a.date.compareTo(b.date));
    } catch (_) {}
    _loadingHolidays = false;
    notifyListeners();
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
