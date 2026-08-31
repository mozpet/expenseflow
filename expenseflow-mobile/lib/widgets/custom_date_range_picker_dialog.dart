import 'package:flutter/material.dart';

/// Membuka dialog pemilih rentang tanggal bergaya Material 3 dengan 2 input header (Dari Kapan & Sampai Kapan).
Future<DateTimeRange?> showCustomDateRangePicker({
  required BuildContext context,
  DateTimeRange? initialDateRange,
  required DateTime firstDate,
  required DateTime lastDate,
}) {
  return showDialog<DateTimeRange>(
    context: context,
    builder: (ctx) => _CustomDateRangePickerDialog(
      initialDateRange: initialDateRange,
      firstDate: firstDate,
      lastDate: lastDate,
    ),
  );
}

class _CustomDateRangePickerDialog extends StatefulWidget {
  final DateTimeRange? initialDateRange;
  final DateTime firstDate;
  final DateTime lastDate;

  const _CustomDateRangePickerDialog({
    this.initialDateRange,
    required this.firstDate,
    required this.lastDate,
  });

  @override
  State<_CustomDateRangePickerDialog> createState() =>
      _CustomDateRangePickerDialogState();
}

enum _ActiveInputField { start, end }

class _CustomDateRangePickerDialogState
    extends State<_CustomDateRangePickerDialog> {
  DateTime? _startDate;
  DateTime? _endDate;
  late DateTime _displayedMonth;
  _ActiveInputField _activeField = _ActiveInputField.start;
  bool _isManualInput = false;

  late TextEditingController _startTextController;
  late TextEditingController _endTextController;
  String? _inputError;

  static const _monthNames = [
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

  static const _dayHeaders = ['M', 'S', 'S', 'R', 'K', 'J', 'S'];

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _startDate = widget.initialDateRange?.start ??
        DateTime(now.year, now.month, now.day);
    _endDate = widget.initialDateRange?.end ??
        DateTime(now.year, now.month, now.day);

    _displayedMonth = DateTime(_startDate!.year, _startDate!.month, 1);

    _startTextController = TextEditingController(
      text: _formatDateInput(_startDate),
    );
    _endTextController = TextEditingController(
      text: _formatDateInput(_endDate),
    );
  }

  @override
  void dispose() {
    _startTextController.dispose();
    _endTextController.dispose();
    super.dispose();
  }

  String _formatDateInput(DateTime? d) {
    if (d == null) return '';
    return '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';
  }

  String _formatDisplayDate(DateTime? d) {
    if (d == null) return 'Pilih tanggal';
    const months = [
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
    return '${d.day} ${months[d.month]} ${d.year}';
  }

  void _onDateTapped(DateTime date) {
    setState(() {
      _inputError = null;
      if (_activeField == _ActiveInputField.start) {
        _startDate = date;
        if (_endDate != null && _endDate!.isBefore(date)) {
          _endDate = date;
        }
        _activeField = _ActiveInputField.end;
      } else {
        if (_startDate != null && date.isBefore(_startDate!)) {
          // Jika memilih tanggal selesai sebelum tanggal mulai, jadikan tanggal mulai baru
          _startDate = date;
        } else {
          _endDate = date;
          _activeField = _ActiveInputField.start;
        }
      }
      _startTextController.text = _formatDateInput(_startDate);
      _endTextController.text = _formatDateInput(_endDate);
    });
  }

  void _prevMonth() {
    setState(() {
      _displayedMonth = DateTime(
        _displayedMonth.year,
        _displayedMonth.month - 1,
        1,
      );
    });
  }

  void _nextMonth() {
    setState(() {
      _displayedMonth = DateTime(
        _displayedMonth.year,
        _displayedMonth.month + 1,
        1,
      );
    });
  }

  void _applyPreset(String preset) {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    setState(() {
      _inputError = null;
      if (preset == 'today') {
        _startDate = today;
        _endDate = today;
      } else if (preset == '7days') {
        _startDate = today.subtract(const Duration(days: 6));
        _endDate = today;
      } else if (preset == 'this_month') {
        _startDate = DateTime(today.year, today.month, 1);
        _endDate = today;
      } else if (preset == 'last_month') {
        _startDate = DateTime(today.year, today.month - 1, 1);
        _endDate = DateTime(today.year, today.month, 0);
      }
      if (_startDate != null) {
        _displayedMonth = DateTime(_startDate!.year, _startDate!.month, 1);
      }
      _startTextController.text = _formatDateInput(_startDate);
      _endTextController.text = _formatDateInput(_endDate);
    });
  }

  DateTime? _parseDate(String input) {
    final parts = input.trim().split('/');
    if (parts.length != 3) return null;
    final day = int.tryParse(parts[0]);
    final month = int.tryParse(parts[1]);
    final year = int.tryParse(parts[2]);
    if (day == null || month == null || year == null) return null;
    if (month < 1 || month > 12) return null;
    if (day < 1 || day > 31) return null;
    try {
      final dt = DateTime(year, month, day);
      if (dt.day != day || dt.month != month || dt.year != year) return null;
      return dt;
    } catch (_) {
      return null;
    }
  }

  void _confirmSelection() {
    if (_isManualInput) {
      final s = _parseDate(_startTextController.text);
      final e = _parseDate(_endTextController.text);
      if (s == null || e == null) {
        setState(() {
          _inputError = 'Format tanggal tidak valid (gunakan DD/MM/YYYY).';
        });
        return;
      }
      if (e.isBefore(s)) {
        setState(() {
          _inputError = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
        });
        return;
      }
      _startDate = s;
      _endDate = e;
    }

    if (_startDate == null || _endDate == null) {
      setState(() {
        _inputError = 'Pilih rentang tanggal terlebih dahulu.';
      });
      return;
    }

    Navigator.of(context).pop(
      DateTimeRange(start: _startDate!, end: _endDate!),
    );
  }

  @override
  Widget build(BuildContext context) {
    const primaryColor = Color(0xFF6750A4);
    const primaryContainer = Color(0xFFE8DEF8);
    const onPrimaryContainer = Color(0xFF21005D);
    const surfaceColor = Color(0xFFF7F2FA);

    return Dialog(
      backgroundColor: surfaceColor,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
      insetPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(28),
        child: SingleChildScrollView(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // ── Header: Title & Edit Mode Toggle ──
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'Pilih rentang tanggal',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFF49454F),
                        letterSpacing: 0.2,
                      ),
                    ),
                    IconButton(
                      icon: Icon(
                        _isManualInput
                            ? Icons.calendar_month_outlined
                            : Icons.edit_outlined,
                        size: 20,
                        color: const Color(0xFF49454F),
                      ),
                      tooltip: _isManualInput
                          ? 'Beralih ke Kalender'
                          : 'Ketik Tanggal Manual',
                      onPressed: () {
                        setState(() {
                          _isManualInput = !_isManualInput;
                          _inputError = null;
                        });
                      },
                    ),
                  ],
                ),

                const SizedBox(height: 8),

                // ── 2 Input Header: Dari Kapan & Sampai Kapan ──
                _buildTopTwoInputs(
                  primaryColor: primaryColor,
                  primaryContainer: primaryContainer,
                  onPrimaryContainer: onPrimaryContainer,
                ),

                if (_inputError != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    _inputError!,
                    style: const TextStyle(
                      fontSize: 11,
                      color: Colors.redAccent,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],

                const SizedBox(height: 16),
                const Divider(height: 1, color: Color(0xFFCAC4D0)),
                const SizedBox(height: 12),

                // ── Calendar View or Manual Text Fields ──
                if (!_isManualInput) ...[
                  _buildMonthNavigator(primaryColor),
                  const SizedBox(height: 12),
                  _buildDayOfWeekHeader(),
                  const SizedBox(height: 8),
                  _buildCalendarGrid(
                    primaryColor: primaryColor,
                    primaryContainer: primaryContainer,
                    onPrimaryContainer: onPrimaryContainer,
                  ),
                ] else ...[
                  _buildManualInputFields(primaryColor),
                ],

                const SizedBox(height: 12),

                // ── Presets Chips ──
                _buildPresetChips(primaryColor),

                const SizedBox(height: 16),

                // ── Bottom Action Buttons (Cancel / OK) ──
                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    TextButton(
                      onPressed: () => Navigator.of(context).pop(null),
                      style: TextButton.styleFrom(
                        foregroundColor: primaryColor,
                        padding: const EdgeInsets.symmetric(
                          horizontal: 16,
                          vertical: 10,
                        ),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(20),
                        ),
                      ),
                      child: const Text(
                        'Cancel',
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 14,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    FilledButton(
                      onPressed: _confirmSelection,
                      style: FilledButton.styleFrom(
                        backgroundColor: primaryColor,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(
                          horizontal: 20,
                          vertical: 10,
                        ),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(20),
                        ),
                      ),
                      child: const Text(
                        'OK',
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 14,
                        ),
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
  }

  /// ─── 2 Input Box Header (Dari Kapan & Sampai Kapan) ───────────────
  Widget _buildTopTwoInputs({
    required Color primaryColor,
    required Color primaryContainer,
    required Color onPrimaryContainer,
  }) {
    final isStartActive = _activeField == _ActiveInputField.start;
    final isEndActive = _activeField == _ActiveInputField.end;

    return Row(
      children: [
        // Box 1: DARI KAPAN
        Expanded(
          child: GestureDetector(
            onTap: () {
              setState(() {
                _activeField = _ActiveInputField.start;
                if (_startDate != null) {
                  _displayedMonth = DateTime(
                    _startDate!.year,
                    _startDate!.month,
                    1,
                  );
                }
              });
            },
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(
                color: isStartActive ? primaryContainer : Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                  color: isStartActive ? primaryColor : const Color(0xFFCAC4D0),
                  width: isStartActive ? 1.8 : 1,
                ),
                boxShadow: isStartActive
                    ? [
                        BoxShadow(
                          color: primaryColor.withValues(alpha: 0.12),
                          blurRadius: 6,
                          offset: const Offset(0, 2),
                        ),
                      ]
                    : [],
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        Icons.calendar_today_outlined,
                        size: 11,
                        color: isStartActive
                            ? onPrimaryContainer
                            : const Color(0xFF79747E),
                      ),
                      const SizedBox(width: 4),
                      Text(
                        'DARI',
                        style: TextStyle(
                          fontSize: 10,
                          fontWeight: FontWeight.bold,
                          color: isStartActive
                              ? onPrimaryContainer
                              : const Color(0xFF79747E),
                          letterSpacing: 0.6,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    _formatDisplayDate(_startDate),
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.bold,
                      color: isStartActive
                          ? onPrimaryContainer
                          : const Color(0xFF1D1B20),
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          ),
        ),

        const Padding(
          padding: EdgeInsets.symmetric(horizontal: 6),
          child: Icon(Icons.arrow_forward, size: 14, color: Color(0xFF79747E)),
        ),

        // Box 2: SAMPAI KAPAN
        Expanded(
          child: GestureDetector(
            onTap: () {
              setState(() {
                _activeField = _ActiveInputField.end;
                if (_endDate != null) {
                  _displayedMonth = DateTime(
                    _endDate!.year,
                    _endDate!.month,
                    1,
                  );
                }
              });
            },
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(
                color: isEndActive ? primaryContainer : Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                  color: isEndActive ? primaryColor : const Color(0xFFCAC4D0),
                  width: isEndActive ? 1.8 : 1,
                ),
                boxShadow: isEndActive
                    ? [
                        BoxShadow(
                          color: primaryColor.withValues(alpha: 0.12),
                          blurRadius: 6,
                          offset: const Offset(0, 2),
                        ),
                      ]
                    : [],
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        Icons.event_outlined,
                        size: 11,
                        color: isEndActive
                            ? onPrimaryContainer
                            : const Color(0xFF79747E),
                      ),
                      const SizedBox(width: 4),
                      Text(
                        'SAMPAI',
                        style: TextStyle(
                          fontSize: 10,
                          fontWeight: FontWeight.bold,
                          color: isEndActive
                              ? onPrimaryContainer
                              : const Color(0xFF79747E),
                          letterSpacing: 0.6,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    _formatDisplayDate(_endDate),
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.bold,
                      color: isEndActive
                          ? onPrimaryContainer
                          : const Color(0xFF1D1B20),
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// ─── Month & Year Navigator ──────────────────────────────────────
  Widget _buildMonthNavigator(Color primaryColor) {
    final year = _displayedMonth.year;
    final month = _displayedMonth.month;

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        // Dropdown Month/Year Selector
        PopupMenuButton<int>(
          tooltip: 'Pilih Bulan & Tahun',
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              children: [
                Text(
                  '${_monthNames[month]} $year',
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.bold,
                    color: Color(0xFF1D1B20),
                  ),
                ),
                const SizedBox(width: 4),
                const Icon(
                  Icons.arrow_drop_down,
                  size: 20,
                  color: Color(0xFF49454F),
                ),
              ],
            ),
          ),
          itemBuilder: (context) {
            final items = <PopupMenuEntry<int>>[];
            final currentYear = DateTime.now().year;
            for (int y = currentYear - 2; y <= currentYear + 1; y++) {
              for (int m = 1; m <= 12; m++) {
                final encoded = y * 100 + m;
                items.add(
                  PopupMenuItem<int>(
                    value: encoded,
                    child: Text(
                      '${_monthNames[m]} $y',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: (y == year && m == month)
                            ? FontWeight.bold
                            : FontWeight.normal,
                        color: (y == year && m == month)
                            ? primaryColor
                            : Colors.black87,
                      ),
                    ),
                  ),
                );
              }
            }
            return items;
          },
          onSelected: (val) {
            final y = val ~/ 100;
            final m = val % 100;
            setState(() {
              _displayedMonth = DateTime(y, m, 1);
            });
          },
        ),

        // Navigation Arrows < >
        Row(
          children: [
            IconButton(
              icon: const Icon(Icons.chevron_left, size: 22),
              onPressed: _prevMonth,
              splashRadius: 18,
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
            ),
            const SizedBox(width: 4),
            IconButton(
              icon: const Icon(Icons.chevron_right, size: 22),
              onPressed: _nextMonth,
              splashRadius: 18,
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
            ),
          ],
        ),
      ],
    );
  }

  /// ─── Day of Week Header (S M T W T F S) ──────────────────────────
  Widget _buildDayOfWeekHeader() {
    return Row(
      children: _dayHeaders
          .map(
            (h) => Expanded(
              child: Center(
                child: Text(
                  h,
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF49454F),
                  ),
                ),
              ),
            ),
          )
          .toList(),
    );
  }

  /// ─── Calendar Grid with Continuous Range Highlighting ────────────
  Widget _buildCalendarGrid({
    required Color primaryColor,
    required Color primaryContainer,
    required Color onPrimaryContainer,
  }) {
    final year = _displayedMonth.year;
    final month = _displayedMonth.month;
    final daysInMonth = DateUtils.getDaysInMonth(year, month);
    // DateTime weekday: Monday = 1 ... Sunday = 7
    // S M T W T F S: Sunday = index 0, Monday = index 1
    final firstWeekday = DateTime(year, month, 1).weekday;
    final leadingBlanks = firstWeekday % 7; // Sunday becomes 0
    final totalCells = leadingBlanks + daysInMonth;
    final totalRows = (totalCells / 7).ceil();

    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);

    return Column(
      children: List.generate(totalRows, (rowIdx) {
        return Padding(
          padding: const EdgeInsets.symmetric(vertical: 2),
          child: Row(
            children: List.generate(7, (colIdx) {
              final cellIdx = rowIdx * 7 + colIdx;
              final dayNum = cellIdx - leadingBlanks + 1;

              if (dayNum < 1 || dayNum > daysInMonth) {
                return const Expanded(child: SizedBox(height: 38));
              }

              final cellDate = DateTime(year, month, dayNum);
              final isToday = cellDate.isAtSameMomentAs(today);

              // Date Range matching
              final isStart = _startDate != null &&
                  cellDate.isAtSameMomentAs(_startDate!);
              final isEnd =
                  _endDate != null && cellDate.isAtSameMomentAs(_endDate!);
              final isInBetween = _startDate != null &&
                  _endDate != null &&
                  cellDate.isAfter(_startDate!) &&
                  cellDate.isBefore(_endDate!);

              final isSingleDateSelected = isStart && isEnd;

              return Expanded(
                child: GestureDetector(
                  onTap: () => _onDateTapped(cellDate),
                  behavior: HitTestBehavior.opaque,
                  child: SizedBox(
                    height: 38,
                    child: Stack(
                      alignment: Alignment.center,
                      children: [
                        // Background Range Ribbon
                        if (isInBetween)
                          Container(
                            height: 34,
                            color: primaryContainer,
                          )
                        else if (isStart && !isSingleDateSelected)
                          Align(
                            alignment: Alignment.centerRight,
                            child: Container(
                              width: 20,
                              height: 34,
                              color: primaryContainer,
                            ),
                          )
                        else if (isEnd && !isSingleDateSelected)
                          Align(
                            alignment: Alignment.centerLeft,
                            child: Container(
                              width: 20,
                              height: 34,
                              color: primaryContainer,
                            ),
                          ),

                        // Selected Circle / Badge
                        Container(
                          width: 34,
                          height: 34,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: (isStart || isEnd)
                                ? primaryColor
                                : (isInBetween ? Colors.transparent : null),
                            border: isToday && !isStart && !isEnd
                                ? Border.all(color: primaryColor, width: 1.2)
                                : null,
                          ),
                          alignment: Alignment.center,
                          child: Text(
                            '$dayNum',
                            style: TextStyle(
                              fontSize: 13,
                              fontWeight: (isStart || isEnd || isToday)
                                  ? FontWeight.bold
                                  : FontWeight.normal,
                              color: (isStart || isEnd)
                                  ? Colors.white
                                  : (isInBetween
                                      ? onPrimaryContainer
                                      : const Color(0xFF1D1B20)),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              );
            }),
          ),
        );
      }),
    );
  }

  /// ─── Manual Text Input Fields (DD/MM/YYYY) ───────────────────────
  Widget _buildManualInputFields(Color primaryColor) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Column(
        children: [
          TextField(
            controller: _startTextController,
            keyboardType: TextInputType.datetime,
            decoration: InputDecoration(
              labelText: 'Tanggal Mulai (DD/MM/YYYY)',
              hintText: 'Contoh: 01/08/2024',
              prefixIcon: const Icon(Icons.calendar_today, size: 18),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: BorderSide(color: primaryColor, width: 2),
              ),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _endTextController,
            keyboardType: TextInputType.datetime,
            decoration: InputDecoration(
              labelText: 'Tanggal Selesai (DD/MM/YYYY)',
              hintText: 'Contoh: 31/08/2024',
              prefixIcon: const Icon(Icons.event, size: 18),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: BorderSide(color: primaryColor, width: 2),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// ─── Quick Preset Chips ──────────────────────────────────────────
  Widget _buildPresetChips(Color primaryColor) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          _chipPreset('Hari Ini', () => _applyPreset('today'), primaryColor),
          const SizedBox(width: 6),
          _chipPreset('7 Hari Terakhir', () => _applyPreset('7days'), primaryColor),
          const SizedBox(width: 6),
          _chipPreset('Bulan Ini', () => _applyPreset('this_month'), primaryColor),
          const SizedBox(width: 6),
          _chipPreset('Bulan Lalu', () => _applyPreset('last_month'), primaryColor),
        ],
      ),
    );
  }

  Widget _chipPreset(String label, VoidCallback onTap, Color primaryColor) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: const Color(0xFFCAC4D0)),
        ),
        child: Text(
          label,
          style: const TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w600,
            color: Color(0xFF49454F),
          ),
        ),
      ),
    );
  }
}
