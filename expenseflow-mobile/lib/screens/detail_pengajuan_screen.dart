import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/receipt_provider.dart';
import '../utils.dart';
import 'submit_step1_screen.dart';

class DetailPengajuanScreen extends StatefulWidget {
  final ReceiptRecord receipt;
  const DetailPengajuanScreen({super.key, required this.receipt});

  @override
  State<DetailPengajuanScreen> createState() => _DetailPengajuanScreenState();
}

class _DetailPengajuanScreenState extends State<DetailPengajuanScreen> {
  bool _isDeleting = false;

  // ── Banner berdasarkan status ──────────────────────────────
  ({Color bg, Color text, String msg}) get _banner {
    switch (widget.receipt.status) {
      case 'approved':
        return (
          bg: const Color(0xFFE8F5E9),
          text: const Color(0xFF1B5E20),
          msg: 'Pengajuan disetujui oleh Finance',
        );
      case 'rejected':
        return (
          bg: const Color(0xFFFFEBEE),
          text: const Color(0xFFB71C1C),
          msg: 'Pengajuan ditolak oleh Finance',
        );
      case 'draft':
        final ocrMsg = switch (widget.receipt.ocrStatus) {
          'pending' || 'processing' => 'OCR sedang memproses foto...',
          'failed' => 'OCR gagal — belum disubmit ke Finance',
          _ => 'Draft — belum disubmit ke Finance',
        };
        return (
          bg: Colors.grey.shade100,
          text: Colors.blueGrey.shade700,
          msg: ocrMsg,
        );
      default: // submitted
        return (
          bg: const Color(0xFFFFF3E0),
          text: const Color(0xFFE65100),
          msg: 'Pengajuan menunggu approval Finance',
        );
    }
  }

  // ── Hapus draft ───────────────────────────────────────────
  Future<void> _handleDelete() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Hapus Draft?'),
        content: Text(
          'Draft struk ${widget.receipt.receiptNumber} akan dihapus permanen.\n'
          'Tindakan ini tidak bisa dibatalkan.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          TextButton(
            style: TextButton.styleFrom(foregroundColor: Colors.red),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    setState(() => _isDeleting = true);
    try {
      await Provider.of<ReceiptProvider>(context, listen: false)
          .deleteDraft(widget.receipt.id);
      if (!mounted) return;
      Navigator.pop(context); // tutup bottom sheet
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Draft berhasil dihapus.'),
          backgroundColor: Colors.green,
        ),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _isDeleting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
      );
    }
  }

  // ── Foto ulang: hapus draft lama → Step1 ─────────────────
  Future<void> _handleFotoUlang() async {
    setState(() => _isDeleting = true);
    try {
      await Provider.of<ReceiptProvider>(context, listen: false)
          .deleteDraft(widget.receipt.id);
      if (!mounted) return;
      Navigator.pop(context); // tutup bottom sheet
      Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => const SubmitStep1Screen()),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _isDeleting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final banner = _banner;

    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.only(
          topLeft: Radius.circular(28),
          topRight: Radius.circular(28),
        ),
      ),
      padding: EdgeInsets.only(
          bottom: MediaQuery.of(context).viewInsets.bottom + 20),
      child: SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Drag handle
            Container(
              width: 40,
              height: 4,
              margin: const EdgeInsets.only(top: 12, bottom: 16),
              decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2),
              ),
            ),

            // Header
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('Detail Pengajuan',
                      style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: Colors.black87)),
                  GestureDetector(
                    onTap: () => Navigator.pop(context),
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 16, vertical: 8),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade100,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: const Text('Tutup',
                          style: TextStyle(
                              fontWeight: FontWeight.bold,
                              color: Color(0xFF78909C),
                              fontSize: 12)),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Scrollable content
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // Banner status
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      decoration: BoxDecoration(
                        color: banner.bg,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      alignment: Alignment.center,
                      child: Text(banner.msg,
                          style: TextStyle(
                              color: banner.text,
                              fontWeight: FontWeight.bold,
                              fontSize: 14)),
                    ),
                    const SizedBox(height: 16),

                    // Nomor pengajuan
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 8),
                      decoration: BoxDecoration(
                        color: Colors.blue.shade50,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('No. Pengajuan',
                              style: TextStyle(
                                  color: Colors.blueGrey, fontSize: 12)),
                          Text(widget.receipt.receiptNumber,
                              style: TextStyle(
                                  color: Colors.blue.shade800,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 13)),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),

                    // Foto placeholder
                    Container(
                      height: 80,
                      decoration: BoxDecoration(
                        color: const Color(0xFFECEFF1),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: Colors.blueGrey.shade100),
                      ),
                      child: Center(
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.photo_library_outlined,
                                color: Colors.blueGrey.shade400, size: 20),
                            const SizedBox(width: 8),
                            Text('Foto struk tersimpan permanen',
                                style: TextStyle(
                                    color: Colors.blueGrey.shade600,
                                    fontSize: 13)),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 20),

                    // Data OCR
                    const Text('DATA OCR (TERSIMPAN OTOMATIS)',
                        style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: Colors.blueGrey,
                            letterSpacing: 0.5)),
                    const SizedBox(height: 8),
                    _row(
                      'Nominal OCR',
                      widget.receipt.ocrRawAmount != null
                          ? formatCurrency(
                              double.tryParse(
                                      widget.receipt.ocrRawAmount!) ??
                                  0)
                          : '-',
                      isBold: true,
                    ),
                    const SizedBox(height: 8),
                    _row('Merchant OCR',
                        widget.receipt.ocrRawMerchant ??
                            widget.receipt.vendorName ??
                            '-'),
                    const SizedBox(height: 8),
                    _row('Tanggal OCR', widget.receipt.displayDate),

                    const Divider(height: 32, thickness: 0.5),

                    // Klaim
                    const Text('KLAIM ANDA',
                        style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: Colors.blueGrey,
                            letterSpacing: 0.5)),
                    const SizedBox(height: 8),
                    _row(
                      'Nominal klaim',
                      widget.receipt.displayAmount > 0
                          ? formatCurrency(widget.receipt.displayAmount)
                          : '-',
                      isBold: true,
                    ),
                    const SizedBox(height: 8),
                    _row('Kategori', widget.receipt.category ?? '-'),
                    const SizedBox(height: 8),
                    _row('Catatan', widget.receipt.notes ?? '-'),
                    const SizedBox(height: 8),
                    _row('Status', widget.receipt.displayStatus),

                    const SizedBox(height: 20),

                    // Alasan penolakan
                    if (widget.receipt.status == 'rejected' &&
                        widget.receipt.rejectionReason != null) ...[
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: const Color(0xFFFFEBEE),
                          borderRadius: BorderRadius.circular(16),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Catatan Penolakan Finance:',
                                style: TextStyle(
                                    color: Colors.red.shade900,
                                    fontWeight: FontWeight.bold,
                                    fontSize: 13)),
                            const SizedBox(height: 4),
                            Text(
                              widget.receipt.rejectionReason!,
                              style: TextStyle(
                                  color: Colors.red.shade800,
                                  fontSize: 12,
                                  height: 1.4),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 12),
                    ],

                    // ── Aksi khusus draft ──────────────────────────────────
                    if (widget.receipt.status == 'draft') ...[
                      const Divider(height: 32, thickness: 0.5),
                      const Text('AKSI',
                          style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.bold,
                              color: Colors.blueGrey,
                              letterSpacing: 0.5)),
                      const SizedBox(height: 12),
                      Row(
                        children: [
                          Expanded(
                            child: OutlinedButton.icon(
                              onPressed:
                                  _isDeleting ? null : _handleFotoUlang,
                              icon: _isDeleting
                                  ? const SizedBox(
                                      width: 16,
                                      height: 16,
                                      child: CircularProgressIndicator(
                                          strokeWidth: 2))
                                  : const Icon(
                                      Icons.camera_alt_outlined,
                                      size: 18),
                              label: const Text('Foto Ulang'),
                              style: OutlinedButton.styleFrom(
                                foregroundColor: Colors.blue.shade700,
                                side: BorderSide(
                                    color: Colors.blue.shade300),
                                padding: const EdgeInsets.symmetric(
                                    vertical: 12),
                                shape: RoundedRectangleBorder(
                                    borderRadius:
                                        BorderRadius.circular(10)),
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: OutlinedButton.icon(
                              onPressed:
                                  _isDeleting ? null : _handleDelete,
                              icon: const Icon(Icons.delete_outline,
                                  size: 18),
                              label: const Text('Hapus Draft'),
                              style: OutlinedButton.styleFrom(
                                foregroundColor: Colors.red,
                                side:
                                    const BorderSide(color: Colors.red),
                                padding: const EdgeInsets.symmetric(
                                    vertical: 12),
                                shape: RoundedRectangleBorder(
                                    borderRadius:
                                        BorderRadius.circular(10)),
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String value, {bool isBold = false}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label,
            style:
                const TextStyle(color: Colors.blueGrey, fontSize: 13)),
        Flexible(
          child: Text(value,
              textAlign: TextAlign.end,
              style: TextStyle(
                  fontWeight:
                      isBold ? FontWeight.bold : FontWeight.w600,
                  color: Colors.black87,
                  fontSize: 13)),
        ),
      ],
    );
  }
}
