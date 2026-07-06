import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../presensi_provider.dart';
import 'ajukan_izin_screen.dart';

class IzinCutiScreen extends StatefulWidget {
  const IzinCutiScreen({super.key});

  @override
  State<IzinCutiScreen> createState() => _IzinCutiScreenState();
}

class _IzinCutiScreenState extends State<IzinCutiScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final prov = Provider.of<PresensiProvider>(context, listen: false);
      prov.fetchLeaveRequests();
      prov.fetchLeaveBalance();
      prov.fetchHolidays(DateTime.now().year);
    });
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Izin & Cuti'),
        automaticallyImplyLeading: false,
        bottom: TabBar(
          controller: _tabController,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white60,
          indicatorColor: Colors.white,
          tabs: const [
            Tab(text: 'Riwayat'),
            Tab(text: 'Saldo Cuti'),
            Tab(text: 'Hari Libur'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: const [
          _RiwayatIzinTab(),
          _SaldoCutiTab(),
          _HariLiburTab(),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          // Ambil provider sebelum await agar tidak pakai context lintas async
          final prov =
              Provider.of<PresensiProvider>(context, listen: false);
          await Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const AjukanIzinScreen()),
          );
          // Refresh riwayat dari backend setelah kembali
          prov.fetchLeaveRequests();
        },
        backgroundColor: Theme.of(context).primaryColor,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: const Text(
          'Ajukan Izin',
          style: TextStyle(fontWeight: FontWeight.bold),
        ),
      ),
    );
  }
}

// ─── Tab Hari Libur ──────────────────────────────────────────
class _HariLiburTab extends StatefulWidget {
  const _HariLiburTab();

  @override
  State<_HariLiburTab> createState() => _HariLiburTabState();
}

class _HariLiburTabState extends State<_HariLiburTab> {
  late int _selectedYear;

  @override
  void initState() {
    super.initState();
    _selectedYear = DateTime.now().year;
  }

  void _changeYear(int delta) {
    final newYear = _selectedYear + delta;
    setState(() => _selectedYear = newYear);
    Provider.of<PresensiProvider>(context, listen: false)
        .fetchHolidays(newYear);
  }

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<PresensiProvider>(context);
    final holidays = prov.holidays;

    return Column(
      children: [
        // ─ Header pemilih tahun
        Container(
          color: Colors.white,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              IconButton(
                onPressed: () => _changeYear(-1),
                icon: const Icon(Icons.chevron_left),
                tooltip: 'Tahun sebelumnya',
              ),
              Text(
                'Kalender Libur $_selectedYear',
                style: const TextStyle(
                    fontSize: 15, fontWeight: FontWeight.bold),
              ),
              IconButton(
                onPressed: () => _changeYear(1),
                icon: const Icon(Icons.chevron_right),
                tooltip: 'Tahun berikutnya',
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        // ─ Body
        Expanded(
          child: prov.loadingHolidays
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: () => Provider.of<PresensiProvider>(
                          context,
                          listen: false)
                      .fetchHolidays(_selectedYear),
                  child: holidays.isEmpty
                      ? ListView(
                          physics: const AlwaysScrollableScrollPhysics(),
                          children: const [
                            SizedBox(height: 120),
                            Center(
                              child: Text(
                                'Belum ada data hari libur.',
                                style: TextStyle(color: Colors.grey),
                              ),
                            ),
                          ],
                        )
                      : ListView.builder(
                          physics: const AlwaysScrollableScrollPhysics(),
                          padding: const EdgeInsets.fromLTRB(16, 12, 16, 80),
                          itemCount: holidays.length,
                          itemBuilder: (context, i) =>
                              _HolidayCard(holiday: holidays[i]),
                        ),
                ),
        ),
      ],
    );
  }
}

class _HolidayCard extends StatelessWidget {
  final HolidayRecord holiday;
  const _HolidayCard({required this.holiday});

  @override
  Widget build(BuildContext context) {
    final date = _formatDate(holiday.date);
    final dayName = _dayName(holiday.date);
    final isWeekend = _isWeekend(holiday.date);

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(color: Colors.grey.shade200),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          children: [
            // Kalender ikon dengan tanggal
            Container(
              width: 48,
              height: 52,
              decoration: BoxDecoration(
                color: Colors.red.shade50,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.red.shade100),
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    _dayNumber(holiday.date),
                    style: TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      color: Colors.red.shade700,
                    ),
                  ),
                  Text(
                    _monthShort(holiday.date),
                    style: TextStyle(
                      fontSize: 10,
                      color: Colors.red.shade400,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            // Nama & info
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    holiday.name,
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.bold,
                      color: Colors.black87,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    '$dayName, $date',
                    style: const TextStyle(fontSize: 11, color: Colors.grey),
                  ),
                ],
              ),
            ),
            // Badge tipe libur
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                if (holiday.isNational)
                  _badge('Nasional', Colors.red)
                else
                  _badge('Perusahaan', Colors.blue),
                if (isWeekend) ...[
                  const SizedBox(height: 4),
                  _badge('Weekend', Colors.grey),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _badge(String label, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Text(
        label,
        style: TextStyle(
            fontSize: 10, color: color, fontWeight: FontWeight.bold),
      ),
    );
  }

  String _formatDate(String iso) {
    final dt = DateTime.tryParse(iso);
    if (dt == null) return iso;
    const months = [
      'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
      'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    return '${dt.day} ${months[dt.month - 1]} ${dt.year}';
  }

  String _dayNumber(String iso) {
    final dt = DateTime.tryParse(iso);
    return dt != null ? dt.day.toString() : '-';
  }

  String _monthShort(String iso) {
    final dt = DateTime.tryParse(iso);
    if (dt == null) return '';
    const short = [
      'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
      'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'
    ];
    return short[dt.month - 1];
  }

  String _dayName(String iso) {
    final dt = DateTime.tryParse(iso);
    if (dt == null) return '';
    const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return days[dt.weekday % 7];
  }

  bool _isWeekend(String iso) {
    final dt = DateTime.tryParse(iso);
    if (dt == null) return false;
    return dt.weekday == DateTime.saturday || dt.weekday == DateTime.sunday;
  }
}

// ─── Tab Riwayat ─────────────────────────────────────────────
class _RiwayatIzinTab extends StatelessWidget {
  const _RiwayatIzinTab();

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<PresensiProvider>(context);
    final leaves = prov.leaveRequests;

    if (prov.loadingLeaves && leaves.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    return RefreshIndicator(
      onRefresh: () =>
          Provider.of<PresensiProvider>(context, listen: false)
              .fetchLeaveRequests(),
      child: leaves.isEmpty
          ? ListView(
              // ListView agar tetap bisa ditarik (pull-to-refresh) walau kosong
              physics: const AlwaysScrollableScrollPhysics(),
              children: const [
                SizedBox(height: 120),
                Center(
                  child: Text('Belum ada pengajuan izin/cuti.',
                      style: TextStyle(color: Colors.grey)),
                ),
              ],
            )
          : ListView.builder(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              itemCount: leaves.length,
              itemBuilder: (context, index) =>
                  _LeaveCard(leave: leaves[index]),
            ),
    );
  }
}

class _LeaveCard extends StatelessWidget {
  final LeaveRequestRecord leave;
  const _LeaveCard({required this.leave});

  @override
  Widget build(BuildContext context) {
    final statusStyle = _statusStyle(leave.status);
    final typeLabel = _typeLabel(leave.leaveType);
    final typeColor = _typeColor(leave.leaveType);

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: Colors.grey.shade200),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                // Tipe badge
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: typeColor.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    typeLabel,
                    style: TextStyle(
                      color: typeColor,
                      fontWeight: FontWeight.bold,
                      fontSize: 12,
                    ),
                  ),
                ),
                // Status badge
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusStyle.$2,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    statusStyle.$1,
                    style: TextStyle(
                      color: statusStyle.$3,
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                const Icon(Icons.calendar_today_outlined,
                    size: 14, color: Colors.grey),
                const SizedBox(width: 6),
                Text(
                  '${leave.startDate} — ${leave.endDate}',
                  style: const TextStyle(color: Colors.grey, fontSize: 12),
                ),
                const SizedBox(width: 8),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    '${leave.totalDays} hari',
                    style: const TextStyle(fontSize: 11, color: Colors.black54),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              leave.reason,
              style: const TextStyle(fontSize: 13, color: Colors.black87),
            ),
            if (leave.status == 'rejected' &&
                leave.rejectionReason != null) ...[
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: Colors.red.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.red.shade100),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Alasan Penolakan:',
                      style: TextStyle(
                          color: Colors.red,
                          fontWeight: FontWeight.bold,
                          fontSize: 11),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      leave.rejectionReason!,
                      style: TextStyle(
                          color: Colors.red.shade900,
                          fontSize: 11,
                          height: 1.4),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  (String, Color, Color) _statusStyle(String status) {
    switch (status) {
      case 'approved':
        return ('Disetujui', const Color(0xFFE8F5E9), Colors.green);
      case 'rejected':
        return ('Ditolak', const Color(0xFFFFEBEE), Colors.red);
      default:
        return ('Menunggu', const Color(0xFFFFF3E0), Colors.orange);
    }
  }

  String _typeLabel(String type) {
    switch (type) {
      case 'wfh':
        return 'Work From Home';
      case 'izin':
        return 'Izin';
      case 'sakit':
        return 'Sakit';
      case 'cuti':
        return 'Cuti';
      default:
        return type;
    }
  }

  Color _typeColor(String type) {
    switch (type) {
      case 'wfh':
        return const Color(0xFF1E88E5);
      case 'izin':
        return Colors.purple;
      case 'sakit':
        return Colors.orange;
      case 'cuti':
        return Colors.teal;
      default:
        return Colors.grey;
    }
  }
}

// ─── Tab Saldo Cuti ───────────────────────────────────────────
class _SaldoCutiTab extends StatelessWidget {
  const _SaldoCutiTab();

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<PresensiProvider>(context);
    final balances = prov.leaveBalances;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SizedBox(height: 4),
          Text(
            'Saldo Cuti Tahun ${DateTime.now().year}',
            style: const TextStyle(
                fontSize: 16, fontWeight: FontWeight.bold, color: Colors.black87),
          ),
          const SizedBox(height: 4),
          const Text(
            'Kuota cuti & sakit yang tersedia untuk Anda.',
            style: TextStyle(fontSize: 12, color: Colors.grey),
          ),
          const SizedBox(height: 20),
          ...balances.map((b) => _BalanceCard(balance: b)),
          const SizedBox(height: 24),
          // Info note
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFE3F2FD),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFFBBDEFB)),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(Icons.info_outline,
                    color: Color(0xFF1E88E5), size: 18),
                const SizedBox(width: 8),
                const Expanded(
                  child: Text(
                    'Saldo cuti dipotong otomatis saat pengajuan disetujui HRD. Kuota dapat disesuaikan oleh HRD.',
                    style:
                        TextStyle(fontSize: 12, color: Color(0xFF0D47A1)),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _BalanceCard extends StatelessWidget {
  final LeaveBalanceRecord balance;
  const _BalanceCard({required this.balance});

  @override
  Widget build(BuildContext context) {
    final isCuti = balance.leaveType == 'cuti';
    final color = isCuti ? Colors.teal : Colors.purple;
    final label = isCuti ? 'Cuti Tahunan' : 'Izin';
    final icon = isCuti
        ? Icons.beach_access_outlined
        : Icons.event_busy_outlined;

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: Colors.grey.shade200),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(icon, color: color, size: 20),
                const SizedBox(width: 8),
                Text(
                  label,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 14),
                ),
                const Spacer(),
                if (isCuti)
                  RichText(
                    text: TextSpan(
                      children: [
                        TextSpan(
                          text: '${balance.remaining}',
                          style: TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.bold,
                            color: color,
                          ),
                        ),
                        TextSpan(
                          text: ' / ${balance.quota} hari',
                          style: const TextStyle(
                              fontSize: 13, color: Colors.grey),
                        ),
                      ],
                    ),
                  )
                else
                  RichText(
                    text: TextSpan(
                      children: [
                        TextSpan(
                          text: '${balance.used}',
                          style: TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.bold,
                            color: color,
                          ),
                        ),
                        const TextSpan(
                          text: ' hari',
                          style: TextStyle(fontSize: 13, color: Colors.grey),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            if (isCuti) ...[
              ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: LinearProgressIndicator(
                  value: (balance.quota > 0
                          ? balance.used / balance.quota
                          : 0.0)
                      .clamp(0.0, 1.0),
                  minHeight: 8,
                  backgroundColor: Colors.grey.shade200,
                  color: color,
                ),
              ),
              const SizedBox(height: 8),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Terpakai: ${balance.used} hari',
                    style: const TextStyle(fontSize: 12, color: Colors.grey),
                  ),
                  Text(
                    'Sisa: ${balance.remaining} hari',
                    style: TextStyle(
                        fontSize: 12,
                        color: color,
                        fontWeight: FontWeight.bold),
                  ),
                ],
              ),
            ] else ...[
              Text(
                'Total hari izin & sakit yang digunakan',
                style: const TextStyle(fontSize: 12, color: Colors.grey),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
