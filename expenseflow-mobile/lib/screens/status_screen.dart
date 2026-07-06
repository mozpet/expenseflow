import 'package:flutter/material.dart';
import '../providers/receipt_provider.dart';
import '../utils.dart';

class StatusScreen extends StatelessWidget {
  final ReceiptRecord receipt;
  const StatusScreen({super.key, required this.receipt});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Status Pengajuan'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(24),
          child: Container(
            padding: const EdgeInsets.only(bottom: 8),
            child: const Text(
              'Terkirim — Menunggu approval',
              style: TextStyle(color: Colors.white70, fontSize: 12),
            ),
          ),
        ),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Banner sukses
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: const Color(0xFFE8F5E9),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFC8E6C9)),
              ),
              child: const Column(
                children: [
                  Icon(Icons.check_circle, color: Colors.green, size: 48),
                  SizedBox(height: 8),
                  Text(
                    'Pengajuan berhasil dikirim!',
                    style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: Colors.green),
                  ),
                  SizedBox(height: 4),
                  Text(
                    'Finance akan mereview dalam 1x24 jam',
                    style: TextStyle(color: Colors.black54, fontSize: 12),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),

            // Ringkasan pengajuan
            const Text('Ringkasan pengajuan',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
            const SizedBox(height: 12),
            Card(
              elevation: 0,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
                side: BorderSide(color: Colors.grey.shade200),
              ),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  children: [
                    _row('ID Pengajuan', receipt.receiptNumber,
                        highlight: true),
                    const Divider(height: 24),
                    _row('Nominal (dari OCR)',
                        receipt.ocrRawAmount != null
                            ? formatCurrency(double.tryParse(receipt.ocrRawAmount!) ?? 0)
                            : '-'),
                    const Divider(height: 24),
                    _row('Merchant (dari OCR)',
                        receipt.ocrRawMerchant ?? receipt.vendorName ?? '-'),
                    const Divider(height: 24),
                    _row('Tanggal struk', receipt.displayDate),
                    const Divider(height: 24),
                    _row('Kategori', receipt.category ?? '-'),
                    const Divider(height: 24),
                    _row('Waktu submit',
                        _formatDateTime(receipt.createdAt)),
                    const Divider(height: 24),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('Status',
                            style: TextStyle(color: Colors.grey)),
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(
                            color: const Color(0xFFFFF3E0),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: const Text('Menunggu',
                              style: TextStyle(
                                  color: Colors.orange,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 12)),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),

            // Audit trail
            const Text('Audit trail',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: Colors.grey.shade200),
              ),
              child: Column(
                children: [
                  _timelineItem(true, 'Foto diupload & dikunci',
                      'SHA-256 hash tersimpan permanen', isLast: false),
                  _timelineItem(
                    receipt.ocrStatus == 'done',
                    'OCR selesai diproses',
                    receipt.ocrStatus == 'done'
                        ? 'nominal, merchant, tanggal terkunci'
                        : 'OCR gagal — data diisi manual',
                    isLast: false,
                  ),
                  _timelineItem(
                      true, 'Keterangan diisi karyawan',
                      receipt.category ?? 'kategori & catatan',
                      isLast: false),
                  _timelineItem(
                      true, 'Dikirim ke finance',
                      'menunggu review', isLast: true),
                ],
              ),
            ),
            const SizedBox(height: 32),

            ElevatedButton(
              onPressed: () =>
                  Navigator.of(context).popUntil((r) => r.isFirst),
              style: ElevatedButton.styleFrom(
                backgroundColor: Theme.of(context).primaryColor,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 16),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
              child: const Text('Kembali ke Beranda',
                  style: TextStyle(
                      fontSize: 16, fontWeight: FontWeight.bold)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String value, {bool highlight = false}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(color: Colors.grey)),
        Text(value,
            style: TextStyle(
                fontWeight: FontWeight.bold,
                color: highlight
                    ? Colors.blue.shade800
                    : Colors.black87)),
      ],
    );
  }

  Widget _timelineItem(bool done, String title, String desc,
      {required bool isLast}) {
    return Container(
      margin: const EdgeInsets.only(bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Column(
            children: [
              Container(
                width: 12,
                height: 12,
                decoration: BoxDecoration(
                  color: done ? Colors.blue : Colors.grey.shade400,
                  shape: BoxShape.circle,
                ),
              ),
              if (!isLast)
                Container(
                    width: 2, height: 36, color: Colors.grey.shade300),
            ],
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title,
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 13)),
                const SizedBox(height: 2),
                Text(desc,
                    style: const TextStyle(
                        color: Colors.grey, fontSize: 11)),
                const SizedBox(height: 12),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _formatDateTime(String iso) {
    try {
      final dt = DateTime.parse(iso).toLocal();
      const months = [
        'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
      ];
      final h = dt.hour.toString().padLeft(2, '0');
      final m = dt.minute.toString().padLeft(2, '0');
      return '${dt.day} ${months[dt.month - 1]} ${dt.year}, $h:$m';
    } catch (_) {
      return iso;
    }
  }
}
