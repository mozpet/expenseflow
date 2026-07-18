import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/receipt_provider.dart';
import '../utils.dart';
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
        backgroundColor: Theme.of(context).primaryColor,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.photo_camera),
        label: const Text('Scan Struk'),
      ),
      appBar: AppBar(
        title: const Text('Struk Saya'),
        automaticallyImplyLeading: false,
      ),
      body: Column(
        children: [
          // ─── Kartu statistik bulan ini ─────────────────────────────
          Consumer<ReceiptProvider>(
            builder: (context, prov, _) {
              return Padding(
                padding:
                    const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                child: Row(
                  children: [
                    // Total bulan ini
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: const Color(0xFFE8F0FE),
                          borderRadius: BorderRadius.circular(16),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'Total bulan ini',
                              style: TextStyle(
                                  color: Colors.grey, fontSize: 12),
                            ),
                            const SizedBox(height: 4),
                            prov.loading && prov.receipts.isEmpty
                                ? const SizedBox(
                                    height: 20,
                                    width: 20,
                                    child: CircularProgressIndicator(
                                        strokeWidth: 2))
                                : Text(
                                    formatCurrency(prov.totalThisMonth),
                                    style: const TextStyle(
                                      fontSize: 16,
                                      fontWeight: FontWeight.bold,
                                      color: Color(0xFF1565C0),
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
                          borderRadius: BorderRadius.circular(16),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'Disetujui',
                              style: TextStyle(
                                  color: Colors.grey, fontSize: 12),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              '${prov.approvedCount} struk',
                              style: const TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                                color: Colors.green,
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
                  return const Center(child: CircularProgressIndicator());
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
      label: Text(label),
      selected: selected,
      onSelected: (v) {
        if (v) setState(() => _filter = label);
      },
      selectedColor: Theme.of(context).primaryColor,
      backgroundColor: Colors.grey.shade100,
      labelStyle:
          TextStyle(color: selected ? Colors.white : Colors.black87),
      shape:
          RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
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
    switch (receipt.status) {
      case 'approved':
        statusColor = Colors.green;
        statusBg = const Color(0xFFE8F5E9);
        break;
      case 'rejected':
        statusColor = Colors.red;
        statusBg = const Color(0xFFFFEBEE);
        break;
      case 'submitted':
        statusColor = Colors.orange;
        statusBg = const Color(0xFFFFF3E0);
        break;
      default:
        statusColor = Colors.grey;
        statusBg = Colors.grey.shade100;
    }

    return GestureDetector(
      onTap: () => showModalBottomSheet(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (_) => DetailPengajuanScreen(receipt: receipt),
      ),
      child: Card(
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
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
                          fontWeight: FontWeight.bold, fontSize: 15),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: statusBg,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Text(
                      status,
                      style: TextStyle(
                          color: statusColor,
                          fontSize: 11,
                          fontWeight: FontWeight.bold),
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
                      color: Colors.blue.shade400,
                      fontSize: 10,
                      fontStyle: FontStyle.italic),
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
                          color: Colors.black87, fontSize: 13),
                    ),
                  ),
                  Text(
                    receipt.displayAmount > 0
                        ? formatCurrency(receipt.displayAmount)
                        : '-',
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 15),
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
                        receipt.rejectionReason!,
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
      ),
    );
  }
}
