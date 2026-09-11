import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/expense_report_provider.dart';
import '../providers/receipt_provider.dart';
import '../utils.dart';
import '../widgets/custom_date_range_picker_dialog.dart';
import '../widgets/skeleton.dart';
import 'buat_laporan_dinas_screen.dart';
import 'detail_laporan_dinas_screen.dart';
import 'detail_pengajuan_screen.dart';
import 'submit_step1_screen.dart';

class RiwayatScreen extends StatefulWidget {
  const RiwayatScreen({super.key});

  @override
  State<RiwayatScreen> createState() => _RiwayatScreenState();
}

class _RiwayatScreenState extends State<RiwayatScreen> {
  int _mainTab = 0; // 0: Struk Satuan, 1: Laporan Dinas (Bundling)

  String _filter = 'Semua';
  DateTimeRange? _selectedDateRange;
  static const _filters = ['Semua', 'Menunggu', 'Pending', 'Dibayar', 'Ditolak', 'Draf'];

  String _reportFilter = 'Semua';
  static const _reportFilters = ['Semua', 'Draf', 'Menunggu', 'Disetujui', 'Ditolak'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Provider.of<ReceiptProvider>(context, listen: false).fetchMyReceipts();
      Provider.of<ExpenseReportProvider>(context, listen: false).fetchReports();
    });
  }

  String _formatDate(DateTime d) => formatDateIndonesian(d);

  List<ReceiptRecord> _filtered(List<ReceiptRecord> all) {
    var list = all;
    if (_filter != 'Semua') {
      list = list.where((r) => r.displayStatus == _filter).toList();
    }
    if (_selectedDateRange != null) {
      list = list.where((r) {
        final dStr = r.receiptDate ?? r.ocrRawDate ?? r.createdAt;
        if (dStr.isEmpty) return true;
        final raw = dStr.length >= 10 ? dStr.substring(0, 10) : dStr;
        final parsed = DateTime.tryParse(raw);
        if (parsed == null) return true;
        final start = DateTime(
          _selectedDateRange!.start.year,
          _selectedDateRange!.start.month,
          _selectedDateRange!.start.day,
        );
        final end = DateTime(
          _selectedDateRange!.end.year,
          _selectedDateRange!.end.month,
          _selectedDateRange!.end.day,
          23,
          59,
          59,
        );
        return (parsed.isAfter(start) || parsed.isAtSameMomentAs(start)) &&
            (parsed.isBefore(end) || parsed.isAtSameMomentAs(end));
      }).toList();
    }
    return list;
  }

  Future<void> _pickDateRange() async {
    final now = DateTime.now();
    final picked = await showCustomDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime(now.year + 2),
      initialDateRange: _selectedDateRange ??
          DateTimeRange(
            start: DateTime(now.year, now.month, 1),
            end: DateTime(now.year, now.month, now.day),
          ),
    );
    if (picked != null) {
      setState(() {
        _selectedDateRange = picked;
      });
    }
  }

  void _setDatePreset(String preset) {
    final now = DateTime.now();
    setState(() {
      if (preset == 'bulan_ini') {
        _selectedDateRange = DateTimeRange(
          start: DateTime(now.year, now.month, 1),
          end: DateTime(now.year, now.month, now.day),
        );
      } else if (preset == 'bulan_lalu') {
        final lastMonth = DateTime(now.year, now.month - 1, 1);
        final lastMonthEnd = DateTime(now.year, now.month, 0);
        _selectedDateRange = DateTimeRange(
          start: lastMonth,
          end: lastMonthEnd,
        );
      } else {
        _selectedDateRange = null;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'riwayat_fab',
        onPressed: () {
          final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
          final expProv = Provider.of<ExpenseReportProvider>(context, listen: false);

          if (_mainTab == 0) {
            Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const SubmitStep1Screen()),
            ).then((_) {
              rcpProv.fetchMyReceipts();
            });
          } else {
            Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const BuatLaporanDinasScreen()),
            ).then((_) {
              expProv.fetchReports();
              rcpProv.fetchMyReceipts();
            });
          }
        },
        backgroundColor: const Color(0xFF0088FF),
        foregroundColor: Colors.white,
        icon: Icon(_mainTab == 0 ? Icons.photo_camera : Icons.add_rounded),
        label: Text(
          _mainTab == 0 ? 'Scan Struk' : 'Buat Laporan',
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
      ),
      appBar: AppBar(
        title: const Text('Struk & Laporan'),
        automaticallyImplyLeading: false,
      ),
      body: Column(
        children: [
          // ─── Top Segmented Switcher (Struk Satuan vs Laporan Dinas) ────
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Container(
              height: 44,
              decoration: BoxDecoration(
                color: Colors.grey.shade200,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: GestureDetector(
                      onTap: () => setState(() => _mainTab = 0),
                      child: Container(
                        decoration: BoxDecoration(
                          color: _mainTab == 0 ? Colors.white : Colors.transparent,
                          borderRadius: BorderRadius.circular(8),
                          boxShadow: _mainTab == 0
                              ? [
                                  BoxShadow(
                                    color: Colors.black.withValues(alpha: 0.08),
                                    blurRadius: 4,
                                    offset: const Offset(0, 1),
                                  ),
                                ]
                              : null,
                        ),
                        margin: const EdgeInsets.all(3),
                        child: Center(
                          child: Text(
                            'Struk Satuan',
                            style: TextStyle(
                              fontSize: 13,
                              fontWeight: _mainTab == 0 ? FontWeight.bold : FontWeight.w500,
                              color: _mainTab == 0 ? const Color(0xFF1E88E5) : Colors.grey.shade700,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                  Expanded(
                    child: GestureDetector(
                      onTap: () {
                        setState(() => _mainTab = 1);
                        Provider.of<ExpenseReportProvider>(context, listen: false).fetchReports();
                      },
                      child: Container(
                        decoration: BoxDecoration(
                          color: _mainTab == 1 ? Colors.white : Colors.transparent,
                          borderRadius: BorderRadius.circular(8),
                          boxShadow: _mainTab == 1
                              ? [
                                  BoxShadow(
                                    color: Colors.black.withValues(alpha: 0.08),
                                    blurRadius: 4,
                                    offset: const Offset(0, 1),
                                  ),
                                ]
                              : null,
                        ),
                        margin: const EdgeInsets.all(3),
                        child: Center(
                          child: Text(
                            'Laporan Dinas',
                            style: TextStyle(
                              fontSize: 13,
                              fontWeight: _mainTab == 1 ? FontWeight.bold : FontWeight.w500,
                              color: _mainTab == 1 ? const Color(0xFF1E88E5) : Colors.grey.shade700,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),

          // ─── Konten Tab ────────────────────────────────────────────────
          Expanded(
            child: _mainTab == 0 ? _buildStrukSatuanTab() : _buildLaporanDinasTab(),
          ),
        ],
      ),
    );
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // TAB 1: STRUK SATUAN
  // ═══════════════════════════════════════════════════════════════════════════
  Widget _buildStrukSatuanTab() {
    final receiptProv = Provider.of<ReceiptProvider>(context);

    // Hitung struk draf yang belum masuk bundle
    final unbundledDrafts =
        receiptProv.receipts.where((r) => r.isDraft && !r.isBundled).toList();

    return Column(
      children: [
        // ─── Kartu statistik bulan ini ─────────────────────────────
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: [
              // Total bulan ini
              Expanded(
                child: Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE3F2FD),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFF90CAF9)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Total bulan ini',
                        style: TextStyle(
                          color: Color(0xFF1565C0),
                          fontSize: 11.5,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 4),
                      receiptProv.loading && receiptProv.receipts.isEmpty
                          ? const SizedBox(
                              height: 18,
                              width: 18,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Color(0xFF0D47A1),
                              ),
                            )
                          : Text(
                              formatCurrency(receiptProv.totalThisMonth),
                              style: const TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF0D47A1),
                              ),
                            ),
                    ],
                  ),
                ),
              ),
              const SizedBox(width: 10),
              // Disetujui / Cair
              Expanded(
                child: Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE8F5E9),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFA5D6A7)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Disetujui / Cair',
                        style: TextStyle(
                          color: Color(0xFF2E7D32),
                          fontSize: 11.5,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${receiptProv.approvedCount + receiptProv.receipts.where((r) => r.isPaid).length} struk',
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF1B5E20),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),

        // ─── Banner Ajakan Bundling Draf (Jika ada struk draf) ───────
        if (unbundledDrafts.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: InkWell(
              onTap: () {
                final expProv = Provider.of<ExpenseReportProvider>(context, listen: false);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => BuatLaporanDinasScreen(
                      initialSelectedReceiptIds: unbundledDrafts.map((r) => r.id).toList(),
                    ),
                  ),
                ).then((_) {
                  expProv.fetchReports();
                  receiptProv.fetchMyReceipts();
                });
              },
              borderRadius: BorderRadius.circular(10),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: const Color(0xFFEDE7F6),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: const Color(0xFFD1C4E9)),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.layers_rounded, color: Color(0xFF512DA8), size: 18),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Punya ${unbundledDrafts.length} struk draf? Gabungkan ke Laporan Dinas',
                        style: const TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w600,
                          color: Color(0xFF4527A0),
                        ),
                      ),
                    ),
                    const Icon(Icons.arrow_forward_ios, size: 12, color: Color(0xFF512DA8)),
                  ],
                ),
              ),
            ),
          ),

        // ─── Filter Tanggal Bar ─────────────────────────────────────
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: _selectedDateRange != null ? const Color(0xFFE3F2FD) : Colors.white,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(
                color: _selectedDateRange != null ? const Color(0xFF90CAF9) : Colors.grey.shade200,
              ),
            ),
            child: Row(
              children: [
                Icon(
                  Icons.calendar_month,
                  size: 17,
                  color: _selectedDateRange != null ? const Color(0xFF1565C0) : Colors.grey.shade600,
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: InkWell(
                    onTap: _pickDateRange,
                    child: Text(
                      _selectedDateRange != null
                          ? '${_formatDate(_selectedDateRange!.start)} – ${_formatDate(_selectedDateRange!.end)}'
                          : 'Semua Tanggal',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: _selectedDateRange != null ? FontWeight.bold : FontWeight.w500,
                        color: _selectedDateRange != null ? const Color(0xFF1565C0) : Colors.grey.shade700,
                      ),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ),
                if (_selectedDateRange != null) ...[
                  GestureDetector(
                    onTap: () => setState(() => _selectedDateRange = null),
                    child: Container(
                      padding: const EdgeInsets.all(2),
                      decoration: BoxDecoration(
                        color: Colors.blue.shade100,
                        shape: BoxShape.circle,
                      ),
                      child: const Icon(Icons.close, size: 14, color: Color(0xFF0D47A1)),
                    ),
                  ),
                  const SizedBox(width: 6),
                ],
                PopupMenuButton<String>(
                  tooltip: 'Opsi Tanggal',
                  icon: Icon(
                    Icons.tune,
                    size: 16,
                    color: _selectedDateRange != null ? const Color(0xFF1565C0) : Colors.grey.shade600,
                  ),
                  padding: EdgeInsets.zero,
                  onSelected: (val) {
                    if (val == 'custom') {
                      _pickDateRange();
                    } else {
                      _setDatePreset(val);
                    }
                  },
                  itemBuilder: (_) => [
                    const PopupMenuItem(value: 'semua', child: Text('Semua Tanggal', style: TextStyle(fontSize: 13))),
                    const PopupMenuItem(value: 'bulan_ini', child: Text('Bulan Ini', style: TextStyle(fontSize: 13))),
                    const PopupMenuItem(value: 'bulan_lalu', child: Text('Bulan Lalu', style: TextStyle(fontSize: 13))),
                    const PopupMenuDivider(),
                    const PopupMenuItem(value: 'custom', child: Text('Pilih Rentang...', style: TextStyle(fontSize: 13))),
                  ],
                ),
              ],
            ),
          ),
        ),

        // ─── Filter chips ───────────────────────────────────────────
        Container(
          height: 40,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: ListView(
            scrollDirection: Axis.horizontal,
            children: _filters
                .expand((f) => [_chip(f), const SizedBox(width: 8)])
                .toList(),
          ),
        ),

        const SizedBox(height: 4),

        // ─── Daftar struk ───────────────────────────────────────────
        Expanded(
          child: Consumer<ReceiptProvider>(
            builder: (context, prov, _) {
              if (prov.loading && prov.receipts.isEmpty) {
                return ShimmerLoading(
                  child: ListView.builder(
                    physics: const NeverScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 80),
                    itemCount: 6,
                    itemBuilder: (_, _) => const SkeletonListTileItem(),
                  ),
                );
              }
              final items = _filtered(prov.receipts);
              return RefreshIndicator(
                onRefresh: () => prov.fetchMyReceipts(forceRefresh: true),
                child: items.isEmpty
                    ? ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        children: [
                          const SizedBox(height: 80),
                          Center(
                            child: Column(
                              children: [
                                Icon(Icons.receipt_long_outlined, size: 48, color: Colors.grey.shade400),
                                const SizedBox(height: 8),
                                Text(
                                  _selectedDateRange != null
                                      ? 'Tidak ada struk pada rentang tanggal ini.'
                                      : 'Belum ada struk.',
                                  style: const TextStyle(color: Colors.grey, fontSize: 13),
                                ),
                                if (_selectedDateRange != null || _filter != 'Semua') ...[
                                  const SizedBox(height: 8),
                                  TextButton(
                                    onPressed: () {
                                      setState(() {
                                        _filter = 'Semua';
                                        _selectedDateRange = null;
                                      });
                                    },
                                    child: const Text('Reset Filter'),
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ],
                      )
                    : ListView.builder(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.fromLTRB(16, 4, 16, 80),
                        itemCount: items.length,
                        itemBuilder: (_, i) => _ReceiptCard(receipt: items[i]),
                      ),
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _chip(String label) {
    final selected = _filter == label;
    return ChoiceChip(
      label: Text(
        label,
        style: TextStyle(
          color: selected ? Colors.white : Colors.black87,
          fontWeight: selected ? FontWeight.bold : FontWeight.w500,
          fontSize: 12.5,
        ),
      ),
      selected: selected,
      onSelected: (v) {
        if (v) setState(() => _filter = label);
      },
      selectedColor: const Color(0xFF0088FF),
      backgroundColor: Colors.white,
      side: BorderSide(
        color: selected ? const Color(0xFF0088FF) : Colors.grey.shade300,
      ),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
    );
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // TAB 2: LAPORAN DINAS (BUNDLING)
  // ═══════════════════════════════════════════════════════════════════════════
  Widget _buildLaporanDinasTab() {
    return Consumer<ExpenseReportProvider>(
      builder: (context, repProv, _) {
        if (repProv.loading && repProv.reports.isEmpty) {
          return ShimmerLoading(
            child: ListView.builder(
              physics: const NeverScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 80),
              itemCount: 4,
              itemBuilder: (_, _) => const SkeletonExpenseReportCard(),
            ),
          );
        }

        var list = repProv.reports;
        if (_reportFilter != 'Semua') {
          list = list.where((r) => r.displayStatus == _reportFilter).toList();
        }

        return RefreshIndicator(
          onRefresh: () => repProv.fetchReports(forceRefresh: true),
          child: Column(
            children: [
              // ─── Filter chips untuk Laporan Dinas ───────────────────
              Container(
                height: 40,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  children: _reportFilters.expand((f) {
                    final isSelected = _reportFilter == f;
                    return [
                      ChoiceChip(
                        label: Text(
                          f,
                          style: TextStyle(
                            color: isSelected ? Colors.white : Colors.black87,
                            fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                            fontSize: 12.5,
                          ),
                        ),
                        selected: isSelected,
                        onSelected: (v) {
                          if (v) setState(() => _reportFilter = f);
                        },
                        selectedColor: const Color(0xFF0088FF),
                        backgroundColor: Colors.white,
                        side: BorderSide(
                          color: isSelected ? const Color(0xFF0088FF) : Colors.grey.shade300,
                        ),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                      ),
                      const SizedBox(width: 8),
                    ];
                  }).toList(),
                ),
              ),

              const SizedBox(height: 6),

              // ─── Daftar Laporan Dinas ──────────────────────────────
              Expanded(
                child: list.isEmpty
                    ? ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        children: [
                          const SizedBox(height: 60),
                          Center(
                            child: Column(
                              children: [
                                Icon(Icons.layers_clear_outlined, size: 52, color: Colors.grey.shade400),
                                const SizedBox(height: 12),
                                Text(
                                  _reportFilter != 'Semua'
                                      ? 'Tidak ada laporan dinas dengan status "$_reportFilter".'
                                      : 'Belum ada Laporan Dinas / Bundling.',
                                  style: TextStyle(
                                    fontSize: 13,
                                    color: Colors.grey.shade700,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 6),
                                Padding(
                                  padding: const EdgeInsets.symmetric(horizontal: 32),
                                  child: Text(
                                    'Gabungkan beberapa struk perjalanan dinas menjadi 1 laporan untuk diajukan sekaligus ke Finance.',
                                    textAlign: TextAlign.center,
                                    style: TextStyle(fontSize: 12, color: Colors.grey.shade500),
                                  ),
                                ),
                                const SizedBox(height: 16),
                                ElevatedButton.icon(
                                  onPressed: () {
                                    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
                                    Navigator.push(
                                      context,
                                      MaterialPageRoute(builder: (_) => const BuatLaporanDinasScreen()),
                                    ).then((_) {
                                      repProv.fetchReports();
                                      rcpProv.fetchMyReceipts();
                                    });
                                  },
                                  icon: const Icon(Icons.add, size: 18),
                                  label: const Text('Buat Laporan Baru'),
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: const Color(0xFF1E88E5),
                                    foregroundColor: Colors.white,
                                    padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      )
                    : ListView.builder(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.fromLTRB(16, 4, 16, 80),
                        itemCount: list.length,
                        itemBuilder: (_, i) => _ExpenseReportCard(report: list[i]),
                      ),
              ),
            ],
          ),
        );
      },
    );
  }
}

// ─── Card Laporan Dinas ─────────────────────────────────────────────────────
class _ExpenseReportCard extends StatelessWidget {
  final ExpenseReportRecord report;

  const _ExpenseReportCard({required this.report});

  @override
  Widget build(BuildContext context) {
    Color statusColor;
    Color statusBg;
    Color statusBorder;

    switch (report.status) {
      case 'paid':
        statusColor = const Color(0xFF00695C);
        statusBg = const Color(0xFFE0F2F1);
        statusBorder = const Color(0xFF80CBC4);
        break;
      case 'approved':
        statusColor = const Color(0xFF2E7D32);
        statusBg = const Color(0xFFE8F5E9);
        statusBorder = const Color(0xFFA5D6A7);
        break;
      case 'rejected':
        statusColor = const Color(0xFFC62828);
        statusBg = const Color(0xFFFFEBEE);
        statusBorder = const Color(0xFFFFCDD2);
        break;
      case 'submitted':
        statusColor = const Color(0xFFE65100);
        statusBg = const Color(0xFFFFF3E0);
        statusBorder = const Color(0xFFFFE0B2);
        break;
      default: // draft
        statusColor = const Color(0xFF455A64);
        statusBg = const Color(0xFFECEFF1);
        statusBorder = const Color(0xFFCFD8DC);
    }

    return GestureDetector(
      onTap: () {
        final expProv = Provider.of<ExpenseReportProvider>(context, listen: false);
        final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
        Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => DetailLaporanDinasScreen(reportId: report.id)),
        ).then((_) {
          expProv.fetchReports();
          rcpProv.fetchMyReceipts();
        });
      },
      child: Card(
        color: Colors.white,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: Colors.grey.shade200),
        ),
        margin: const EdgeInsets.only(bottom: 12),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.layers_rounded, size: 16, color: Color(0xFF1E88E5)),
                      const SizedBox(width: 6),
                      Text(
                        report.reportNumber,
                        style: const TextStyle(
                          fontFamily: 'monospace',
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF1E88E5),
                        ),
                      ),
                    ],
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: statusBg,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: statusBorder),
                    ),
                    child: Text(
                      report.displayStatus,
                      style: TextStyle(
                        fontSize: 10.5,
                        fontWeight: FontWeight.bold,
                        color: statusColor,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                report.title,
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 4),
              Row(
                children: [
                  Icon(Icons.calendar_month, size: 13, color: Colors.grey.shade500),
                  const SizedBox(width: 4),
                  Expanded(
                    child: Text(
                      report.displayPeriod,
                      style: TextStyle(fontSize: 11.5, color: Colors.grey.shade600),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: Colors.grey.shade100,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      '${report.totalReceipts} Struk',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: Colors.grey.shade700,
                      ),
                    ),
                  ),
                ],
              ),
              const Divider(height: 18),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Total Klaim Bundle',
                    style: TextStyle(fontSize: 11.5, color: Colors.grey.shade500),
                  ),
                  Text(
                    formatCurrency(report.totalClaimedAmount),
                    style: const TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.bold,
                      color: Color(0xFF0D47A1),
                    ),
                  ),
                ],
              ),
              if (report.isRejected && report.rejectionReason != null) ...[
                const SizedBox(height: 8),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFFEBEE),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    'Alasan: ${report.rejectionReason}',
                    style: const TextStyle(fontSize: 11, color: Color(0xFFC62828)),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

// ─── Card struk satuan ──────────────────────────────────────────────────────
class _ReceiptCard extends StatelessWidget {
  final ReceiptRecord receipt;
  const _ReceiptCard({required this.receipt});

  @override
  Widget build(BuildContext context) {
    final status = receipt.displayStatus;
    Color statusColor;
    Color statusBg;
    Color statusBorder;

    switch (receipt.status) {
      case 'paid':
        statusColor = const Color(0xFF00695C);
        statusBg = const Color(0xFFE0F2F1);
        statusBorder = const Color(0xFF80CBC4);
        break;
      case 'approved':
        statusColor = const Color(0xFF1565C0);
        statusBg = const Color(0xFFE3F2FD);
        statusBorder = const Color(0xFF90CAF9);
        break;
      case 'rejected':
        statusColor = const Color(0xFFC62828);
        statusBg = const Color(0xFFFFEBEE);
        statusBorder = const Color(0xFFFFCDD2);
        break;
      case 'submitted':
        statusColor = const Color(0xFFE65100);
        statusBg = const Color(0xFFFFF3E0);
        statusBorder = const Color(0xFFFFE0B2);
        break;
      default:
        statusColor = const Color(0xFF455A64);
        statusBg = const Color(0xFFECEFF1);
        statusBorder = const Color(0xFFCFD8DC);
    }

    final hasAdjustedAmount = receipt.approvedAmount != null &&
        receipt.claimedAmount != null &&
        (receipt.approvedAmount! < receipt.claimedAmount!);

    return GestureDetector(
      onTap: () => showModalBottomSheet(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (_) => DetailPengajuanScreen(receipt: receipt),
      ),
      child: Card(
        color: Colors.white,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: Colors.grey.shade200),
        ),
        margin: const EdgeInsets.only(bottom: 12),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Expanded(
                    child: Row(
                      children: [
                        Flexible(
                          child: Text(
                            receipt.displayMerchant,
                            style: const TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                            ),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        if (receipt.isBundled) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: const Color(0xFFEDE7F6),
                              borderRadius: BorderRadius.circular(4),
                              border: Border.all(color: const Color(0xFFD1C4E9)),
                            ),
                            child: const Text(
                              'Bundle Dinas',
                              style: TextStyle(
                                fontSize: 9.5,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF512DA8),
                              ),
                            ),
                          ),
                        ],
                        if (receipt.showDuplicateWarning) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: Colors.purple.shade50,
                              borderRadius: BorderRadius.circular(4),
                              border: Border.all(color: Colors.purple.shade200),
                            ),
                            child: const Text(
                              'Duplikat',
                              style: TextStyle(
                                fontSize: 9.5,
                                fontWeight: FontWeight.bold,
                                color: Colors.purple,
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 4,
                    ),
                    decoration: BoxDecoration(
                      color: statusBg,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: statusBorder),
                    ),
                    child: Text(
                      status,
                      style: TextStyle(
                        color: statusColor,
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                receipt.displayDate,
                style: const TextStyle(color: Colors.grey, fontSize: 12),
              ),
              const SizedBox(height: 12),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    receipt.category ?? '-',
                    style: const TextStyle(
                      color: Colors.blueGrey,
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        formatCurrency(receipt.displayAmount),
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF0088FF),
                        ),
                      ),
                      if (hasAdjustedAmount)
                        Text(
                          'Klaim diajukan: ${formatCurrency(receipt.claimedAmount ?? 0)}',
                          style: TextStyle(
                            fontSize: 10,
                            color: Colors.orange.shade800,
                            decoration: TextDecoration.lineThrough,
                          ),
                        ),
                      if (receipt.isPaid)
                        const Text(
                          'Telah Ditransfer',
                          style: TextStyle(
                            fontSize: 9.5,
                            color: Color(0xFF00695C),
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                    ],
                  ),
                ],
              ),
              if (receipt.status == 'rejected' &&
                  receipt.rejectionReason != null) ...[
                const SizedBox(height: 12),
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
                        receipt.rejectionReason!,
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
              if (receipt.showDuplicateWarning) ...[
                const SizedBox(height: 12),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF3E8FF),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: const Color(0xFFD8B4FE)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Row(
                        children: [
                          Icon(Icons.shield_outlined, color: Color(0xFF7E22CE), size: 14),
                          SizedBox(width: 4),
                          Text(
                            'Peringatan Potensi Duplikat:',
                            style: TextStyle(
                              color: Color(0xFF6B21A8),
                              fontWeight: FontWeight.bold,
                              fontSize: 11,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 2),
                      Text(
                        receipt.duplicateReason ??
                            'Struk ini terindikasi memiliki kemiripan dengan pengajuan lain.',
                        style: const TextStyle(
                          color: Color(0xFF7E22CE),
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
      ),
    );
  }
}
