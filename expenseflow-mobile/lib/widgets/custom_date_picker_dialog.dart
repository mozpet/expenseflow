import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/shift_provider.dart';

Future<DateTime?> showCustomDatePicker({
  required BuildContext context,
  required DateTime initialDate,
  required DateTime firstDate,
  required DateTime lastDate,
}) {
  return showDialog<DateTime>(
    context: context,
    builder: (ctx) => _CustomDatePickerDialog(
      initialDate: initialDate,
      firstDate: firstDate,
      lastDate: lastDate,
    ),
  );
}

class _CustomDatePickerDialog extends StatefulWidget {
  final DateTime initialDate;
  final DateTime firstDate;
  final DateTime lastDate;

  const _CustomDatePickerDialog({
    required this.initialDate,
    required this.firstDate,
    required this.lastDate,
  });

  @override
  State<_CustomDatePickerDialog> createState() => _CustomDatePickerDialogState();
}

class _CustomDatePickerDialogState extends State<_CustomDatePickerDialog> {
  late DateTime _displayedMonth;
  late DateTime _selectedDate;

  final List<String> _monthNames = [
    '', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
  ];
  final List<String> _dayHeaders = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

  @override
  void initState() {
    super.initState();
    _selectedDate = widget.initialDate;
    _displayedMonth = DateTime(_selectedDate.year, _selectedDate.month, 1);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadMonth();
    });
  }

  void _loadMonth() {
    final prov = Provider.of<ShiftProvider>(context, listen: false);
    prov.fetchScheduleCalendar(_displayedMonth.year, _displayedMonth.month);
  }

  void _prevMonth() {
    setState(() {
      _displayedMonth = DateTime(_displayedMonth.year, _displayedMonth.month - 1, 1);
    });
    _loadMonth();
  }

  void _nextMonth() {
    setState(() {
      _displayedMonth = DateTime(_displayedMonth.year, _displayedMonth.month + 1, 1);
    });
    _loadMonth();
  }

  int _toApiDow(DateTime d) => d.weekday % 7;

  Color _parseColor(String hex) {
    try {
      return Color(int.parse('FF', radix: 16));
    } catch (_) {
      return const Color(0xFF9CA3AF);
    }
  }

  String _shortTime(String t) {
    final p = t.split(':');
    return p.length >= 2 ? ':' : t;
  }

  String _abbreviate(String name) {
    if (name.length <= 9) return name;
    final words = name.split(' ');
    if (words.length == 1) return '.';
    final first = words[0];
    if (first.length >= 9) return '.';
    return first;
  }

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<ShiftProvider>(context);
    final year = _displayedMonth.year;
    final month = _displayedMonth.month;
    final daysInMonth = DateUtils.getDaysInMonth(year, month);
    final firstWeekday = DateTime(year, month, 1).weekday; // 1-7
    final leadingBlanks = firstWeekday - 1;
    final totalCells = leadingBlanks + daysInMonth;
    final rows = (totalCells / 7).ceil();

    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final minDate = DateTime(widget.firstDate.year, widget.firstDate.month, widget.firstDate.day);
    final maxDate = DateTime(widget.lastDate.year, widget.lastDate.month, widget.lastDate.day);

    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      insetPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 20),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Navigator bulan
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                IconButton(
                  onPressed: () {
                    final target = DateTime(_displayedMonth.year, _displayedMonth.month - 1, 1);
                    if (target.year < widget.firstDate.year ||
                        (target.year == widget.firstDate.year && target.month < widget.firstDate.month)) return;
                    _prevMonth();
                  },
                  icon: const Icon(Icons.chevron_left),
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.grey.shade100,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                ),
                Text(
                  '${_monthNames[month]} $year',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.black87),
                ),
                IconButton(
                  onPressed: () {
                    final target = DateTime(_displayedMonth.year, _displayedMonth.month + 1, 1);
                    if (target.year > widget.lastDate.year ||
                        (target.year == widget.lastDate.year && target.month > widget.lastDate.month)) return;
                    _nextMonth();
                  },
                  icon: const Icon(Icons.chevron_right),
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.grey.shade100,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),
            
            // Header hari
            Row(
              children: _dayHeaders
                  .map((h) => Expanded(
                        child: Center(
                          child: Text(
                            h,
                            style: TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                              color: Colors.grey.shade500,
                            ),
                          ),
                        ),
                      ))
                  .toList(),
            ),
            const SizedBox(height: 12),
            
            // Grid tanggal
            ...List.generate(rows, (row) {
              return Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  children: List.generate(7, (col) {
                    final cellIndex = row * 7 + col;
                    if (cellIndex < leadingBlanks || cellIndex >= totalCells) {
                      return const Expanded(child: SizedBox(height: 58));
                    }

                    final day = cellIndex - leadingBlanks + 1;
                    final date = DateTime(year, month, day);
                    final apiDow = _toApiDow(date);
                    
                    final calDay = prov.getScheduleForDate(date);
                    final schedule = calDay != null
                        ? ShiftScheduleDay(
                            dayOfWeek: apiDow,
                            dayName: '',
                            workStartTime: calDay.workStartTime,
                            workEndTime: calDay.workEndTime,
                            isOff: calDay.isOff,
                            isCrossDay: calDay.isCrossDay,
                          )
                        : prov.getScheduleForDayOfWeek(apiDow);
                        
                    final isOff = schedule?.isOff ?? false;
                    final isToday = date.year == today.year && date.month == today.month && date.day == today.day;
                    final isSelected = date.year == _selectedDate.year &&
                        date.month == _selectedDate.month &&
                        date.day == _selectedDate.day;
                    final isDisabled = date.isBefore(minDate) || date.isAfter(maxDate);

                    final isCrossDayToday = schedule?.isCrossDay ?? false;
                    final prevDate = date.subtract(const Duration(days: 1));
                    final prevCalDay = prov.getScheduleForDate(prevDate);
                    final isCrossDayFromYesterday = prevCalDay != null &&
                        prevCalDay.isCrossDay &&
                        !prevCalDay.isOff;

                    final defaultShiftColor = prov.shiftInfo?.color != null
                        ? _parseColor(prov.shiftInfo!.color)
                        : const Color(0xFF9CA3AF);

                    final cellColor = calDay?.color != null
                        ? _parseColor(calDay!.color!)
                        : defaultShiftColor;

                    final bool isShiftDay = (calDay?.source == 'shift') ||
                        (calDay == null && prov.source == 'shift');

                    final holiday = calDay?.holiday;
                    final isHoliday = holiday != null;
                    final isCollectiveLeave = calDay?.shiftName == 'Cuti Bersama';
                    final bool isPersonalLeave =
                        (calDay?.personalLeave ?? false) || calDay?.shiftName == 'Cuti Mandiri';
                    final bool isWfhDay = (calDay?.isWfh ?? false) &&
                        !isOff &&
                        !isHoliday &&
                        !isCollectiveLeave &&
                        !isPersonalLeave;
                        
                    final holidayAccent = isCollectiveLeave || isPersonalLeave
                        ? const Color(0xFFD97706)
                        : holiday != null
                            ? (holiday.isNational
                                ? const Color(0xFFEF4444)
                                : const Color(0xFF3B82F6))
                            : null;

                    return Expanded(
                      child: GestureDetector(
                        onTap: isDisabled
                            ? null
                            : () {
                                setState(() => _selectedDate = date);
                                Future.delayed(const Duration(milliseconds: 150), () {
                                  if (mounted) {
                                    Navigator.of(context).pop(date);
                                  }
                                });
                              },
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 150),
                          height: 58,
                          margin: const EdgeInsets.all(1.5),
                          decoration: BoxDecoration(
                            color: isSelected
                                ? cellColor.withValues(alpha: 0.12)
                                : holidayAccent != null
                                    ? holidayAccent.withValues(alpha: 0.15)
                                    : isOff
                                        ? Colors.red.shade50.withValues(alpha: 0.5)
                                        : Colors.transparent,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(
                              color: isSelected
                                  ? cellColor
                                  : isToday
                                      ? const Color(0xFF1E88E5)
                                      : Colors.transparent,
                              width: isSelected || isToday ? 2 : 0,
                            ),
                          ),
                          child: Opacity(
                            opacity: isDisabled ? 0.3 : 1.0,
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                SizedBox(
                                  height: 10,
                                  child: isWfhDay
                                      ? Icon(
                                          Icons.home_rounded,
                                          size: 10,
                                          color: Colors.teal.shade600,
                                        )
                                      : null,
                                ),
                                Container(
                                  width: 26,
                                  height: 26,
                                  alignment: Alignment.center,
                                  decoration: BoxDecoration(
                                    color: isToday
                                        ? const Color(0xFF1E88E5)
                                        : Colors.transparent,
                                    shape: BoxShape.circle,
                                  ),
                                  child: Text(
                                    '$day',
                                    style: TextStyle(
                                      fontSize: 13,
                                      fontWeight: FontWeight.w600,
                                      color: isToday
                                          ? Colors.white
                                          : holidayAccent ?? (isOff
                                              ? Colors.red.shade400
                                              : Colors.grey.shade800),
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    if (isCrossDayToday || isCrossDayFromYesterday)
                                      Padding(
                                        padding: const EdgeInsets.only(right: 2),
                                        child: Icon(
                                          Icons.nights_stay,
                                          size: 9,
                                          color: Colors.purple.shade600,
                                        ),
                                      ),
                                    if (isCollectiveLeave)
                                      Text('CUTI',
                                          style: TextStyle(
                                              fontSize: 8,
                                              fontWeight: FontWeight.w700,
                                              color: const Color(0xFFD97706)))
                                    else if (isPersonalLeave)
                                      Flexible(
                                        child: Text('CUTI MANDIRI',
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: TextStyle(
                                                fontSize: 7,
                                                fontWeight: FontWeight.w700,
                                                color: const Color(0xFFD97706))),
                                      )
                                    else if (isHoliday)
                                      Flexible(
                                        child: Text(
                                          _abbreviate(holiday.name),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                          style: TextStyle(
                                              fontSize: 7,
                                              fontWeight: FontWeight.w700,
                                              color: holidayAccent ?? Colors.red.shade400),
                                        ),
                                      )
                                    else if (isOff)
                                      Text('OFF',
                                          style: TextStyle(
                                              fontSize: 8,
                                              fontWeight: FontWeight.w700,
                                              color: Colors.red.shade400))
                                    else if (schedule != null &&
                                        schedule.workStartTime != null)
                                      Text(
                                        _shortTime(schedule.workStartTime!),
                                        style: TextStyle(
                                            fontSize: 8,
                                            fontWeight: FontWeight.w600,
                                            color: isShiftDay ? cellColor : Colors.grey.shade600),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    );
                  }),
                ),
              );
            }),
            
            const SizedBox(height: 12),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: () => Navigator.of(context).pop(),
                  child: const Text('Batal', style: TextStyle(color: Colors.grey)),
                ),
              ],
            )
          ],
        ),
      ),
    );
  }
}
