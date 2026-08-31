import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../presensi_provider.dart';
import '../widgets/skeleton.dart';
import 'presensi_map_screen.dart';

class PresensiHistoryScreen extends StatefulWidget {
  const PresensiHistoryScreen({super.key});

  @override
  State<PresensiHistoryScreen> createState() => _PresensiHistoryScreenState();
}

class _PresensiHistoryScreenState extends State<PresensiHistoryScreen> {
  @override
  void initState() {
    super.initState();
    // Riwayat presensi selalu dimuat, terlepas dari status WFH.
    // Backend /attendance/my kini tidak membutuhkan attendance_access.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Provider.of<PresensiProvider>(context, listen: false).fetchMyAttendance();
    });
  }

  void _goToPresensiMap() {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const PresensiMapScreen()),
    ).then((_) {
      if (!mounted) return;
      Provider.of<PresensiProvider>(context, listen: false).fetchMyAttendance();
    });
  }

  @override
  Widget build(BuildContext context) {
    final presensiProv = Provider.of<PresensiProvider>(context);
    final todayStr = presensiProv.todayDateFormatted;

    return Scaffold(
      // Tombol "Catat Presensi" muncul dalam dua kondisi:
      // 1. wfhEnabled = true  → bisa check-in baru (WFH aktif)
      // 2. canCheckOut = true → sudah check-in, belum checkout (harus bisa checkout
      //    meski HRD mematikan WFH di tengah shift)
      floatingActionButton:
          (presensiProv.wfhEnabled || presensiProv.canCheckOut)
          ? FloatingActionButton.extended(
              heroTag: 'presensi_history_fab',
              onPressed: _goToPresensiMap,
              backgroundColor: const Color(0xFF0088FF),
              foregroundColor: Colors.white,
              icon: const Icon(Icons.add),
              label: Text(
                presensiProv.canCheckOut ? 'Catat Pulang' : 'Catat Presensi',
              ),
            )
          : null,
      appBar: AppBar(
        title: const Text('Riwayat Presensi'),
        automaticallyImplyLeading: false,
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Banner status WFH — informatif saja, bukan gerbang akses.
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
            child: Consumer<PresensiProvider>(
              builder: (context, prov, _) {
                if (prov.canCheckOut && !prov.wfhEnabled) {
                  // User sudah check-in tapi HRD mematikan WFH → tetap tampilkan
                  // opsi checkout agar user tidak bingung.
                  return Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 10,
                    ),
                    decoration: BoxDecoration(
                      color: const Color(0xFFE3F2FD),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFF90CAF9)),
                    ),
                    child: Row(
                      children: [
                        const Icon(
                          Icons.logout_outlined,
                          color: Colors.blue,
                          size: 18,
                        ),
                        const SizedBox(width: 8),
                        const Expanded(
                          child: Text(
                            'Anda sedang check-in — tekan tombol untuk mencatat pulang.',
                            style: TextStyle(
                              color: Colors.blue,
                              fontWeight: FontWeight.bold,
                              fontSize: 12,
                            ),
                          ),
                        ),
                        GestureDetector(
                          onTap: _goToPresensiMap,
                          child: Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 10,
                              vertical: 6,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.blue,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: const Text(
                              'Catat Pulang',
                              style: TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                                fontSize: 11,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                } else if (prov.wfhEnabled) {
                  // WFH aktif: tampilkan tombol presensi + info
                  return Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 10,
                    ),
                    decoration: BoxDecoration(
                      color: const Color(0xFFE8F5E9),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFC8E6C9)),
                    ),
                    child: Row(
                      children: [
                        const Icon(
                          Icons.home_work_outlined,
                          color: Colors.green,
                          size: 18,
                        ),
                        const SizedBox(width: 8),
                        const Expanded(
                          child: Text(
                            'Mode WFH aktif — tekan tombol + untuk presensi.',
                            style: TextStyle(
                              color: Colors.green,
                              fontWeight: FontWeight.bold,
                              fontSize: 12,
                            ),
                          ),
                        ),
                        // Shortcut tombol presensi di dalam banner
                        GestureDetector(
                          onTap: _goToPresensiMap,
                          child: Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 10,
                              vertical: 6,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.green,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: const Text(
                              'Presensi',
                              style: TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                                fontSize: 11,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                } else {
                  // WFH nonaktif: info bahwa presensi via hardware
                  return Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 10,
                    ),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF3E0),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFFFE0B2)),
                    ),
                    child: const Row(
                      children: [
                        Icon(
                          Icons.business_outlined,
                          color: Colors.orange,
                          size: 18,
                        ),
                        SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'Presensi kantor via perangkat absensi. '
                            'Riwayat Anda ditampilkan di bawah.',
                            style: TextStyle(
                              color: Colors.orange,
                              fontWeight: FontWeight.bold,
                              fontSize: 12,
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                }
              },
            ),
          ),
          const SizedBox(height: 12),
          // Blue Today Card
          Padding(
            padding: const EdgeInsets.all(16.0),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 16),
              decoration: BoxDecoration(
                color: const Color(0xFF0066CC), // Rich corporate blue
                borderRadius: BorderRadius.circular(
                  8,
                ), // matching screenshot style
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.1),
                    blurRadius: 8,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child: Column(
                children: [
                  // Top Date Label
                  Text(
                    presensiProv.todayMasuk != null ? todayStr : '-',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 16,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                  const SizedBox(height: 20),
                  // Check-in and Check-out Grid
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                    children: [
                      // Masuk Column
                      Column(
                        children: [
                          Text(
                            presensiProv.todayMasuk ?? '-',
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 24,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const SizedBox(height: 4),
                          const Text(
                            'Masuk',
                            style: TextStyle(
                              color: Colors.white70,
                              fontSize: 14,
                            ),
                          ),
                        ],
                      ),
                      // Divider line
                      Container(height: 40, width: 1, color: Colors.white30),
                      // Pulang Column
                      Column(
                        children: [
                          Text(
                            presensiProv.todayPulang ?? '-',
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 24,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const SizedBox(height: 4),
                          const Text(
                            'Pulang',
                            style: TextStyle(
                              color: Colors.white70,
                              fontSize: 14,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                  // Total jam kerja + lembur hari ini
                  if (presensiProv.todayTotalJamKerja != '-') ...[
                    const SizedBox(height: 16),
                    Wrap(
                      alignment: WrapAlignment.center,
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        // Chip jam kerja
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 12,
                            vertical: 6,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.15),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Icon(
                                Icons.access_time_outlined,
                                size: 14,
                                color: Colors.white70,
                              ),
                              const SizedBox(width: 6),
                              Text(
                                'Kerja: ${presensiProv.todayTotalJamKerja}',
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 13,
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                            ],
                          ),
                        ),
                        // Chip lembur — hanya tampil jika ada lembur
                        if (presensiProv.todayOvertimeMinutes > 0)
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 6,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.orange.withValues(alpha: 0.85),
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const Icon(
                                  Icons.timer_outlined,
                                  size: 14,
                                  color: Colors.white,
                                ),
                                const SizedBox(width: 6),
                                Text(
                                  'Lembur: ${_fmtMenit(presensiProv.todayOvertimeMinutes)}',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 13,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        // Chip telat
                        if (presensiProv.todayLateMinutes > 0)
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 6,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.red.withValues(alpha: 0.85),
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const Icon(
                                  Icons.warning_amber_outlined,
                                  size: 14,
                                  color: Colors.white,
                                ),
                                const SizedBox(width: 6),
                                Text(
                                  'Telat: ${presensiProv.todayLateMinutes}m',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 13,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                          ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ),

          // Title Section
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 16.0, vertical: 8.0),
            child: Text(
              'Riwayat Presensi',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w600,
                color: Colors.black87,
              ),
            ),
          ),

          // History ListView with Pull to Refresh
          Expanded(
            child: RefreshIndicator(
              onRefresh: presensiProv.fetchMyAttendance,
              child: presensiProv.loadingHistory && presensiProv.records.isEmpty
                  ? ShimmerLoading(
                      child: ListView.builder(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.symmetric(
                          horizontal: 16,
                          vertical: 8,
                        ),
                        itemCount: 5,
                        itemBuilder: (_, _) => const SkeletonAttendanceItem(),
                      ),
                    )
                  : presensiProv.records.isEmpty
                      ? ListView(
                          physics: const AlwaysScrollableScrollPhysics(),
                          children: [
                            SizedBox(
                              height: MediaQuery.of(context).size.height * 0.12,
                            ),
                            Center(
                              child: Padding(
                                padding:
                                    const EdgeInsets.symmetric(horizontal: 32),
                                child: Column(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Icon(
                                      Icons.assignment_ind_outlined,
                                      size: 48,
                                      color: Colors.grey.shade300,
                                    ),
                                    const SizedBox(height: 12),
                                    const Text(
                                      'Belum ada riwayat presensi',
                                      style: TextStyle(color: Colors.grey),
                                      textAlign: TextAlign.center,
                                    ),
                                    const SizedBox(height: 4),
                                    const Text(
                                      'Data presensi Anda akan muncul di sini\nsetelah tercatat di sistem.',
                                      style: TextStyle(
                                        color: Colors.grey,
                                        fontSize: 12,
                                      ),
                                      textAlign: TextAlign.center,
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ],
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 16.0,
                            vertical: 8.0,
                          ),
                          physics: const AlwaysScrollableScrollPhysics(),
                          itemCount: presensiProv.records.length,
                          itemBuilder: (context, index) {
                            final record = presensiProv.records[index];
                            return _buildHistoryCard(record);
                          },
                        ),
            ),
          ),
        ],
      ),
    );
  }

  void _showOvertimeBottomSheet(BuildContext context, PresensiRecord record) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => _OvertimeClaimBottomSheet(record: record),
    );
  }

  Widget _buildHistoryCard(PresensiRecord record) {
    final total = record.totalJamKerja;
    final lembur = record.totalLembur;
    final hasData = total != '-';
    final hasOvertime = record.overtimeMinutes > 0;

    return Card(
      color: Colors.white,
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(8),
        side: BorderSide(
          color: record.canClaimOvertime
              ? const Color(0x590088FF)
              : Colors.grey.shade200,
          width: record.canClaimOvertime ? 1.2 : 1.0,
        ),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: hasOvertime
            ? () => _showOvertimeBottomSheet(context, record)
            : null,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 16.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  // Tanggal + badge hari libur / auto-checkout
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          record.date,
                          style: const TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.w500,
                            color: Colors.black87,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Wrap(
                          spacing: 4,
                          runSpacing: 3,
                          children: [
                            if (record.isHoliday)
                              _badge(
                                'Hari Libur',
                                Colors.red.shade600,
                                Colors.red.shade50,
                              ),
                            if (record.isAutoCheckout)
                              _badge(
                                'Auto-Checkout',
                                Colors.purple.shade600,
                                Colors.purple.shade50,
                              ),
                            if (record.lateMinutes > 0)
                              _badge(
                                'Telat ${record.lateMinutes}m',
                                Colors.orange.shade700,
                                Colors.orange.shade50,
                              ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  // Masuk
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Text(
                        record.masukTime,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.bold,
                          color: Colors.black87,
                        ),
                      ),
                      const SizedBox(height: 2),
                      const Text(
                        'Masuk',
                        style: TextStyle(fontSize: 11, color: Colors.grey),
                      ),
                    ],
                  ),
                  const SizedBox(width: 24),
                  // Pulang
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Text(
                        record.pulangTime,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.bold,
                          color: Colors.black87,
                        ),
                      ),
                      const SizedBox(height: 2),
                      const Text(
                        'Pulang',
                        style: TextStyle(fontSize: 11, color: Colors.grey),
                      ),
                    ],
                  ),
                ],
              ),
              if (hasData) ...[
                const SizedBox(height: 10),
                Divider(height: 1, color: Colors.grey.shade100),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    if (record.checkInType != null &&
                        record.checkInType!.isNotEmpty) ...[
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            record.checkInType == 'wfh'
                                ? Icons.home_rounded
                                : Icons.business_rounded,
                            size: 14,
                            color: record.checkInType == 'wfh'
                                ? Colors.green.shade700
                                : Colors.blue.shade700,
                          ),
                          const SizedBox(width: 4),
                          Text(
                            record.checkInType == 'wfh' ? 'WFH' : 'Kantor',
                            style: TextStyle(
                              fontSize: 12,
                              color: record.checkInType == 'wfh'
                                  ? Colors.green.shade700
                                  : Colors.blue.shade700,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                        ],
                      ),
                      Container(
                        width: 1,
                        height: 12,
                        color: Colors.grey.shade300,
                      ),
                    ],
                    // Jam kerja
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          Icons.access_time_outlined,
                          size: 13,
                          color: Colors.blue.shade400,
                        ),
                        const SizedBox(width: 4),
                        Text(
                          'Kerja: $total',
                          style: TextStyle(
                            fontSize: 12,
                            color: Colors.blue.shade600,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ],
                    ),
                    // Lembur + status approval
                    if (lembur.isNotEmpty) ...[
                      Container(
                        width: 1,
                        height: 12,
                        color: Colors.grey.shade300,
                      ),
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.timer_outlined,
                            size: 13,
                            color: Colors.orange.shade600,
                          ),
                          const SizedBox(width: 4),
                          Text(
                            'Lembur: $lembur',
                            style: TextStyle(
                              fontSize: 12,
                              color: Colors.orange.shade700,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                      // Badge status approval lembur
                      _overtimeStatusBadge(record.overtimeStatus),
                    ],
                  ],
                ),
                if (record.canClaimOvertime) ...[
                  const SizedBox(height: 8),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF0F7FF),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: const Color(0xFFBAE6FD)),
                    ),
                    child: Row(
                      children: [
                        const Icon(
                          Icons.touch_app_rounded,
                          size: 14,
                          color: Color(0xFF0088FF),
                        ),
                        const SizedBox(width: 6),
                        const Expanded(
                          child: Text(
                            'Ketuk kartu untuk mengajukan atau membatalkan lembur',
                            style: TextStyle(
                              fontSize: 11,
                              color: Color(0xFF0088FF),
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        const Icon(
                          Icons.chevron_right_rounded,
                          size: 16,
                          color: Color(0xFF0088FF),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// Badge teks kecil berlatarbelakang warna.
  Widget _badge(String label, Color textColor, Color bgColor) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 10,
          color: textColor,
          fontWeight: FontWeight.bold,
        ),
      ),
    );
  }

  /// Badge status approval lembur: pending = kuning, approved = hijau, rejected = merah, null = belum diajukan.
  Widget _overtimeStatusBadge(String? status) {
    if (status == null || status == 'unsubmitted') {
      return _badge('Belum Diajukan', const Color(0xFF0088FF), const Color(0xFFE3F2FD));
    }
    switch (status) {
      case 'approved':
        return _badge('Disetujui', Colors.green.shade700, Colors.green.shade50);
      case 'rejected':
        return _badge('Ditolak', Colors.red.shade700, Colors.red.shade50);
      default: // pending
        return _badge(
          'Menunggu HRD',
          Colors.orange.shade700,
          Colors.orange.shade50,
        );
    }
  }
}

// ─── Bottom Sheet Pengajuan & Detail Lembur ───────────────────────────────────

class _OvertimeClaimBottomSheet extends StatefulWidget {
  final PresensiRecord record;

  const _OvertimeClaimBottomSheet({required this.record});

  @override
  State<_OvertimeClaimBottomSheet> createState() =>
      _OvertimeClaimBottomSheetState();
}

class _OvertimeClaimBottomSheetState extends State<_OvertimeClaimBottomSheet> {
  final _reasonController = TextEditingController();
  bool _isSubmitting = false;

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  Future<void> _submitClaim() async {
    final reason = _reasonController.text.trim();
    if (reason.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('Deskripsi / penjelasan lembur wajib diisi.'),
          backgroundColor: Colors.red.shade700,
          behavior: SnackBarBehavior.floating,
        ),
      );
      return;
    }

    setState(() => _isSubmitting = true);
    final prov = Provider.of<PresensiProvider>(context, listen: false);
    final success = await prov.claimOvertime(widget.record.id, reason);
    if (!mounted) return;
    setState(() => _isSubmitting = false);

    if (success) {
      Navigator.pop(context);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('Pengajuan lembur berhasil dikirim ke HRD.'),
          backgroundColor: Colors.green.shade700,
          behavior: SnackBarBehavior.floating,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('Gagal mengirim pengajuan lembur. Coba lagi.'),
          backgroundColor: Colors.red.shade700,
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  Future<void> _confirmDecline() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        title: const Text(
          'Batalkan Lembur?',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
        ),
        content: Text(
          'Apakah Anda yakin tidak ingin mengajukan lembur pada tanggal ${widget.record.date}? Durasi lembur (${widget.record.totalLembur}) akan dihapus.',
          style: const TextStyle(fontSize: 13, color: Colors.black87),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Kembali', style: TextStyle(color: Colors.grey)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.red.shade600,
              foregroundColor: Colors.white,
              elevation: 0,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(8),
              ),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Ya, Hapus Lembur'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    setState(() => _isSubmitting = true);
    final prov = Provider.of<PresensiProvider>(context, listen: false);
    final success = await prov.declineOvertime(widget.record.id);
    if (!mounted) return;
    setState(() => _isSubmitting = false);

    if (success) {
      Navigator.pop(context);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('Lembur untuk hari tersebut telah dibatalkan.'),
          backgroundColor: Colors.grey.shade900,
          behavior: SnackBarBehavior.floating,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('Gagal membatalkan lembur. Coba lagi.'),
          backgroundColor: Colors.red.shade700,
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final record = widget.record;
    final isUnsubmitted = record.canClaimOvertime;
    final isPending = record.overtimeStatus == 'pending';
    final isApproved = record.overtimeStatus == 'approved';
    final isRejected = record.overtimeStatus == 'rejected';

    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 12,
        bottom: MediaQuery.of(context).viewInsets.bottom + 24,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Drag handle
            Center(
              child: Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: Colors.grey.shade300,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Header title
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE3F2FD),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(
                    Icons.timer_outlined,
                    color: Color(0xFF0088FF),
                    size: 20,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Pengajuan Lembur',
                        style: TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.bold,
                          color: Colors.black87,
                        ),
                      ),
                      Text(
                        record.date,
                        style: TextStyle(
                          fontSize: 12,
                          color: Colors.grey.shade600,
                        ),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  onPressed: () => Navigator.pop(context),
                  icon: const Icon(Icons.close_rounded, color: Colors.grey),
                  padding: EdgeInsets.zero,
                  constraints: const BoxConstraints(),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // Card Ringkasan Presensi & Lembur
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: const Color(0xFFF0F7FF),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFFBAE6FD)),
              ),
              child: Column(
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Jam Masuk',
                            style: TextStyle(fontSize: 11, color: Colors.grey),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            record.masukTime,
                            style: const TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.bold,
                              color: Colors.black87,
                            ),
                          ),
                        ],
                      ),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          const Text(
                            'Jam Pulang',
                            style: TextStyle(fontSize: 11, color: Colors.grey),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            record.pulangTime,
                            style: const TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.bold,
                              color: Colors.black87,
                            ),
                          ),
                        ],
                      ),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          const Text(
                            'Total Kerja',
                            style: TextStyle(fontSize: 11, color: Colors.grey),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            record.totalJamKerja,
                            style: const TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.bold,
                              color: Colors.black87,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 10),
                    child: Divider(height: 1, color: Color(0xFFE2E8F0)),
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Row(
                        children: [
                          Icon(
                            Icons.timer_outlined,
                            size: 16,
                            color: Colors.orange.shade700,
                          ),
                          const SizedBox(width: 6),
                          const Text(
                            'Durasi Lembur:',
                            style: TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                              color: Colors.black87,
                            ),
                          ),
                        ],
                      ),
                      Text(
                        record.totalLembur,
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                          color: Colors.orange.shade800,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // KONDISI 1: Belum Diajukan (Karyawan Opt-in Ya / Tidak + Input Deskripsi)
            if (isUnsubmitted) ...[
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.blue.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.blue.shade100),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(
                      Icons.info_outline_rounded,
                      size: 18,
                      color: Colors.blue.shade700,
                    ),
                    const SizedBox(width: 8),
                    const Expanded(
                      child: Text(
                        'Apakah Anda ingin mengajukan lembur untuk hari ini? Jika ya, silakan isi penjelasan tugas di bawah.',
                        style: TextStyle(
                          fontSize: 12,
                          color: Color(0xFF0D47A1),
                          height: 1.4,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              const Text(
                'Deskripsi / Penjelasan Lembur *',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.bold,
                  color: Colors.black87,
                ),
              ),
              const SizedBox(height: 4),
              const Text(
                'Jelaskan pekerjaan atau tugas yang Anda selesaikan saat lembur.',
                style: TextStyle(fontSize: 11, color: Colors.grey),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: _reasonController,
                maxLines: 3,
                style: const TextStyle(fontSize: 13),
                decoration: InputDecoration(
                  hintText:
                      'Contoh: Menyelesaikan rekap laporan bulanan dan closing data transaksi...',
                  hintStyle: TextStyle(fontSize: 12, color: Colors.grey.shade400),
                  contentPadding: const EdgeInsets.all(12),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(8),
                    borderSide: BorderSide(color: Colors.grey.shade300),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(8),
                    borderSide: BorderSide(color: Colors.grey.shade300),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(8),
                    borderSide: const BorderSide(color: Color(0xFF0088FF), width: 1.5),
                  ),
                ),
              ),
              const SizedBox(height: 20),

              // Tombol Ya (Ajukan) & Tidak (Batalkan/Hapus)
              Column(
                children: [
                  SizedBox(
                    width: double.infinity,
                    height: 46,
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF0088FF),
                        foregroundColor: Colors.white,
                        elevation: 0,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8),
                        ),
                      ),
                      onPressed: _isSubmitting ? null : _submitClaim,
                      icon: _isSubmitting
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            )
                          : const Icon(Icons.send_rounded, size: 18),
                      label: const Text(
                        'Ya, Ajukan Lembur',
                        style: TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 10),
                  SizedBox(
                    width: double.infinity,
                    height: 44,
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.red.shade700,
                        side: BorderSide(color: Colors.red.shade300),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8),
                        ),
                      ),
                      onPressed: _isSubmitting ? null : _confirmDecline,
                      icon: const Icon(Icons.close_rounded, size: 18),
                      label: const Text(
                        'Tidak, Hapus Lembur',
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ] else ...[
              // KONDISI 2: Sudah Pernah Diajukan (Pending / Approved / Rejected)
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: isApproved
                      ? Colors.green.shade50
                      : isRejected
                          ? Colors.red.shade50
                          : Colors.orange.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(
                    color: isApproved
                        ? Colors.green.shade200
                        : isRejected
                            ? Colors.red.shade200
                            : Colors.orange.shade200,
                  ),
                ),
                child: Row(
                  children: [
                    Icon(
                      isApproved
                          ? Icons.check_circle_outline_rounded
                          : isRejected
                              ? Icons.cancel_outlined
                              : Icons.hourglass_top_rounded,
                      size: 20,
                      color: isApproved
                          ? Colors.green.shade700
                          : isRejected
                              ? Colors.red.shade700
                              : Colors.orange.shade700,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        isApproved
                            ? 'Lembur telah disetujui oleh HRD'
                            : isRejected
                                ? 'Lembur ditolak oleh HRD'
                                : 'Pengajuan lembur sedang menunggu persetujuan HRD',
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.bold,
                          color: isApproved
                              ? Colors.green.shade800
                              : isRejected
                                  ? Colors.red.shade800
                                  : Colors.orange.shade800,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              if (record.overtimeReason != null &&
                  record.overtimeReason!.isNotEmpty) ...[
                const SizedBox(height: 14),
                const Text(
                  'Deskripsi Pekerjaan Lembur:',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
                const SizedBox(height: 4),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade50,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: Colors.grey.shade200),
                  ),
                  child: Text(
                    record.overtimeReason!,
                    style: const TextStyle(fontSize: 12, color: Colors.black87),
                  ),
                ),
              ],
              if (isPending) ...[
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  height: 44,
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: Colors.red.shade700,
                      side: BorderSide(color: Colors.red.shade300),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8),
                      ),
                    ),
                    onPressed: _isSubmitting ? null : _confirmDecline,
                    icon: const Icon(Icons.delete_outline_rounded, size: 18),
                    label: const Text(
                      'Batalkan Pengajuan Lembur',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }
}

// Format menit → "Xj Ym"
String _fmtMenit(int menit) {
  if (menit <= 0) return '';
  final j = menit ~/ 60;
  final m = menit % 60;
  if (j == 0) return '${m}m';
  if (m == 0) return '${j}j';
  return '${j}j ${m}m';
}
