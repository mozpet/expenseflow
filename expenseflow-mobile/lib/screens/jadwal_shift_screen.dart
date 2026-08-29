import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/shift_provider.dart';
import '../widgets/skeleton.dart';

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
      final prov = Provider.of<ShiftProvider>(context, listen: false);
      // Info shift "hari ini" + kalender bulan berjalan (per-tanggal)
      prov.fetchMySchedule();
      prov.fetchScheduleCalendar(now.year, now.month);
    });
  }

  /// Muat kalender saat berpindah bulan (pastikan jadwal per-tanggal akurat).
  void _loadMonth(ShiftProvider prov) {
    prov.fetchScheduleCalendar(_displayedMonth.year, _displayedMonth.month);
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
    _loadMonth(Provider.of<ShiftProvider>(context, listen: false));
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
    _loadMonth(Provider.of<ShiftProvider>(context, listen: false));
  }

  /// Muat ulang (refresh) data shift: info hari ini + kalender bulan yang ditampilkan.
  Future<void> _refreshData() async {
    final prov = Provider.of<ShiftProvider>(context, listen: false);
    await prov.fetchMySchedule();
    prov.clearCalendarCache();
    await prov.fetchScheduleCalendar(_displayedMonth.year, _displayedMonth.month);
  }

  /// day_of_week API: 0=Minggu, 1=Senin ... 6=Sabtu
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

  /// Ambil info shift yang relevan berdasarkan tanggal yang dipilih.
  /// Fokuskan HANYA ke nama jadwal shift atau jam kantor default (tidak pernah menampilkan nama libur/cuti di header).
  _ShiftDisplayInfo _resolveDisplayInfo(ShiftProvider prov) {
    // Prioritaskan tanggal yang dipilih
    final date = _selectedDate;
    if (date != null) {
      final calDay = prov.getScheduleForDate(date);
      if (calDay != null) {
        if (calDay.source == 'shift') {
          // Dapatkan nama shift (jika shiftName sama dengan nama libur atau cuti, fallback ke nama shift dari mySchedule)
          final isHolidayName = calDay.holiday != null && calDay.shiftName == calDay.holiday!.name;
          final String shiftName = (calDay.shiftName != null &&
                  calDay.shiftName != 'Cuti Bersama' &&
                  calDay.shiftName != 'Cuti Mandiri' &&
                  !isHolidayName)
              ? calDay.shiftName!
              : (prov.shiftInfo?.name.isNotEmpty == true
                  ? prov.shiftInfo!.name
                  : 'Shift Kerja');

          final String? startDate = calDay.startDate ??
              _findShiftStartDate(prov, date, calDay.shiftId) ??
              prov.shiftInfo?.startDate;
          final String? endDate = calDay.endDate ?? prov.shiftInfo?.endDate;

          return _ShiftDisplayInfo(
            name: shiftName,
            color: calDay.color ?? (prov.shiftInfo?.color ?? '#6366F1'),
            source: 'shift',
            startDate: startDate,
            endDate: endDate,
          );
        } else if (calDay.source == 'office') {
          return const _ShiftDisplayInfo(
            name: 'Jam Kantor (Default)',
            color: '#64748B',
            source: 'office',
            startDate: null,
            endDate: null,
          );
        }
      }
    }

    // Fallback ke shiftInfo dari /my-schedule (shift berlaku hari ini)
    if (prov.shiftInfo != null && prov.source == 'shift') {
      return _ShiftDisplayInfo(
        name: prov.shiftInfo!.name,
        color: prov.shiftInfo!.color,
        source: 'shift',
        startDate: prov.shiftInfo!.startDate,
        endDate: prov.shiftInfo!.endDate,
      );
    }

    return const _ShiftDisplayInfo(
      name: 'Jam Kantor (Default)',
      color: '#64748B',
      source: 'office',
    );
  }

  /// Cari tanggal pertama shift dengan shiftId tertentu muncul di kalender
  /// (mundur dari tanggal yang dipilih hingga shift berganti atau awal bulan).
  String? _findShiftStartDate(ShiftProvider prov, DateTime selectedDate, int? shiftId) {
    if (shiftId == null) return null;
    
    // Cari tanggal pertama shift ini dari calendarDays yang ter-load
    // Iterasi mundur dari tanggal dipilih
    DateTime cursor = selectedDate;
    String? firstDate;
    
    while (true) {
      final key = _dateKeyStr(cursor);
      final day = prov.calendarDays[key];
      if (day != null && day.shiftId == shiftId) {
        firstDate = key;
        cursor = cursor.subtract(const Duration(days: 1));
      } else {
        break;
      }
    }
    
    return firstDate;
  }

  String _dateKeyStr(DateTime d) {
    final y = d.year.toString().padLeft(4, '0');
    final m = d.month.toString().padLeft(2, '0');
    final day = d.day.toString().padLeft(2, '0');
    return '$y-$m-$day';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.grey.shade50,
      appBar: AppBar(
        title: const Text('Jadwal Kerja Saya'),
        centerTitle: true,
        actions: [
          IconButton(
            tooltip: 'Refresh',
            onPressed: _refreshData,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: Consumer<ShiftProvider>(
        builder: (context, prov, _) {
          if (prov.loading && prov.shiftInfo == null && prov.calendarDays.isEmpty) {
            return ShimmerLoading(
              child: SingleChildScrollView(
                physics: const NeverScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                child: Column(
                  children: [
                    const SkeletonShiftCard(),
                    const SizedBox(height: 16),
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: const Color(0xFFF1F5F9)),
                      ),
                      child: Column(
                        children: [
                          const Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              SkeletonBox(width: 140, height: 18, borderRadius: 4),
                              SkeletonBox(width: 60, height: 28, borderRadius: 8),
                            ],
                          ),
                          const SizedBox(height: 16),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceAround,
                            children: List.generate(
                              7,
                              (index) => const SkeletonBox(width: 24, height: 14, borderRadius: 4),
                            ),
                          ),
                          const SizedBox(height: 12),
                          GridView.builder(
                            shrinkWrap: true,
                            physics: const NeverScrollableScrollPhysics(),
                            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: 7,
                              childAspectRatio: 1,
                              crossAxisSpacing: 4,
                              mainAxisSpacing: 4,
                            ),
                            itemCount: 35,
                            itemBuilder: (context, index) => const SkeletonBox(borderRadius: 8),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    const SkeletonShiftCard(),
                  ],
                ),
              ),
            );
          }
          if (prov.error != null) {
            return _buildError(prov);
          }

          // Tidak ada shift khusus DAN kalender belum memuat apa pun
          // → benar-benar belum ada jadwal untuk ditampilkan.
          if (prov.shiftInfo == null && prov.calendarDays.isEmpty) {
            return _buildEmpty();
          }

          // Tentukan info shift yang relevan berdasarkan tanggal dipilih
          final displayInfo = _resolveDisplayInfo(prov);
          final shiftColor = _parseColor(displayInfo.color);

          // Refresh: muat ulang info shift + kalender bulan yang sedang dilihat
          Future<void> refresh() async {
            await prov.fetchMySchedule();
            // Force reload bulan yang sedang ditampilkan
            prov.clearCalendarCache();
            await prov.fetchScheduleCalendar(_displayedMonth.year, _displayedMonth.month);
          }

          return SafeArea(
            child: RefreshIndicator(
              onRefresh: refresh,
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
                child: Column(
                  children: [
                    _buildShiftInfoCard(displayInfo, shiftColor),
                    const SizedBox(height: 16),
                    _buildCalendarCard(prov, shiftColor),
                    const SizedBox(height: 16),
                    _buildDayDetail(prov, shiftColor),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }  // ─── Shift info card ────────────────────────────────────────
  // Menampilkan nama jadwal shift atau jam kantor default
  Widget _buildShiftInfoCard(_ShiftDisplayInfo info, Color c) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [c, c.withValues(alpha: 0.75)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: c.withValues(alpha: 0.25),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.2),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(
              info.source == 'shift' ? Icons.schedule_rounded : Icons.apartment_rounded,
              color: Colors.white,
              size: 24,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  info.name,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                if (info.endDate != null && info.endDate!.isNotEmpty) ...[
                  const SizedBox(height: 3),
                  Text(
                    info.startDate != null && info.startDate!.isNotEmpty
                        ? '${_fmtDate(info.startDate!)} - ${_fmtDate(info.endDate!)}'
                        : 's/d ${_fmtDate(info.endDate!)}',
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.85),
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
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
            daysInMonth, leadingBlanks, year, month, now, prov,
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
  ) {
    final totalCells = leadingBlanks + daysInMonth;
    final rows = (totalCells / 7).ceil();

    return List.generate(rows, (row) {
      return Padding(
        padding: const EdgeInsets.only(bottom: 2),
        child: Row(
          children: List.generate(7, (col) {
            final cellIndex = row * 7 + col;
            if (cellIndex < leadingBlanks || cellIndex >= totalCells) {
              return const Expanded(child: SizedBox(height: 48));
            }

            final day = cellIndex - leadingBlanks + 1;
            final date = DateTime(year, month, day);
            final apiDow = _toApiDow(date);
            // Gunakan jadwal PER-TANGGAL (akurat untuk perubahan shift masa depan).
            // Fallback ke template shift (perilaku lama) jika kalender belum siap.
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
            final isToday = date.year == now.year &&
                date.month == now.month &&
                date.day == now.day;
            final isSelected = _selectedDate != null &&
                date.year == _selectedDate!.year &&
                date.month == _selectedDate!.month &&
                date.day == _selectedDate!.day;

            // Deteksi apakah hari ini adalah tanggal mulai shift malam (isCrossDay)
            final isCrossDayToday = schedule?.isCrossDay ?? false;

            // Deteksi apakah hari ini adalah tanggal berakhir shift malam dari kemarin
            final prevDate = date.subtract(const Duration(days: 1));
            final prevCalDay = prov.getScheduleForDate(prevDate);
            final isCrossDayFromYesterday = prevCalDay != null &&
                prevCalDay.isCrossDay &&
                !prevCalDay.isOff;

            final defaultShiftColor = prov.shiftInfo?.color != null
                ? _parseColor(prov.shiftInfo!.color)
                : const Color(0xFF9CA3AF);

            // Warna shift per tanggal (hanya berubah jika tanggal tersebut memiliki shift dengan warna khusus)
            final cellColor = calDay?.color != null
                ? _parseColor(calDay!.color!)
                : defaultShiftColor;

            // Hari dengan shift → teks jam memakai warna shift (pilihan warna di web).
            // Hari jam kantor default / tanpa shift → teks jam abu-abu agar bisa dibedakan.
            final bool isShiftDay = (calDay?.source == 'shift') ||
                (calDay == null && prov.source == 'shift');

            // Hari libur nasional / perusahaan
            final holiday = calDay?.holiday;
            final isHoliday = holiday != null;
            // Cuti bersama yang sudah diikuti (accepted) → shiftName 'Cuti Bersama'
            final isCollectiveLeave = calDay?.shiftName == 'Cuti Bersama';
            // CUTI MANDIRI: cuti pribadi yang di-approve HRD (flag personal_leave dari API).
            final bool isPersonalLeave =
                (calDay?.personalLeave ?? false) || calDay?.shiftName == 'Cuti Mandiri';
            // Hari kerja dari rumah (WFH) — hanya jika bukan libur/OFF/cuti bersama/mandiri
            final bool isWfhDay = (calDay?.isWfh ?? false) &&
                !isOff &&
                !isHoliday &&
                !isCollectiveLeave &&
                !isPersonalLeave;
            // Warna aksen sesuai jenis libur: nasional merah, cuti bersama & cuti mandiri kuning, perusahaan/cabang biru
            final holidayAccent = isCollectiveLeave || isPersonalLeave
                ? const Color(0xFFD97706) // amber 600
                : holiday != null
                    ? (holiday.isNational
                        ? const Color(0xFFEF4444) // merah
                        : const Color(0xFF3B82F6)) // biru
                    : null;

            return Expanded(
              child: GestureDetector(
                onTap: () => setState(() => _selectedDate = date),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 150),
                  height: 48,
                  margin: const EdgeInsets.symmetric(horizontal: 1.5, vertical: 1.5),
                  decoration: BoxDecoration(
                    color: isSelected
                        ? cellColor.withValues(alpha: 0.14)
                        : isToday
                            ? const Color(0xFFEBF5FF)
                            : isCollectiveLeave || isPersonalLeave
                                ? const Color(0xFFFFFBEB)
                                : isHoliday
                                    ? const Color(0xFFFEF2F2)
                                    : isOff
                                        ? const Color(0xFFFFF1F2).withValues(alpha: 0.6)
                                        : Colors.white,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(
                      color: isSelected
                          ? cellColor
                          : isToday
                              ? const Color(0xFF1E88E5)
                              : isCollectiveLeave || isPersonalLeave
                                  ? const Color(0xFFFDE68A)
                                  : isHoliday
                                      ? const Color(0xFFFECACA)
                                      : isOff
                                          ? const Color(0xFFFFE4E6)
                                          : const Color(0xFFF1F5F9),
                      width: isSelected ? 1.8 : (isToday ? 1.4 : 0.8),
                    ),
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      // Baris tanggal & indikator kecil WFH
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(
                            width: 22,
                            height: 20,
                            alignment: Alignment.center,
                            decoration: BoxDecoration(
                              color: isToday
                                  ? const Color(0xFF1E88E5)
                                  : Colors.transparent,
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Text(
                              '$day',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: (isToday || isSelected)
                                    ? FontWeight.bold
                                    : FontWeight.w600,
                                color: isToday
                                    ? Colors.white
                                    : holidayAccent ??
                                        (isOff
                                            ? Colors.red.shade400
                                            : const Color(0xFF1F2937)),
                              ),
                            ),
                          ),
                          if (isWfhDay)
                            Padding(
                              padding: const EdgeInsets.only(left: 1),
                              child: Icon(
                                Icons.home_rounded,
                                size: 9,
                                color: Colors.teal.shade600,
                              ),
                            ),
                        ],
                      ),
                      const SizedBox(height: 1),
                      // Indikator jam / OFF / nama libur / CUTI
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          if (isCrossDayToday || isCrossDayFromYesterday)
                            Padding(
                              padding: const EdgeInsets.only(right: 1.5),
                              child: Icon(
                                Icons.nights_stay,
                                size: 8,
                                color: Colors.purple.shade600,
                              ),
                            ),
                          if (isCollectiveLeave || isPersonalLeave)
                            const Text(
                              'CUTI',
                              style: TextStyle(
                                fontSize: 7.5,
                                fontWeight: FontWeight.w800,
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
                                  fontSize: 7.5,
                                  fontWeight: FontWeight.w700,
                                  color: holidayAccent ?? Colors.red.shade500,
                                ),
                              ),
                            )
                          else if (isOff)
                            Text(
                              'OFF',
                              style: TextStyle(
                                fontSize: 7.5,
                                fontWeight: FontWeight.w800,
                                color: Colors.red.shade400,
                              ),
                            )
                          else if (schedule != null &&
                              schedule.workStartTime != null)
                            Text(
                              _shortTime(schedule.workStartTime!),
                              style: TextStyle(
                                fontSize: 7.5,
                                fontWeight: FontWeight.w600,
                                color: isShiftDay
                                    ? cellColor
                                    : Colors.grey.shade600,
                              ),
                            )
                          else
                            Text(
                              '-',
                              style: TextStyle(
                                fontSize: 7.5,
                                color: Colors.grey.shade300,
                              ),
                            ),
                        ],
                      ),
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
    // Jadwal per-tanggal (akurat untuk shift yang berubah di tengah bulan).
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
    final dayIdx = date.weekday - 1; // 0=Senin
    final dayName = _fullDayNames[dayIdx];
    // Warna shift tanggal terpilih
    final detailColor = calDay?.color != null
        ? _parseColor(calDay!.color!)
        : shiftColor;

    final holiday = calDay?.holiday;
    // Cuti Bersama: ditandai dengan holiday.isCollective == true ATAU shiftName 'Cuti Bersama'
    final bool isCollectiveLeave = (holiday != null && holiday.isCollective) ||
        calDay?.shiftName == 'Cuti Bersama';
    // Cuti Mandiri: cuti pribadi yang disetujui HRD
    final bool isPersonalLeave =
        (calDay?.personalLeave ?? false) || calDay?.shiftName == 'Cuti Mandiri';

    // Deteksi shift malam lintas hari dari kemarin
    final prevDate = date.subtract(const Duration(days: 1));
    final prevCalDay = prov.getScheduleForDate(prevDate);
    final isCrossDayFromYesterday = prevCalDay != null &&
        prevCalDay.isCrossDay &&
        !prevCalDay.isOff;

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
              Icon(Icons.calendar_today, size: 18, color: detailColor),
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
          // ── KONDISI 1: Cuti (Cuti Bersama / Cuti Mandiri)
          else if (isCollectiveLeave || isPersonalLeave) ...[
            _buildLeaveCard(
              isCollective: isCollectiveLeave,
              holiday: holiday,
              shiftName: calDay?.shiftName,
            ),
          ]
          // ── KONDISI 2: Hari Libur / Tanggal Merah Biasa (Libur Nasional / Perusahaan / Cabang)
          else if (holiday != null && !holiday.isCollective) ...[
            _buildHolidayCard(holiday),
          ]
          // ── KONDISI 3: Hari Libur Mingguan / OFF Jadwal Shift
          else if (schedule.isOff) ...[
            _buildOffDayCard(calDay),
          ]
          // ── KONDISI 4: Hari Kerja Aktif (Normal / Shift)
          else ...[
            // Banner "Kerja Dari Rumah" / "Kerja Lapangan" (hanya hari kerja aktif)
            if (calDay != null && calDay.isWfh) ...[
              _statusBanner(
                icon: calDay.isField ? Icons.directions_walk : Icons.home_rounded,
                label: calDay.isField
                    ? 'Kerja Lapangan (Field / Kunjungan Luar)'
                    : 'Kerja Dari Rumah (WFH)',
                color: calDay.isField ? Colors.deepPurple : Colors.teal,
              ),
              const SizedBox(height: 12),
            ],

            // Banner info shift malam / lintas hari
            if (schedule.isCrossDay) ...[
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFFAF5FF),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xFFE9D5FF)),
                ),
                child: Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(6),
                      decoration: BoxDecoration(
                        color: const Color(0xFF9333EA).withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: const Icon(Icons.nights_stay_rounded,
                          color: Color(0xFF9333EA), size: 18),
                    ),
                    const SizedBox(width: 10),
                    const Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Shift Malam (Lintas Hari)',
                            style: TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.bold,
                              color: Color(0xFF6B21A8),
                            ),
                          ),
                          Text(
                            'Jam pulang keesokan harinya',
                            style: TextStyle(
                              fontSize: 11,
                              color: Color(0xFF9333EA),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
            ],

            // Info shift malam kemarin yang berakhir hari ini
            if (prevCalDay != null && isCrossDayFromYesterday) ...[
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFEEF2FF),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xFFC7D2FE)),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.nights_stay_outlined,
                        color: Color(0xFF4F46E5), size: 18),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'Shift malam kemarin berakhir pukul ${_shortTime(prevCalDay.workEndTime ?? '')} hari ini',
                        style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: Color(0xFF3730A3),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
            ],

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
                    Text(
                      'Jam Kustom',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: Colors.amber.shade800,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 10),
            ],

            // Jam Masuk
            _timeRow(
              Icons.login,
              'Jam Masuk',
              _fmtTime(schedule.workStartTime),
              Colors.green,
            ),
            const SizedBox(height: 10),

            // Jam Pulang (dengan badge +1 hari jika cross-day)
            _timeRow(
              Icons.logout,
              'Jam Pulang',
              _fmtTime(schedule.workEndTime),
              Colors.orange,
              isCrossDay: schedule.isCrossDay,
            ),
            const SizedBox(height: 12),

            // Status Hari Kerja
            _statusBanner(
              icon: Icons.check_circle,
              label: 'Hari Kerja Normal',
              color: Colors.green,
            ),
          ],
        ],
      ),
    );
  }

  /// Kartu Tanggal Merah / Libur Nasional / Libur Perusahaan
  Widget _buildHolidayCard(HolidayInfo holiday) {
    final bool isNational = holiday.isNational;
    final Color baseColor =
        isNational ? const Color(0xFFDC2626) : const Color(0xFF2563EB);
    final Color bgColor =
        isNational ? const Color(0xFFFEF2F2) : const Color(0xFFEFF6FF);
    final Color borderColor =
        isNational ? const Color(0xFFFECACA) : const Color(0xFFBFDBFE);
    final Color textColor =
        isNational ? const Color(0xFF991B1B) : const Color(0xFF1E40AF);

    final String typeTitle = isNational
        ? 'Libur Nasional: ${holiday.name}'
        : (holiday.scope == 'cabang'
            ? 'Libur Cabang: ${holiday.name}'
            : 'Libur Perusahaan: ${holiday.name}');

    final String typeSubtitle = isNational
        ? 'Hari Libur Nasional / Tanggal Merah (Tidak Bekerja)'
        : (holiday.scope == 'cabang'
            ? 'Hari Libur Khusus Kantor Cabang (Tidak Bekerja)'
            : 'Hari Libur Khusus Perusahaan (Tidak Bekerja)');

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: borderColor),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: baseColor.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(
              isNational ? Icons.flag_rounded : Icons.apartment_rounded,
              color: baseColor,
              size: 22,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  typeTitle,
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.bold,
                    color: textColor,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  typeSubtitle,
                  style: TextStyle(
                    fontSize: 12,
                    color: baseColor,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Kartu Cuti (Cuti Mandiri / Cuti Bersama)
  Widget _buildLeaveCard({
    required bool isCollective,
    HolidayInfo? holiday,
    String? shiftName,
  }) {
    const Color baseColor = Color(0xFFD97706);
    const Color bgColor = Color(0xFFFFFBEB);
    const Color borderColor = Color(0xFFFDE68A);
    const Color textColor = Color(0xFF92400E);

    final String title = isCollective
        ? (holiday != null && holiday.name.isNotEmpty
            ? 'Cuti Bersama: ${holiday.name}'
            : 'Cuti Bersama')
        : 'Cuti Mandiri';

    final String subtitle = isCollective
        ? (holiday?.scope == 'cabang'
            ? 'Hari Libur Cuti Bersama Khusus Cabang (Diikuti)'
            : 'Hari Libur Cuti Bersama yang Diikuti')
        : 'Pengajuan Cuti Pribadi (Disetujui HRD)';

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: borderColor),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: baseColor.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(
              isCollective
                  ? Icons.celebration_rounded
                  : Icons.person_outline_rounded,
              color: baseColor,
              size: 22,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.bold,
                    color: textColor,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  subtitle,
                  style: const TextStyle(
                    fontSize: 12,
                    color: baseColor,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Kartu Hari Libur Mingguan / OFF
  Widget _buildOffDayCard(ShiftCalendarDay? calDay) {
    const Color baseColor = Color(0xFFE11D48);
    const Color bgColor = Color(0xFFFFF1F2);
    const Color borderColor = Color(0xFFFFE4E6);
    const Color textColor = Color(0xFF9F1239);

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: borderColor),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: baseColor.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(
              Icons.beach_access_rounded,
              color: baseColor,
              size: 22,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Hari Libur (OFF)',
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.bold,
                    color: textColor,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  calDay?.source == 'shift'
                      ? 'Jadwal Libur Shift'
                      : 'Libur Akhir Pekan Kantor',
                  style: const TextStyle(
                    fontSize: 12,
                    color: baseColor,
                  ),
                ),
              ],
            ),
          ),
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
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                color: color.shade700,
                fontWeight: FontWeight.w600,
                fontSize: 13,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _timeRow(
    IconData icon,
    String label,
    String value,
    Color color, {
    bool isCrossDay = false,
  }) {
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
            Row(
              children: [
                Text(value,
                    style: const TextStyle(
                        fontSize: 16, fontWeight: FontWeight.bold)),
                if (isCrossDay) ...[
                  const SizedBox(width: 6),
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: Colors.indigo.shade50,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: Colors.indigo.shade200),
                    ),
                    child: Text('+1 hari (Besok)',
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

  /// Singkat nama libur agar muat di cell kalender kecil (maks ~8 karakter).
  /// Contoh: "Maulid Nabi Muhammad SAW" → "Maulid N."
  String _abbreviate(String name) {
    if (name.length <= 9) return name;
    final words = name.split(' ');
    if (words.length == 1) return '${name.substring(0, 8)}.';
    // Ambil kata pertama + inisial kata berikutnya jika masih panjang
    final first = words[0];
    if (first.length >= 9) return '${first.substring(0, 8)}.';
    return first;
  }
}

/// Model ringkas info shift untuk kartu header.
/// Digunakan agar kartu info berubah sesuai tanggal yang dipilih.
class _ShiftDisplayInfo {
  final String name;
  final String color;
  final String source;
  final String? startDate;
  final String? endDate;

  const _ShiftDisplayInfo({
    required this.name,
    required this.color,
    required this.source,
    this.startDate,
    this.endDate,
  });
}
