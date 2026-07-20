import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/shift_provider.dart';

class JadwalShiftScreen extends StatefulWidget {
  const JadwalShiftScreen({super.key});

  @override
  State<JadwalShiftScreen> createState() => _JadwalShiftScreenState();
}

class _JadwalShiftScreenState extends State<JadwalShiftScreen> {
  late DateTime _displayedMonth;
  DateTime? _selectedDate;

  static const _dayHeaders = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
  static const _fullDayNames = [
    'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'
  ];
  static const _monthNames = [
    '', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
  ];

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _displayedMonth = DateTime(now.year, now.month);
    _selectedDate = now;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Provider.of<ShiftProvider>(context, listen: false).fetchMySchedule();
    });
  }

  void _prevMonth() {
    setState(() {
      _displayedMonth = DateTime(
        _displayedMonth.month == 1
            ? _displayedMonth.year - 1
            : _displayedMonth.year,
        _displayedMonth.month == 1 ? 12 : _displayedMonth.month - 1,
      );
    });
  }

  void _nextMonth() {
    setState(() {
      _displayedMonth = DateTime(
        _displayedMonth.month == 12
            ? _displayedMonth.year + 1
            : _displayedMonth.year,
        _displayedMonth.month == 12 ? 1 : _displayedMonth.month + 1,
      );
    });
  }

  /// day_of_week API: 0=Minggu, 1=Senin ... 6=Sabtu
  int _toApiDow(DateTime d) => d.weekday % 7;

  Color _parseColor(String hex) {
    try {
      return Color(int.parse('FF${hex.replaceAll('#', '')}', radix: 16));
    } catch (_) {
      return const Color(0xFF6366f1);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.grey.shade50,
      appBar: AppBar(
        title: const Text('Jadwal Kerja Saya'),
        centerTitle: true,
      ),
      body: Consumer<ShiftProvider>(
        builder: (context, prov, _) {
          if (prov.loading) {
            return const Center(child: CircularProgressIndicator());
          }
          if (prov.error != null) {
            return _buildError(prov);
          }
          if (prov.source == 'none') {
            return _buildEmpty();
          }

          final shiftColor = _parseColor(prov.shiftInfo?.color ?? '#6366f1');

          return RefreshIndicator(
            onRefresh: () => prov.fetchMySchedule(),
            child: SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  _buildShiftInfoCard(prov, shiftColor),
                  const SizedBox(height: 16),
                  _buildCalendarCard(prov, shiftColor),
                  const SizedBox(height: 16),
                  _buildDayDetail(prov, shiftColor),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  // ─── Shift info card ────────────────────────────────────────
  Widget _buildShiftInfoCard(ShiftProvider prov, Color c) {
    final info = prov.shiftInfo!;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [c, c.withValues(alpha: 0.7)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(color: c.withValues(alpha: 0.3), blurRadius: 12,
              offset: const Offset(0, 4)),
        ],
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.2),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.schedule, color: Colors.white, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(info.name,
                    style: const TextStyle(color: Colors.white, fontSize: 18,
                        fontWeight: FontWeight.bold)),
                if (info.officeName != null)
                  Text(info.officeName!,
                      style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.85),
                          fontSize: 13)),
                if (info.startDate != null)
                  Text('Berlaku sejak ${_fmtDate(info.startDate!)}',
                      style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.7),
                          fontSize: 11)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ─── Calendar grid 1 bulan penuh ────────────────────────────
  Widget _buildCalendarCard(ShiftProvider prov, Color shiftColor) {
    final year = _displayedMonth.year;
    final month = _displayedMonth.month;
    final daysInMonth = DateUtils.getDaysInMonth(year, month);

    // Hari pertama bulan ini (1=Senin...7=Minggu)
    final firstWeekday = DateTime(year, month, 1).weekday; // 1-7
    final leadingBlanks = firstWeekday - 1; // slot kosong sebelum tanggal 1

    final now = DateTime.now();

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Column(
        children: [
          // Navigator bulan
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              IconButton(
                onPressed: _prevMonth,
                icon: const Icon(Icons.chevron_left),
                style: IconButton.styleFrom(
                  backgroundColor: Colors.grey.shade100,
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10)),
                ),
              ),
              Text(
                '${_monthNames[month]} $year',
                style: const TextStyle(
                    fontSize: 16, fontWeight: FontWeight.bold),
              ),
              IconButton(
                onPressed: _nextMonth,
                icon: const Icon(Icons.chevron_right),
                style: IconButton.styleFrom(
                  backgroundColor: Colors.grey.shade100,
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),

          // Header hari
          Row(
            children: _dayHeaders.map((h) => Expanded(
              child: Center(
                child: Text(h,
                    style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: Colors.grey.shade500)),
              ),
            )).toList(),
          ),
          const SizedBox(height: 8),

          // Grid tanggal
          ..._buildWeekRows(
            daysInMonth, leadingBlanks, year, month, now, prov, shiftColor,
          ),
        ],
      ),
    );
  }

  List<Widget> _buildWeekRows(
    int daysInMonth,
    int leadingBlanks,
    int year,
    int month,
    DateTime now,
    ShiftProvider prov,
    Color shiftColor,
  ) {
    final totalCells = leadingBlanks + daysInMonth;
    final rows = (totalCells / 7).ceil();

    return List.generate(rows, (row) {
      return Padding(
        padding: const EdgeInsets.only(bottom: 4),
        child: Row(
          children: List.generate(7, (col) {
            final cellIndex = row * 7 + col;
            if (cellIndex < leadingBlanks || cellIndex >= totalCells) {
              return const Expanded(child: SizedBox(height: 52));
            }

            final day = cellIndex - leadingBlanks + 1;
            final date = DateTime(year, month, day);
            final apiDow = _toApiDow(date);
            final schedule = prov.getScheduleForDayOfWeek(apiDow);
            final isOff = schedule?.isOff ?? false;
            final isToday = date.year == now.year &&
                date.month == now.month &&
                date.day == now.day;
            final isSelected = _selectedDate != null &&
                date.year == _selectedDate!.year &&
                date.month == _selectedDate!.month &&
                date.day == _selectedDate!.day;

            return Expanded(
              child: GestureDetector(
                onTap: () => setState(() => _selectedDate = date),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 150),
                  height: 52,
                  margin: const EdgeInsets.all(1.5),
                  decoration: BoxDecoration(
                    color: isSelected
                        ? shiftColor.withValues(alpha: 0.12)
                        : isOff
                            ? Colors.red.shade50.withValues(alpha: 0.5)
                            : Colors.transparent,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(
                      color: isSelected
                          ? shiftColor
                          : isToday
                              ? const Color(0xFF1E88E5)
                              : Colors.transparent,
                      width: isSelected || isToday ? 2 : 0,
                    ),
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      // Tanggal
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
                                : isOff
                                    ? Colors.red.shade400
                                    : Colors.grey.shade800,
                          ),
                        ),
                      ),
                      const SizedBox(height: 2),
                      // Indikator jam / OFF
                      if (isOff)
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
                              color: shiftColor),
                        )
                       else
                         Text('-',
                             style: TextStyle(
                                 fontSize: 8, color: Colors.grey.shade400)),
                    ],
                  ),
                ),
              ),
            );
          }),
        ),
      );
    });
  }

  // ─── Detail hari yang dipilih ────────────────────────────────
  Widget _buildDayDetail(ShiftProvider prov, Color shiftColor) {
    if (_selectedDate == null) return const SizedBox.shrink();

    final date = _selectedDate!;
    final apiDow = _toApiDow(date);
    final schedule = prov.getScheduleForDayOfWeek(apiDow);
    final dayIdx = date.weekday - 1; // 0=Senin
    final dayName = _fullDayNames[dayIdx];

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.calendar_today, size: 18, color: shiftColor),
              const SizedBox(width: 8),
              Text(
                '$dayName, ${date.day} ${_monthNames[date.month]} ${date.year}',
                style: const TextStyle(
                    fontSize: 15, fontWeight: FontWeight.bold),
              ),
            ],
          ),
          const SizedBox(height: 14),
          if (schedule == null)
            Text('Tidak ada jadwal.',
                style: TextStyle(color: Colors.grey.shade500))
          else if (schedule.isOff)
            _statusBanner(
              icon: Icons.weekend,
              label: 'Hari Libur Shift',
              color: Colors.red,
            )
          else ...[
            // Badge jam kustom
            if (schedule.isCustom) ...[
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.amber.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.amber.shade200),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.tune, size: 13, color: Colors.amber.shade700),
                    const SizedBox(width: 4),
                    Text('Jam Kustom',
                        style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: Colors.amber.shade800)),
                  ],
                ),
              ),
              const SizedBox(height: 10),
            ],
            _timeRow(Icons.login, 'Jam Masuk',
                _fmtTime(schedule.workStartTime), Colors.green),
            const SizedBox(height: 10),
            // Jam pulang dengan label +1 hari jika cross-day
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: Colors.orange.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.logout, size: 20, color: Colors.orange),
                ),
                const SizedBox(width: 12),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Jam Pulang',
                        style: TextStyle(fontSize: 12, color: Colors.grey.shade500)),
                    Row(
                      children: [
                        Text(_fmtTime(schedule.workEndTime),
                            style: const TextStyle(
                                fontSize: 16, fontWeight: FontWeight.bold)),
                        if (schedule.isCrossDay) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
                            decoration: BoxDecoration(
                              color: Colors.indigo.shade50,
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: Colors.indigo.shade200),
                            ),
                            child: Text('+1 hari',
                                style: TextStyle(
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold,
                                    color: Colors.indigo.shade700)),
                          ),
                        ],
                      ],
                    ),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 10),
            _statusBanner(
              icon: Icons.check_circle,
              label: 'Hari Kerja',
              color: Colors.green,
            ),
          ],
        ],
      ),
    );
  }

  // ─── Reusable widgets ─────────────────────────────────────
  Widget _statusBanner({
    required IconData icon,
    required String label,
    required MaterialColor color,
  }) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: color.shade50,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.shade200),
      ),
      child: Row(
        children: [
          Icon(icon, color: color.shade600, size: 18),
          const SizedBox(width: 8),
          Text(label,
              style: TextStyle(
                  color: color.shade700,
                  fontWeight: FontWeight.w600,
                  fontSize: 13)),
        ],
      ),
    );
  }

  Widget _timeRow(IconData icon, String label, String value, Color color) {
    return Row(
      children: [
        Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.1),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Icon(icon, size: 20, color: color),
        ),
        const SizedBox(width: 12),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label,
                style: TextStyle(fontSize: 12, color: Colors.grey.shade500)),
            Text(value,
                style: const TextStyle(
                    fontSize: 16, fontWeight: FontWeight.bold)),
          ],
        ),
      ],
    );
  }

  Widget _buildError(ShiftProvider prov) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline, size: 48, color: Colors.red.shade300),
            const SizedBox(height: 12),
            Text(prov.error!, textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey.shade600)),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: () => prov.fetchMySchedule(),
              child: const Text('Coba Lagi'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildEmpty() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.event_busy, size: 48, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text('Belum ada jadwal shift',
                style: TextStyle(color: Colors.grey.shade600, fontSize: 16)),
            const SizedBox(height: 4),
            Text('Hubungi HRD untuk pengaturan jadwal kerja Anda.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey.shade400, fontSize: 13)),
          ],
        ),
      ),
    );
  }

  // ─── Format helpers ──────────────────────────────────────────
  String _shortTime(String t) {
    final p = t.split(':');
    return p.length >= 2 ? '${p[0]}:${p[1]}' : t;
  }

  String _fmtTime(String? t) => t == null ? '-' : _shortTime(t);

  String _fmtDate(String s) {
    try {
      final d = DateTime.parse(s);
      return '${d.day} ${_monthNames[d.month]} ${d.year}';
    } catch (_) {
      return s;
    }
  }
}
