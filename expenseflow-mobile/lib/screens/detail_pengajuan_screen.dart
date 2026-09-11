import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/receipt_provider.dart';
import '../utils.dart';
import '../widgets/receipt_thumbnail.dart';
import 'submit_step1_screen.dart';

class DetailPengajuanScreen extends StatefulWidget {
  final ReceiptRecord receipt;
  const DetailPengajuanScreen({super.key, required this.receipt});

  @override
  State<DetailPengajuanScreen> createState() => _DetailPengajuanScreenState();
}

class _DetailPengajuanScreenState extends State<DetailPengajuanScreen> {
  late ReceiptRecord _currentReceipt;
  bool _isDeleting = false;
  bool _isEditing = false;
  bool _isSavingEdit = false;

  late TextEditingController _claimedAmountController;
  late TextEditingController _notesController;
  late String _selectedCategory;

  static const _categories = [
    'Konsumsi / Makan Siang',
    'Transportasi & BBM',
    'Alat tulis & perlengkapan',
    'Akomodasi & Tiket',
    'Lain-lain / Operasional',
  ];

  @override
  void initState() {
    super.initState();
    _currentReceipt = widget.receipt;
    _initFormControllers();
  }

  void _initFormControllers() {
    _selectedCategory = _categories.contains(_currentReceipt.category)
        ? _currentReceipt.category!
        : 'Lain-lain / Operasional';
    final amountVal = _currentReceipt.claimedAmount ?? _currentReceipt.displayAmount;
    _claimedAmountController = TextEditingController(
      text: amountVal > 0 ? amountVal.toInt().toString() : '',
    );
    _notesController = TextEditingController(text: _currentReceipt.notes ?? '');
  }

  @override
  void dispose() {
    _claimedAmountController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  // ── Banner berdasarkan status ──────────────────────────────
  ({Color bg, Color text, String msg}) get _banner {
    switch (_currentReceipt.status) {
      case 'paid':
        return (
          bg: const Color(0xFFE0F2F1),
          text: const Color(0xFF004D40),
          msg: 'Dana Reimbursement Sudah Cair (Ditransfer)',
        );
      case 'approved':
        return (
          bg: const Color(0xFFE3F2FD),
          text: const Color(0xFF0D47A1),
          msg: 'Pengajuan Disetujui (Pending Pembayaran / Transfer)',
        );
      case 'rejected':
        return (
          bg: const Color(0xFFFFEBEE),
          text: const Color(0xFFB71C1C),
          msg: 'Pengajuan ditolak oleh Finance',
        );
      case 'draft':
        final ocrMsg = switch (_currentReceipt.ocrStatus) {
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

  // ── Simpan perubahan edit draft ───────────────────────────
  Future<void> _handleSaveEdit() async {
    final rawAmountText = _claimedAmountController.text
        .replaceAll('.', '')
        .replaceAll(',', '.')
        .trim();
    final amount = double.tryParse(rawAmountText);

    if (amount != null && amount <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Nominal klaim harus lebih besar dari 0.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    setState(() => _isSavingEdit = true);
    try {
      final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
      final updated = await rcpProv.updateDraftClaim(
        id: _currentReceipt.id,
        category: _selectedCategory,
        notes: _notesController.text.trim().isNotEmpty
            ? _notesController.text.trim()
            : null,
        claimedAmount: amount,
      );

      if (!mounted) return;
      setState(() {
        _currentReceipt = updated;
        _isEditing = false;
        _isSavingEdit = false;
      });

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Perubahan data klaim berhasil disimpan!'),
          backgroundColor: Colors.green,
        ),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _isSavingEdit = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
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
          'Draft struk ${_currentReceipt.receiptNumber} akan dihapus permanen.\n'
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
          .deleteDraft(_currentReceipt.id);
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
          .deleteDraft(_currentReceipt.id);
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
    final media = MediaQuery.of(context);

    return Container(
      constraints: BoxConstraints(
        maxHeight: media.size.height * 0.9,
      ),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.only(
          topLeft: Radius.circular(28),
          topRight: Radius.circular(28),
        ),
      ),
      padding: EdgeInsets.only(
          bottom: media.viewInsets.bottom + 20),
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
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (_currentReceipt.status == 'draft' && !_isEditing)
                        GestureDetector(
                          onTap: () => setState(() => _isEditing = true),
                          child: Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 10, vertical: 5),
                            margin: const EdgeInsets.only(right: 8),
                            decoration: BoxDecoration(
                              color: const Color(0xFFE3F2FD),
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(color: const Color(0xFF90CAF9)),
                            ),
                            child: const Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.edit_outlined,
                                    size: 13, color: Color(0xFF1565C0)),
                                SizedBox(width: 4),
                                Text(
                                  'Edit Data',
                                  style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    color: Color(0xFF1565C0),
                                    fontSize: 11.5,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      GestureDetector(
                        onTap: () => Navigator.pop(context),
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 14, vertical: 6),
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
                        borderRadius: BorderRadius.circular(8),
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
                          Text(_currentReceipt.receiptNumber,
                              style: TextStyle(
                                  color: Colors.blue.shade800,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 13)),
                        ],
                      ),
                    ),

                    if (_currentReceipt.isBundled) ...[
                      const SizedBox(height: 10),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 14, vertical: 10),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF3E5F5),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: const Color(0xFFCE93D8)),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.folder_shared_outlined,
                                size: 20, color: Color(0xFF7B1FA2)),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text(
                                    'Tergabung dalam Laporan Dinas',
                                    style: TextStyle(
                                      fontSize: 11,
                                      fontWeight: FontWeight.w600,
                                      color: Color(0xFF6A1B9A),
                                    ),
                                  ),
                                  const SizedBox(height: 2),
                                  Text(
                                    _currentReceipt.expenseReportTitle ??
                                        _currentReceipt.expenseReportNumber ??
                                        'Laporan Dinas',
                                    style: const TextStyle(
                                      fontSize: 12.5,
                                      fontWeight: FontWeight.bold,
                                      color: Color(0xFF4A148C),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                    const SizedBox(height: 16),

                    // Peringatan Potensi Duplikat jika terdeteksi (hanya jika belum disetujui finance)
                    if (_currentReceipt.showDuplicateWarning) ...[
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: const Color(0xFFFFF8E1),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: const Color(0xFFFFB300)),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.warning_amber_rounded,
                                color: Color(0xFFD97706), size: 20),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text(
                                    'Peringatan: Potensi Struk Duplikat',
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 12.5,
                                      color: Color(0xFFB45309),
                                    ),
                                  ),
                                  const SizedBox(height: 3),
                                  Text(
                                    _currentReceipt.duplicateReason ??
                                        'Struk ini terdeteksi serupa dengan struk lain yang pernah diajukan.',
                                    style: const TextStyle(
                                      fontSize: 11.5,
                                      color: Color(0xFF92400E),
                                      height: 1.35,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 16),
                    ],

                    // Box Foto Struk Fisik & Zoom Preview
                    Container(
                      margin: const EdgeInsets.only(bottom: 16),
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: const Color(0xFFF8F9FD),
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: Colors.blue.shade100),
                      ),
                      child: Row(
                        children: [
                          ReceiptThumbnail(
                            receiptId: _currentReceipt.id,
                            imagePath: _currentReceipt.imagePath,
                            size: 60,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Row(
                                  children: [
                                    Icon(Icons.image_outlined, size: 14, color: Color(0xFF1E88E5)),
                                    SizedBox(width: 4),
                                    Text(
                                      'Foto Struk Fisik Asli',
                                      style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        fontSize: 12.5,
                                        color: Colors.black87,
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 3),
                                Text(
                                  'Ketuk foto thumbnail untuk memperbesar & mencocokkan struk fisik dengan data OCR AI di bawah.',
                                  style: TextStyle(
                                    fontSize: 11,
                                    color: Colors.grey.shade600,
                                    height: 1.3,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),

                    // Data OCR
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('DATA OCR (TERSIMPAN OTOMATIS)',
                            style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                                color: Colors.blueGrey,
                                letterSpacing: 0.5)),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: Colors.green.shade50,
                            borderRadius: BorderRadius.circular(4),
                            border: Border.all(color: Colors.green.shade200),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(Icons.auto_awesome, size: 11, color: Colors.green.shade700),
                              const SizedBox(width: 3),
                              Text(
                                'AI OCR',
                                style: TextStyle(
                                  fontSize: 10,
                                  fontWeight: FontWeight.bold,
                                  color: Colors.green.shade800,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    _row('Merchant OCR',
                        _currentReceipt.ocrRawMerchant ??
                            _currentReceipt.vendorName ??
                            '-'),
                    const SizedBox(height: 8),
                    _row('Tanggal OCR', _currentReceipt.displayDate),
                    const SizedBox(height: 8),

                    // Rincian Item Belanjaan
                    if (_currentReceipt.items.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF8F9FD),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: Colors.blue.shade100),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                const Icon(Icons.receipt_outlined, size: 14, color: Color(0xFF1E88E5)),
                                const SizedBox(width: 6),
                                Text(
                                  'Rincian Belanja (${_currentReceipt.items.length} item)',
                                  style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 11.5,
                                    color: Color(0xFF1E88E5),
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 8),
                            ..._currentReceipt.items.map((it) => Padding(
                              padding: const EdgeInsets.symmetric(vertical: 3),
                              child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Expanded(
                                    child: Text(
                                      '${it.name} (${it.qty}x)',
                                      style: const TextStyle(fontSize: 11.5, color: Colors.black87),
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                  Text(
                                    formatCurrency(it.total),
                                    style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600),
                                  ),
                                ],
                              ),
                            )),
                            if (_currentReceipt.ocrRawSubtotal != null ||
                                _currentReceipt.ocrRawDiscount != null ||
                                _currentReceipt.ocrRawTax != null) ...[
                              const Divider(height: 14),
                              if (_currentReceipt.ocrRawSubtotal != null)
                                _miniBreakdownRow('Subtotal', formatCurrency(_currentReceipt.ocrRawSubtotal!)),
                              if (_currentReceipt.ocrRawDiscount != null && _currentReceipt.ocrRawDiscount! > 0)
                                _miniBreakdownRow(
                                  'Diskon',
                                  '- ${formatCurrency(_currentReceipt.ocrRawDiscount!)}',
                                  textColor: const Color(0xFF2E7D32),
                                ),
                              if (_currentReceipt.ocrRawTax != null && _currentReceipt.ocrRawTax! > 0)
                                _miniBreakdownRow(
                                  'Pajak/PPN',
                                  '+ ${formatCurrency(_currentReceipt.ocrRawTax!)}',
                                  textColor: const Color(0xFFE65100),
                                ),
                            ],
                          ],
                        ),
                      ),
                      const SizedBox(height: 8),
                    ],

                    _row(
                      'Total Bayar OCR',
                      _currentReceipt.ocrRawAmount != null
                          ? formatCurrency(
                              double.tryParse(
                                      _currentReceipt.ocrRawAmount!) ??
                                  0)
                          : '-',
                      isBold: true,
                    ),

                    const Divider(height: 32, thickness: 0.5),

                    // Klaim
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('KLAIM ANDA',
                            style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                                color: Colors.blueGrey,
                                letterSpacing: 0.5)),
                        if (_currentReceipt.status == 'draft' && !_isEditing)
                          GestureDetector(
                            onTap: () => setState(() => _isEditing = true),
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(
                                color: Colors.blue.shade50,
                                borderRadius: BorderRadius.circular(6),
                                border: Border.all(color: Colors.blue.shade200),
                              ),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Icon(Icons.edit, size: 11, color: Colors.blue.shade800),
                                  const SizedBox(width: 4),
                                  Text(
                                    'Sesuaikan Klaim',
                                    style: TextStyle(
                                      fontSize: 10.5,
                                      fontWeight: FontWeight.bold,
                                      color: Colors.blue.shade800,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 8),

                    if (_isEditing) ...[
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF0F7FF),
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: const Color(0xFF90CAF9)),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'Kategori Pengeluaran',
                              style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 4),
                            DropdownButtonFormField<String>(
                              initialValue: _selectedCategory,
                              isDense: true,
                              decoration: InputDecoration(
                                filled: true,
                                fillColor: Colors.white,
                                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide(color: Colors.grey.shade300)),
                                enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide(color: Colors.grey.shade300)),
                              ),
                              items: _categories
                                  .map((c) => DropdownMenuItem(value: c, child: Text(c, style: const TextStyle(fontSize: 12))))
                                  .toList(),
                              onChanged: (val) {
                                if (val != null) setState(() => _selectedCategory = val);
                              },
                            ),
                            const SizedBox(height: 10),
                            const Text(
                              'Nominal Klaim (Rp)',
                              style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 4),
                            TextFormField(
                              controller: _claimedAmountController,
                              keyboardType: TextInputType.number,
                              decoration: InputDecoration(
                                prefixText: 'Rp ',
                                hintText: '0',
                                filled: true,
                                fillColor: Colors.white,
                                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide(color: Colors.grey.shade300)),
                                enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide(color: Colors.grey.shade300)),
                              ),
                            ),
                            if (_currentReceipt.ocrRawAmount != null) ...[
                              const SizedBox(height: 3),
                              Text(
                                '* Terdeteksi OCR: ${formatCurrency(double.tryParse(_currentReceipt.ocrRawAmount!) ?? 0)}',
                                style: TextStyle(fontSize: 10.5, color: Colors.grey.shade600, fontStyle: FontStyle.italic),
                              ),
                            ],
                            const SizedBox(height: 10),
                            const Text(
                              'Catatan / Keperluan (Opsional)',
                              style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 4),
                            TextFormField(
                              controller: _notesController,
                              maxLines: 2,
                              decoration: InputDecoration(
                                hintText: 'Misal: Makan malam bersama klien di Surabaya',
                                hintStyle: TextStyle(fontSize: 11.5, color: Colors.grey.shade400),
                                filled: true,
                                fillColor: Colors.white,
                                contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide(color: Colors.grey.shade300)),
                                enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide(color: Colors.grey.shade300)),
                              ),
                            ),
                            const SizedBox(height: 14),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.end,
                              children: [
                                TextButton(
                                  onPressed: _isSavingEdit
                                      ? null
                                      : () {
                                          setState(() {
                                            _isEditing = false;
                                            _initFormControllers();
                                          });
                                        },
                                  child: const Text('Batal', style: TextStyle(fontSize: 12)),
                                ),
                                const SizedBox(width: 8),
                                ElevatedButton.icon(
                                  onPressed: _isSavingEdit ? null : _handleSaveEdit,
                                  icon: _isSavingEdit
                                      ? const SizedBox(
                                          width: 14,
                                          height: 14,
                                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                        )
                                      : const Icon(Icons.check, size: 15),
                                  label: Text(
                                    _isSavingEdit ? 'Menyimpan...' : 'Simpan Perubahan',
                                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                  ),
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: const Color(0xFF1E88E5),
                                    foregroundColor: Colors.white,
                                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                    elevation: 0,
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 12),
                    ] else ...[
                      _row(
                        'Nominal klaim awal',
                        _currentReceipt.claimedAmount != null
                            ? formatCurrency(_currentReceipt.claimedAmount!)
                            : (_currentReceipt.displayAmount > 0
                                ? formatCurrency(_currentReceipt.displayAmount)
                                : '-'),
                        isBold: true,
                        valueColor: _currentReceipt.isRejected
                            ? Colors.red.shade700
                            : (_currentReceipt.isApproved || _currentReceipt.isPaid
                                ? const Color(0xFF2E7D32)
                                : null),
                        decoration: _currentReceipt.isRejected ? TextDecoration.lineThrough : null,
                      ),
                      const SizedBox(height: 8),
                      _row('Kategori', _currentReceipt.category ?? '-'),
                      const SizedBox(height: 8),
                      _row('Catatan', _currentReceipt.notes ?? '-'),
                      const SizedBox(height: 8),
                      _row(
                        'Status',
                        _currentReceipt.isRejected
                            ? 'Ditolak'
                            : (_currentReceipt.isPaid
                                ? 'Dibayar'
                                : (_currentReceipt.isApproved
                                    ? 'Disetujui'
                                    : _currentReceipt.displayStatus)),
                        isBold: true,
                        valueColor: _currentReceipt.isRejected
                            ? Colors.red.shade700
                            : (_currentReceipt.isPaid
                                ? const Color(0xFF00695C)
                                : (_currentReceipt.isApproved
                                    ? const Color(0xFF2E7D32)
                                    : null)),
                      ),
                    ],

                    // Anti-fraud indicator jika duplikat (hanya jika belum disetujui finance)
                    if (_currentReceipt.showDuplicateWarning) ...[
                      const SizedBox(height: 12),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF3E5F5),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: const Color(0xFFCE93D8)),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.shield_outlined,
                                color: Color(0xFF7B1FA2), size: 18),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text(
                                    'Peringatan Sistem: Terindikasi Duplikat',
                                    style: TextStyle(
                                      color: Color(0xFF6A1B9A),
                                      fontWeight: FontWeight.bold,
                                      fontSize: 12,
                                    ),
                                  ),
                                  const SizedBox(height: 2),
                                  const Text(
                                    'Struk ini memiliki tanggal, merchant, dan nominal serupa dengan pengajuan lain.',
                                    style: TextStyle(
                                      color: Color(0xFF4A148C),
                                      fontSize: 11,
                                      height: 1.3,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],

                    // Box Info Persetujuan & Pencairan
                    if (_currentReceipt.isApproved || _currentReceipt.isPaid) ...[
                      const SizedBox(height: 16),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: _currentReceipt.isPaid
                              ? const Color(0xFFE0F2F1)
                              : const Color(0xFFE3F2FD),
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(
                            color: _currentReceipt.isPaid
                                ? const Color(0xFF80CBC4)
                                : const Color(0xFF90CAF9),
                          ),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Icon(
                                  _currentReceipt.isPaid
                                      ? Icons.check_circle
                                      : Icons.verified,
                                  color: _currentReceipt.isPaid
                                      ? const Color(0xFF00796B)
                                      : const Color(0xFF1565C0),
                                  size: 18,
                                ),
                                const SizedBox(width: 6),
                                Text(
                                  _currentReceipt.isPaid
                                      ? 'RINCIAN PENCAIRAN REIMBURSEMENT'
                                      : 'RINCIAN PERSETUJUAN FINANCE',
                                  style: TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.bold,
                                    color: _currentReceipt.isPaid
                                        ? const Color(0xFF004D40)
                                        : const Color(0xFF0D47A1),
                                    letterSpacing: 0.5,
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 10),
                            _row(
                              'Nominal disetujui',
                              formatCurrency(_currentReceipt.approvedAmount ??
                                  _currentReceipt.claimedAmount ??
                                  0),
                              isBold: true,
                            ),
                            if (_currentReceipt.approvedAmount != null &&
                                _currentReceipt.claimedAmount != null &&
                                _currentReceipt.approvedAmount! <
                                    _currentReceipt.claimedAmount!) ...[
                              const SizedBox(height: 4),
                              Text(
                                '* Nominal disesuaikan dari klaim awal (${formatCurrency(_currentReceipt.claimedAmount!)})',
                                style: const TextStyle(
                                  fontSize: 10.5,
                                  color: Color(0xFFC62828),
                                  fontStyle: FontStyle.italic,
                                ),
                              ),
                            ],
                            if (_currentReceipt.isPaid) ...[
                              const SizedBox(height: 6),
                              _row(
                                'Metode pembayaran',
                                _currentReceipt.displayPaymentMethod,
                              ),
                              if (_currentReceipt.paymentRefNo != null &&
                                  _currentReceipt.paymentRefNo!.isNotEmpty) ...[
                                const SizedBox(height: 6),
                                _row(
                                  'No. Referensi / Mutasi',
                                  _currentReceipt.paymentRefNo!,
                                ),
                              ],
                              if (_currentReceipt.paidAt != null) ...[
                                const SizedBox(height: 6),
                                _row(
                                  'Waktu pencairan',
                                  _currentReceipt.paidAt!.length >= 10
                                      ? _currentReceipt.paidAt!.substring(0, 10)
                                      : _currentReceipt.paidAt!,
                                ),
                              ],
                            ],
                          ],
                        ),
                      ),
                    ],

                    const SizedBox(height: 20),

                    // Alasan penolakan
                    if (_currentReceipt.isRejected) ...[
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: const Color(0xFFFFEBEE),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: const Color(0xFFFFCDD2)),
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
                              (_currentReceipt.rejectionReason != null &&
                                      _currentReceipt.rejectionReason!.trim().isNotEmpty)
                                  ? _currentReceipt.rejectionReason!.trim()
                                  : 'Pengajuan struk ini ditolak oleh Finance.',
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
                    if (_currentReceipt.status == 'draft' && !_isEditing) ...[
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
                              onPressed: () => setState(() => _isEditing = true),
                              icon: const Icon(Icons.edit_outlined, size: 16),
                              label: const Text('Edit Data'),
                              style: OutlinedButton.styleFrom(
                                foregroundColor: const Color(0xFF1565C0),
                                side: const BorderSide(color: Color(0xFF90CAF9)),
                                padding: const EdgeInsets.symmetric(vertical: 11),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(8),
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: OutlinedButton.icon(
                              onPressed: _isDeleting ? null : _handleFotoUlang,
                              icon: _isDeleting
                                  ? const SizedBox(
                                      width: 14,
                                      height: 14,
                                      child: CircularProgressIndicator(strokeWidth: 2),
                                    )
                                  : const Icon(Icons.camera_alt_outlined, size: 16),
                              label: const Text('Foto Ulang'),
                              style: OutlinedButton.styleFrom(
                                foregroundColor: const Color(0xFF7B1FA2),
                                side: const BorderSide(color: Color(0xFFCE93D8)),
                                padding: const EdgeInsets.symmetric(vertical: 11),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(8),
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: OutlinedButton.icon(
                              onPressed: _isDeleting ? null : _handleDelete,
                              icon: const Icon(Icons.delete_outline, size: 16),
                              label: const Text('Hapus'),
                              style: OutlinedButton.styleFrom(
                                foregroundColor: Colors.red,
                                side: const BorderSide(color: Colors.red),
                                padding: const EdgeInsets.symmetric(vertical: 11),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(8),
                                ),
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

  Widget _row(String label, String value, {bool isBold = false, Color? valueColor, TextDecoration? decoration}) {
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
                  color: valueColor ?? Colors.black87,
                  decoration: decoration,
                  fontSize: 13)),
        ),
      ],
    );
  }

  Widget _miniBreakdownRow(String label, String value, {Color? textColor}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: 11,
            color: Colors.grey.shade600,
            fontWeight: FontWeight.w500,
          ),
        ),
        Text(
          value,
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w600,
            color: textColor ?? Colors.black87,
          ),
        ),
      ],
    );
  }
}
