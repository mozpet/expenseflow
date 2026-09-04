import 'package:flutter/material.dart';
import '../services/api_service.dart';

class ShiftScheduleDay {
  final int dayOfWeek;
  final String dayName;
  final String? workStartTime;
  final String? workEndTime;
  final bool isOff;
  /// true = jam hari ini dikustomisasi berbeda dari default kantor
  final bool isCustom;
  /// true = jam pulang hari berikutnya (shift malam lintas hari)
  final bool isCrossDay;

  ShiftScheduleDay({
    required this.dayOfWeek,
    required this.dayName,
    this.workStartTime,
    this.workEndTime,
    required this.isOff,
    this.isCustom = false,
    this.isCrossDay = false,
  });

  factory ShiftScheduleDay.fromJson(Map<String, dynamic> json) {
    return ShiftScheduleDay(
      dayOfWeek: json['day_of_week'] ?? 0,
      dayName: json['day_name'] ?? '',
      workStartTime: json['work_start_time'],
      workEndTime: json['work_end_time'],
      isOff: json['is_off'] ?? false,
      isCustom: json['is_custom'] == true,
      isCrossDay: json['is_cross_day'] == true,
    );
  }
}

/// Info hari libur nasional / perusahaan yang melekat pada suatu tanggal.
class HolidayInfo {
  final int id;
  final String name;
  final bool isNational;
  final bool isCollective;
  final String scope; // 'nasional' | 'perusahaan' | 'cabang'

  const HolidayInfo({
    required this.id,
    required this.name,
    required this.isNational,
    required this.isCollective,
    required this.scope,
  });

  factory HolidayInfo.fromJson(Map<String, dynamic> json) {
    return HolidayInfo(
      id: json['id'] ?? 0,
      name: json['name'] ?? '',
      isNational: json['is_national'] == true,
      isCollective: json['is_collective'] == true,
      scope: json['scope'] ?? 'nasional',
    );
  }
}

/// Jadwal satu hari dari endpoint kalender bulanan (my-schedule-calendar).
/// Sama seperti ShiftScheduleDay namun ditentukan berdasarkan TANGGAL,
/// bukan template shift yang aktif hari ini.
class ShiftCalendarDay {
  final String date; // "2026-08-09"
  final String source; // 'shift' | 'office' | 'none'
  final int? shiftId;
  final String? shiftName;
  final String? color;
  final String? startDate;
  final String? endDate;
  final String? workStartTime;
  final String? workEndTime;
  final bool isOff;
  final bool isCrossDay;
  /// true = hari ini karyawan bekerja dari rumah (Work From Home).
  final bool isWfh;
  /// true = hari ini karyawan bekerja di lapangan (Field / kunjungan luar).
  final bool isField;
  /// Info hari libur jika tanggal ini merupakan hari libur (null = bukan libur).
  final HolidayInfo? holiday;
  /// true = tanggal ini adalah CUTI MANDIRI (cuti pribadi approved, bukan cuti bersama).
  /// Warna sama dengan cuti bersama (kuning), hanya label yang berbeda.
  final bool personalLeave;

  ShiftCalendarDay({
    required this.date,
    required this.source,
    this.shiftId,
    this.shiftName,
    this.color,
    this.startDate,
    this.endDate,
    this.workStartTime,
    this.workEndTime,
    required this.isOff,
    required this.isCrossDay,
    this.isWfh = false,
    this.isField = false,
    this.holiday,
    this.personalLeave = false,
  });

  factory ShiftCalendarDay.fromJson(String date, Map<String, dynamic> json) {
    final holidayJson = json['holiday'];
    return ShiftCalendarDay(
      date: date,
      source: json['source'] ?? 'none',
      shiftId: json['shift_id'],
      shiftName: json['shift_name'],
      color: json['color'],
      startDate: json['start_date'],
      endDate: json['end_date'],
      workStartTime: json['work_start_time'],
      workEndTime: json['work_end_time'],
      isOff: json['is_off'] ?? false,
      isCrossDay: json['is_cross_day'] == true,
      isWfh: json['is_wfh'] == true,
      isField: json['is_field'] == true,
      holiday: holidayJson != null ? HolidayInfo.fromJson(holidayJson as Map<String, dynamic>) : null,
      personalLeave: json['personal_leave'] == true,
    );
  }
}

class ShiftInfo {
  final String name;
  final String color;
  final String? startDate;
  final String? endDate;
  final String? officeName;

  ShiftInfo({
    required this.name,
    required this.color,
    this.startDate,
    this.endDate,
    this.officeName,
  });

  factory ShiftInfo.fromJson(Map<String, dynamic> json) {
    return ShiftInfo(
      name: json['name'] ?? '',
      color: json['color'] ?? '#9CA3AF',
      startDate: json['start_date'],
      endDate: json['end_date'],
      officeName: json['office_name'],
    );
  }
}

class ShiftProvider extends ChangeNotifier {
  bool _loading = false;
  String? _error;
  String _source = 'none';
  ShiftInfo? _shiftInfo;
  List<ShiftScheduleDay> _schedules = [];

  // Banner shift update di beranda
  bool _hasShiftUpdate = false;
  String? _shiftUpdateNote;

  // Kalender bulanan per-tanggal (dari /attendance/my-schedule-calendar)
  // Map: "2026-08-09" → ShiftCalendarDay
  Map<String, ShiftCalendarDay> _calendarDays = {};
  int _calendarYear = 0;
  int _calendarMonth = 0;
  /// List hari libur bulan yang sedang di-load (untuk info di UI kalender).
  List<HolidayInfo> _calendarHolidays = [];

  bool get loading => _loading;
  String? get error => _error;
  String get source => _source;
  ShiftInfo? get shiftInfo => _shiftInfo;
  List<ShiftScheduleDay> get schedules => _schedules;
  bool get hasShiftUpdate => _hasShiftUpdate;
  String? get shiftUpdateNote => _shiftUpdateNote;
  int get calendarYear => _calendarYear;
  int get calendarMonth => _calendarMonth;
  Map<String, ShiftCalendarDay> get calendarDays => _calendarDays;
  List<HolidayInfo> get calendarHolidays => _calendarHolidays;

  ShiftScheduleDay? getScheduleForDayOfWeek(int dayOfWeek) {
    try {
      return _schedules.firstWhere((s) => s.dayOfWeek == dayOfWeek);
    } catch (_) {
      return null;
    }
  }

  /// Ambil jadwal satu tanggal dari kalender bulanan yang sudah di-load.
  /// Fallback ke template shift (perilaku lama) jika kalender belum di-load.
  ShiftCalendarDay? getScheduleForDate(DateTime date) {
    final key = _dateKey(date);
    return _calendarDays[key];
  }

  Future<void> fetchMySchedule({bool forceRefresh = false}) async {
    _loading = true;
    _error = null;
    notifyListeners();

    try {
      final data = await ApiService.get('/employee/my-schedule', forceRefresh: forceRefresh);
      _source = data['source'] ?? 'none';

      if (data['shift'] != null) {
        _shiftInfo = ShiftInfo.fromJson(data['shift']);
      } else {
        _shiftInfo = null;
      }

      if (data['schedules'] != null) {
        _schedules = (data['schedules'] as List)
            .map((s) => ShiftScheduleDay.fromJson(s))
            .toList();
      } else {
        _schedules = [];
      }
    } catch (e) {
      _error = e.toString();
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  // ─── Hapus cache kalender agar fetch berikutnya selalu reload dari server ─
  void clearCalendarCache() {
    _calendarYear = 0;
    _calendarMonth = 0;
    _calendarDays = {};
    _calendarHolidays = [];
  }

  // ─── Kalender jadwal kerja bulanan (per-tanggal) ──────────────────────────
  //     Memakai endpoint /attendance/my-schedule-calendar yang menentukan
  //     jadwal berdasarkan TANGGAL yang dilihat, bukan shift aktif hari ini.
  //     Sehingga perubahan jadwal HRD langsung terlihat di kalender.
  Future<void> fetchScheduleCalendar(int year, int month, {bool forceRefresh = false}) async {
    if (!forceRefresh && _calendarYear == year && _calendarMonth == month && _calendarDays.isNotEmpty) {
      return; // sudah di-load untuk bulan ini
    }

    _loading = true;
    _error = null;
    notifyListeners();

    try {
      final data = await ApiService.get(
        '/attendance/my-schedule-calendar',
        query: {
          'month': month.toString(),
          'year': year.toString(),
        },
        forceRefresh: forceRefresh,
      );

      final days = data['days'] as Map<String, dynamic>? ?? {};
      final parsed = <String, ShiftCalendarDay>{};
      days.forEach((date, val) {
        if (val is Map<String, dynamic>) {
          parsed[date] = ShiftCalendarDay.fromJson(date, val);
        }
      });

      // Parse daftar hari libur bulan ini
      final rawHolidays = data['holidays'] as List<dynamic>? ?? [];
      _calendarHolidays = rawHolidays
          .whereType<Map<String, dynamic>>()
          .map(HolidayInfo.fromJson)
          .toList();

      _calendarDays = parsed;
      _calendarYear = year;
      _calendarMonth = month;
    } catch (e) {
      _error = e.toString();
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  /// Format tanggal → "YYYY-MM-DD" (key map kalender).
  String _dateKey(DateTime d) {
    final y = d.year.toString().padLeft(4, '0');
    final m = d.month.toString().padLeft(2, '0');
    final day = d.day.toString().padLeft(2, '0');
    return '$y-$m-$day';
  }

  // ─── Cek notifikasi shift terbaru (untuk banner "Shift Diperbarui" di beranda) ──
  Future<void> checkShiftUpdates({bool forceRefresh = false}) async {
    try {
      final data = await ApiService.get('/attendance/shift-updates', forceRefresh: forceRefresh);
      _hasShiftUpdate = data['has_update'] == true;
      if (_hasShiftUpdate) {
        final latest = data['latest'] as Map<String, dynamic>?;
        _shiftUpdateNote = latest?['note'] as String? ?? 'Jadwal shift Anda telah diperbarui.';
      } else {
        _shiftUpdateNote = null;
      }
      notifyListeners();
    } catch (_) {
      // Diam – gagal cek notifikasi tidak perlu error ke user
    }
  }

  // ─── Tandai notifikasi shift sudah "dilihat" — hilangkan banner ──────────────
  Future<void> dismissShiftUpdate() async {
    _hasShiftUpdate = false;
    _shiftUpdateNote = null;
    notifyListeners();
    try {
      await ApiService.post('/attendance/dismiss-shift-update');
    } catch (_) {
      // Jika gagal, banner akan muncul lagi di next fetch — aman
    }
  }
}
