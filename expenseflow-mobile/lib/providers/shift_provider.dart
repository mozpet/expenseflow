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

class ShiftInfo {
  final String name;
  final String color;
  final String? startDate;
  final String? officeName;

  ShiftInfo({
    required this.name,
    required this.color,
    this.startDate,
    this.officeName,
  });

  factory ShiftInfo.fromJson(Map<String, dynamic> json) {
    return ShiftInfo(
      name: json['name'] ?? '',
      color: json['color'] ?? '#6366f1',
      startDate: json['start_date'],
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

  bool get loading => _loading;
  String? get error => _error;
  String get source => _source;
  ShiftInfo? get shiftInfo => _shiftInfo;
  List<ShiftScheduleDay> get schedules => _schedules;
  bool get hasShiftUpdate => _hasShiftUpdate;
  String? get shiftUpdateNote => _shiftUpdateNote;

  ShiftScheduleDay? getScheduleForDayOfWeek(int dayOfWeek) {
    try {
      return _schedules.firstWhere((s) => s.dayOfWeek == dayOfWeek);
    } catch (_) {
      return null;
    }
  }

  Future<void> fetchMySchedule() async {
    _loading = true;
    _error = null;
    notifyListeners();

    try {
      final data = await ApiService.get('/employee/my-schedule');
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

  // ─── Cek notifikasi shift terbaru (untuk banner "Shift Diperbarui" di beranda) ──
  Future<void> checkShiftUpdates() async {
    try {
      final data = await ApiService.get('/attendance/shift-updates');
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
