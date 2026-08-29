import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../presensi_provider.dart';
import '../widgets/skeleton.dart';
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
    _tabController = TabController(length: 2, vsync: this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final prov = Provider.of<PresensiProvider>(context, listen: false);
      prov.fetchLeaveRequests();
      prov.fetchLeaveBalance();
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
        actions: [
          // Refresh manual di pojok kanan atas
          IconButton(
            onPressed: () {
              final prov = Provider.of<PresensiProvider>(
                context,
                listen: false,
              );
              prov.fetchLeaveRequests();
              prov.fetchLeaveBalance();
            },
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          indicatorColor: Colors.white,
          indicatorWeight: 3,
          labelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
          tabs: const [
            Tab(text: 'Riwayat'),
            Tab(text: 'Saldo Cuti'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: const [
          _RiwayatIzinTab(),
          _SaldoCutiTab(),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'izin_cuti_fab',
        onPressed: () async {
          final prov =
              Provider.of<PresensiProvider>(context, listen: false);
          await Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const AjukanIzinScreen()),
          );
          prov.fetchLeaveRequests();
        },
        backgroundColor: const Color(0xFF0088FF),
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

// ─── Tab Riwayat ─────────────────────────────────────────────
class _RiwayatIzinTab extends StatelessWidget {
  const _RiwayatIzinTab();

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<PresensiProvider>(context);
    final leaves = prov.leaveRequests;

    if (prov.loadingLeaves && leaves.isEmpty) {
      return ShimmerLoading(
        child: ListView.builder(
          physics: const NeverScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          itemCount: 5,
          itemBuilder: (context, index) => const SkeletonLeaveCard(),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: () =>
          Provider.of<PresensiProvider>(context, listen: false)
              .fetchLeaveRequests(),
      child: leaves.isEmpty
          ? ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: const [
                SizedBox(height: 120),
                Center(
                  child: Text(
                    'Belum ada pengajuan izin/cuti.',
                    style: TextStyle(color: Colors.grey, fontSize: 14),
                  ),
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
    final typeStyle = _typeStyle(leave.leaveType);

    return Card(
      color: Colors.white,
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
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
                    color: typeStyle.bg,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: typeStyle.border),
                  ),
                  child: Text(
                    typeLabel,
                    style: TextStyle(
                      color: typeStyle.text,
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
                    color: statusStyle.bg,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: statusStyle.border),
                  ),
                  child: Text(
                    statusStyle.label,
                    style: TextStyle(
                      color: statusStyle.text,
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                const Icon(Icons.calendar_today_outlined,
                    size: 14, color: Color(0xFF546E7A)),
                const SizedBox(width: 6),
                Text(
                  '${leave.startDate} — ${leave.endDate}',
                  style: const TextStyle(
                    color: Color(0xFF455A64),
                    fontSize: 12,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(width: 8),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE3F2FD),
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: const Color(0xFFBBDEFB)),
                  ),
                  child: Text(
                    '${leave.totalDays} hari',
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: Color(0xFF1565C0),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              leave.reason,
              style: const TextStyle(
                fontSize: 13,
                color: Colors.black87,
                height: 1.3,
              ),
            ),
            if (leave.status == 'rejected' &&
                leave.rejectionReason != null) ...[
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFEBEE),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: const Color(0xFFFFCDD2)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Alasan Penolakan:',
                      style: TextStyle(
                        color: Color(0xFFC62828),
                        fontWeight: FontWeight.bold,
                        fontSize: 11,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      leave.rejectionReason!,
                      style: const TextStyle(
                        color: Color(0xFFB71C1C),
                        fontSize: 11,
                        height: 1.4,
                      ),
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

  ({String label, Color bg, Color border, Color text}) _statusStyle(String status) {
    switch (status) {
      case 'approved':
        return (
          label: 'Disetujui',
          bg: const Color(0xFFE8F5E9),
          border: const Color(0xFFA5D6A7),
          text: const Color(0xFF2E7D32),
        );
      case 'rejected':
        return (
          label: 'Ditolak',
          bg: const Color(0xFFFFEBEE),
          border: const Color(0xFFFFCDD2),
          text: const Color(0xFFC62828),
        );
      default:
        return (
          label: 'Menunggu',
          bg: const Color(0xFFFFF3E0),
          border: const Color(0xFFFFE0B2),
          text: const Color(0xFFE65100),
        );
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

  ({Color bg, Color border, Color text}) _typeStyle(String type) {
    switch (type) {
      case 'wfh':
        return (
          bg: const Color(0xFFE3F2FD),
          border: const Color(0xFF90CAF9),
          text: const Color(0xFF1565C0),
        );
      case 'izin':
        return (
          bg: const Color(0xFFF3E5F5),
          border: const Color(0xFFCE93D8),
          text: const Color(0xFF7B1FA2),
        );
      case 'sakit':
        return (
          bg: const Color(0xFFFFF3E0),
          border: const Color(0xFFFFCC80),
          text: const Color(0xFFE65100),
        );
      case 'cuti':
        return (
          bg: const Color(0xFFE0F2F1),
          border: const Color(0xFF80CBC4),
          text: const Color(0xFF00695C),
        );
      default:
        return (
          bg: const Color(0xFFECEFF1),
          border: const Color(0xFFCFD8DC),
          text: const Color(0xFF455A64),
        );
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
              fontSize: 16,
              fontWeight: FontWeight.bold,
              color: Colors.black87,
            ),
          ),
          const SizedBox(height: 4),
          const Text(
            'Kuota cuti & sakit yang tersedia untuk Anda.',
            style: TextStyle(fontSize: 12, color: Color(0xFF546E7A)),
          ),
          const SizedBox(height: 16),
          if (prov.loadingBalance && balances.isEmpty)
            const ShimmerLoading(
              child: Column(
                children: [
                  SkeletonLeaveCard(),
                  SkeletonLeaveCard(),
                ],
              ),
            )
          else
            ...balances.map((b) => _BalanceCard(balance: b)),
          const SizedBox(height: 20),
          if (prov.leaveResetInfo != null && prov.leaveResetInfo!['leave_reset_date'] != null)
            Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFFFF8E1),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFFFE082)),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.update, color: Color(0xFFE65100), size: 18),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Builder(
                      builder: (context) {
                        final dateStr = prov.leaveResetInfo!['leave_reset_date'].toString();
                        final parts = dateStr.split('-');
                        String prettyDate = dateStr;
                        if (parts.length == 2) {
                          final int? month = int.tryParse(parts[0]);
                          final int? day = int.tryParse(parts[1]);
                          if (month != null && day != null && month >= 1 && month <= 12) {
                            const months = [
                              'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                              'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
                            ];
                            prettyDate = '$day ${months[month - 1]}';
                          }
                        }
                        return Text(
                          'Saldo cuti tahunan akan di-reset menjadi ${prov.leaveResetInfo!['default_leave_quota']} hari pada setiap tanggal $prettyDate.',
                          style: const TextStyle(
                            fontSize: 12,
                            color: Color(0xFFBF360C),
                            fontWeight: FontWeight.w500,
                          ),
                        );
                      },
                    ),
                  ),
                ],
              ),
            ),
          // Info note
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFE3F2FD),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFF90CAF9)),
            ),
            child: const Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.info_outline,
                    color: Color(0xFF1565C0), size: 18),
                SizedBox(width: 8),
                Expanded(
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
    final color = isCuti ? const Color(0xFF00695C) : const Color(0xFF7B1FA2);
    final label = isCuti ? 'Cuti Tahunan' : 'Izin';
    final icon = isCuti
        ? Icons.beach_access_outlined
        : Icons.event_busy_outlined;

    return Card(
      color: Colors.white,
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
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
                    fontWeight: FontWeight.bold,
                    fontSize: 14,
                    color: Colors.black87,
                  ),
                ),
                const Spacer(),
                if (isCuti)
                  if (balance.quota > 0)
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
                          const TextSpan(
                            text: ' / ',
                            style: TextStyle(
                              fontSize: 14,
                              color: Colors.grey,
                            ),
                          ),
                          TextSpan(
                            text: '${balance.quota} hari',
                            style: const TextStyle(
                              fontSize: 13,
                              color: Color(0xFF455A64),
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    )
                  else
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: const Color(0xFFECEFF1),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: const Color(0xFFCFD8DC)),
                      ),
                      child: const Text(
                        'Tidak Aktif',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF546E7A),
                        ),
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
                          style: TextStyle(
                            fontSize: 13,
                            color: Color(0xFF455A64),
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            if (isCuti) ...[
              if (balance.quota > 0) ...[
                ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: (balance.used / balance.quota).clamp(0.0, 1.0),
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
                      style: const TextStyle(
                        fontSize: 12,
                        color: Color(0xFF546E7A),
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                    Text(
                      'Sisa: ${balance.remaining} hari',
                      style: TextStyle(
                        fontSize: 12,
                        color: color,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
              ] else ...[
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      balance.used > 0
                          ? 'Terpakai: ${balance.used} hari'
                          : 'Belum diaktifkan oleh HRD',
                      style: const TextStyle(fontSize: 12, color: Colors.grey),
                    ),
                    const Text(
                      'Status: Tidak Aktif',
                      style: TextStyle(
                        fontSize: 12,
                        color: Color(0xFF546E7A),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ],
            ] else ...[
              const Text(
                'Total hari izin & sakit yang digunakan',
                style: TextStyle(fontSize: 12, color: Color(0xFF546E7A)),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
