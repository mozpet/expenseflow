import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/receipt_provider.dart';
import '../utils.dart';
import '../widgets/skeleton.dart';
import 'detail_pengajuan_screen.dart';
import 'submit_step1_screen.dart';

class RiwayatScreen extends StatefulWidget {
  const RiwayatScreen({super.key});

  @override
  State<RiwayatScreen> createState() => _RiwayatScreenState();
}

class _RiwayatScreenState extends State<RiwayatScreen> {
  String _filter = 'Semua';

  static const _filters = ['Semua', 'Menunggu', 'Disetujui', 'Ditolak', 'Draf'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Provider.of<ReceiptProvider>(context, listen: false).fetchMyReceipts();
    });
  }

  List<ReceiptRecord> _filtered(List<ReceiptRecord> all) {
    if (_filter == 'Semua') return all;
    return all.where((r) => r.displayStatus == _filter).toList();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'riwayat_fab',
        onPressed: () => Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => const SubmitStep1Screen()),
        ),
        backgroundColor: const Color(0xFF0088FF),
        foregroundColor: Colors.white,
        icon: const Icon(Icons.photo_camera),
        label: const Text(
          'Scan Struk',
          style: TextStyle(fontWeight: FontWeight.bold),
        ),
      ),
      appBar: AppBar(
        title: const Text('Struk Saya'),
        automaticallyImplyLeading: false,
        actions: [
          // Refresh manual di pojok kanan atas
          IconButton(
            onPressed: () => Provider.of<ReceiptProvider>(
              context,
              listen: false,
            ).fetchMyReceipts(),
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
          ),
        ],
      ),
      body: Column(
        children: [
          // ─── Kartu statistik bulan ini ─────────────────────────────
          Consumer<ReceiptProvider>(
            builder: (context, prov, _) {
              return Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 12,
                ),
                child: Row(
                  children: [
                    // Total bulan ini
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.all(16),
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
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            const SizedBox(height: 4),
                            prov.loading && prov.receipts.isEmpty
                                ? const SizedBox(
                                    height: 20,
                                    width: 20,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                      color: Color(0xFF0D47A1),
                                    ),
                                  )
                                : Text(
                                    formatCurrency(prov.totalThisMonth),
                                    style: const TextStyle(
                                      fontSize: 16,
                                      fontWeight: FontWeight.bold,
                                      color: Color(0xFF0D47A1),
                                    ),
                                  ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    // Disetujui
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: const Color(0xFFE8F5E9),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: const Color(0xFFA5D6A7)),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'Disetujui',
                              style: TextStyle(
                                color: Color(0xFF2E7D32),
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              '${prov.approvedCount} struk',
                              style: const TextStyle(
                                fontSize: 16,
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
              );
            },
          ),

          // ─── Filter chips ───────────────────────────────────────────
          Container(
            height: 50,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: _filters
                  .expand((f) => [_chip(f), const SizedBox(width: 8)])
                  .toList(),
            ),
          ),

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
                  onRefresh: prov.fetchMyReceipts,
                  child: items.isEmpty
                      ? ListView(
                          physics: const AlwaysScrollableScrollPhysics(),
                          children: const [
                            SizedBox(height: 100),
                            Center(
                              child: Text(
                                'Belum ada struk.',
                                style: TextStyle(color: Colors.grey),
                              ),
                            ),
                          ],
                        )
                      : ListView.builder(
                          physics: const AlwaysScrollableScrollPhysics(),
                          padding: const EdgeInsets.fromLTRB(16, 8, 16, 80),
                          itemCount: items.length,
                          itemBuilder: (_, i) =>
                              _ReceiptCard(receipt: items[i]),
                        ),
                );
              },
            ),
          ),
        ],
      ),
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
          fontSize: 13,
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
}

// ─── Card struk ─────────────────────────────────────────────────────────────
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
      default:
        statusColor = const Color(0xFF455A64);
        statusBg = const Color(0xFFECEFF1);
        statusBorder = const Color(0xFFCFD8DC);
    }

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
                    child: Text(
                      receipt.displayMerchant,
                      style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 15,
                        color: Colors.black87,
                      ),
                      overflow: TextOverflow.ellipsis,
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
              if (receipt.status == 'draft') ...[
                const SizedBox(height: 2),
                Text(
                  'Ketuk untuk lihat aksi',
                  style: TextStyle(
                    color: Colors.blue.shade600,
                    fontSize: 11,
                    fontStyle: FontStyle.italic,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ],
              const Divider(height: 24, thickness: 0.5),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Expanded(
                    child: Text(
                      receipt.category ?? '-',
                      style: const TextStyle(
                        color: Colors.black87,
                        fontSize: 13,
                      ),
                    ),
                  ),
                  Text(
                    receipt.displayAmount > 0
                        ? formatCurrency(receipt.displayAmount)
                        : '-',
                    style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 15,
                      color: Colors.black87,
                    ),
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
            ],
          ),
        ),
      ),
    );
  }
}
