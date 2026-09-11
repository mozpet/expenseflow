import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/expense_report_provider.dart';
import '../providers/receipt_provider.dart';
import '../services/api_service.dart';
import '../utils.dart';
import 'detail_laporan_dinas_screen.dart';
import 'status_screen.dart';

enum _Phase { uploading, polling, ready, ocrFailed, submitting, error }

class SubmitStep2Screen extends StatefulWidget {
  final Uint8List imageBytes;
  final String fileName;
  final List<Uint8List>? additionalPhotos;
  final List<String>? additionalFileNames;
  final int? preselectedReportId;
  const SubmitStep2Screen({
    super.key,
    required this.imageBytes,
    required this.fileName,
    this.additionalPhotos,
    this.additionalFileNames,
    this.preselectedReportId,
  });

  @override
  State<SubmitStep2Screen> createState() => _SubmitStep2ScreenState();
}

class _SubmitStep2ScreenState extends State<SubmitStep2Screen> {
  _Phase _phase = _Phase.uploading;
  String? _errorMsg;

  int? _receiptId;
  Map<String, dynamic>? _ocrData;
  int _previewingPhotoIndex = 0;

  bool _isLaporanDinas = false;
  int? _selectedReportId;
  bool _isCreatingNewReport = false;
  final _newReportTitleController = TextEditingController();

  Uint8List get _currentPreviewBytes {
    if (_previewingPhotoIndex == 0 ||
        widget.additionalPhotos == null ||
        widget.additionalPhotos!.isEmpty) {
      return widget.imageBytes;
    }
    final extraIdx = _previewingPhotoIndex - 1;
    if (extraIdx >= 0 && extraIdx < widget.additionalPhotos!.length) {
      return widget.additionalPhotos![extraIdx];
    }
    return widget.imageBytes;
  }

  // Timer estimasi & durasi scan OCR
  Timer? _scanTimer;
  int _elapsedSeconds = 0;
  int _uploadDuration = 0;
  int _lastScanDuration = 0;

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
    _isLaporanDinas = widget.preselectedReportId != null;
    _selectedReportId = widget.preselectedReportId;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        context.read<ExpenseReportProvider>().fetchReports();
      }
    });
    _uploadAndPoll();
  }

  @override
  void dispose() {
    _stopScanTimer();
    _notesController.dispose();
    _claimedAmountController.dispose();
    _amountController.dispose();
    _merchantController.dispose();
    _dateController.dispose();
    _newReportTitleController.dispose();
    super.dispose();
  }

  void _startScanTimer() {
    _stopScanTimer();
    _elapsedSeconds = 0;
    _scanTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      setState(() {
        _elapsedSeconds++;
      });
    });
  }

  void _stopScanTimer() {
    if (_elapsedSeconds > 0) {
      _lastScanDuration = _elapsedSeconds;
    }
    _scanTimer?.cancel();
    _scanTimer = null;
  }

  Future<void> _uploadAndPoll() async {
    _startScanTimer();
    final prov = Provider.of<ReceiptProvider>(context, listen: false);
    try {
      // 1. Upload foto
      final uploaded = await prov.uploadReceipt(
        widget.imageBytes,
        widget.fileName,
        additionalPhotos: widget.additionalPhotos,
        additionalFileNames: widget.additionalFileNames,
        expenseReportId: _selectedReportId,
      );
      _receiptId = uploaded.id;
      if (!mounted) return;
      _uploadDuration = _elapsedSeconds;
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
    } finally {
      _stopScanTimer();
    }
  }

  Future<int?> _resolveReportId(ExpenseReportProvider expProv) async {
    if (!_isLaporanDinas) return null;
    final draftReports = expProv.reports.where((r) => r.isDraft).toList();
    if (_isCreatingNewReport || (_selectedReportId == null && draftReports.isEmpty)) {
      final title = _newReportTitleController.text.trim();
      final finalTitle = title.isNotEmpty ? title : 'Laporan Pengeluaran Dinas';
      final newReport = await expProv.createReport(title: finalTitle);
      return newReport.id;
    }
    return _selectedReportId ?? (draftReports.isNotEmpty ? draftReports.first.id : null);
  }

  Future<void> _saveAndContinue({required bool continueScan}) async {
    if (_receiptId == null) return;
    final wasOcrFailed = _ocrData?['ocr_status'] != 'done';
    final notes = _notesController.text.trim();
    final claimedText = wasOcrFailed
        ? _amountController.text
        : _claimedAmountController.text;
    final claimedAmount = double.tryParse(
        claimedText.replaceAll('.', '').replaceAll(',', '.'));
    final totalAmount = wasOcrFailed
        ? double.tryParse(_amountController.text.replaceAll('.', '').replaceAll(',', '.'))
        : null;
    final receiptDate = wasOcrFailed && _dateController.text.isNotEmpty
        ? _dateController.text.trim()
        : null;
    final vendorName = wasOcrFailed && _merchantController.text.isNotEmpty
        ? _merchantController.text.trim()
        : null;

    setState(() => _phase = _Phase.submitting);
    try {
      final prov = Provider.of<ReceiptProvider>(context, listen: false);
      final expProv = Provider.of<ExpenseReportProvider>(context, listen: false);

      await ApiService.updateClaim(
        _receiptId!,
        category: _selectedCategory,
        notes: notes.isEmpty ? null : notes,
        claimedAmount: claimedAmount,
        totalAmount: totalAmount,
        receiptDate: receiptDate,
        vendorName: vendorName,
      );

      int? targetReportId;
      if (_isLaporanDinas) {
        targetReportId = await _resolveReportId(expProv);
        if (targetReportId != null) {
          await expProv.addReceipts(targetReportId, [_receiptId!]);
        }
      }

      await prov.fetchMyReceipts(forceRefresh: true);

      if (!mounted) return;

      if (continueScan) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Row(
              children: [
                const Icon(Icons.check_circle, color: Colors.white, size: 18),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    _isLaporanDinas
                        ? 'Struk tersimpan ke Laporan Dinas! Silakan foto struk berikutnya.'
                        : 'Struk tersimpan! Silakan foto struk berikutnya.',
                  ),
                ),
              ],
            ),
            backgroundColor: const Color(0xFF2E7D32),
            duration: const Duration(seconds: 3),
          ),
        );
        Navigator.pop(context, {
          'continueScan': true,
          'reportId': targetReportId,
        });
      } else {
        if (_isLaporanDinas && targetReportId != null) {
          Navigator.pushReplacement(
            context,
            MaterialPageRoute(
              builder: (_) => DetailLaporanDinasScreen(reportId: targetReportId!),
            ),
          );
        } else {
          Navigator.pop(context);
        }
      }
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

  Future<void> _submit() async {
    if (_isLaporanDinas) {
      await _saveAndContinue(continueScan: false);
      return;
    }

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
                child: Image.memory(_currentPreviewBytes,
                    fit: BoxFit.cover, cacheWidth: 800),
              ),
            ),
            if (widget.additionalPhotos != null && widget.additionalPhotos!.isNotEmpty) ...[
              const SizedBox(height: 8),
              SizedBox(
                height: 36,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  children: [
                    ChoiceChip(
                      label: const Text('1. Struk Utama', style: TextStyle(fontSize: 11)),
                      selected: _previewingPhotoIndex == 0,
                      onSelected: (val) {
                        if (val) setState(() => _previewingPhotoIndex = 0);
                      },
                    ),
                    const SizedBox(width: 6),
                    for (int i = 0; i < widget.additionalPhotos!.length; i++) ...[
                      ChoiceChip(
                        label: Text(
                          i == 0 ? '2. Slip EDC' : '${i + 2}. Lampiran',
                          style: const TextStyle(fontSize: 11),
                        ),
                        selected: _previewingPhotoIndex == i + 1,
                        onSelected: (val) {
                          if (val) setState(() => _previewingPhotoIndex = i + 1);
                        },
                      ),
                      const SizedBox(width: 6),
                    ],
                  ],
                ),
              ),
            ],
            const SizedBox(height: 16),

            // Panel status OCR
            _buildOcrStatusPanel(),
            const SizedBox(height: 16),

            // Form input (hanya tampil setelah OCR selesai atau gagal non-buram, BUKAN jika foto buram/ditolak)
            if ((_phase == _Phase.ready ||
                (_phase == _Phase.ocrFailed && !_isOcrBlurry) ||
                _phase == _Phase.submitting)) ...[
              _buildFormSection(),
              const SizedBox(height: 16),
              Consumer<ExpenseReportProvider>(
                builder: (context, expProv, _) => _buildDestinationSelector(expProv),
              ),
              const SizedBox(height: 24),
              _buildSubmitButtons(),
            ],
          ],
        ),
      ),
    ),
  );
}

  bool get _isOcrBlurry {
    final ocrError = (_ocrData?['ocr_error'] ?? '').toString().toLowerCase();
    return ocrError.contains('buram') ||
        ocrError.contains('blur') ||
        ocrError.contains('goyang') ||
        ocrError.contains('tidak terbaca') ||
        ocrError.contains('tidak terdeteksi');
  }

  Widget _buildOcrStatusPanel() {
    switch (_phase) {
      case _Phase.uploading:
      case _Phase.polling:
        return _buildScanningProgressCard();

      case _Phase.ready:
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _infoBox(
              color: const Color(0xFFE8F5E9),
              border: const Color(0xFFC8E6C9),
              icon: const Icon(Icons.check_circle_outline,
                  color: Colors.green, size: 20),
              text: _lastScanDuration > 0
                  ? 'OCR berhasil — data dikunci otomatis (selesai dalam $_lastScanDuration detik)'
                  : 'OCR berhasil — data dikunci otomatis',
              textColor: Colors.green,
            ),
            const SizedBox(height: 12),
            _buildLockedOcrCard(),
          ],
        );

      case _Phase.ocrFailed:
        final ocrError = (_ocrData?['ocr_error'] ?? '').toString();

        // ─── Kasus Khusus: Foto Buram / Bergoyang ────────────────
        if (_isOcrBlurry) {
          return _buildBlurryRejectionCard(ocrError);
        }

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

  Widget _buildBlurryRejectionCard(String ocrError) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF5F5),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFFF8A80), width: 1.5),
        boxShadow: [
          BoxShadow(
            color: Colors.red.withValues(alpha: 0.06),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFEBEE),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Icon(
                  Icons.no_photography_outlined,
                  color: Color(0xFFD32F2F),
                  size: 28,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Foto Struk Ditolak: Buram / Bergoyang',
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 14.5,
                        color: Color(0xFFB71C1C),
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      ocrError.isNotEmpty
                          ? ocrError
                          : 'Sistem mendeteksi foto struk buram atau bergoyang sehingga angka tidak dapat dibaca jelas.',
                      style: const TextStyle(
                        fontSize: 12,
                        color: Color(0xFFC62828),
                        height: 1.4,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: const Color(0xFFFFCDD2)),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(Icons.shield_outlined,
                    size: 16, color: Color(0xFFE53935)),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'Demi akurasi pembacaan OCR dan mencegah manipulasi data, pengisian manual dilarang untuk foto yang tidak jelas. Anda wajib mengambil foto ulang struk fisik Anda.',
                    style: TextStyle(
                      fontSize: 11,
                      color: Colors.grey.shade800,
                      height: 1.35,
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          ElevatedButton.icon(
            onPressed: () {
              if (_receiptId != null) {
                Provider.of<ReceiptProvider>(context, listen: false)
                    .deleteDraft(_receiptId!);
              }
              Navigator.pop(context);
            },
            icon: const Icon(Icons.camera_alt, color: Colors.white, size: 18),
            label: const Text(
              '📸 Ambil Foto Ulang Struk',
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 14,
                color: Colors.white,
              ),
            ),
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFFD32F2F),
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(8),
              ),
              elevation: 0,
            ),
          ),
        ],
      ),
    );
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

  Widget _buildDestinationSelector(ExpenseReportProvider expProv) {
    final draftReports = expProv.reports.where((r) => r.isDraft).toList();

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: _isLaporanDinas ? const Color(0xFF7C3AED) : Colors.grey.shade300,
          width: _isLaporanDinas ? 1.5 : 1,
        ),
        boxShadow: [
          BoxShadow(
            color: (_isLaporanDinas ? const Color(0xFF7C3AED) : Colors.grey).withValues(alpha: 0.06),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                _isLaporanDinas ? Icons.folder_special : Icons.receipt_long,
                size: 18,
                color: _isLaporanDinas ? const Color(0xFF7C3AED) : const Color(0xFF1E88E5),
              ),
              const SizedBox(width: 8),
              const Text(
                'Tipe Pengajuan Klaim',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              // Option 1: Klaim Satuan
              Expanded(
                child: InkWell(
                  onTap: () {
                    setState(() {
                      _isLaporanDinas = false;
                    });
                  },
                  borderRadius: BorderRadius.circular(8),
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
                    decoration: BoxDecoration(
                      color: !_isLaporanDinas ? const Color(0xFFEBF5FF) : Colors.grey.shade50,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                        color: !_isLaporanDinas ? const Color(0xFF1E88E5) : Colors.grey.shade300,
                        width: !_isLaporanDinas ? 1.5 : 1,
                      ),
                    ),
                    child: Column(
                      children: [
                        Icon(
                          Icons.description_outlined,
                          size: 20,
                          color: !_isLaporanDinas ? const Color(0xFF1E88E5) : Colors.grey.shade600,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Klaim Satuan',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                            color: !_isLaporanDinas ? const Color(0xFF1565C0) : Colors.grey.shade700,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Kirim 1 struk mandiri',
                          style: TextStyle(fontSize: 10, color: Colors.grey.shade600),
                          textAlign: TextAlign.center,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              // Option 2: Gabung ke Laporan Dinas
              Expanded(
                child: InkWell(
                  onTap: () {
                    setState(() {
                      _isLaporanDinas = true;
                      if (_selectedReportId == null && draftReports.isNotEmpty) {
                        _selectedReportId = draftReports.first.id;
                      }
                    });
                  },
                  borderRadius: BorderRadius.circular(8),
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
                    decoration: BoxDecoration(
                      color: _isLaporanDinas ? const Color(0xFFF5F3FF) : Colors.grey.shade50,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                        color: _isLaporanDinas ? const Color(0xFF7C3AED) : Colors.grey.shade300,
                        width: _isLaporanDinas ? 1.5 : 1,
                      ),
                    ),
                    child: Column(
                      children: [
                        Icon(
                          Icons.folder_shared_outlined,
                          size: 20,
                          color: _isLaporanDinas ? const Color(0xFF7C3AED) : Colors.grey.shade600,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Laporan Dinas',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                            color: _isLaporanDinas ? const Color(0xFF6D28D9) : Colors.grey.shade700,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Bundling multi-struk',
                          style: TextStyle(fontSize: 10, color: Colors.grey.shade600),
                          textAlign: TextAlign.center,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ),
          if (_isLaporanDinas) ...[
            const SizedBox(height: 12),
            const Divider(height: 1),
            const SizedBox(height: 12),
            if (draftReports.isNotEmpty && !_isCreatingNewReport) ...[
              const Text(
                'Pilih Laporan Dinas Tujuan:',
                style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, color: Color(0xFF4C1D95)),
              ),
              const SizedBox(height: 6),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.purple.shade200),
                ),
                child: DropdownButtonHideUnderline(
                  child: DropdownButton<int>(
                    value: _selectedReportId != null && draftReports.any((r) => r.id == _selectedReportId)
                        ? _selectedReportId
                        : draftReports.first.id,
                    isExpanded: true,
                    items: draftReports.map((r) {
                      return DropdownMenuItem<int>(
                        value: r.id,
                        child: Text(
                          '${r.title} (${r.reportNumber})',
                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                          overflow: TextOverflow.ellipsis,
                        ),
                      );
                    }).toList(),
                    onChanged: (val) {
                      if (val != null) {
                        setState(() {
                          _selectedReportId = val;
                        });
                      }
                    },
                  ),
                ),
              ),
              const SizedBox(height: 6),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  onPressed: () {
                    setState(() {
                      _isCreatingNewReport = true;
                      _selectedReportId = null;
                    });
                  },
                  icon: const Icon(Icons.add, size: 14, color: Color(0xFF7C3AED)),
                  label: const Text(
                    'Buat Laporan Baru',
                    style: TextStyle(fontSize: 11.5, color: Color(0xFF7C3AED), fontWeight: FontWeight.bold),
                  ),
                  style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact),
                ),
              ),
            ] else ...[
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text(
                    'Judul Laporan Dinas Baru:',
                    style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, color: Color(0xFF4C1D95)),
                  ),
                  if (draftReports.isNotEmpty)
                    TextButton(
                      onPressed: () {
                        setState(() {
                          _isCreatingNewReport = false;
                          _selectedReportId = draftReports.first.id;
                        });
                      },
                      style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact),
                      child: const Text('Pilih yg sudah ada', style: TextStyle(fontSize: 11)),
                    ),
                ],
              ),
              const SizedBox(height: 6),
              TextField(
                controller: _newReportTitleController,
                decoration: InputDecoration(
                  hintText: 'Contoh: Perjalanan Dinas Surabaya',
                  hintStyle: TextStyle(fontSize: 12, color: Colors.grey.shade400),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(8),
                    borderSide: const BorderSide(color: Color(0xFF7C3AED), width: 1.5),
                  ),
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _buildSubmitButtons() {
    final isLoading = _phase == _Phase.submitting;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Tombol 1: Simpan & Foto Struk Lainnya (Continuous Batch Scan)
        OutlinedButton.icon(
          onPressed: isLoading ? null : () => _saveAndContinue(continueScan: true),
          icon: const Icon(Icons.add_a_photo_outlined, size: 18),
          label: const Text(
            'Simpan & Foto Struk Berikutnya',
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold),
          ),
          style: OutlinedButton.styleFrom(
            foregroundColor: _isLaporanDinas ? const Color(0xFF7C3AED) : const Color(0xFF0088FF),
            side: BorderSide(
              color: _isLaporanDinas ? const Color(0xFF7C3AED) : const Color(0xFF0088FF),
              width: 1.5,
            ),
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            backgroundColor: _isLaporanDinas ? const Color(0xFFF5F3FF) : const Color(0xFFF0F7FF),
          ),
        ),
        const SizedBox(height: 10),

        // Tombol 2: Submit / Selesai
        ElevatedButton.icon(
          onPressed: isLoading
              ? null
              : (_isLaporanDinas ? () => _saveAndContinue(continueScan: false) : _submit),
          icon: isLoading
              ? const SizedBox.shrink()
              : Icon(
                  _isLaporanDinas ? Icons.check_circle_outline : Icons.send_rounded,
                  size: 18,
                  color: Colors.white,
                ),
          label: isLoading
              ? const SizedBox(
                  height: 20,
                  width: 20,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                )
              : Text(
                  _isLaporanDinas ? 'Selesai & Buka Laporan Dinas' : 'Kirim ke Finance',
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                ),
          style: ElevatedButton.styleFrom(
            backgroundColor: _isLaporanDinas ? const Color(0xFF7C3AED) : const Color(0xFF0088FF),
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 15),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            elevation: 2,
          ),
        ),
      ],
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

  /// Card progres scan dengan timer real-time, estimasi waktu, dan deskripsi dinamis
  Widget _buildScanningProgressCard() {
    String stepTitle;
    String stepDesc;
    String estimateText;
    Color primaryColor;
    Color bgColor;
    Color borderColor;
    double progress;

    if (_phase == _Phase.uploading) {
      stepTitle = 'Tahap 1/2: Mengunggah Foto Struk...';
      stepDesc = 'Mengirim foto struk via koneksi aman ke server';
      primaryColor = const Color(0xFF1565C0);
      bgColor = const Color(0xFFE3F2FD);
      borderColor = const Color(0xFF90CAF9);
      estimateText = 'Estimasi upload: ~2 – 4 detik';
      progress = (_elapsedSeconds / 4.0).clamp(0.12, 0.42);
    } else {
      // _Phase.polling
      primaryColor = const Color(0xFF0077CC);
      bgColor = const Color(0xFFF0F7FF);
      borderColor = const Color(0xFFBAE6FD);
      estimateText = 'Estimasi AI: ~7 – 9 detik';

      final ocrElapsed = (_elapsedSeconds - _uploadDuration).clamp(0, 999);

      if (ocrElapsed <= 2) {
        stepTitle = 'Tahap 2/2: Menganalisis dengan AI Gemini...';
        stepDesc = 'AI sedang membaca teks struk & mendeteksi toko';
      } else if (ocrElapsed <= 5) {
        stepTitle = 'Tahap 2/2: Mengekstrak Nominal & Item...';
        stepDesc = 'Mengekstrak tanggal transaksi, total bayar, & item produk';
      } else if (ocrElapsed <= 8) {
        stepTitle = 'Tahap 2/2: Menyusun Rincian Belanja...';
        stepDesc = 'Menghitung subtotal, pajak, diskon, & rincian belanja';
      } else {
        stepTitle = 'Tahap 2/2: Memverifikasi Data Struk...';
        stepDesc = 'Menyelesaikan validasi anti-fraud dan kalkulasi akhir';
      }

      progress = (0.42 + (ocrElapsed / 8.0) * 0.52).clamp(0.42, 0.95);
    }

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: borderColor, width: 1.2),
        boxShadow: [
          BoxShadow(
            color: primaryColor.withValues(alpha: 0.05),
            blurRadius: 10,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.white,
                  shape: BoxShape.circle,
                  boxShadow: [
                    BoxShadow(
                      color: primaryColor.withValues(alpha: 0.15),
                      blurRadius: 6,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                child: SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2.5,
                    valueColor: AlwaysStoppedAnimation(primaryColor),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      stepTitle,
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 13.5,
                        color: primaryColor,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      stepDesc,
                      style: TextStyle(
                        fontSize: 11.5,
                        color: Colors.grey.shade700,
                      ),
                    ),
                  ],
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: borderColor),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.timer_outlined, size: 13, color: primaryColor),
                    const SizedBox(width: 4),
                    Text(
                      '${_elapsedSeconds}s',
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 12,
                        color: primaryColor,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 6,
              backgroundColor: Colors.white,
              valueColor: AlwaysStoppedAnimation(primaryColor),
            ),
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.hourglass_bottom,
                      size: 12, color: primaryColor.withValues(alpha: 0.8)),
                  const SizedBox(width: 4),
                  Text(
                    estimateText,
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: primaryColor.withValues(alpha: 0.85),
                    ),
                  ),
                ],
              ),
              Text(
                'AI Gemini Vision',
                style: TextStyle(
                  fontSize: 10.5,
                  fontWeight: FontWeight.w500,
                  color: Colors.grey.shade600,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
