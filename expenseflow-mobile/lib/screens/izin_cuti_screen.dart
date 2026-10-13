import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../presensi_provider.dart';
import '../utils.dart';
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
        bottom: TabBar(
          controller: _tabController,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          indicatorColor: Colors.white,
          indicatorWeight: 3,
          labelStyle: const TextStyle(
            fontWeight: FontWeight.bold,
            fontSize: 14,
          ),
          tabs: const [
            Tab(text: 'Riwayat'),
            Tab(text: 'Saldo Cuti'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: const [_RiwayatIzinTab(), _SaldoCutiTab()],
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'izin_cuti_fab',
        onPressed: () async {
          final prov = Provider.of<PresensiProvider>(context, listen: false);
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
// ─── Tab Riwayat ─────────────────────────────────────────────
class _RiwayatIzinTab extends StatefulWidget {
  const _RiwayatIzinTab();

  @override
  State<_RiwayatIzinTab> createState() => _RiwayatIzinTabState();
}

class _RiwayatIzinTabState extends State<_RiwayatIzinTab> {
  int _selectedFilter = 0; // 0 = Semua, 1 = Pengajuan, 2 = Perubahan Jatah

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<PresensiProvider>(context);
    final leaves = prov.leaveRequests;
    final adjustments = prov.leaveAdjustments;

    if (prov.loadingLeaves && leaves.isEmpty && adjustments.isEmpty) {
      return ShimmerLoading(
        child: ListView.builder(
          physics: const NeverScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          itemCount: 5,
          itemBuilder: (context, index) => const SkeletonLeaveCard(),
        ),
      );
    }

    final List<_HistoryItem> allItems = [];
    for (final l in leaves) {
      allItems.add(_RequestHistoryItem(l));
    }
    for (final a in adjustments) {
      allItems.add(_AdjustmentHistoryItem(a));
    }

    allItems.sort((a, b) {
      final da = a.sortDate ?? DateTime(2000);
      final db = b.sortDate ?? DateTime(2000);
      return db.compareTo(da);
    });

    final List<_HistoryItem> displayedItems;
    if (_selectedFilter == 1) {
      displayedItems = allItems.whereType<_RequestHistoryItem>().toList();
    } else if (_selectedFilter == 2) {
      displayedItems = allItems.whereType<_AdjustmentHistoryItem>().toList();
    } else {
      displayedItems = allItems;
    }

    return Column(
      children: [
        // Filter bar
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 6),
          child: Row(
            children: [
              _buildFilterChip(0, 'Semua', allItems.length),
              const SizedBox(width: 8),
              _buildFilterChip(1, 'Pengajuan', leaves.length),
              const SizedBox(width: 8),
              _buildFilterChip(2, 'Perubahan Jatah', adjustments.length),
            ],
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () async {
              final prov = Provider.of<PresensiProvider>(context, listen: false);
              await Future.wait([
                prov.fetchLeaveRequests(forceRefresh: true),
                prov.fetchLeaveBalance(forceRefresh: true),
              ]);
            },
            child: displayedItems.isEmpty
                ? ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    children: [
                      const SizedBox(height: 100),
                      Center(
                        child: Text(
                          _selectedFilter == 2
                              ? 'Belum ada riwayat perubahan jatah cuti oleh HRD.'
                              : (_selectedFilter == 1
                                  ? 'Belum ada pengajuan izin/cuti.'
                                  : 'Belum ada riwayat izin atau perubahan jatah cuti.'),
                          style: const TextStyle(
                              color: Colors.grey, fontSize: 14),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ],
                  )
                : ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 80),
                    itemCount: displayedItems.length,
                    itemBuilder: (context, index) {
                      final item = displayedItems[index];
                      if (item is _RequestHistoryItem) {
                        return _LeaveCard(leave: item.leave);
                      } else if (item is _AdjustmentHistoryItem) {
                        return _LeaveAdjustmentCard(
                            adjustment: item.adjustment);
                      }
                      return const SizedBox.shrink();
                    },
                  ),
          ),
        ),
      ],
    );
  }

  Widget _buildFilterChip(int index, String label, int count) {
    final isSelected = _selectedFilter == index;
    return Expanded(
      child: GestureDetector(
        onTap: () => setState(() => _selectedFilter = index),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          padding: const EdgeInsets.symmetric(vertical: 8),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: isSelected
                ? const Color(0xFF1565C0)
                : Colors.grey.shade100,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(
              color: isSelected
                  ? const Color(0xFF1565C0)
                  : Colors.grey.shade300,
            ),
          ),
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              '$label ($count)',
              style: TextStyle(
                fontSize: 11.5,
                fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                color: isSelected ? Colors.white : const Color(0xFF455A64),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

sealed class _HistoryItem {
  DateTime? get sortDate;
}

class _RequestHistoryItem extends _HistoryItem {
  final LeaveRequestRecord leave;
  _RequestHistoryItem(this.leave);

  @override
  DateTime? get sortDate {
    if (leave.createdAt != null && leave.createdAt!.isNotEmpty) {
      return DateTime.tryParse(leave.createdAt!);
    }
    if (leave.startDate.isNotEmpty) {
      return DateTime.tryParse(leave.startDate);
    }
    return null;
  }
}

class _AdjustmentHistoryItem extends _HistoryItem {
  final LeaveQuotaAdjustmentRecord adjustment;
  _AdjustmentHistoryItem(this.adjustment);

  @override
  DateTime? get sortDate {
    if (adjustment.createdAt != null && adjustment.createdAt!.isNotEmpty) {
      return DateTime.tryParse(adjustment.createdAt!);
    }
    return null;
  }
}

class _LeaveCard extends StatelessWidget {
  final LeaveRequestRecord leave;
  const _LeaveCard({required this.leave});

  @override
  Widget build(BuildContext context) {
    final statusStyle = _statusStyle(leave);
    final typeLabel = leave.leaveType == 'cuti_setengah_hari'
        ? (leave.halfDaySession == 'morning'
            ? 'Cuti Setengah Hari (Sesi 1)'
            : leave.halfDaySession == 'afternoon'
                ? 'Cuti Setengah Hari (Sesi 2)'
                : 'Cuti Setengah Hari')
        : _typeLabel(leave.leaveType);
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
                Flexible(
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 4,
                    ),
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
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                // Status badge
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 4,
                  ),
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
                const Icon(
                  Icons.calendar_today_outlined,
                  size: 14,
                  color: Color(0xFF546E7A),
                ),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    formatDateIndonesianRange(leave.startDate, leave.endDate),
                    style: const TextStyle(
                      color: Color(0xFF455A64),
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                const SizedBox(width: 8),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 7,
                    vertical: 2,
                  ),
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
            if (leave.status == 'pending' && leave.currentStep == 'hrd') ...[
              const SizedBox(height: 8),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: const Color(0xFFF0FDF4),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: const Color(0xFFDCFCE7)),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.check_circle_rounded, size: 14, color: Color(0xFF16A34A)),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        'Disetujui SPV${leave.spvName != null ? ' (${leave.spvName})' : ''}. Sedang ditinjau HRD.',
                        style: const TextStyle(
                          color: Color(0xFF15803D),
                          fontSize: 11,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              if (leave.spvNotes != null && leave.spvNotes!.isNotEmpty) ...[
                const SizedBox(height: 4),
                Padding(
                  padding: const EdgeInsets.only(left: 4),
                  child: Text(
                    'Catatan SPV: ${leave.spvNotes}',
                    style: const TextStyle(
                      color: Color(0xFF64748B),
                      fontSize: 10.5,
                      fontStyle: FontStyle.italic,
                    ),
                  ),
                ),
              ],
            ],
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

  ({String label, Color bg, Color border, Color text}) _statusStyle(
    LeaveRequestRecord leave,
  ) {
    switch (leave.status) {
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
        if (leave.currentStep == 'hrd') {
          return (
            label: 'Tahap 2: HRD',
            bg: const Color(0xFFF0F9FF),
            border: const Color(0xFFBAE6FD),
            text: const Color(0xFF0284C7),
          );
        }
        return (
          label: 'Tahap 1: SPV',
          bg: const Color(0xFFFFFBEB),
          border: const Color(0xFFFDE68A),
          text: const Color(0xFFD97706),
        );
    }
  }

  String _typeLabel(String type) => _resolveLeaveTypeLabel(type);
  ({Color bg, Color border, Color text}) _typeStyle(String type) =>
      _resolveLeaveTypeStyle(type);
}

String _resolveLeaveTypeLabel(String type) {
  switch (type) {
    case 'wfh':
      return 'Work From Home';
    case 'izin':
      return 'Izin';
    case 'sakit':
      return 'Cuti Sakit';
    case 'cuti':
      return 'Cuti Tahunan';
    case 'cuti_hamil':
      return 'Cuti Melahirkan';
    case 'cuti_keguguran':
      return 'Cuti Keguguran';
    case 'cuti_ayah':
      return 'Cuti Ayah';
    case 'cuti_haid':
      return 'Cuti Haid';
    case 'cuti_menikah':
      return 'Cuti Menikah';
    case 'cuti_menikahkan_anak':
      return 'Cuti Menikahkan Anak';
    case 'cuti_khitan_baptis_anak':
      return 'Cuti Khitan/Baptis Anak';
    case 'cuti_duka_keluarga_inti':
      return 'Cuti Duka Keluarga Inti';
    case 'cuti_duka_serumah':
      return 'Cuti Duka Serumah';
    case 'cuti_ibadah_haji_umrah':
      return 'Cuti Ibadah Keagamaan';
    case 'cuti_setengah_hari':
      return 'Cuti Setengah Hari';
    default:
      return type.replaceAll('_', ' ').toUpperCase();
  }
}

({Color bg, Color border, Color text}) _resolveLeaveTypeStyle(String type) {
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
    case 'cuti_hamil':
    case 'cuti_keguguran':
    case 'cuti_haid':
      return (
        bg: const Color(0xFFFCE4EC),
        border: const Color(0xFFF48FB1),
        text: const Color(0xFFAD1457),
      );
    case 'cuti_ayah':
    case 'cuti_menikah':
    case 'cuti_menikahkan_anak':
      return (
        bg: const Color(0xFFEDE7F6),
        border: const Color(0xFFB39DDB),
        text: const Color(0xFF512DA8),
      );
    case 'cuti_ibadah_haji_umrah':
      return (
        bg: const Color(0xFFE8F5E9),
        border: const Color(0xFFA5D6A7),
        text: const Color(0xFF2E7D32),
      );
    default:
      return (
        bg: const Color(0xFFECEFF1),
        border: const Color(0xFFCFD8DC),
        text: const Color(0xFF455A64),
      );
  }
}

class _LeaveAdjustmentCard extends StatelessWidget {
  final LeaveQuotaAdjustmentRecord adjustment;
  const _LeaveAdjustmentCard({required this.adjustment});

  @override
  Widget build(BuildContext context) {
    final typeStyle = _resolveLeaveTypeStyle(adjustment.leaveType);
    final isIncrease = adjustment.difference > 0;
    final isDecrease = adjustment.difference < 0;

    String dateStr = '—';
    if (adjustment.createdAt != null && adjustment.createdAt!.isNotEmpty) {
      try {
        final dt = DateTime.parse(adjustment.createdAt!);
        dateStr =
            '${dt.day.toString().padLeft(2, '0')} ${_monthName(dt.month)} ${dt.year}, ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
      } catch (_) {
        dateStr = adjustment.createdAt!;
      }
    }

    final typeLabel = adjustment.leaveTypeLabel.isNotEmpty
        ? adjustment.leaveTypeLabel
        : _resolveLeaveTypeLabel(adjustment.leaveType);

    return Card(
      color: Colors.white,
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Color(0xFFD1C4E9), width: 1.2),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                // Badge: Penyesuaian Kuota
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 3.5),
                  decoration: BoxDecoration(
                    color: const Color(0xFFEDE7F6),
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: const Color(0xFFD1C4E9)),
                  ),
                  child: const Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.tune_rounded,
                          size: 12, color: Color(0xFF512DA8)),
                      SizedBox(width: 4),
                      Text(
                        'Perubahan Jatah Cuti',
                        style: TextStyle(
                          color: Color(0xFF512DA8),
                          fontWeight: FontWeight.bold,
                          fontSize: 10.5,
                        ),
                      ),
                    ],
                  ),
                ),
                const Spacer(),
                // Timestamp
                Flexible(
                  child: Text(
                    dateStr,
                    style: TextStyle(
                      color: Colors.grey.shade500,
                      fontSize: 11,
                      fontWeight: FontWeight.w500,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.end,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),

            // Jenis cuti & Badge delta
            Row(
              children: [
                Expanded(
                  child: Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
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
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                // Badge delta
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 3.5),
                  decoration: BoxDecoration(
                    color: isIncrease
                        ? const Color(0xFFE8F5E9)
                        : (isDecrease
                            ? const Color(0xFFFFEBEE)
                            : const Color(0xFFF5F5F5)),
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(
                      color: isIncrease
                          ? const Color(0xFFA5D6A7)
                          : (isDecrease
                              ? const Color(0xFFFFCDD2)
                              : Colors.grey.shade300),
                    ),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        isIncrease
                            ? Icons.arrow_upward_rounded
                            : (isDecrease
                                ? Icons.arrow_downward_rounded
                                : Icons.remove_rounded),
                        size: 13,
                        color: isIncrease
                            ? const Color(0xFF2E7D32)
                            : (isDecrease
                                ? const Color(0xFFC62828)
                                : Colors.grey.shade700),
                      ),
                      const SizedBox(width: 3),
                      Text(
                        isIncrease
                            ? '+${adjustment.difference} hari'
                            : '${adjustment.difference} hari',
                        style: TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.bold,
                          color: isIncrease
                              ? const Color(0xFF2E7D32)
                              : (isDecrease
                                  ? const Color(0xFFC62828)
                                  : Colors.grey.shade700),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),

            // Quota transit: old -> new
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
              decoration: BoxDecoration(
                color: const Color(0xFFF8FAFC),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFFE2E8F0)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.history_toggle_off_rounded,
                      size: 14, color: Color(0xFF64748B)),
                  const SizedBox(width: 6),
                  const Text(
                    'Perubahan Jatah: ',
                    style: TextStyle(
                      fontSize: 11.5,
                      color: Color(0xFF64748B),
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                  Text(
                    '${adjustment.oldQuota} hr',
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: Color(0xFF475569),
                    ),
                  ),
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 6),
                    child: Icon(Icons.arrow_forward_rounded,
                        size: 12, color: Color(0xFF94A3B8)),
                  ),
                  Text(
                    '${adjustment.newQuota} hr',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                      color: isIncrease
                          ? const Color(0xFF16A34A)
                          : (isDecrease
                              ? const Color(0xFFDC2626)
                              : const Color(0xFF0F172A)),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 8),

            // Keterangan siapa yang mengubah dan alasan
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.person_outline,
                    size: 14, color: Colors.blueGrey.shade600),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    'Disesuaikan oleh ${adjustment.adjustedByName} (Tahun ${adjustment.year})',
                    style: TextStyle(
                      fontSize: 11.5,
                      color: Colors.blueGrey.shade800,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
              ],
            ),
            if (adjustment.reason != null &&
                adjustment.reason!.trim().isNotEmpty) ...[
              const SizedBox(height: 4),
              Padding(
                padding: const EdgeInsets.only(left: 18),
                child: Text(
                  'Catatan: "${adjustment.reason!.trim()}"',
                  style: TextStyle(
                    fontSize: 11,
                    fontStyle: FontStyle.italic,
                    color: Colors.grey.shade600,
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  static String _monthName(int m) {
    const months = [
      '',
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'Mei',
      'Jun',
      'Jul',
      'Agu',
      'Sep',
      'Okt',
      'Nov',
      'Des'
    ];
    return m >= 1 && m <= 12 ? months[m] : '';
  }
}

// ─── Tab Saldo Cuti ───────────────────────────────────────────
// ─── Tab Saldo Cuti ───────────────────────────────────────────
class _SaldoCutiTab extends StatelessWidget {
  const _SaldoCutiTab();

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<PresensiProvider>(context);
    final balances = prov.leaveBalances;

    // Pisahkan yang aktif/tersedia dengan yang dinonaktifkan di kantor
    final activeBalances = balances.where((b) => !b.isDisabled).toList();
    final disabledBalances = balances.where((b) => b.isDisabled).toList();

    return RefreshIndicator(
      onRefresh: () async {
        final prov = Provider.of<PresensiProvider>(context, listen: false);
        await Future.wait([
          prov.fetchLeaveRequests(forceRefresh: true),
          prov.fetchLeaveBalance(forceRefresh: true),
        ]);
      },
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
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
              'Informasi kuota cuti, izin, dan hak istirahat yang tersedia untuk Anda.',
              style: TextStyle(fontSize: 12, color: Color(0xFF546E7A)),
            ),
            const SizedBox(height: 16),
            if (prov.loadingBalance && balances.isEmpty)
              const ShimmerLoading(
                child: Column(
                  children: [SkeletonLeaveCard(), SkeletonLeaveCard()],
                ),
              )
            else if (balances.isEmpty)
              const Center(
                child: Padding(
                  padding: EdgeInsets.symmetric(vertical: 40),
                  child: Text(
                    'Tidak ada data saldo cuti.',
                    style: TextStyle(color: Colors.grey, fontSize: 13),
                  ),
                ),
              )
            else ...[
              // Daftar jenis cuti aktif & tersedia
              ...activeBalances.map((b) => _BalanceCard(balance: b)),

              // Bagian jenis cuti non-aktif di kantor (jika ada)
              if (disabledBalances.isNotEmpty) ...[
                const SizedBox(height: 12),
                Row(
                  children: [
                    const Icon(Icons.info_outline, size: 14, color: Color(0xFF78909C)),
                    const SizedBox(width: 6),
                    Text(
                      'Jenis Cuti Nonaktif di Kantor (${disabledBalances.length})',
                      style: const TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFF78909C),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                ...disabledBalances.map((b) => _BalanceCard(balance: b)),
              ],
            ],
            const SizedBox(height: 16),
            if (prov.leaveResetPolicy != null) ...[
              _LeavePolicyCard(policy: prov.leaveResetPolicy!),
            ] else if (prov.leaveResetInfo != null &&
                (prov.leaveResetInfo!['leave_reset_date'] != null ||
                    prov.leaveResetInfo!['default_leave_quota'] != null)) ...[
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
                      child: Text(
                        'Saldo cuti tahunan akan di-reset menjadi ${prov.leaveResetInfo!['default_leave_quota'] ?? 12} hari pada setiap tanggal ${formatResetDate(prov.leaveResetInfo!['leave_reset_date']?.toString())}.',
                        style: const TextStyle(
                          fontSize: 12,
                          color: Color(0xFFBF360C),
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
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
                  Icon(Icons.info_outline, color: Color(0xFF1565C0), size: 18),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Saldo cuti dipotong otomatis saat pengajuan disetujui HRD. Izin tidak memotong saldo cuti tahunan.',
                      style: TextStyle(fontSize: 12, color: Color(0xFF0D47A1)),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 40),
          ],
        ),
      ),
    );
  }
}

class _BalanceCard extends StatelessWidget {
  final LeaveBalanceRecord balance;
  const _BalanceCard({required this.balance});

  (Color, IconData) _styleForType(String type) {
    switch (type) {
      case 'cuti':
        return (const Color(0xFF00695C), Icons.beach_access_outlined);
      case 'izin':
        return (const Color(0xFF7B1FA2), Icons.event_busy_outlined);
      case 'sakit':
        return (const Color(0xFFE65100), Icons.local_hospital_outlined);
      case 'cuti_hamil':
        return (const Color(0xFFC2185B), Icons.pregnant_woman_outlined);
      case 'cuti_keguguran':
        return (const Color(0xFFAD1457), Icons.healing_outlined);
      case 'cuti_ayah':
        return (const Color(0xFF1565C0), Icons.family_restroom_outlined);
      case 'cuti_haid':
        return (const Color(0xFFD81B60), Icons.water_drop_outlined);
      case 'cuti_menikah':
        return (const Color(0xFF6A1B9A), Icons.favorite_outline);
      case 'cuti_menikahkan_anak':
        return (const Color(0xFF4A148C), Icons.celebration_outlined);
      case 'cuti_khitan_baptis_anak':
        return (const Color(0xFF00838F), Icons.child_care_outlined);
      case 'cuti_duka_keluarga_inti':
      case 'cuti_duka_serumah':
        return (const Color(0xFF37474F), Icons.sentiment_very_dissatisfied_outlined);
      case 'cuti_ibadah_haji_umrah':
        return (const Color(0xFF2E7D32), Icons.flight_takeoff_outlined);
      case 'cuti_setengah_hari':
        return (const Color(0xFF0277BD), Icons.timelapse_outlined);
      default:
        return (const Color(0xFF455A64), Icons.assignment_outlined);
    }
  }

  @override
  Widget build(BuildContext context) {
    final style = _styleForType(balance.leaveType);
    final color = balance.isDisabled ? const Color(0xFF78909C) : style.$1;
    final icon = style.$2;
    final label = balance.leaveTypeLabel.isNotEmpty
        ? balance.leaveTypeLabel
        : (balance.leaveType == 'cuti'
            ? 'Cuti Tahunan'
            : (balance.leaveType == 'izin' ? 'Izin' : balance.leaveType));

    return Card(
      color: balance.isDisabled ? const Color(0xFFFAFAFA) : Colors.white,
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: balance.isDisabled ? Colors.grey.shade300 : Colors.grey.shade200,
        ),
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
                Expanded(
                  child: Text(
                    label,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 14,
                      color: balance.isDisabled ? const Color(0xFF616161) : Colors.black87,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                const SizedBox(width: 8),
                if (balance.isDisabled)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: const Color(0xFFECEFF1),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: const Color(0xFFCFD8DC)),
                    ),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.lock_outline, size: 12, color: Color(0xFF78909C)),
                        SizedBox(width: 4),
                        Text(
                          'Nonaktif',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF546E7A),
                          ),
                        ),
                      ],
                    ),
                  )
                else if (balance.isUnlimited)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF3E5F5),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: const Color(0xFFE1BEE7)),
                    ),
                    child: const Text(
                      'Tanpa Batas',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF7B1FA2),
                      ),
                    ),
                  )
                else if (balance.active && balance.quota > 0)
                  // Angka besar (warna biru) = Sisa Alokasi Live Karyawan (berkurang otomatis saat cuti terpakai / berubah saat alokasi HRD diubah)
                  // Angka kecil (warna merah) = Standar Kantor (dari Pengaturan Jenis Cuti Kantor)
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    mainAxisSize: MainAxisSize.min,
                    children: [
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
                              style: TextStyle(fontSize: 14, color: Colors.grey),
                            ),
                            TextSpan(
                              text: '${balance.officeQuota} hari',
                              style: const TextStyle(
                                fontSize: 13,
                                color: Color(0xFF455A64),
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                      const Text(
                        'Sisa / Standar kantor',
                        style: TextStyle(fontSize: 9.5, color: Color(0xFF90A4AE)),
                      ),
                    ],
                  )
                else
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: const Color(0xFFECEFF1),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: const Color(0xFFCFD8DC)),
                    ),
                    child: const Text(
                      'Belum Aktif',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF546E7A),
                      ),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            if (balance.isDisabled) ...[
              const Text(
                'Jenis cuti ini sedang dinonaktifkan oleh pengaturan kantor Anda.',
                style: TextStyle(fontSize: 12, color: Color(0xFF78909C)),
              ),
              if (balance.used > 0) ...[
                const SizedBox(height: 4),
                Text(
                  'Riwayat terpakai tahun ini: ${balance.used} hari',
                  style: const TextStyle(fontSize: 11.5, color: Color(0xFF546E7A)),
                ),
              ],
            ] else if (balance.isUnlimited) ...[
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Terpakai tahun ini: ${balance.used} hari',
                    style: const TextStyle(
                      fontSize: 12,
                      color: Color(0xFF546E7A),
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                  const Text(
                    'Status: Aktif',
                    style: TextStyle(
                      fontSize: 12,
                      color: Color(0xFF7B1FA2),
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ],
              ),
            ] else ...[
              if (balance.active && balance.quota > 0) ...[
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
                      balance.quota != balance.officeQuota
                          ? 'Terpakai: ${balance.used} hr (Alokasi: ${balance.quota} hr)'
                          : 'Terpakai: ${balance.used} hari',
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
                if (balance.leaveType == 'cuti') ...[
                  const SizedBox(height: 10),
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF1F8E9),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: const Color(0xFFDCEDC8)),
                    ),
                    child: Column(
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text(
                              'Kuota Periode Berjalan',
                              style: TextStyle(
                                fontSize: 11.5,
                                color: Color(0xFF33691E),
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                            Text(
                              '${balance.currentPeriodQuota} hari',
                              style: const TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF33691E),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 4),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Row(
                              children: [
                                const Text(
                                  'Cuti Bawaan Periode Lalu',
                                  style: TextStyle(
                                    fontSize: 11.5,
                                    color: Color(0xFFE65100),
                                    fontWeight: FontWeight.w500,
                                  ),
                                ),
                                if (balance.carriedOverQuota > 0) ...[
                                  const SizedBox(width: 4),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                                    decoration: BoxDecoration(
                                      color: const Color(0xFFFFE0B2),
                                      borderRadius: BorderRadius.circular(4),
                                    ),
                                    child: const Text(
                                      'Carry-Over',
                                      style: TextStyle(
                                        fontSize: 9,
                                        fontWeight: FontWeight.bold,
                                        color: Color(0xFFBF360C),
                                      ),
                                    ),
                                  ),
                                ],
                              ],
                            ),
                            Text(
                              '${balance.carriedOverQuota} hari',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: balance.carriedOverQuota > 0
                                    ? const Color(0xFFE65100)
                                    : const Color(0xFF757575),
                              ),
                            ),
                          ],
                        ),
                        const Divider(height: 10, thickness: 0.8, color: Color(0xFFDCEDC8)),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text(
                              'Total Kuota Aktif',
                              style: TextStyle(
                                fontSize: 11.5,
                                fontWeight: FontWeight.w600,
                                color: Color(0xFF2E7D32),
                              ),
                            ),
                            Text(
                              '${balance.quota} hari',
                              style: const TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF2E7D32),
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  if (balance.carriedOverQuota > 0) ...[
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.all(9),
                      decoration: BoxDecoration(
                        color: const Color(0xFFFFF3E0),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: const Color(0xFFFFCC80)),
                      ),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Icon(Icons.warning_amber_rounded, color: Color(0xFFE65100), size: 16),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  '${balance.carriedOverQuota} hari cuti dibawa dari periode sebelumnya.',
                                  style: const TextStyle(
                                    fontSize: 11.5,
                                    fontWeight: FontWeight.w600,
                                    color: Color(0xFFBF360C),
                                  ),
                                ),
                                if (balance.carryOverExpiredAt != null) ...[
                                  const SizedBox(height: 3),
                                  Text(
                                    '⏰ Pakai sebelum ${formatDateIndonesian(balance.carryOverExpiredAt)} agar tidak hangus / diproses sesuai kebijakan!',
                                    style: const TextStyle(
                                      fontSize: 11,
                                      fontWeight: FontWeight.bold,
                                      color: Color(0xFFD84315),
                                    ),
                                  ),
                                ] else ...[
                                  const SizedBox(height: 2),
                                  const Text(
                                    'Hari bawaan berlaku sepanjang periode berjalan tanpa batas kedaluwarsa khusus.',
                                    style: TextStyle(fontSize: 11, color: Color(0xFF6D4C41)),
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ],
              ] else ...[
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      balance.used > 0
                          ? 'Terpakai: ${balance.used} hari'
                          : 'Belum dialokasikan kuota oleh HRD',
                      style: const TextStyle(fontSize: 12, color: Colors.grey),
                    ),
                    const Text(
                      'Status: Non-Aktif',
                      style: TextStyle(
                        fontSize: 12,
                        color: Color(0xFF78909C),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }
}

class _LeavePolicyCard extends StatelessWidget {
  final LeaveResetInfo policy;

  const _LeavePolicyCard({required this.policy});

  String _expiryMonthsLabel(int? months) {
    if (months == null) {
      return 'Tidak pernah kedaluwarsa';
    } else if (months == 0) {
      return '0 bulan (diputuskan di tanggal reset)';
    } else {
      return '$months bulan setelah tanggal reset';
    }
  }

  String _encashmentTimingLabel(CarryOverPolicy carryOver) {
    final months = carryOver.expiryMonths;
    if (months == 0) {
      return 'Tepat pada tanggal reset saldo cuti';
    } else if (months == null) {
      return 'Sesuai ketentuan kebijakan kantor';
    } else {
      return 'Saat masa berlaku $months bulan berakhir (untuk hari bawaan tersisa)';
    }
  }

  @override
  Widget build(BuildContext context) {
    final carry = policy.carryOver;
    final encash = policy.encashment;
    final resetDateStr = formatResetDate(policy.leaveResetDate);
    final hasAnyPolicy = carry.enabled || encash.enabled;

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(color: Colors.blueGrey.shade100),
      ),
      color: Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Header
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(7),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE0F2F1),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(
                    Icons.policy_outlined,
                    color: Color(0xFF00695C),
                    size: 20,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Kebijakan Sisa Cuti Tahunan',
                        style: TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF263238),
                        ),
                      ),
                      const SizedBox(height: 1),
                      Text(
                        'Reset tiap $resetDateStr • Kuota standar: ${policy.defaultLeaveQuota} hari',
                        style: const TextStyle(
                          fontSize: 11.5,
                          color: Color(0xFF546E7A),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),

            // 1. Kalimat Kebijakan Resmi dari Backend (WAJIB APA ADANYA)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFF4FBF7),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: const Color(0xFFB2DFDB)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: const Color(0xFF00796B),
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: const Text(
                          'Ringkasan Kebijakan Kantor',
                          style: TextStyle(
                            fontSize: 10,
                            fontWeight: FontWeight.bold,
                            color: Colors.white,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    policy.remainingLeavePolicyLabel.isNotEmpty
                        ? policy.remainingLeavePolicyLabel
                        : (hasAnyPolicy
                            ? 'Ketentuan carry over dan pencairan cuti berlaku sesuai pengaturan kantor.'
                            : 'Sisa cuti yang tidak digunakan akan hangus pada tanggal reset saldo cuti.'),
                    style: const TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: Color(0xFF004D40),
                      height: 1.4,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 14),

            // 2. Angka Pendukung & Parameter Teknis
            const Text(
              'Parameter Kebijakan Kantor',
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.bold,
                color: Color(0xFF455A64),
              ),
            ),
            const SizedBox(height: 8),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Box Carry-Over
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: carry.enabled ? const Color(0xFFE8F5E9) : const Color(0xFFF5F5F5),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                        color: carry.enabled ? const Color(0xFFA5D6A7) : const Color(0xFFE0E0E0),
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Icon(
                              Icons.redo_outlined,
                              size: 14,
                              color: carry.enabled ? const Color(0xFF2E7D32) : const Color(0xFF757575),
                            ),
                            const SizedBox(width: 4),
                            Expanded(
                              child: Text(
                                'Carry-Over',
                                style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.bold,
                                  color: carry.enabled ? const Color(0xFF2E7D32) : const Color(0xFF757575),
                                ),
                              ),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
                              decoration: BoxDecoration(
                                color: carry.enabled ? const Color(0xFFC8E6C9) : const Color(0xFFEEEEEE),
                                borderRadius: BorderRadius.circular(3),
                              ),
                              child: Text(
                                carry.enabled ? 'Aktif' : 'Nonaktif',
                                style: TextStyle(
                                  fontSize: 9,
                                  fontWeight: FontWeight.bold,
                                  color: carry.enabled ? const Color(0xFF1B5E20) : const Color(0xFF757575),
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        if (carry.enabled) ...[
                          Text(
                            'Maks. Dibawa: ${carry.maxDays} hari',
                            style: const TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.w600,
                              color: Color(0xFF1B5E20),
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            'Masa Berlaku: ${_expiryMonthsLabel(carry.expiryMonths)}',
                            style: const TextStyle(
                              fontSize: 10,
                              color: Color(0xFF33691E),
                            ),
                          ),
                        ] else ...[
                          const Text(
                            'Sisa cuti tidak dapat dialihkan ke periode baru.',
                            style: TextStyle(fontSize: 10, color: Color(0xFF757575)),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                // Box Pencairan ke Uang
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: encash.enabled ? const Color(0xFFFFF8E1) : const Color(0xFFF5F5F5),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                        color: encash.enabled ? const Color(0xFFFFE082) : const Color(0xFFE0E0E0),
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Icon(
                              Icons.payments_outlined,
                              size: 14,
                              color: encash.enabled ? const Color(0xFFE65100) : const Color(0xFF757575),
                            ),
                            const SizedBox(width: 4),
                            Expanded(
                              child: Text(
                                'Pencairan Uang',
                                style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.bold,
                                  color: encash.enabled ? const Color(0xFFE65100) : const Color(0xFF757575),
                                ),
                              ),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
                              decoration: BoxDecoration(
                                color: encash.enabled ? const Color(0xFFFFECB3) : const Color(0xFFEEEEEE),
                                borderRadius: BorderRadius.circular(3),
                              ),
                              child: Text(
                                encash.enabled ? 'Aktif' : 'Nonaktif',
                                style: TextStyle(
                                  fontSize: 9,
                                  fontWeight: FontWeight.bold,
                                  color: encash.enabled ? const Color(0xFFBF360C) : const Color(0xFF757575),
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        if (encash.enabled) ...[
                          Text(
                            formatCurrency(encash.ratePerDay),
                            style: const TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.w600,
                              color: Color(0xFFBF360C),
                            ),
                          ),
                          const Text(
                            '/ hari kerja',
                            style: TextStyle(fontSize: 9.5, color: Color(0xFF8D6E63)),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            encash.maxDays != null
                                ? 'Pagu: ${encash.maxDays} hari'
                                : 'Pagu: Tanpa batas',
                            style: const TextStyle(
                              fontSize: 10,
                              color: Color(0xFFE65100),
                            ),
                          ),
                        ] else ...[
                          const Text(
                            'Sisa cuti tidak dapat dikonversi ke uang tunai.',
                            style: TextStyle(fontSize: 10, color: Color(0xFF757575)),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),

            // 3. Hal yang Karyawan Perlu Tahu (Aturan 4 & 5)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: !hasAnyPolicy ? const Color(0xFFFFF3E0) : const Color(0xFFE8EAF6),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(
                  color: !hasAnyPolicy ? const Color(0xFFFFCC80) : const Color(0xFFC5CAE9),
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        !hasAnyPolicy ? Icons.warning_amber_rounded : Icons.info_outline,
                        size: 15,
                        color: !hasAnyPolicy ? const Color(0xFFD84315) : const Color(0xFF283593),
                      ),
                      const SizedBox(width: 6),
                      Text(
                        'Penting Dipahami Karyawan:',
                        style: TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.bold,
                          color: !hasAnyPolicy ? const Color(0xFFD84315) : const Color(0xFF283593),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  if (!hasAnyPolicy) ...[
                    // Aturan 5: carry OFF dan encashment OFF
                    const Text(
                      '• Kantor tidak menerapkan carry-over maupun pencairan uang.\n'
                      '• Seluruh sisa cuti tahunan yang belum terpakai akan hangus secara permanen pada tanggal reset saldo cuti.',
                      style: TextStyle(
                        fontSize: 11,
                        color: Color(0xFFBF360C),
                        height: 1.4,
                      ),
                    ),
                  ] else ...[
                    // Aturan 4: Hari cuti di ATAS batas carry-over HANGUS PERMANEN dan TIDAK PERNAH jadi uang
                    Text(
                      '• Hari cuti di atas batas carry-over (${carry.maxDays} hari) HANGUS PERMANEN pada tanggal reset dan TIDAK PERNAH menjadi uang.',
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFF1A237E),
                        height: 1.35,
                      ),
                    ),
                    const SizedBox(height: 3),
                    if (encash.enabled) ...[
                      Text(
                        '• Hanya hari bawaan (maksimal ${carry.maxDays} hari) yang berhak dicairkan menjadi uang.\n'
                        '• Pencairan dilakukan saat masa berlaku hari bawaan berakhir: ${_encashmentTimingLabel(carry)}.',
                        style: const TextStyle(
                          fontSize: 11,
                          color: Color(0xFF283593),
                          height: 1.35,
                        ),
                      ),
                    ] else ...[
                      Text(
                        '• Hari bawaan (maksimal ${carry.maxDays} hari) wajib digunakan dalam masa berlakunya. Hari bawaan yang tidak sempat terpakai akan hangus tanpa pencairan uang.',
                        style: const TextStyle(
                          fontSize: 11,
                          color: Color(0xFF283593),
                          height: 1.35,
                        ),
                      ),
                    ],
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

