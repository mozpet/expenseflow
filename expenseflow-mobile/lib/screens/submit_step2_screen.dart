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
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
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
                    fit: BoxFit.cover, cacheWidth: 1200),
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
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _infoBox(
              color: const Color(0xFFFFEBEE),
              border: const Color(0xFFFFCDD2),
              icon: const Icon(Icons.warning_amber_outlined,
                  color: Colors.red, size: 20),
              text: 'OCR gagal membaca struk. Isi data manual di bawah.',
              textColor: Colors.red,
            ),
            const SizedBox(height: 12),
            _buildManualOcrFields(),
          ],
        );

      case _Phase.error:
        return _infoBox(
          color: const Color(0xFFFFEBEE),
          border: const Color(0xFFFFCDD2),
          icon: const Icon(Icons.error_outline, color: Colors.red, size: 20),
          text: _errorMsg ?? 'Gagal mengunggah foto.',
          textColor: Colors.red,
        );

      default:
        return const SizedBox.shrink();
    }
  }

  Widget _buildLockedOcrCard() {
    final amount = _ocrData?['ocr_raw_amount'];
    final merchant = _ocrData?['ocr_raw_merchant'] ?? '-';
    final date = (_ocrData?['ocr_raw_date'] ?? '-').toString();

    final displayAmount = amount != null
        ? formatCurrency((double.tryParse(amount.toString()) ?? 0))
        : '-';

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFF8F9FD),
        borderRadius: BorderRadius.circular(16),
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
            ],
          ),
          const SizedBox(height: 12),
          _lockedRow('Nominal OCR', displayAmount),
          const Divider(height: 20),
          _lockedRow('Merchant OCR', merchant.toString()),
          const Divider(height: 20),
          _lockedRow('Tanggal OCR', date.length >= 10 ? date.substring(0, 10) : date),
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
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
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

  Widget _buildManualOcrFields() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF8E1),
        borderRadius: BorderRadius.circular(16),
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
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
              contentPadding: const EdgeInsets.all(12),
            ),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _merchantController,
            decoration: InputDecoration(
              labelText: 'Nama merchant / toko',
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
              contentPadding: const EdgeInsets.all(12),
            ),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _dateController,
            decoration: InputDecoration(
              labelText: 'Tanggal struk (YYYY-MM-DD)',
              hintText: '2026-05-26',
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
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
                  borderRadius: BorderRadius.circular(8)),
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
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
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
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
            contentPadding: const EdgeInsets.all(16),
          ),
        ),
        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: const Color(0xFFFFF9C4),
            borderRadius: BorderRadius.circular(12),
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
        backgroundColor: Theme.of(context).primaryColor,
        foregroundColor: Colors.white,
        padding: const EdgeInsets.symmetric(vertical: 16),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
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
        borderRadius: BorderRadius.circular(12),
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
      children: [
        Text(label,
            style: const TextStyle(color: Colors.grey, fontSize: 13)),
        Row(
          children: [
            Text(value,
                style: const TextStyle(
                    fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(width: 6),
            const Icon(Icons.lock, size: 14, color: Colors.blueGrey),
          ],
        ),
      ],
    );
  }
}
