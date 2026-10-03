import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/spv_leave_provider.dart';
import '../utils.dart';

class SpvLeaveApprovalScreen extends StatefulWidget {
  const SpvLeaveApprovalScreen({super.key});

  @override
  State<SpvLeaveApprovalScreen> createState() => _SpvLeaveApprovalScreenState();
}

class _SpvLeaveApprovalScreenState extends State<SpvLeaveApprovalScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  final List<String> _statuses = ['pending', 'approved', 'rejected'];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _tabController.addListener(_onTabChanged);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadData();
    });
  }

  @override
  void dispose() {
    _tabController.removeListener(_onTabChanged);
    _tabController.dispose();
    super.dispose();
  }

  void _onTabChanged() {
    if (_tabController.indexIsChanging) return;
    _loadData();
  }

  Future<void> _loadData() async {
    final status = _statuses[_tabController.index];
    final provider = Provider.of<SpvLeaveProvider>(context, listen: false);
    await provider.fetchApprovals(status: status, forceRefresh: true);
    await provider.fetchPendingCount(forceRefresh: true);
  }

  void _showApproveDialog(SpvLeaveItem item) {
    final notesController = TextEditingController();

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        return Container(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
            left: 20,
            right: 20,
            top: 20,
          ),
          decoration: const BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
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
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: const BoxDecoration(
                      color: Color(0xFFECFDF5),
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(Icons.check_circle_rounded, color: Color(0xFF059669), size: 24),
                  ),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Setujui Pengajuan (Tahap 1: SPV)',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
                        ),
                        Text(
                          'Pengajuan akan diteruskan ke HRD untuk finalisasi kuota',
                          style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFF8FAFC),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xFFE2E8F0)),
                ),
                child: Column(
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('Karyawan:', style: TextStyle(fontSize: 12.5, color: Color(0xFF64748B))),
                        Text(item.userName, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Color(0xFF0F172A))),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('Jenis & Durasi:', style: TextStyle(fontSize: 12.5, color: Color(0xFF64748B))),
                        Text(
                          '${item.leaveType.toUpperCase()} • ${item.totalDays} Hari',
                          style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Color(0xFF0284C7)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('Tanggal:', style: TextStyle(fontSize: 12.5, color: Color(0xFF64748B))),
                        Text(
                          formatDateIndonesianRange(item.startDate, item.endDate),
                          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Color(0xFF334155)),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              const Text(
                'Catatan Supervisor (Opsional)',
                style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Color(0xFF334155)),
              ),
              const SizedBox(height: 6),
              TextField(
                controller: notesController,
                maxLines: 2,
                decoration: InputDecoration(
                  hintText: 'Misal: Pekerjaan telah di-handover, disetujui...',
                  hintStyle: TextStyle(fontSize: 12.5, color: Colors.grey.shade400),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFCBD5E1))),
                  enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFCBD5E1))),
                  focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFF059669), width: 1.5)),
                ),
              ),
              const SizedBox(height: 20),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => Navigator.pop(ctx),
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        side: const BorderSide(color: Color(0xFFCBD5E1)),
                      ),
                      child: const Text('Batal', style: TextStyle(color: Color(0xFF64748B))),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    flex: 2,
                    child: Consumer<SpvLeaveProvider>(
                      builder: (consumerContext, prov, _) {
                        return ElevatedButton(
                          onPressed: prov.isActionLoading
                              ? null
                              : () async {
                                  Navigator.pop(ctx);
                                  final success = await prov.approve(item.id, notes: notesController.text.trim());
                                  if (!mounted) return;
                                  if (success) {
                                    if (_tabController.index == 1) {
                                      _loadData();
                                    } else {
                                      _tabController.animateTo(1);
                                    }
                                    ScaffoldMessenger.of(context).showSnackBar(
                                      SnackBar(
                                        content: Text('Pengajuan ${item.userName} berhasil disetujui (Tahap 1: SPV). Masuk ke riwayat disetujui.'),
                                        backgroundColor: const Color(0xFF059669),
                                      ),
                                    );
                                  } else {
                                    ScaffoldMessenger.of(context).showSnackBar(
                                      SnackBar(
                                        content: Text(prov.errorMessage ?? 'Gagal memproses persetujuan.'),
                                        backgroundColor: Colors.red,
                                      ),
                                    );
                                  }
                                },
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF059669),
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            elevation: 0,
                          ),
                          child: prov.isActionLoading
                              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                              : const Text('Konfirmasi Setujui', style: TextStyle(fontWeight: FontWeight.bold)),
                        );
                      },
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }

  void _showRejectDialog(SpvLeaveItem item) {
    final reasonController = TextEditingController();
    String? localError;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (modalCtx, setModalState) {
            return Container(
              padding: EdgeInsets.only(
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
                left: 20,
                right: 20,
                top: 20,
              ),
              decoration: const BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
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
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: const BoxDecoration(
                          color: Color(0xFFFEF2F2),
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.cancel_rounded, color: Color(0xFFDC2626), size: 24),
                      ),
                      const SizedBox(width: 12),
                      const Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Tolak Pengajuan (SPV)',
                              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
                            ),
                            Text(
                              'Berikan alasan penolakan yang jelas untuk karyawan',
                              style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF8FAFC),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFFE2E8F0)),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('Karyawan:', style: TextStyle(fontSize: 12.5, color: Color(0xFF64748B))),
                        Text(item.userName, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Color(0xFF0F172A))),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  const Text(
                    'Alasan Penolakan (Wajib)',
                    style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Color(0xFF334155)),
                  ),
                  const SizedBox(height: 6),
                  TextField(
                    controller: reasonController,
                    maxLines: 3,
                    decoration: InputDecoration(
                      hintText: 'Misal: Tanggal tersebut bentrok dengan deadline audit tim...',
                      hintStyle: TextStyle(fontSize: 12.5, color: Colors.grey.shade400),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                      errorText: localError,
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFCBD5E1))),
                      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFCBD5E1))),
                      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFDC2626), width: 1.5)),
                    ),
                  ),
                  const SizedBox(height: 20),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => Navigator.pop(ctx),
                          style: OutlinedButton.styleFrom(
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            side: const BorderSide(color: Color(0xFFCBD5E1)),
                          ),
                          child: const Text('Batal', style: TextStyle(color: Color(0xFF64748B))),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        flex: 2,
                        child: Consumer<SpvLeaveProvider>(
                          builder: (consumerContext, prov, _) {
                            return ElevatedButton(
                              onPressed: prov.isActionLoading
                                  ? null
                                  : () async {
                                      final reason = reasonController.text.trim();
                                      if (reason.isEmpty) {
                                        setModalState(() {
                                          localError = 'Alasan penolakan tidak boleh kosong';
                                        });
                                        return;
                                      }
                                      Navigator.pop(ctx);
                                      final success = await prov.reject(item.id, reason: reason);
                                      if (!mounted) return;
                                      if (success) {
                                        if (_tabController.index == 2) {
                                          _loadData();
                                        } else {
                                          _tabController.animateTo(2);
                                        }
                                        ScaffoldMessenger.of(context).showSnackBar(
                                          SnackBar(
                                            content: Text('Pengajuan ${item.userName} telah ditolak. Masuk ke riwayat ditolak.'),
                                            backgroundColor: const Color(0xFFDC2626),
                                          ),
                                        );
                                      } else {
                                        ScaffoldMessenger.of(context).showSnackBar(
                                          SnackBar(
                                            content: Text(prov.errorMessage ?? 'Gagal memproses penolakan.'),
                                            backgroundColor: Colors.red,
                                          ),
                                        );
                                      }
                                    },
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFFDC2626),
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(vertical: 12),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                elevation: 0,
                              ),
                              child: prov.isActionLoading
                                  ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                                  : const Text('Tolak Pengajuan', style: TextStyle(fontWeight: FontWeight.bold)),
                            );
                          },
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      appBar: AppBar(
        title: const Text('Persetujuan Izin & Cuti Tim'),
        elevation: 0,
        backgroundColor: const Color(0xFF0284C7),
        foregroundColor: Colors.white,
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: Colors.white,
          indicatorWeight: 3,
          labelColor: Colors.white,
          unselectedLabelColor: const Color(0xFFBAE6FD),
          labelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
          tabs: [
            Consumer<SpvLeaveProvider>(
              builder: (tabContext, prov, child) {
                return Tab(
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Text('Menunggu'),
                      if (prov.pendingCount > 0) ...[
                        const SizedBox(width: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: const Color(0xFFE53935),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Text(
                            '${prov.pendingCount}',
                            style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                          ),
                        ),
                      ],
                    ],
                  ),
                );
              },
            ),
            const Tab(text: 'Disetujui'),
            const Tab(text: 'Ditolak'),
          ],
        ),
      ),
      body: Consumer<SpvLeaveProvider>(
        builder: (context, provider, _) {
          if (provider.isLoading) {
            return const Center(child: CircularProgressIndicator());
          }

          if (provider.errorMessage != null) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.error_outline_rounded, color: Colors.red, size: 48),
                    const SizedBox(height: 12),
                    Text(
                      provider.errorMessage!,
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: Color(0xFF475569)),
                    ),
                    const SizedBox(height: 16),
                    ElevatedButton(
                      onPressed: _loadData,
                      child: const Text('Coba Lagi'),
                    ),
                  ],
                ),
              ),
            );
          }

          final list = provider.approvals;
          if (list.isEmpty) {
            final isPendingTab = _tabController.index == 0;
            return RefreshIndicator(
              onRefresh: _loadData,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  SizedBox(height: MediaQuery.of(context).size.height * 0.2),
                  Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 80,
                          height: 80,
                          decoration: BoxDecoration(
                            color: isPendingTab ? const Color(0xFFECFDF5) : const Color(0xFFF1F5F9),
                            shape: BoxShape.circle,
                          ),
                          child: Icon(
                            isPendingTab ? Icons.done_all_rounded : Icons.inbox_outlined,
                            size: 40,
                            color: isPendingTab ? const Color(0xFF059669) : Colors.grey.shade400,
                          ),
                        ),
                        const SizedBox(height: 16),
                        Text(
                          isPendingTab ? 'Semua pengajuan telah ditinjau!' : 'Belum ada data izin/cuti di tab ini',
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Color(0xFF334155)),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          isPendingTab
                              ? 'Tidak ada pengajuan izin/cuti bawahan yang pending.'
                              : 'Pengajuan akan muncul setelah diproses.',
                          style: TextStyle(fontSize: 12.5, color: Colors.grey.shade500),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            );
          }

          return RefreshIndicator(
            onRefresh: _loadData,
            child: ListView.separated(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              itemCount: list.length,
              separatorBuilder: (context, index) => const SizedBox(height: 12),
              itemBuilder: (context, index) {
                final item = list[index];
                return _buildLeaveCard(item);
              },
            ),
          );
        },
      ),
    );
  }

  Widget _buildLeaveCard(SpvLeaveItem item) {
    Color statusBadgeBg;
    Color statusBadgeText;
    String statusBadgeLabel;

    final bool isApprovedBySpv = item.spvApprovedAt != null || item.currentStep == 'hrd';
    final bool isPendingForSpv = item.status == 'pending' && !isApprovedBySpv && item.currentStep == 'spv';

    if (item.status == 'approved') {
      statusBadgeBg = const Color(0xFFECFDF5);
      statusBadgeText = const Color(0xFF059669);
      statusBadgeLabel = 'Disetujui Final';
    } else if (item.status == 'rejected') {
      statusBadgeBg = const Color(0xFFFEF2F2);
      statusBadgeText = const Color(0xFFDC2626);
      statusBadgeLabel = 'Ditolak';
    } else if (isApprovedBySpv) {
      statusBadgeBg = const Color(0xFFECFDF5);
      statusBadgeText = const Color(0xFF059669);
      statusBadgeLabel = 'Disetujui SPV (Ke HRD)';
    } else {
      statusBadgeBg = const Color(0xFFFFFBEB);
      statusBadgeText = const Color(0xFFD97706);
      statusBadgeLabel = 'Menunggu SPV';
    }

    Color typeColor;
    String typeLabel = item.leaveType.toUpperCase();
    switch (item.leaveType) {
      case 'cuti':
        typeColor = const Color(0xFF2563EB); // Blue
        break;
      case 'izin':
        typeColor = const Color(0xFFD97706); // Amber
        break;
      case 'sakit':
        typeColor = const Color(0xFFDC2626); // Red
        break;
      case 'wfh':
        typeColor = const Color(0xFF7C3AED); // Purple
        break;
      case 'cuti_setengah_hari':
        typeColor = const Color(0xFF0284C7); // Sky blue
        typeLabel = item.halfDaySession == 'morning'
            ? 'CUTI SETENGAH HARI (SESI 1)'
            : item.halfDaySession == 'afternoon'
                ? 'CUTI SETENGAH HARI (SESI 2)'
                : 'CUTI SETENGAH HARI';
        break;
      default:
        typeColor = const Color(0xFF0D9488); // Teal
    }

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.03),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header Karyawan & Status Badge
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: typeColor.withValues(alpha: 0.1),
                  shape: BoxShape.circle,
                ),
                child: Center(
                  child: Text(
                    item.userName.isNotEmpty ? item.userName.substring(0, 1).toUpperCase() : 'K',
                    style: TextStyle(fontWeight: FontWeight.bold, color: typeColor, fontSize: 16),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.userName,
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${item.divisionName} • ${item.positionName}',
                      style: const TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: typeColor.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: typeColor.withValues(alpha: 0.2)),
                    ),
                    child: Text(
                      typeLabel,
                      style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.bold, color: typeColor),
                    ),
                  ),
                  const SizedBox(height: 4),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: statusBadgeBg,
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      statusBadgeLabel,
                      style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.bold, color: statusBadgeText),
                    ),
                  ),
                ],
              ),
            ],
          ),

          const SizedBox(height: 14),

          // Detail Tanggal & Total Hari
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFF8FAFC),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Periode Tanggal', style: TextStyle(fontSize: 11, color: Color(0xFF94A3B8))),
                      const SizedBox(height: 2),
                      Text(
                        formatDateIndonesianRange(item.startDate, item.endDate),
                        style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: Color(0xFF1E293B)),
                      ),
                    ],
                  ),
                ),
                Container(height: 28, width: 1, color: const Color(0xFFE2E8F0)),
                const SizedBox(width: 12),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Total Durasi', style: TextStyle(fontSize: 11, color: Color(0xFF94A3B8))),
                    const SizedBox(height: 2),
                    Text(
                      '${item.totalDays} Hari',
                      style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: typeColor),
                    ),
                  ],
                ),
              ],
            ),
          ),

          const SizedBox(height: 12),

          // Alasan / Keterangan
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.description_outlined, size: 16, color: Color(0xFF64748B)),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  item.reason,
                  style: const TextStyle(fontSize: 12.5, color: Color(0xFF334155), height: 1.3),
                ),
              ),
            ],
          ),

          // Catatan SPV jika ada
          if (item.spvNotes != null && item.spvNotes!.isNotEmpty) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(
                color: const Color(0xFFF0FDF4),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(
                children: [
                  const Icon(Icons.comment_outlined, size: 14, color: Color(0xFF16A34A)),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      'Catatan SPV: ${item.spvNotes}',
                      style: const TextStyle(fontSize: 11.5, color: Color(0xFF15803D)),
                    ),
                  ),
                ],
              ),
            ),
          ],

          // Alasan Penolakan jika ditolak
          if (item.rejectionReason != null && item.rejectionReason!.isNotEmpty) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(
                color: const Color(0xFFFEF2F2),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(
                children: [
                  const Icon(Icons.info_outline, size: 14, color: Color(0xFFDC2626)),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      'Alasan Ditolak: ${item.rejectionReason}',
                      style: const TextStyle(fontSize: 11.5, color: Color(0xFFB91C1C)),
                    ),
                  ),
                ],
              ),
            ),
          ],

          // Tombol Tindakan jika masih Menunggu SPV
          if (isPendingForSpv) ...[
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _showRejectDialog(item),
                    icon: const Icon(Icons.close_rounded, size: 18),
                    label: const Text('Tolak'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: const Color(0xFFDC2626),
                      side: const BorderSide(color: Color(0xFFFCA5A5)),
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: () => _showApproveDialog(item),
                    icon: const Icon(Icons.check_rounded, size: 18),
                    label: const Text('Setujui (Lv1)'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF059669),
                      foregroundColor: Colors.white,
                      elevation: 0,
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    ),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
