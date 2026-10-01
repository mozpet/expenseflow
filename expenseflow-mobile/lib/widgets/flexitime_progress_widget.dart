import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../presensi_provider.dart';

/// Widget pelacak durasi jam kerja berjalan (live tracker & progress)
/// khusus untuk karyawan dengan sistem Jam Kerja Fleksibel (Flexitime).
/// Otomatis tidak menampilkan apa pun (SizedBox.shrink) jika flexitime tidak aktif.
class FlexitimeProgressWidget extends StatefulWidget {
  final bool compact;

  const FlexitimeProgressWidget({
    super.key,
    this.compact = false,
  });

  @override
  State<FlexitimeProgressWidget> createState() =>
      _FlexitimeProgressWidgetState();
}

class _FlexitimeProgressWidgetState extends State<FlexitimeProgressWidget> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    // Update live timer setiap 15 detik agar menit kerja terus terbarui tanpa membebani performa
    _timer = Timer.periodic(const Duration(seconds: 15), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  String _formatHAndM(int totalMinutes) {
    if (totalMinutes <= 0) return '0 Menit';
    final h = totalMinutes ~/ 60;
    final m = totalMinutes % 60;
    if (h == 0) return '$m Menit';
    if (m == 0) return '$h Jam';
    return '$h Jam $m Menit';
  }

  String _formatShort(int totalMinutes) {
    if (totalMinutes <= 0) return '0m';
    final h = totalMinutes ~/ 60;
    final m = totalMinutes % 60;
    if (h == 0) return '${m}m';
    if (m == 0) return '${h}j';
    return '${h}j ${m}m';
  }

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<PresensiProvider>(context);

    // Keamanan 100%: Sembunyikan sepenuhnya jika user bukan karyawan flexitime
    if (!prov.flexitimeEnabled) {
      return const SizedBox.shrink();
    }

    final isCheckedIn = !prov.canCheckIn && prov.canCheckOut;
    final hasCheckedOut = prov.todayPulang != null;
    final targetMins = prov.flexTargetMinutes;
    final targetHours = (targetMins / 60).toStringAsFixed(0);
    final targetCheckout = prov.flexTargetCheckoutTime;
    final breakMins = prov.flexBreakMinutes;
    final coreHours = prov.flexCoreHours ?? '10:00 - 15:00';

    if (widget.compact) {
      return _buildCompactView(
        context: context,
        prov: prov,
        isCheckedIn: isCheckedIn,
        hasCheckedOut: hasCheckedOut,
        targetMins: targetMins,
        targetHours: targetHours,
        targetCheckout: targetCheckout,
      );
    }

    return _buildFullView(
      context: context,
      prov: prov,
      isCheckedIn: isCheckedIn,
      hasCheckedOut: hasCheckedOut,
      targetMins: targetMins,
      targetHours: targetHours,
      targetCheckout: targetCheckout,
      breakMins: breakMins,
      coreHours: coreHours,
    );
  }

  // ─── Tampilan Ringkas (untuk Kartu Presensi Home Screen) ────────────────────
  Widget _buildCompactView({
    required BuildContext context,
    required PresensiProvider prov,
    required bool isCheckedIn,
    required bool hasCheckedOut,
    required int targetMins,
    required String targetHours,
    required String? targetCheckout,
  }) {
    if (!isCheckedIn && !hasCheckedOut) {
      return const SizedBox.shrink();
    }

    if (hasCheckedOut) {
      final workMins = prov.todayWorkMinutes;
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: const Color(0xFFF0FDF4),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: const Color(0xFFBBF7D0)),
        ),
        child: Row(
          children: [
            const Icon(Icons.check_circle_outline_rounded,
                size: 16, color: Color(0xFF16A34A)),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                workMins != null
                    ? 'Total Jam Kerja Efektif: ${_formatHAndM(workMins)}'
                    : 'Presensi Selesai • Target $targetHours Jam',
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF166534),
                ),
              ),
            ),
          ],
        ),
      );
    }

    final netMinutes = prov.flexElapsedNetMinutes;
    final progress = prov.flexProgressRatio;
    final isAchieved = prov.isFlexTargetAchieved;
    final remainingMins = prov.flexRemainingMinutes;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isAchieved ? const Color(0xFFF0FDF4) : const Color(0xFFF0F9FF),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: isAchieved ? const Color(0xFF86EFAC) : const Color(0xFFBAE6FD),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  Container(
                    width: 7,
                    height: 7,
                    decoration: BoxDecoration(
                      color: isAchieved
                          ? const Color(0xFF16A34A)
                          : const Color(0xFF0284C7),
                      shape: BoxShape.circle,
                    ),
                  ),
                  const SizedBox(width: 6),
                  Text(
                    'Durasi Kerja Berjalan',
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: isAchieved
                          ? const Color(0xFF15803D)
                          : const Color(0xFF0369A1),
                    ),
                  ),
                ],
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(
                  color: isAchieved
                      ? const Color(0xFFDCFCE7)
                      : const Color(0xFFE0F2FE),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  isAchieved
                      ? 'Target Tercapai 🎉'
                      : 'Sisa ${_formatShort(remainingMins)}',
                  style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    color: isAchieved
                        ? const Color(0xFF166534)
                        : const Color(0xFF0284C7),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 6,
              backgroundColor: isAchieved
                  ? const Color(0xFFDCFCE7)
                  : const Color(0xFFE0F2FE),
              valueColor: AlwaysStoppedAnimation<Color>(
                isAchieved ? const Color(0xFF16A34A) : const Color(0xFF0284C7),
              ),
            ),
          ),
          const SizedBox(height: 6),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                '${_formatHAndM(netMinutes)} / $targetHours Jam',
                style: const TextStyle(
                  fontSize: 10.5,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF334155),
                ),
              ),
              if (targetCheckout != null)
                Text(
                  'Target Pulang: $targetCheckout WIB',
                  style: TextStyle(
                    fontSize: 10.5,
                    color: Colors.grey.shade600,
                    fontWeight: FontWeight.w500,
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }

  // ─── Tampilan Penuh / Detail (untuk Halaman Peta Presensi) ─────────────────
  Widget _buildFullView({
    required BuildContext context,
    required PresensiProvider prov,
    required bool isCheckedIn,
    required bool hasCheckedOut,
    required int targetMins,
    required String targetHours,
    required String? targetCheckout,
    required int breakMins,
    required String coreHours,
  }) {
    final arrival = prov.flexArrivalWindow ?? '07:00 - 10:00';

    return Container(
      margin: const EdgeInsets.only(top: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFCBD5E1)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.03),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header Baris Flexitime
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(6),
                decoration: BoxDecoration(
                  color: const Color(0xFF0284C7).withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Icon(Icons.auto_awesome,
                    size: 15, color: Color(0xFF0284C7)),
              ),
              const SizedBox(width: 8),
              const Expanded(
                child: Text(
                  'Jam Kerja Fleksibel (Flexitime)',
                  style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.bold,
                    color: Color(0xFF0F172A),
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: const Color(0xFFE0F2FE),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: const Color(0xFFBAE6FD)),
                ),
                child: Text(
                  '$targetHours Jam Kerja',
                  style: const TextStyle(
                    fontSize: 10.5,
                    fontWeight: FontWeight.bold,
                    color: Color(0xFF0369A1),
                  ),
                ),
              ),
            ],
          ),

          // Jika Sedang Aktif Bekerja (Checked-in & Belum Pulang)
          if (isCheckedIn) ...[
            const SizedBox(height: 12),
            _buildLiveTrackerCard(
              prov: prov,
              targetMins: targetMins,
              targetHours: targetHours,
              targetCheckout: targetCheckout,
              breakMins: breakMins,
            ),
          ] else if (hasCheckedOut) ...[
            const SizedBox(height: 10),
            _buildCompletedCard(prov: prov, targetHours: targetHours),
          ] else ...[
            const SizedBox(height: 10),
            _buildInitialGuidanceCard(
              targetHours: targetHours,
              breakMins: breakMins,
            ),
          ],

          const SizedBox(height: 10),
          // Baris Parameter Jam Kedatangan & Jam Inti
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Row(
                    children: [
                      Icon(Icons.login_rounded,
                          size: 13, color: Colors.blue.shade600),
                      const SizedBox(width: 4),
                      Text(
                        'Datang: $arrival',
                        style: const TextStyle(
                          fontSize: 10,
                          color: Color(0xFF334155),
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ],
                  ),
                ),
                Container(height: 12, width: 1, color: Colors.grey.shade300),
                const SizedBox(width: 8),
                Expanded(
                  child: Row(
                    children: [
                      Icon(Icons.lock_clock_rounded,
                          size: 13, color: Colors.orange.shade700),
                      const SizedBox(width: 4),
                      Text(
                        'Jam Inti: $coreHours',
                        style: const TextStyle(
                          fontSize: 10,
                          color: Color(0xFF334155),
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ─── Kartu Live Tracker Detail ─────────────────────────────────────────────
  Widget _buildLiveTrackerCard({
    required PresensiProvider prov,
    required int targetMins,
    required String targetHours,
    required String? targetCheckout,
    required int breakMins,
  }) {
    final netMinutes = prov.flexElapsedNetMinutes;
    final grossMinutes = prov.flexElapsedGrossMinutes;
    final progress = prov.flexProgressRatio;
    final isAchieved = prov.isFlexTargetAchieved;
    final remainingMins = prov.flexRemainingMinutes;
    final percentInt = (progress * 100).toInt();

    final themeColor =
        isAchieved ? const Color(0xFF16A34A) : const Color(0xFF0284C7);
    final bgColor =
        isAchieved ? const Color(0xFFF0FDF4) : const Color(0xFFF0F9FF);
    final borderColor =
        isAchieved ? const Color(0xFF86EFAC) : const Color(0xFFBAE6FD);

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  Icon(Icons.timer_outlined, size: 16, color: themeColor),
                  const SizedBox(width: 6),
                  Text(
                    'Durasi Kerja Berjalan',
                    style: TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.bold,
                      color: themeColor,
                    ),
                  ),
                ],
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: isAchieved
                      ? const Color(0xFFDCFCE7)
                      : const Color(0xFFE0F2FE),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  isAchieved ? '100% Target' : '$percentInt%',
                  style: TextStyle(
                    fontSize: 10.5,
                    fontWeight: FontWeight.bold,
                    color: themeColor,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),

          // Angka Besar Durasi
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                _formatHAndM(netMinutes),
                style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w800,
                  color: themeColor,
                  letterSpacing: -0.5,
                ),
              ),
              const SizedBox(width: 6),
              Text(
                '/ $targetHours Jam Target',
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF64748B),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),

          // Progress Bar
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 8,
              backgroundColor: isAchieved
                  ? const Color(0xFFDCFCE7)
                  : const Color(0xFFE0F2FE),
              valueColor: AlwaysStoppedAnimation<Color>(themeColor),
            ),
          ),
          const SizedBox(height: 10),

          // Target Pulang & Status Sisa Waktu
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: borderColor.withValues(alpha: 0.5)),
            ),
            child: Column(
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.flag_rounded,
                            size: 14, color: Color(0xFF16A34A)),
                        const SizedBox(width: 4),
                        const Text(
                          'Target Pulang:',
                          style: TextStyle(
                              fontSize: 11, color: Color(0xFF334155)),
                        ),
                        const SizedBox(width: 4),
                        Text(
                          targetCheckout != null
                              ? '$targetCheckout WIB'
                              : '--:--',
                          style: const TextStyle(
                            fontSize: 11.5,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF15803D),
                          ),
                        ),
                      ],
                    ),
                    Text(
                      isAchieved
                          ? 'Siap Check-out'
                          : 'Sisa ${_formatShort(remainingMins)} lagi',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: themeColor,
                      ),
                    ),
                  ],
                ),
                if (grossMinutes >= 300 && breakMins > 0) ...[
                  const Divider(height: 10, thickness: 0.5),
                  Row(
                    children: [
                      Icon(Icons.restaurant_rounded,
                          size: 11, color: Colors.amber.shade800),
                      const SizedBox(width: 4),
                      Expanded(
                        child: Text(
                          'Otomatis terpotong $breakMins menit istirahat siang (UU 13/2003)',
                          style: const TextStyle(
                            fontSize: 9.5,
                            color: Color(0xFF475569),
                            fontStyle: FontStyle.italic,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ─── Kartu Saat Sudah Selesai Check-out ────────────────────────────────────
  Widget _buildCompletedCard({
    required PresensiProvider prov,
    required String targetHours,
  }) {
    final workMins = prov.todayWorkMinutes;
    final isEarly = prov.todayIsEarlyLeave;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isEarly ? const Color(0xFFFAF5FF) : const Color(0xFFF0FDF4),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: isEarly ? const Color(0xFFE9D5FF) : const Color(0xFFBBF7D0),
        ),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(6),
            decoration: BoxDecoration(
              color: isEarly ? const Color(0xFFF3E8FF) : const Color(0xFFDCFCE7),
              shape: BoxShape.circle,
            ),
            child: Icon(
              isEarly ? Icons.exit_to_app_rounded : Icons.check_rounded,
              size: 18,
              color: isEarly ? const Color(0xFF7E22CE) : const Color(0xFF16A34A),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  isEarly
                      ? 'Presensi Selesai (Pulang Cepat)'
                      : 'Presensi Selesai Hari Ini',
                  style: TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.bold,
                    color: isEarly
                        ? const Color(0xFF7E22CE)
                        : const Color(0xFF166534),
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  workMins != null
                      ? 'Total jam kerja efektif: ${_formatHAndM(workMins)}'
                      : 'Total jam kerja: ${prov.todayTotalJamKerja}',
                  style: const TextStyle(
                    fontSize: 10.5,
                    color: Color(0xFF475569),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ─── Kartu Panduan Saat Belum Check-in ──────────────────────────────────────
  Widget _buildInitialGuidanceCard({
    required String targetHours,
    required int breakMins,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Row(
        children: [
          const Icon(Icons.info_outline_rounded,
              size: 15, color: Color(0xFF0284C7)),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'Jam pulang dinamis dihitung otomatis: Jam check-in + $targetHours jam kerja + $breakMins mnt istirahat.',
              style: const TextStyle(
                fontSize: 10.5,
                color: Color(0xFF334155),
                height: 1.3,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
