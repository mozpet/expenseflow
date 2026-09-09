import 'package:flutter_test/flutter_test.dart';
import 'package:cobain/providers/shift_provider.dart';

void main() {
  group('ShiftCalendarDay Leave Type Tests', () {
    test('parses izin correctly and sets isIzin true', () {
      final json = {
        'source': 'shift',
        'shift_name': 'Shift Pagi',
        'color': '#A855F7',
        'is_off': true,
        'personal_leave': true,
        'leave_type': 'izin',
        'leave_reason': 'Keperluan keluarga mendesak',
      };

      final day = ShiftCalendarDay.fromJson('2026-09-08', json);

      expect(day.personalLeave, isTrue);
      expect(day.leaveType, equals('izin'));
      expect(day.leaveReason, equals('Keperluan keluarga mendesak'));
      expect(day.isIzin, isTrue);
      expect(day.isSakit, isFalse);
      expect(day.isCuti, isFalse);
    });

    test('parses sakit correctly and sets isSakit true', () {
      final json = {
        'source': 'office',
        'color': '#EA580C',
        'is_off': true,
        'personal_leave': true,
        'leave_type': 'sakit',
        'leave_reason': 'Demam tinggi',
      };

      final day = ShiftCalendarDay.fromJson('2026-09-10', json);

      expect(day.personalLeave, isTrue);
      expect(day.leaveType, equals('sakit'));
      expect(day.leaveReason, equals('Demam tinggi'));
      expect(day.isIzin, isFalse);
      expect(day.isSakit, isTrue);
      expect(day.isCuti, isFalse);
    });

    test('parses cuti correctly and sets isCuti true', () {
      final json = {
        'source': 'shift',
        'color': '#FACC15',
        'is_off': true,
        'personal_leave': true,
        'leave_type': 'cuti',
        'leave_reason': 'Libur keluarga',
      };

      final day = ShiftCalendarDay.fromJson('2026-09-12', json);

      expect(day.personalLeave, isTrue);
      expect(day.leaveType, equals('cuti'));
      expect(day.leaveReason, equals('Libur keluarga'));
      expect(day.isIzin, isFalse);
      expect(day.isSakit, isFalse);
      expect(day.isCuti, isTrue);
    });

    test('fallback: personal_leave true without leave_type is treated as cuti', () {
      final json = {
        'source': 'shift',
        'color': '#FACC15',
        'is_off': true,
        'personal_leave': true,
      };

      final day = ShiftCalendarDay.fromJson('2026-09-15', json);

      expect(day.personalLeave, isTrue);
      expect(day.leaveType, isNull);
      expect(day.isIzin, isFalse);
      expect(day.isSakit, isFalse);
      expect(day.isCuti, isTrue);
    });
  });
}
