import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/shift_provider.dart';

Future<DateTime?> showCustomDatePicker({
  required BuildContext context,
  required DateTime initialDate,
  required DateTime firstDate,
  required DateTime lastDate,
  bool disableUnavailable = true,
  String? leaveType,
}) {
  return showDialog<DateTime>(
    context: context,
    builder: (ctx) => _CustomDatePickerDialog(
      initialDate: initialDate,
      firstDate: firstDate,
      lastDate: lastDate,
      disableUnavailable: disableUnavailable,
      leaveType: leaveType,
    ),
  );
}

Future<List<DateTime>?> showCustomMultiDatePicker({
  required BuildContext context,
  required List<DateTime> initialDates,
  required DateTime firstDate,
  required DateTime lastDate,
  bool disableUnavailable = true,
  String? leaveType,
}) {
  return showDialog<List<DateTime>>(
    context: context,
    builder: (ctx) => _CustomMultiDatePickerDialog(
      initialDates: initialDates,
      firstDate: firstDate,
      lastDate: lastDate,
      disableUnavailable: disableUnavailable,
      leaveType: leaveType,
    ),
  );
}

class _CustomDatePickerDialog extends StatefulWidget {
  final DateTime initialDate;
  final DateTime firstDate;
  final DateTime lastDate;
  final bool disableUnavailable;
  final String? leaveType;

  const _CustomDatePickerDialog({
    required this.initialDate,
    required this.firstDate,
    required this.lastDate,
    this.disableUnavailable = true,
    this.leaveType,
  });

  @override
  State<_CustomDatePickerDialog> createState() =>
      _CustomDatePickerDialogState();
}

class _CustomDatePickerDialogState extends State<_CustomDatePickerDialog> {
  late DateTime _displayedMonth;
  late DateTime _selectedDate;

  final List<String> _monthNames = [
    '',
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
  final List<String> _dayHeaders = [
    'Sen',
    'Sel',
    'Rab',
    'Kam',
    'Jum',
    'Sab',
    'Min',
  ];

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
      _displayedMonth = DateTime(
        _displayedMonth.year,
        _displayedMonth.month - 1,
        1,
      );
    });
    _loadMonth();
  }

  void _nextMonth() {
    setState(() {
      _displayedMonth = DateTime(
        _displayedMonth.year,
        _displayedMonth.month + 1,
        1,
      );
    });
    _loadMonth();
  }

  int _toApiDow(DateTime d) => d.weekday % 7;

  final Map<String, Color> _colorCache = {};

  Color _parseColor(String hex) {
    if (_colorCache.containsKey(hex)) return _colorCache[hex]!;
    try {
      final clean = hex.replaceAll('#', '').trim();
      final full = clean.length == 6 ? 'FF$clean' : clean;
      final c = Color(int.parse(full, radix: 16));
      _colorCache[hex] = c;
      return c;
    } catch (_) {
      const fallback = Color(0xFF9CA3AF);
      _colorCache[hex] = fallback;
      return fallback;
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
    final minDate = DateTime(
      widget.firstDate.year,
      widget.firstDate.month,
      widget.firstDate.day,
    );
    final maxDate = DateTime(
      widget.lastDate.year,
      widget.lastDate.month,
      widget.lastDate.day,
    );

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
                    final target = DateTime(
                      _displayedMonth.year,
                      _displayedMonth.month - 1,
                      1,
                    );
                    if (target.year < widget.firstDate.year ||
                        (target.year == widget.firstDate.year &&
                            target.month < widget.firstDate.month)) {
                      return;
                    }
                    _prevMonth();
                  },
                  icon: const Icon(Icons.chevron_left),
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.grey.shade100,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
                Text(
                  '${_monthNames[month]} $year',
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
                IconButton(
                  onPressed: () {
                    final target = DateTime(
                      _displayedMonth.year,
                      _displayedMonth.month + 1,
                      1,
                    );
                    if (target.year > widget.lastDate.year ||
                        (target.year == widget.lastDate.year &&
                            target.month > widget.lastDate.month)) {
                      return;
                    }
                    _nextMonth();
                  },
                  icon: const Icon(Icons.chevron_right),
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.grey.shade100,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),

            // Header hari
            Row(
              children: _dayHeaders
                  .map(
                    (h) => Expanded(
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
                    ),
                  )
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
                    final isToday =
                        date.year == today.year &&
                        date.month == today.month &&
                        date.day == today.day;
                    final isSelected =
                        date.year == _selectedDate.year &&
                        date.month == _selectedDate.month &&
                        date.day == _selectedDate.day;

                    final isCrossDayToday = schedule?.isCrossDay ?? false;
                    final prevDate = date.subtract(const Duration(days: 1));
                    final prevCalDay = prov.getScheduleForDate(prevDate);
                    final isCrossDayFromYesterday =
                        prevCalDay != null &&
                        prevCalDay.isCrossDay &&
                        !prevCalDay.isOff;

                    final defaultShiftColor = prov.shiftInfo?.color != null
                        ? _parseColor(prov.shiftInfo!.color)
                        : const Color(0xFF9CA3AF);

                    final cellColor = calDay?.color != null
                        ? _parseColor(calDay!.color!)
                        : defaultShiftColor;

                    final bool isShiftDay =
                        (calDay?.source == 'shift') ||
                        (calDay == null && prov.source == 'shift');

                    final holiday = calDay?.holiday;
                    final isHoliday = holiday != null;
                    final isCollectiveLeave =
                        calDay?.shiftName == 'Cuti Bersama';
                    final bool isPersonalLeave =
                        (calDay?.personalLeave ?? false) ||
                        calDay?.shiftName == 'Cuti Mandiri' ||
                        calDay?.shiftName == 'Izin' ||
                        calDay?.shiftName == 'Sakit';
                    final bool isIzin = (calDay?.isIzin == true) || calDay?.shiftName == 'Izin';
                    final bool isSakit = (calDay?.isSakit == true) || calDay?.shiftName == 'Sakit';
                    final bool isCuti = isCollectiveLeave || (isPersonalLeave && !isIzin && !isSakit);

                    final bool isWfhDay =
                        (calDay?.isWfh ?? false) &&
                        !isOff &&
                        !isHoliday &&
                        !isCollectiveLeave &&
                        !isPersonalLeave;

                    // Jika user sedang mengajukan WFH, tanggal yang sudah berstatus WFH (jadwal shift / pengajuan sebelumnya)
                    // dianggap OFF / tidak tersedia agar tidak dapat dipilih lagi (menghindari dobel).
                    final bool isWfhOff =
                        (widget.leaveType?.toLowerCase() == 'wfh') && isWfhDay;

                    final bool isUnavailable =
                        isOff ||
                        isHoliday ||
                        isCollectiveLeave ||
                        isPersonalLeave ||
                        isWfhOff;
                    final bool isDisabled =
                        date.isBefore(minDate) ||
                        date.isAfter(maxDate) ||
                        (widget.disableUnavailable && isUnavailable);

                    final holidayAccent = isIzin
                        ? const Color(0xFF7B1FA2)
                        : isSakit
                            ? const Color(0xFFEA580C)
                            : isCuti
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
                                final nav = Navigator.of(context);
                                setState(() => _selectedDate = date);
                                Future.delayed(
                                  const Duration(milliseconds: 150),
                                  () {
                                    if (mounted) {
                                      nav.pop(date);
                                    }
                                  },
                                );
                              },
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 150),
                          height: 58,
                          margin: const EdgeInsets.all(1.5),
                          decoration: BoxDecoration(
                            color: isDisabled
                                ? (isUnavailable
                                      ? Colors.grey.shade100.withValues(
                                          alpha: 0.6,
                                        )
                                      : Colors.transparent)
                                : isSelected
                                ? cellColor.withValues(alpha: 0.12)
                                : holidayAccent != null
                                ? holidayAccent.withValues(alpha: 0.15)
                                : isOff || isWfhOff
                                ? Colors.red.shade50.withValues(alpha: 0.5)
                                : Colors.transparent,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(
                              color: !isDisabled && isSelected
                                  ? cellColor
                                  : isToday && !isDisabled
                                  ? const Color(0xFF1E88E5)
                                  : Colors.transparent,
                              width:
                                  (!isDisabled && isSelected) ||
                                      (isToday && !isDisabled)
                                  ? 2
                                  : 0,
                            ),
                          ),
                          child: Opacity(
                            opacity: isDisabled ? 0.35 : 1.0,
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                SizedBox(
                                  height: 10,
                                  child: isWfhDay
                                      ? Icon(
                                          Icons.home_rounded,
                                          size: 10,
                                          color: isWfhOff
                                              ? Colors.red.shade400
                                              : Colors.teal.shade600,
                                        )
                                      : null,
                                ),
                                Container(
                                  width: 26,
                                  height: 26,
                                  alignment: Alignment.center,
                                  decoration: BoxDecoration(
                                    color: isToday && !isDisabled
                                        ? const Color(0xFF1E88E5)
                                        : Colors.transparent,
                                    shape: BoxShape.circle,
                                  ),
                                  child: Text(
                                    '$day',
                                    style: TextStyle(
                                      fontSize: 13,
                                      fontWeight: FontWeight.w600,
                                      color: (isToday && !isDisabled)
                                          ? Colors.white
                                          : holidayAccent ??
                                                (isOff || isWfhOff
                                                    ? Colors.red.shade400
                                                    : Colors.grey.shade800),
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    if (isCrossDayToday ||
                                        isCrossDayFromYesterday)
                                      Padding(
                                        padding: const EdgeInsets.only(
                                          right: 2,
                                        ),
                                        child: Icon(
                                          Icons.nights_stay,
                                          size: 9,
                                          color: Colors.purple.shade600,
                                        ),
                                      ),
                                    if (isIzin)
                                      const Text(
                                        'IZIN',
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w700,
                                          color: Color(0xFF7B1FA2),
                                        ),
                                      )
                                    else if (isSakit)
                                      const Text(
                                        'SAKIT',
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w700,
                                          color: Color(0xFFEA580C),
                                        ),
                                      )
                                    else if (isCuti)
                                      const Text(
                                        'CUTI',
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w700,
                                          color: Color(0xFFD97706),
                                        ),
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
                                            color:
                                                holidayAccent ??
                                                Colors.red.shade400,
                                          ),
                                        ),
                                      )
                                    else if (isOff || isWfhOff)
                                      Text(
                                        'OFF',
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w700,
                                          color: Colors.red.shade400,
                                        ),
                                      )
                                    else if (schedule != null &&
                                        schedule.workStartTime != null)
                                      Text(
                                        _shortTime(schedule.workStartTime!),
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w600,
                                          color: isShiftDay
                                              ? cellColor
                                              : Colors.grey.shade600,
                                        ),
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
                  child: const Text(
                    'Batal',
                    style: TextStyle(color: Colors.grey),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _CustomMultiDatePickerDialog extends StatefulWidget {
  final List<DateTime> initialDates;
  final DateTime firstDate;
  final DateTime lastDate;
  final bool disableUnavailable;
  final String? leaveType;

  const _CustomMultiDatePickerDialog({
    required this.initialDates,
    required this.firstDate,
    required this.lastDate,
    this.disableUnavailable = true,
    this.leaveType,
  });

  @override
  State<_CustomMultiDatePickerDialog> createState() =>
      _CustomMultiDatePickerDialogState();
}

class _CustomMultiDatePickerDialogState
    extends State<_CustomMultiDatePickerDialog> {
  late DateTime _displayedMonth;
  late Set<DateTime> _selectedDates;

  final List<String> _monthNames = [
    '',
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
  final List<String> _dayHeaders = [
    'Sen',
    'Sel',
    'Rab',
    'Kam',
    'Jum',
    'Sab',
    'Min',
  ];

  @override
  void initState() {
    super.initState();
    _selectedDates = widget.initialDates
        .map((d) => DateTime(d.year, d.month, d.day))
        .toSet();

    final now = DateTime.now();
    final firstSelected = _selectedDates.isNotEmpty
        ? (_selectedDates.toList()..sort()).first
        : DateTime(now.year, now.month, 1);

    _displayedMonth = DateTime(firstSelected.year, firstSelected.month, 1);

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
      _displayedMonth = DateTime(
        _displayedMonth.year,
        _displayedMonth.month - 1,
        1,
      );
    });
    _loadMonth();
  }

  void _nextMonth() {
    setState(() {
      _displayedMonth = DateTime(
        _displayedMonth.year,
        _displayedMonth.month + 1,
        1,
      );
    });
    _loadMonth();
  }

  int _toApiDow(DateTime d) => d.weekday % 7;

  final Map<String, Color> _colorCache = {};

  Color _parseColor(String hex) {
    if (_colorCache.containsKey(hex)) return _colorCache[hex]!;
    try {
      final clean = hex.replaceAll('#', '').trim();
      final full = clean.length == 6 ? 'FF$clean' : clean;
      final c = Color(int.parse(full, radix: 16));
      _colorCache[hex] = c;
      return c;
    } catch (_) {
      const fallback = Color(0xFF9CA3AF);
      _colorCache[hex] = fallback;
      return fallback;
    }
  }

  String _shortTime(String t) {
    final p = t.split(':');
    return p.length >= 2 ? '${p[0]}:${p[1]}' : t;
  }

  String _abbreviate(String name) {
    if (name.length <= 9) return name;
    final words = name.split(' ');
    if (words.length == 1) return '${name.substring(0, 7)}..';
    final first = words[0];
    if (first.length >= 9) return '${first.substring(0, 7)}..';
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
    final minDate = DateTime(
      widget.firstDate.year,
      widget.firstDate.month,
      widget.firstDate.day,
    );
    final maxDate = DateTime(
      widget.lastDate.year,
      widget.lastDate.month,
      widget.lastDate.day,
    );

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
            // Header Dialog: Judul & Subtitle
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: const Color(0xFF0088FF).withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(
                    Icons.date_range_rounded,
                    color: Color(0xFF0088FF),
                    size: 20,
                  ),
                ),
                const SizedBox(width: 10),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Pilih Tanggal Acak',
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                          color: Colors.black87,
                        ),
                      ),
                      Text(
                        'Ketuk tanggal untuk memilih / batal',
                        style: TextStyle(
                          fontSize: 11,
                          color: Colors.grey,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // Navigator bulan
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                IconButton(
                  onPressed: () {
                    final target = DateTime(
                      _displayedMonth.year,
                      _displayedMonth.month - 1,
                      1,
                    );
                    if (target.year < widget.firstDate.year ||
                        (target.year == widget.firstDate.year &&
                            target.month < widget.firstDate.month)) {
                      return;
                    }
                    _prevMonth();
                  },
                  icon: const Icon(Icons.chevron_left),
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.grey.shade100,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
                Text(
                  '${_monthNames[month]} $year',
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
                IconButton(
                  onPressed: () {
                    final target = DateTime(
                      _displayedMonth.year,
                      _displayedMonth.month + 1,
                      1,
                    );
                    if (target.year > widget.lastDate.year ||
                        (target.year == widget.lastDate.year &&
                            target.month > widget.lastDate.month)) {
                      return;
                    }
                    _nextMonth();
                  },
                  icon: const Icon(Icons.chevron_right),
                  style: IconButton.styleFrom(
                    backgroundColor: Colors.grey.shade100,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // Header hari
            Row(
              children: _dayHeaders
                  .map(
                    (h) => Expanded(
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
                    ),
                  )
                  .toList(),
            ),
            const SizedBox(height: 10),

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
                    final isToday =
                        date.year == today.year &&
                        date.month == today.month &&
                        date.day == today.day;
                    final isSelected = _selectedDates.contains(date);

                    final isCrossDayToday = schedule?.isCrossDay ?? false;
                    final prevDate = date.subtract(const Duration(days: 1));
                    final prevCalDay = prov.getScheduleForDate(prevDate);
                    final isCrossDayFromYesterday =
                        prevCalDay != null &&
                        prevCalDay.isCrossDay &&
                        !prevCalDay.isOff;

                    final defaultShiftColor = prov.shiftInfo?.color != null
                        ? _parseColor(prov.shiftInfo!.color)
                        : const Color(0xFF9CA3AF);

                    final cellColor = calDay?.color != null
                        ? _parseColor(calDay!.color!)
                        : defaultShiftColor;

                    final bool isShiftDay =
                        (calDay?.source == 'shift') ||
                        (calDay == null && prov.source == 'shift');

                    final holiday = calDay?.holiday;
                    final isHoliday = holiday != null;
                    final isCollectiveLeave =
                        calDay?.shiftName == 'Cuti Bersama';
                    final bool isPersonalLeave =
                        (calDay?.personalLeave ?? false) ||
                        calDay?.shiftName == 'Cuti Mandiri' ||
                        calDay?.shiftName == 'Izin' ||
                        calDay?.shiftName == 'Sakit';
                    final bool isIzin = (calDay?.isIzin == true) || calDay?.shiftName == 'Izin';
                    final bool isSakit = (calDay?.isSakit == true) || calDay?.shiftName == 'Sakit';
                    final bool isCuti = isCollectiveLeave || (isPersonalLeave && !isIzin && !isSakit);

                    final bool isWfhDay =
                        (calDay?.isWfh ?? false) &&
                        !isOff &&
                        !isHoliday &&
                        !isCollectiveLeave &&
                        !isPersonalLeave;

                    final bool isWfhOff =
                        (widget.leaveType?.toLowerCase() == 'wfh') && isWfhDay;

                    final bool isUnavailable =
                        isOff ||
                        isHoliday ||
                        isCollectiveLeave ||
                        isPersonalLeave ||
                        isWfhOff;
                    final bool isDisabled =
                        date.isBefore(minDate) ||
                        date.isAfter(maxDate) ||
                        (widget.disableUnavailable && isUnavailable);

                    final holidayAccent = isIzin
                        ? const Color(0xFF7B1FA2)
                        : isSakit
                            ? const Color(0xFFEA580C)
                            : isCuti
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
                                setState(() {
                                  if (_selectedDates.contains(date)) {
                                    _selectedDates.remove(date);
                                  } else {
                                    _selectedDates.add(date);
                                  }
                                });
                              },
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 150),
                          height: 58,
                          margin: const EdgeInsets.all(1.5),
                          decoration: BoxDecoration(
                            color: isDisabled
                                ? (isUnavailable
                                      ? Colors.grey.shade100.withValues(
                                          alpha: 0.6,
                                        )
                                      : Colors.transparent)
                                : isSelected
                                ? const Color(0xFF0088FF).withValues(alpha: 0.18)
                                : holidayAccent != null
                                ? holidayAccent.withValues(alpha: 0.15)
                                : isOff || isWfhOff
                                ? Colors.red.shade50.withValues(alpha: 0.5)
                                : Colors.transparent,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(
                              color: !isDisabled && isSelected
                                  ? const Color(0xFF0088FF)
                                  : isToday && !isDisabled
                                  ? const Color(0xFF1E88E5)
                                  : Colors.transparent,
                              width: (!isDisabled && isSelected) ||
                                      (isToday && !isDisabled)
                                  ? 2
                                  : 0,
                            ),
                          ),
                          child: Opacity(
                            opacity: isDisabled ? 0.35 : 1.0,
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                SizedBox(
                                  height: 10,
                                  child: isWfhDay
                                      ? Icon(
                                          Icons.home_rounded,
                                          size: 10,
                                          color: isWfhOff
                                              ? Colors.red.shade400
                                              : Colors.teal.shade600,
                                        )
                                      : null,
                                ),
                                Container(
                                  width: 26,
                                  height: 26,
                                  alignment: Alignment.center,
                                  decoration: BoxDecoration(
                                    color: (!isDisabled && isSelected)
                                        ? const Color(0xFF0088FF)
                                        : (isToday && !isDisabled
                                            ? const Color(0xFF1E88E5)
                                            : Colors.transparent),
                                    shape: BoxShape.circle,
                                  ),
                                  child: Text(
                                    '$day',
                                    style: TextStyle(
                                      fontSize: 13,
                                      fontWeight: FontWeight.w600,
                                      color: (!isDisabled && isSelected)
                                          ? Colors.white
                                          : (isToday && !isDisabled
                                              ? Colors.white
                                              : holidayAccent ??
                                                    (isOff || isWfhOff
                                                        ? Colors.red.shade400
                                                        : Colors.grey.shade800)),
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    if (isCrossDayToday ||
                                        isCrossDayFromYesterday)
                                      Padding(
                                        padding: const EdgeInsets.only(
                                          right: 2,
                                        ),
                                        child: Icon(
                                          Icons.nights_stay,
                                          size: 9,
                                          color: Colors.purple.shade600,
                                        ),
                                      ),
                                    if (isIzin)
                                      const Text(
                                        'IZIN',
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w700,
                                          color: Color(0xFF7B1FA2),
                                        ),
                                      )
                                    else if (isSakit)
                                      const Text(
                                        'SAKIT',
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w700,
                                          color: Color(0xFFEA580C),
                                        ),
                                      )
                                    else if (isCuti)
                                      const Text(
                                        'CUTI',
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w700,
                                          color: Color(0xFFD97706),
                                        ),
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
                                            color:
                                                holidayAccent ??
                                                Colors.red.shade400,
                                          ),
                                        ),
                                      )
                                    else if (isOff || isWfhOff)
                                      Text(
                                        'OFF',
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w700,
                                          color: Colors.red.shade400,
                                        ),
                                      )
                                    else if (schedule != null &&
                                        schedule.workStartTime != null)
                                      Text(
                                        _shortTime(schedule.workStartTime!),
                                        style: TextStyle(
                                          fontSize: 8,
                                          fontWeight: FontWeight.w600,
                                          color: isShiftDay
                                              ? cellColor
                                              : Colors.grey.shade600,
                                        ),
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

            const SizedBox(height: 16),
            // Bottom Action Bar: jumlah terpilih + Batal + Simpan
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE3F2FD),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(
                        Icons.check_circle_outline_rounded,
                        size: 15,
                        color: Color(0xFF1565C0),
                      ),
                      const SizedBox(width: 5),
                      Text(
                        '${_selectedDates.length} dipilih',
                        style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF1565C0),
                        ),
                      ),
                    ],
                  ),
                ),
                Row(
                  children: [
                    TextButton(
                      onPressed: () => Navigator.of(context).pop(),
                      child: const Text(
                        'Batal',
                        style: TextStyle(color: Colors.grey),
                      ),
                    ),
                    const SizedBox(width: 8),
                    ElevatedButton(
                      onPressed: () {
                        final sorted = _selectedDates.toList()
                          ..sort((a, b) => a.compareTo(b));
                        Navigator.of(context).pop(sorted);
                      },
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF0088FF),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(
                            horizontal: 18, vertical: 10),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8),
                        ),
                      ),
                      child: const Text(
                        'Pilih',
                        style: TextStyle(fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
