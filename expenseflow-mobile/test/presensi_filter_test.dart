import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cobain/models/attendance_model.dart';

void main() {
  group('PresensiRecord parsedDate and filtering tests', () {
    test('parses rawDate correctly', () {
      final r1 = PresensiRecord(
        date: '6 September 2026',
        rawDate: '2026-09-06',
        masukTime: '09:17',
        pulangTime: '17:18',
      );
      expect(r1.parsedDate, equals(DateTime(2026, 9, 6)));
    });

    test('falls back to Indonesian formatted date string', () {
      final r2 = PresensiRecord(
        date: '30 Agustus 2026',
        masukTime: '00:47',
        pulangTime: '00:48',
      );
      expect(r2.parsedDate, equals(DateTime(2026, 8, 30)));
    });

    test('filters records by DateTimeRange correctly', () {
      final r1 = PresensiRecord(
        date: '6 September 2026',
        rawDate: '2026-09-06',
        masukTime: '09:17',
        pulangTime: '17:18',
      );
      final r2 = PresensiRecord(
        date: '30 Agustus 2026',
        rawDate: '2026-08-30',
        masukTime: '00:47',
        pulangTime: '00:48',
      );
      final r3 = PresensiRecord(
        date: '27 Agustus 2026',
        rawDate: '2026-08-27',
        masukTime: '08:00',
        pulangTime: '17:00',
      );

      final allRecords = [r1, r2, r3];

      // Filter: 28 Agustus 2026 - 10 September 2026
      final range = DateTimeRange(
        start: DateTime(2026, 8, 28),
        end: DateTime(2026, 9, 10),
      );

      final start = DateTime(range.start.year, range.start.month, range.start.day);
      final end = DateTime(range.end.year, range.end.month, range.end.day, 23, 59, 59);

      final filtered = allRecords.where((r) {
        final dt = r.parsedDate;
        if (dt == null) return true;
        return (dt.isAfter(start) || dt.isAtSameMomentAs(start)) &&
            (dt.isBefore(end) || dt.isAtSameMomentAs(end));
      }).toList();

      expect(filtered.length, equals(2));
      expect(filtered, contains(r1));
      expect(filtered, contains(r2));
      expect(filtered.contains(r3), isFalse);
    });

    test('isEarlyLeave getter returns true when status is early_leave', () {
      final normalRecord = PresensiRecord(
        date: '6 September 2026',
        masukTime: '08:00',
        pulangTime: '17:00',
        status: 'present',
      );
      final earlyRecord = PresensiRecord(
        date: '6 September 2026',
        masukTime: '08:00',
        pulangTime: '15:30',
        status: 'early_leave',
      );

      expect(normalRecord.isEarlyLeave, isFalse);
      expect(earlyRecord.isEarlyLeave, isTrue);
    });
  });
}
