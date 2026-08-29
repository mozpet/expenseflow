import 'dart:convert';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/receipt_provider.dart';
import '../utils.dart';
import 'status_screen.dart';

enum _Phase { uploading, polling, ready, ocrFailed, submitting, error }

class SubmitStep2Screen extends StatefulWidget {
  final Uint8List imageBytes;
  final String fileName;
  const SubmitStep2Screen({
    super.key,
    required this.imageBytes,
    required this.fileName,
  });

  @override
  State<SubmitStep2Screen> createState() => _SubmitStep2ScreenState();
}

class _SubmitStep2ScreenState extends State<SubmitStep2Screen> {
  _Phase _phase = _Phase.uploading;
  String? _errorMsg;

  int? _receiptId;
  Map<String, dynamic>? _ocrData;

  String _selectedCategory = 'Lain-lain / Operasional';
  final _notesController = TextEditingController();

  // Kolom claimed_amount (bisa diedit karyawan, baik OCR sukses maupun gagal)
  final _claimedAmountController = TextEditingController();
  bool _claimedAmountEdited = false;

  // Kolom manual tambahan (hanya dipakai saat OCR gagal)
  final _amountController = TextEditingController();
  final _merchantController = TextEditingController();
  final _dateController = TextEditingController();

  static const _categories = [
    'Alat tulis & perlengkapan',
    'Konsumsi / Makan Siang',
    'Transportasi & BBM',
    'Akomodasi & Tiket',
    'Lain-lain / Operasional',
  ];

  @override
  void initState() {
    super.initState();
    _uploadAndPoll();
  }

  @override
  void dispose() {
    _notesController.dispose();
    _claimedAmountController.dispose();
    _amountController.dispose();
    _merchantController.dispose();
    _dateController.dispose();
    super.dispose();
  }

  Future<void> _uploadAndPoll() async {
    final prov = Provider.of<ReceiptProvider>(context, listen: false);
    try {
      // 1. Upload foto
      final uploaded =
          await prov.uploadReceipt(widget.imageBytes, widget.fileName);
      _receiptId = uploaded.id;
      if (!mounted) return;
      setState(() => _phase = _Phase.polling);

      // 2. Poll OCR
      final data = await prov.pollOcrStatus(_receiptId!);
      if (!mounted) return;
      _ocrData = data;
      final ocrStatus = (data['ocr_status'] ?? 'pending').toString();

      // Pre-fill claimed_amount dari OCR jika tersedia (tanpa desimal jika bulat)
      final ocrAmount = data['ocr_raw_amount'];
      if (ocrAmount != null && ocrStatus == 'done') {
        final parsed = double.tryParse(ocrAmount.toString()) ?? 0;
        _claimedAmountController.text = parsed == parsed.truncateToDouble()
            ? parsed.toInt().toString()
            : parsed.toString();
      }

      setState(() => _phase =
          ocrStatus == 'done' ? _Phase.ready : _Phase.ocrFailed);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _errorMsg = e.toString();
        _phase = _Phase.error;
      });
    }
  }

  Future<void> _submit() async {
    final notes = _notesController.text.trim();
    final prov = Provider.of<ReceiptProvider>(context, listen: false);
    final wasOcrFailed = _ocrData?['ocr_status'] != 'done';
    setState(() => _phase = _Phase.submitting);
    try {
      // Parse claimed_amount — selalu dikirim (baik OCR sukses maupun gagal)
      final claimedText = wasOcrFailed
          ? _amountController.text
          : _claimedAmountController.text;
      final claimedAmount = double.tryParse(
          claimedText.replaceAll('.', '').replaceAll(',', '.'));

      final receipt = await prov.finalizeAndSubmit(
        id: _receiptId!,
        category: _selectedCategory,
        notes: notes.isEmpty ? null : notes,
        claimedAmount: claimedAmount,
        totalAmount: wasOcrFailed
            ? double.tryParse(_amountController.text.replaceAll('.', '').replaceAll(',', '.'))
            : null,
        receiptDate: wasOcrFailed && _dateController.text.isNotEmpty
            ? _dateController.text.trim()
            : null,
        vendorName: wasOcrFailed && _merchantController.text.isNotEmpty
            ? _merchantController.text.trim()
            : null,
      );
      if (!mounted) return;
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => StatusScreen(receipt: receipt)),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _phase = _ocrData?['ocr_status'] == 'done'
          ? _Phase.ready
          : _Phase.ocrFailed);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Review & Submit'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(24),
          child: Container(
            padding: const EdgeInsets.only(bottom: 8),
            child: const Text('Langkah 2 dari 2',
                style: TextStyle(color: Colors.white70, fontSize: 12)),
          ),
        ),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 36),
          child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Preview foto
            Container(
              height: 200,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.blue.shade200),
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.memory(widget.imageBytes,
                    fit: BoxFit.cover, cacheWidth: 800),
              ),
            ),
            const SizedBox(height: 16),

            // Panel status OCR
            _buildOcrStatusPanel(),
            const SizedBox(height: 16),

            // Form input (hanya tampil setelah OCR selesai/gagal)
            if (_phase == _Phase.ready || _phase == _Phase.ocrFailed ||
                _phase == _Phase.submitting) ...[
              _buildFormSection(),
              const SizedBox(height: 32),
              _buildSubmitButton(),
            ],
          ],
        ),
      ),
    ),
  );
}

  Widget _buildOcrStatusPanel() {
    switch (_phase) {
      case _Phase.uploading:
        return _infoBox(
          color: const Color(0xFFE3F2FD),
          border: const Color(0xFFBBDEFB),
          icon: const SizedBox(
              width: 18, height: 18,
              child: CircularProgressIndicator(strokeWidth: 2)),
          text: 'Mengunggah foto ke server...',
          textColor: const Color(0xFF1565C0),
        );

      case _Phase.polling:
        return _infoBox(
          color: const Color(0xFFFFF9C4),
          border: const Color(0xFFFFF59D),
          icon: const SizedBox(
              width: 18, height: 18,
              child: CircularProgressIndicator(strokeWidth: 2,
                  valueColor: AlwaysStoppedAnimation(Color(0xFF827717)))),
          text: 'OCR sedang membaca struk... Harap tunggu.',
          textColor: const Color(0xFF827717),
        );

      case _Phase.ready:
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _infoBox(
              color: const Color(0xFFE8F5E9),
              border: const Color(0xFFC8E6C9),
              icon: const Icon(Icons.check_circle_outline,
                  color: Colors.green, size: 20),
              text: 'OCR berhasil — data dikunci otomatis',
              textColor: Colors.green,
            ),
            const SizedBox(height: 12),
            _buildLockedOcrCard(),
          ],
        );

      case _Phase.ocrFailed:
        final ocrError = (_ocrData?['ocr_error'] ?? '').toString();
        final isRateLimited = ocrError.contains('429') ||
            ocrError.contains('Too Many Requests') ||
            ocrError.contains('RESOURCE_EXHAUSTED') ||
            ocrError.contains('kuota') ||
            ocrError.contains('sibuk') ||
            ocrError.contains('menit');

        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: isRateLimited
                    ? const Color(0xFFFFF8E1)
                    : const Color(0xFFFFEBEE),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(
                  color: isRateLimited
                      ? const Color(0xFFFFE082)
                      : const Color(0xFFFFCDD2),
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(
                        isRateLimited
                            ? Icons.hourglass_top_rounded
                            : Icons.warning_amber_outlined,
                        color: isRateLimited
                            ? const Color(0xFFE65100)
                            : Colors.red,
                        size: 22,
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              isRateLimited
                                  ? 'Layanan OCR Sedang Sibuk (Limit Kuota)'
                                  : 'OCR Gagal Membaca Struk',
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 13,
                                color: isRateLimited
                                    ? const Color(0xFFE65100)
                                    : Colors.red.shade900,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              isRateLimited
                                  ? 'Batas scan OCR menit ini telah penuh (15 RPM). Silakan tunggu 1 menit lalu tekan tombol Coba Scan Ulang, atau isi rincian struk secara manual di bawah.'
                                  : 'Sistem tidak dapat membaca teks pada foto secara otomatis. Silakan isi rincian struk manual di bawah atau coba scan ulang.',
                              style: TextStyle(
                                fontSize: 11.5,
                                color: isRateLimited
                                    ? const Color(0xFFBF360C)
                                    : Colors.red.shade800,
                                height: 1.35,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      onPressed: () {
                        setState(() {
                          _phase = _Phase.uploading;
                        });
                        _uploadAndPoll();
                      },
                      icon: const Icon(Icons.refresh, size: 16),
                      label: const Text('Coba Scan Ulang Sekarang',
                          style: TextStyle(
                              fontSize: 12, fontWeight: FontWeight.bold)),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: isRateLimited
                            ? const Color(0xFFE65100)
                            : Colors.red.shade800,
                        side: BorderSide(
                          color: isRateLimited
                              ? const Color(0xFFFFB74D)
                              : Colors.red.shade300,
                        ),
                        backgroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 8),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            _buildManualOcrFields(),
          ],
        );

      case _Phase.error:
        return Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: const Color(0xFFFFEBEE),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: const Color(0xFFFFCDD2)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.error_outline, color: Colors.red, size: 20),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      _errorMsg ?? 'Gagal mengunggah foto.',
                      style: const TextStyle(color: Colors.red, fontSize: 12),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: () {
                    setState(() => _phase = _Phase.uploading);
                    _uploadAndPoll();
                  },
                  icon: const Icon(Icons.refresh, size: 16),
                  label: const Text('Coba Lagi',
                      style: TextStyle(
                          fontSize: 12, fontWeight: FontWeight.bold)),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.red,
                    side: const BorderSide(color: Colors.red),
                    backgroundColor: Colors.white,
                  ),
                ),
              ),
            ],
          ),
        );

      default:
        return const SizedBox.shrink();
    }
  }

  Widget _buildLockedOcrCard() {
    final amount = _ocrData?['ocr_raw_amount'];
    final subtotal = _ocrData?['ocr_raw_subtotal'];
    final tax = _ocrData?['ocr_raw_tax'];
    final discount = _ocrData?['ocr_raw_discount'];
    final merchant = _ocrData?['ocr_raw_merchant'] ?? '-';
    final date = (_ocrData?['ocr_raw_date'] ?? '-').toString();
    dynamic rawItems = _ocrData?['ocr_raw_items'];

    if (rawItems is String) {
      try {
        rawItems = jsonDecode(rawItems);
      } catch (_) {}
    }

    List<ReceiptItem> items = [];
    if (rawItems is List) {
      for (final it in rawItems) {
        if (it is Map<String, dynamic>) {
          items.add(ReceiptItem.fromJson(it));
        } else if (it is Map) {
          items.add(ReceiptItem.fromJson(Map<String, dynamic>.from(it)));
        }
      }
    }

    final displayAmount = amount != null
        ? formatCurrency((double.tryParse(amount.toString()) ?? 0))
        : '-';

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFF8F9FD),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.blue.shade100),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text('Data dari struk',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: Colors.green.shade50,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: Colors.green.shade200),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.auto_awesome, size: 12, color: Colors.green.shade700),
                    const SizedBox(width: 4),
                    Text(
                      'AI OCR',
                      style: TextStyle(
                        fontSize: 10.5,
                        fontWeight: FontWeight.bold,
                        color: Colors.green.shade800,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          _lockedRow('Merchant OCR', merchant.toString()),
          const Divider(height: 18),
          _lockedRow('Tanggal OCR', date.length >= 10 ? date.substring(0, 10) : date),
          const Divider(height: 18),

          // Rincian Item Belanjaan jika terdeteksi
          if (items.isNotEmpty) ...[
            Row(
              children: [
                const Icon(Icons.receipt_outlined, size: 15, color: Color(0xFF1E88E5)),
                const SizedBox(width: 6),
                Text(
                  'Rincian Belanja (${items.length} item)',
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 12,
                    color: Color(0xFF1E88E5),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Container(
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.grey.shade200),
              ),
              child: ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                padding: const EdgeInsets.symmetric(vertical: 4),
                itemCount: items.length,
                separatorBuilder: (context, index) => Divider(height: 1, color: Colors.grey.shade100),
                itemBuilder: (context, idx) {
                  final item = items[idx];
                  return Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                item.name,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w600,
                                  fontSize: 12,
                                  color: Colors.black87,
                                ),
                              ),
                              if (item.qty > 1 || item.price > 0)
                                Text(
                                  '${item.qty}x @ ${formatCurrency(item.price)}',
                                  style: TextStyle(
                                    fontSize: 10.5,
                                    color: Colors.grey.shade600,
                                  ),
                                ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          formatCurrency(item.total),
                          style: const TextStyle(
                            fontWeight: FontWeight.w600,
                            fontSize: 12,
                            color: Colors.black87,
                          ),
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
            const SizedBox(height: 10),
          ],

          // Breakdown Subtotal, Diskon, Pajak
          if (subtotal != null || discount != null || tax != null) ...[
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.grey.shade200),
              ),
              child: Column(
                children: [
                  if (subtotal != null) ...[
                    _summaryMiniRow('Subtotal', formatCurrency(double.tryParse(subtotal.toString()) ?? 0)),
                  ],
                  if (discount != null && (double.tryParse(discount.toString()) ?? 0) > 0) ...[
                    if (subtotal != null) const SizedBox(height: 5),
                    _summaryMiniRow(
                      'Diskon / Promo',
                      '- ${formatCurrency(double.tryParse(discount.toString()) ?? 0)}',
                      textColor: const Color(0xFF2E7D32),
                    ),
                  ],
                  if (tax != null && (double.tryParse(tax.toString()) ?? 0) > 0) ...[
                    if (subtotal != null || discount != null) const SizedBox(height: 5),
                    _summaryMiniRow(
                      'Pajak / PPN',
                      '+ ${formatCurrency(double.tryParse(tax.toString()) ?? 0)}',
                      textColor: const Color(0xFFE65100),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 10),
          ],

          _lockedRow('Total Bayar OCR', displayAmount),
          const Divider(height: 20),
          const Text('Nominal klaim (bisa diubah)',
              style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
          const SizedBox(height: 8),
          TextField(
            controller: _claimedAmountController,
            keyboardType: TextInputType.number,
            onChanged: (v) {
              final edited = v != (amount?.toString() ?? '');
              if (edited != _claimedAmountEdited) {
                setState(() => _claimedAmountEdited = edited);
              }
            },
            decoration: InputDecoration(
              labelText: 'Nominal klaim',
              prefixText: 'Rp ',
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              contentPadding: const EdgeInsets.all(12),
            ),
          ),
          if (_claimedAmountEdited) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: Colors.orange.shade50,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.orange.shade200),
              ),
              child: Row(
                children: [
                  Icon(Icons.info_outline, size: 16, color: Colors.orange.shade700),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Nominal klaim berbeda dari OCR. Finance akan melihat selisihnya.',
                      style: TextStyle(
                        color: Colors.orange.shade800,
                        fontSize: 11,
                        fontStyle: FontStyle.italic,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _summaryMiniRow(String label, String value, {Color? textColor}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: 11.5,
            color: Colors.grey.shade600,
            fontWeight: FontWeight.w500,
          ),
        ),
        Text(
          value,
          style: TextStyle(
            fontSize: 11.5,
            fontWeight: FontWeight.w600,
            color: textColor ?? Colors.black87,
          ),
        ),
      ],
    );
  }

  Widget _buildManualOcrFields() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF8E1),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: Colors.orange.shade100),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Isi data struk secara manual',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          const SizedBox(height: 12),
          TextField(
            controller: _amountController,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(
              labelText: 'Nominal (angka, tanpa titik)',
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              contentPadding: const EdgeInsets.all(12),
            ),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _merchantController,
            decoration: InputDecoration(
              labelText: 'Nama merchant / toko',
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              contentPadding: const EdgeInsets.all(12),
            ),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _dateController,
            decoration: InputDecoration(
              labelText: 'Tanggal struk (YYYY-MM-DD)',
              hintText: '2026-05-26',
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              contentPadding: const EdgeInsets.all(12),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFormSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text('Keterangan tambahan',
                style: TextStyle(fontWeight: FontWeight.bold)),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                  color: Colors.green.shade50,
                  borderRadius: BorderRadius.circular(6)),
              child: const Text('Bisa diisi',
                  style: TextStyle(
                      color: Colors.green,
                      fontSize: 10,
                      fontWeight: FontWeight.bold)),
            ),
          ],
        ),
        const SizedBox(height: 12),
        const Text('Kategori pengeluaran',
            style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
        const SizedBox(height: 8),
        DropdownButtonFormField<String>(
          initialValue: _selectedCategory,
          decoration: InputDecoration(
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
            contentPadding: const EdgeInsets.all(16),
          ),
          items: _categories
              .map((v) => DropdownMenuItem(value: v, child: Text(v)))
              .toList(),
          onChanged: (v) {
            if (v != null) setState(() => _selectedCategory = v);
          },
        ),
        const SizedBox(height: 16),
        const Text('Keterangan / tujuan',
            style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
        const SizedBox(height: 8),
        TextField(
          controller: _notesController,
          maxLines: 3,
          decoration: InputDecoration(
            hintText: 'Tulis tujuan pengeluaran...',
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
            contentPadding: const EdgeInsets.all(16),
          ),
        ),
        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: const Color(0xFFFFF9C4),
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: const Color(0xFFFFF59D)),
          ),
          child: const Text(
            'Merchant dan tanggal diambil dari foto struk (terkunci). Nominal klaim bisa diubah.',
            style: TextStyle(
                color: Color(0xFF5D4037), fontSize: 12, height: 1.4),
          ),
        ),
      ],
    );
  }

  Widget _buildSubmitButton() {
    final isLoading = _phase == _Phase.submitting;
    return ElevatedButton(
      onPressed: isLoading ? null : _submit,
      style: ElevatedButton.styleFrom(
        backgroundColor: const Color(0xFF0088FF),
        foregroundColor: Colors.white,
        padding: const EdgeInsets.symmetric(vertical: 16),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
      ),
      child: isLoading
          ? const SizedBox(
              height: 20,
              width: 20,
              child: CircularProgressIndicator(
                  strokeWidth: 2, color: Colors.white))
          : const Text('Kirim ke Finance',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
    );
  }

  Widget _infoBox({
    required Color color,
    required Color border,
    required Widget icon,
    required String text,
    required Color textColor,
  }) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: border),
      ),
      child: Row(
        children: [
          icon,
          const SizedBox(width: 10),
          Expanded(
            child: Text(text,
                style: TextStyle(
                    color: textColor,
                    fontWeight: FontWeight.bold,
                    fontSize: 13)),
          ),
        ],
      ),
    );
  }

  Widget _lockedRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: const TextStyle(color: Colors.grey, fontSize: 13)),
        const SizedBox(width: 12),
        Flexible(
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Flexible(
                child: Text(
                  value,
                  textAlign: TextAlign.end,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 14),
                ),
              ),
              const SizedBox(width: 6),
              const Icon(Icons.lock, size: 14, color: Colors.blueGrey),
            ],
          ),
        ),
      ],
    );
  }
}
