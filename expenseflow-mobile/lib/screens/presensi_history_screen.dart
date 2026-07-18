import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../presensi_provider.dart';
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
      Provider.of<PresensiProvider>(context, listen: false)
          .fetchMyAttendance();
    });
  }

  @override
  Widget build(BuildContext context) {
    final presensiProv = Provider.of<PresensiProvider>(context);
    final todayStr = presensiProv.todayDateFormatted;

    return Scaffold(
      floatingActionButton: presensiProv.wfhEnabled
          ? FloatingActionButton.extended(
              heroTag: 'presensi_history_fab',
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const PresensiMapScreen()),
              ),
              backgroundColor: const Color(0xFF0088FF),
              foregroundColor: Colors.white,
              icon: const Icon(Icons.add),
              label: const Text('Catat Presensi'),
            )
          : null,
      appBar: AppBar(
        title: const Text('Riwayat Presensi'),
        automaticallyImplyLeading: false,
        actions: [
          // Tombol refresh manual
          if (presensiProv.loadingHistory)
            const Padding(
              padding: EdgeInsets.all(16),
              child: SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(
                      strokeWidth: 2, color: Colors.white)),
            )
          else
            IconButton(
              icon: const Icon(Icons.refresh),
              tooltip: 'Muat ulang riwayat',
              onPressed: () => presensiProv.fetchMyAttendance(),
            ),
        ],
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Banner status WFH — informatif saja, bukan gerbang akses.
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
            child: Consumer<PresensiProvider>(
              builder: (context, prov, _) {
                if (prov.wfhEnabled) {
                  // WFH aktif: tampilkan tombol presensi + info
                  return Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(
                        horizontal: 14, vertical: 10),
                    decoration: BoxDecoration(
                      color: const Color(0xFFE8F5E9),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFC8E6C9)),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.home_work_outlined,
                            color: Colors.green, size: 18),
                        const SizedBox(width: 8),
                        const Expanded(
                          child: Text(
                            'Mode WFH aktif — tekan tombol + untuk presensi.',
                            style: TextStyle(
                                color: Colors.green,
                                fontWeight: FontWeight.bold,
                                fontSize: 12),
                          ),
                        ),
                        // Shortcut tombol presensi di dalam banner
                        GestureDetector(
                          onTap: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                                builder: (_) => const PresensiMapScreen()),
                          ),
                          child: Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 10, vertical: 6),
                            decoration: BoxDecoration(
                              color: Colors.green,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: const Text(
                              'Presensi',
                              style: TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 11),
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
                        horizontal: 14, vertical: 10),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF3E0),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFFFE0B2)),
                    ),
                    child: const Row(
                      children: [
                        Icon(Icons.business_outlined,
                            color: Colors.orange, size: 18),
                        SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'Presensi kantor via perangkat absensi. '
                            'Riwayat Anda ditampilkan di bawah.',
                            style: TextStyle(
                                color: Colors.orange,
                                fontWeight: FontWeight.bold,
                                fontSize: 12),
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
                borderRadius: BorderRadius.circular(8), // matching screenshot style
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
                      Container(
                        height: 40,
                        width: 1,
                        color: Colors.white30,
                      ),
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
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        // Chip jam kerja
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 6),
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.15),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Icon(Icons.access_time_outlined,
                                  size: 14, color: Colors.white70),
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
                        if (presensiProv.todayOvertimeMinutes > 0) ...[
                          const SizedBox(width: 8),
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 6),
                            decoration: BoxDecoration(
                              color: Colors.orange.withValues(alpha: 0.85),
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const Icon(Icons.timer_outlined,
                                    size: 14, color: Colors.white),
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
                        ],
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

          // History ListView
          Expanded(
            child: presensiProv.loadingHistory
                ? const Center(child: CircularProgressIndicator())
                : presensiProv.records.isEmpty
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 32),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(Icons.assignment_ind_outlined,
                                  size: 48, color: Colors.grey.shade300),
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
                                    color: Colors.grey, fontSize: 12),
                                textAlign: TextAlign.center,
                              ),
                            ],
                          ),
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: presensiProv.fetchMyAttendance,
                        child: ListView.builder(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 16.0, vertical: 8.0),
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

  Widget _buildHistoryCard(PresensiRecord record) {
    final total   = record.totalJamKerja;
    final lembur  = record.totalLembur;
    final hasData = total != '-';

    return Card(
      color: Colors.white,
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(8),
        side: BorderSide(color: Colors.grey.shade200),
      ),
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
                            _badge('Hari Libur', Colors.red.shade600, Colors.red.shade50),
                          if (record.isAutoCheckout)
                            _badge('Auto-Checkout', Colors.purple.shade600, Colors.purple.shade50),
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
                  if (record.checkInType != null && record.checkInType!.isNotEmpty) ...[
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
                    Container(width: 1, height: 12, color: Colors.grey.shade300),
                  ],
                  // Jam kerja
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.access_time_outlined,
                          size: 13, color: Colors.blue.shade400),
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
                    Container(width: 1, height: 12, color: Colors.grey.shade300),
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.timer_outlined,
                            size: 13, color: Colors.orange.shade600),
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
            ],
          ],
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
        style: TextStyle(fontSize: 10, color: textColor, fontWeight: FontWeight.bold),
      ),
    );
  }

  /// Badge status approval lembur: pending = kuning, approved = hijau, rejected = merah.
  Widget _overtimeStatusBadge(String? status) {
    if (status == null) {
      return _badge('Menunggu HRD', Colors.grey.shade600, Colors.grey.shade100);
    }
    switch (status) {
      case 'approved':
        return _badge('Disetujui', Colors.green.shade700, Colors.green.shade50);
      case 'rejected':
        return _badge('Ditolak', Colors.red.shade700, Colors.red.shade50);
      default: // pending
        return _badge('Menunggu HRD', Colors.orange.shade700, Colors.orange.shade50);
    }
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
