import 'package:flutter_test/flutter_test.dart';
import 'package:cobain/providers/shift_provider.dart';

void main() {
  group('ShiftTodayInfo Tests', () {
    test('parses holiday / tanggal merah correctly', () {
      final json = {
        'date': '2026-09-08',
        'source': 'office',
        'type': 'holiday',
        'title': 'Maulid Nabi Muhammad SAW',
        'sub_title': 'Libur',
        'shift_name': null,
        'work_start_time': null,
        'work_end_time': null,
        'is_off': true,
        'is_wfh': false,
        'color': '#EF4444',
        'holiday': {
          'id': 1,
          'name': 'Maulid Nabi Muhammad SAW',
          'is_national': true,
          'is_collective': false,
          'scope': 'nasional',
        },
      };

      final today = ShiftTodayInfo.fromJson(json);
      expect(today.type, 'holiday');
      expect(today.title, 'Maulid Nabi Muhammad SAW');
      expect(today.subTitle, 'Libur');
      expect(today.isOff, true);
    });

    test('parses jam kantor default correctly', () {
      final json = {
        'date': '2026-09-08',
        'source': 'office',
        'type': 'work',
        'title': 'Jam Kantor Default',
        'sub_title': '08:00 — 17:00 WIB',
        'shift_name': null,
        'work_start_time': '08:00',
        'work_end_time': '17:00',
        'is_off': false,
        'is_wfh': false,
        'color': '#64748B',
      };

      final today = ShiftTodayInfo.fromJson(json);
      expect(today.type, 'work');
      expect(today.title, 'Jam Kantor Default');
      expect(today.subTitle, '08:00 — 17:00 WIB');
      expect(today.isOff, false);
    });

    test('parses shift assignment with custom hours correctly', () {
      final json = {
        'date': '2026-09-08',
        'source': 'shift',
        'type': 'work',
        'title': 'Shift Pagi',
        'sub_title': '07:00 — 15:00 WIB',
        'shift_name': 'Shift Pagi',
        'work_start_time': '07:00',
        'work_end_time': '15:00',
        'is_off': false,
        'is_wfh': false,
        'color': '#6366F1',
      };

      final today = ShiftTodayInfo.fromJson(json);
      expect(today.type, 'work');
      expect(today.title, 'Shift Pagi');
      expect(today.subTitle, '07:00 — 15:00 WIB');
      expect(today.isOff, false);
      expect(today.shiftName, 'Shift Pagi');
    });
  });
}
