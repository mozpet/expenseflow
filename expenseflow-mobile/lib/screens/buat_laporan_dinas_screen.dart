import 'dart:async';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import '../providers/expense_report_provider.dart';
import '../providers/receipt_provider.dart';
import '../utils.dart';
import '../widgets/custom_date_range_picker_dialog.dart';
import '../widgets/receipt_thumbnail.dart';
import '../widgets/skeleton.dart';
import 'detail_laporan_dinas_screen.dart';
import 'detail_pengajuan_screen.dart';

class BuatLaporanDinasScreen extends StatefulWidget {
  final List<int>? initialSelectedReceiptIds;

  const BuatLaporanDinasScreen({
    super.key,
    this.initialSelectedReceiptIds,
  });

  @override
  State<BuatLaporanDinasScreen> createState() => _BuatLaporanDinasScreenState();
}

class _BuatLaporanDinasScreenState extends State<BuatLaporanDinasScreen> {
  final _formKey = GlobalKey<FormState>();
  final _titleController = TextEditingController();
  final _purposeController = TextEditingController();

  DateTimeRange? _dateRange;
  final Set<int> _selectedReceiptIds = {};
  bool _isSaving = false;
  bool _isUploadingNewReceipts = false;
  String _uploadStatusText = '';
  final ImagePicker _picker = ImagePicker();
  Timer? _ocrPollingTimer;

  Future<void> _handleCaptureFromCamera() async {
    final file = await _picker.pickImage(
      source: ImageSource.camera,
      maxWidth: 1280,
      maxHeight: 1280,
      imageQuality: 80,
    );
    if (file == null) return;
    await _uploadPhotos([file]);
  }

  Future<void> _handlePickFromGallery() async {
    final files = await _picker.pickMultiImage(
      maxWidth: 1280,
      maxHeight: 1280,
      imageQuality: 80,
    );
    if (files.isEmpty) return;
    await _uploadPhotos(files);
  }

  Future<void> _handleRetakeReceipt(ReceiptRecord r) async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Foto Ulang Struk ${r.receiptNumber}',
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 6),
              const Text(
                'Ambil foto struk fisik secara tegak lurus di tempat yang terang untuk hasil optimal.',
                style: TextStyle(fontSize: 12.5, color: Colors.grey),
              ),
              const SizedBox(height: 16),
              ListTile(
                leading: const CircleAvatar(
                  backgroundColor: Color(0xFFF3E5F5),
                  child: Icon(Icons.camera_alt, color: Color(0xFF7B1FA2)),
                ),
                title: const Text('Kamera', style: TextStyle(fontWeight: FontWeight.w600)),
                subtitle: const Text('Foto langsung struk fisik sekarang', style: TextStyle(fontSize: 11)),
                onTap: () => Navigator.pop(ctx, ImageSource.camera),
              ),
              ListTile(
                leading: const CircleAvatar(
                  backgroundColor: Color(0xFFE3F2FD),
                  child: Icon(Icons.photo_library, color: Color(0xFF1976D2)),
                ),
                title: const Text('Galeri', style: TextStyle(fontWeight: FontWeight.w600)),
                subtitle: const Text('Pilih foto yang lebih jelas dari galeri', style: TextStyle(fontSize: 11)),
                onTap: () => Navigator.pop(ctx, ImageSource.gallery),
              ),
            ],
          ),
        ),
      ),
    );

    if (source == null) return;

    final picked = await _picker.pickImage(
      source: source,
      maxWidth: 1280,
      maxHeight: 1280,
      imageQuality: 85,
    );
    if (picked == null) return;
    if (!mounted) return;

    setState(() {
      _isUploadingNewReceipts = true;
      _uploadStatusText = 'Memperbarui foto struk ${r.receiptNumber}...';
    });

    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
    try {
      final bytes = await picked.readAsBytes();
      final name = picked.name.isNotEmpty ? picked.name : 'retake_${DateTime.now().millisecondsSinceEpoch}.jpg';
      await rcpProv.retakeReceipt(r.id, bytes, name);

      if (!mounted) return;
      setState(() {
        _uploadStatusText = 'Memproses ulang OCR AI struk ${r.receiptNumber}...';
      });

      // Poll sebentar sampai status selesai
      for (int i = 0; i < 3; i++) {
        await Future.delayed(const Duration(milliseconds: 1200));
        if (!mounted) break;
        await rcpProv.fetchMyReceipts(forceRefresh: true);
        final fresh = rcpProv.receipts.firstWhere((item) => item.id == r.id, orElse: () => r);
        if (!fresh.isOcrPending) break;
      }

      if (!mounted) return;
      await rcpProv.fetchMyReceipts(forceRefresh: true);
      if (!mounted) return;
      final updated = rcpProv.receipts.firstWhere((item) => item.id == r.id, orElse: () => r);

      if (updated.isBlurry) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Foto struk ${r.receiptNumber} masih buram. Harap coba lagi di tempat yang lebih terang.'),
            backgroundColor: Colors.orange.shade800,
          ),
        );
      } else {
        setState(() {
          _selectedReceiptIds.add(r.id);
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Struk ${r.receiptNumber} berhasil diperbarui & otomatis dicentang!'),
            backgroundColor: Colors.green.shade700,
          ),
        );
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) {
        setState(() {
          _isUploadingNewReceipts = false;
          _uploadStatusText = '';
        });
      }
    }
  }

  Future<void> _handleDeleteDraftReceipt(ReceiptRecord r) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Hapus Struk?'),
        content: Text('Hapus struk "${r.receiptNumber}" dari daftar draf Anda?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
    if (confirm != true) return;
    if (!mounted) return;

    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
    try {
      await rcpProv.deleteDraft(r.id);
      setState(() {
        _selectedReceiptIds.remove(r.id);
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Struk ${r.receiptNumber} berhasil dihapus.')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
      );
    }
  }

  Future<void> _openReceiptDetail(ReceiptRecord r) async {
    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => DetailPengajuanScreen(receipt: r),
    );
    if (!mounted) return;
    await Provider.of<ReceiptProvider>(context, listen: false)
        .fetchMyReceipts(forceRefresh: true);
    if (mounted) setState(() {});
  }

  Future<void> _uploadPhotos(List<XFile> files) async {
    setState(() {
      _isUploadingNewReceipts = true;
      _uploadStatusText = 'Mengunggah 1 dari ${files.length} foto struk...';
    });

    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
    final List<int> newlyUploadedIds = [];
    int successCount = 0;

    for (int i = 0; i < files.length; i++) {
      if (!mounted) break;
      setState(() {
        _uploadStatusText = 'Mengunggah ${i + 1} dari ${files.length} foto struk...';
      });

      try {
        final f = files[i];
        final bytes = await f.readAsBytes();
        final name = f.name.isNotEmpty ? f.name : 'struk_${DateTime.now().millisecondsSinceEpoch}.jpg';
        final uploaded = await rcpProv.uploadReceipt(bytes, name);
        newlyUploadedIds.add(uploaded.id);
        successCount++;
      } catch (e) {
        debugPrint('Gagal upload struk: $e');
      }
    }

    // Polling OCR sebentar (maksimal ~3-4 detik) agar status OCR (berhasil/buram) terupdate
    if (newlyUploadedIds.isNotEmpty && mounted) {
      setState(() {
        _uploadStatusText = 'Memproses pembacaan struk (OCR)...';
      });

      for (int attempt = 0; attempt < 3; attempt++) {
        await Future.delayed(const Duration(milliseconds: 1200));
        if (!mounted) break;
        await rcpProv.fetchMyReceipts(forceRefresh: true);

        final stillPending = rcpProv.receipts.where((r) =>
            newlyUploadedIds.contains(r.id) && r.isOcrPending);
        if (stillPending.isEmpty) {
          break;
        }
      }
    } else if (mounted) {
      await rcpProv.fetchMyReceipts(forceRefresh: true);
    }

    // Auto-select hanya struk yang valid (TIDAK BURAM & TIDAK GAGAL OCR)
    int failedCount = 0;
    if (mounted) {
      for (final id in newlyUploadedIds) {
        final r = rcpProv.receipts.firstWhere(
          (item) => item.id == id,
          orElse: () => ReceiptRecord(
            id: id,
            receiptNumber: '',
            claimedAmount: null,
            category: null,
            notes: null,
            receiptDate: null,
            status: 'draft',
            ocrStatus: 'pending',
            createdAt: DateTime.now().toIso8601String(),
          ),
        );
        if (r.isOcrFailed || r.isBlurry) {
          failedCount++;
        } else {
          _selectedReceiptIds.add(id);
        }
      }
    }

    setState(() {
      _isUploadingNewReceipts = false;
      _uploadStatusText = '';
    });

    if (!mounted) return;
    if (successCount > 0) {
      if (failedCount > 0) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              '$successCount struk diunggah (${successCount - failedCount} berhasil terbaca, $failedCount struk gagal/buram terdeteksi).',
            ),
            backgroundColor: Colors.orange.shade800,
            duration: const Duration(seconds: 4),
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('$successCount struk berhasil difoto & otomatis dipilih!'),
            backgroundColor: Colors.green.shade700,
          ),
        );
      }
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Gagal mengunggah foto struk. Coba lagi.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  void _startOcrPollingIfNeeded(List<ReceiptRecord> candidateReceipts) {
    final hasPending = candidateReceipts.any((r) => r.isOcrPending);
    if (hasPending && (_ocrPollingTimer == null || !_ocrPollingTimer!.isActive)) {
      _ocrPollingTimer = Timer.periodic(const Duration(seconds: 3), (timer) async {
        if (!mounted) {
          timer.cancel();
          return;
        }
        final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
        await rcpProv.fetchMyReceipts(forceRefresh: true);
        if (!mounted) return;
        final stillPending = rcpProv.receipts
            .where((r) => r.isDraft && !r.isBundled)
            .any((r) => r.isOcrPending);
        if (!stillPending) {
          timer.cancel();
          _ocrPollingTimer = null;
        }
      });
    } else if (!hasPending && _ocrPollingTimer != null && _ocrPollingTimer!.isActive) {
      _ocrPollingTimer?.cancel();
      _ocrPollingTimer = null;
    }
  }

  @override
  void initState() {
    super.initState();
    if (widget.initialSelectedReceiptIds != null) {
      _selectedReceiptIds.addAll(widget.initialSelectedReceiptIds!);
    }
  }

  @override
  void dispose() {
    _ocrPollingTimer?.cancel();
    _titleController.dispose();
    _purposeController.dispose();
    super.dispose();
  }

  Future<void> _pickDateRange() async {
    final now = DateTime.now();
    final picked = await showCustomDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime(now.year + 2),
      initialDateRange: _dateRange ??
          DateTimeRange(
            start: now.subtract(const Duration(days: 2)),
            end: now,
          ),
    );
    if (picked != null) {
      setState(() {
        _dateRange = picked;
      });
    }
  }

  Future<void> _saveReport({required bool andSubmit}) async {
    if (!_formKey.currentState!.validate()) return;

    if (andSubmit && _selectedReceiptIds.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Pilih minimal 1 struk untuk langsung diajukan ke Finance.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
    final candidateReceipts = rcpProv.receipts
        .where((r) => r.isDraft && !r.isBundled)
        .toList();

    // Validasi pencegahan struk pending dan gagal OCR jika langsung submit
    if (andSubmit) {
      final pendingSelected = candidateReceipts
          .where((r) => _selectedReceiptIds.contains(r.id) && r.isOcrPending)
          .toList();

      if (pendingSelected.isNotEmpty || _isUploadingNewReceipts) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Proses scan AI belum selesai. Harap tunggu hingga semua struk terpilih selesai discan.'),
            backgroundColor: Colors.orange,
          ),
        );
        return;
      }

      final failedSelected = candidateReceipts
          .where((r) => _selectedReceiptIds.contains(r.id) && (r.isOcrFailed || r.isBlurry))
          .toList();

      if (failedSelected.isNotEmpty) {
        final firstFailed = failedSelected.first;
        final isBlurry = firstFailed.isBlurry;
        final title = isBlurry ? 'Foto Struk Buram' : 'Struk Gagal / Bukan Struk';
        final message = isBlurry
            ? 'Struk ${firstFailed.receiptNumber} (${firstFailed.displayMerchant}) terdeteksi buram atau tidak terbaca jelas sehingga tidak dapat diajukan ke Finance.'
            : 'Struk ${firstFailed.receiptNumber} (${firstFailed.displayMerchant}) gagal diproses OCR atau bukan foto struk yang valid sehingga tidak dapat diajukan ke Finance.';

        await showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: Row(
              children: [
                Icon(Icons.warning_amber_rounded, color: Colors.red.shade700, size: 24),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    title,
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            ),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  message,
                  style: const TextStyle(fontSize: 13),
                ),
                const SizedBox(height: 12),
                Center(
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: ReceiptThumbnail(
                      receiptId: firstFailed.id,
                      imagePath: firstFailed.imagePath,
                      size: 90,
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: Colors.red.shade50,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: Colors.red.shade200),
                  ),
                  child: Text(
                    firstFailed.ocrError ??
                        'Sistem mendeteksi foto tidak terbaca atau bukan struk valid. Harap perbarui foto sebelum mengajukan laporan.',
                    style: TextStyle(fontSize: 11.5, color: Colors.red.shade800),
                  ),
                ),
              ],
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('Batal'),
              ),
              OutlinedButton(
                onPressed: () {
                  setState(() {
                    for (final b in failedSelected) {
                      _selectedReceiptIds.remove(b.id);
                    }
                  });
                  Navigator.pop(ctx);
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text('${failedSelected.length} struk bermasalah dilepas dari pilihan.'),
                      backgroundColor: Colors.blue.shade700,
                    ),
                  );
                },
                style: OutlinedButton.styleFrom(
                  foregroundColor: Colors.red.shade700,
                  side: BorderSide(color: Colors.red.shade300),
                ),
                child: const Text('Lepas Centang'),
              ),
              ElevatedButton.icon(
                onPressed: () {
                  Navigator.pop(ctx);
                  _handleRetakeReceipt(firstFailed);
                },
                icon: const Icon(Icons.camera_alt, size: 15),
                label: const Text('Foto Ulang Sekarang'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF7B1FA2),
                  foregroundColor: Colors.white,
                ),
              ),
            ],
          ),
        );
        return;
      }
    }

    setState(() => _isSaving = true);

    try {
      final repProv = Provider.of<ExpenseReportProvider>(context, listen: false);
      final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);

      String? startStr;
      String? endStr;
      if (_dateRange != null) {
        startStr =
            '${_dateRange!.start.year}-${_dateRange!.start.month.toString().padLeft(2, '0')}-${_dateRange!.start.day.toString().padLeft(2, '0')}';
        endStr =
            '${_dateRange!.end.year}-${_dateRange!.end.month.toString().padLeft(2, '0')}-${_dateRange!.end.day.toString().padLeft(2, '0')}';
      }

      final report = await repProv.createReport(
        title: _titleController.text.trim(),
        purpose: _purposeController.text.trim().isNotEmpty
            ? _purposeController.text.trim()
            : null,
        startDate: startStr,
        endDate: endStr,
        receiptIds: _selectedReceiptIds.isNotEmpty
            ? _selectedReceiptIds.toList()
            : null,
      );

      if (andSubmit) {
        await repProv.submitReport(report.id);
      }

      // Refresh data struk agar badge bundling tersinkronisasi
      await rcpProv.fetchMyReceipts(forceRefresh: true);

      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(andSubmit
              ? 'Laporan dinas berhasil dibuat dan diajukan ke Finance!'
              : 'Draf laporan dinas berhasil disimpan.'),
          backgroundColor: Colors.green.shade700,
        ),
      );

      Navigator.pushReplacement(
        context,
        MaterialPageRoute(
          builder: (_) => DetailLaporanDinasScreen(reportId: report.id),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: Colors.red,
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _isSaving = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final receiptProv = Provider.of<ReceiptProvider>(context);
    // Kandidat struk yang bisa dimasukkan: status draft & belum terikat ke laporan lain
    final candidateReceipts = receiptProv.receipts
        .where((r) => r.isDraft && !r.isBundled)
        .toList();
    _startOcrPollingIfNeeded(candidateReceipts);

    final selectedReceipts = candidateReceipts
        .where((r) => _selectedReceiptIds.contains(r.id))
        .toList();

    // Cek apakah ada struk terpilih yang masih pending scan AI
    final selectedPendingReceipts = selectedReceipts
        .where((r) => r.isOcrPending)
        .toList();
    final bool hasPendingOcr = selectedPendingReceipts.isNotEmpty || _isUploadingNewReceipts;

    // Cek apakah ada struk terpilih yang gagal scan (buram, bukan struk, unreadable)
    final selectedFailedReceipts = selectedReceipts
        .where((r) => r.isOcrFailed || r.isBlurry)
        .toList();
    final bool hasFailedOcr = selectedFailedReceipts.isNotEmpty;

    final bool hasSelectedReceipts = _selectedReceiptIds.isNotEmpty;
    final bool canSubmitToFinance = !_isSaving &&
        hasSelectedReceipts &&
        !hasPendingOcr &&
        !hasFailedOcr;

    // Hitung total nominal dari struk terpilih
    double totalSelectedAmount = 0.0;
    for (final r in selectedReceipts) {
      totalSelectedAmount += r.displayAmount;
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Buat Laporan Dinas'),
      ),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.06),
              blurRadius: 10,
              offset: const Offset(0, -4),
            ),
          ],
        ),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: EdgeInsets.fromLTRB(
              16,
              12,
              16,
              MediaQuery.of(context).padding.bottom > 0 ? 8 : 16,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${_selectedReceiptIds.length} Struk Terpilih',
                          style: TextStyle(
                            fontSize: 12,
                            color: Colors.grey.shade600,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          formatCurrency(totalSelectedAmount),
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF1E88E5),
                          ),
                        ),
                      ],
                    ),
                    ElevatedButton(
                      onPressed: _isSaving ? null : () => _saveReport(andSubmit: false),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.grey.shade100,
                        foregroundColor: Colors.grey.shade800,
                        elevation: 0,
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10),
                          side: BorderSide(color: Colors.grey.shade300),
                        ),
                      ),
                      child: const Text('Simpan Draf', style: TextStyle(fontWeight: FontWeight.w600)),
                    ),
                  ],
                ),
                const SizedBox(height: 10),

                // Banner status jika OCR pending atau ada struk gagal yang dicentang
                if (hasPendingOcr) ...[
                  Container(
                    margin: const EdgeInsets.only(bottom: 8),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
                    decoration: BoxDecoration(
                      color: const Color(0xFFE3F2FD),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: const Color(0xFF90CAF9)),
                    ),
                    child: Row(
                      children: [
                        const SizedBox(
                          width: 14,
                          height: 14,
                          child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF1565C0)),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'Scan AI sedang memproses struk terpilih. Tunggu hingga selesai untuk mengajukan.',
                            style: TextStyle(
                              fontSize: 11,
                              color: Colors.blue.shade900,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ] else if (hasFailedOcr) ...[
                  Container(
                    margin: const EdgeInsets.only(bottom: 8),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF0F0),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: const Color(0xFFFFCDD2)),
                    ),
                    child: Row(
                      children: [
                        Icon(Icons.warning_amber_rounded, size: 16, color: Colors.red.shade700),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            'Ada ${selectedFailedReceipts.length} struk gagal (buram/bukan struk) tercentang.',
                            style: TextStyle(
                              fontSize: 11,
                              color: Colors.red.shade900,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                        ),
                        const SizedBox(width: 6),
                        InkWell(
                          onTap: () {
                            setState(() {
                              for (final f in selectedFailedReceipts) {
                                _selectedReceiptIds.remove(f.id);
                              }
                            });
                          },
                          borderRadius: BorderRadius.circular(4),
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: Colors.red.shade700,
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: const Text(
                              'Lepas Centang',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 10.5,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],

                SizedBox(
                  width: double.infinity,
                  height: 46,
                  child: ElevatedButton.icon(
                    onPressed: canSubmitToFinance ? () => _saveReport(andSubmit: true) : null,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF1E88E5),
                      foregroundColor: Colors.white,
                      disabledBackgroundColor: hasFailedOcr
                          ? const Color(0xFFFFEBEE)
                          : (hasPendingOcr ? const Color(0xFFE3F2FD) : Colors.grey.shade200),
                      disabledForegroundColor: hasFailedOcr
                          ? Colors.red.shade800
                          : (hasPendingOcr ? const Color(0xFF1565C0) : Colors.grey.shade600),
                      elevation: 0,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(10),
                        side: BorderSide(
                          color: hasFailedOcr
                              ? Colors.red.shade300
                              : (hasPendingOcr ? Colors.blue.shade300 : Colors.transparent),
                        ),
                      ),
                    ),
                    icon: _isSaving
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : (hasPendingOcr
                            ? const SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF1565C0)),
                              )
                            : (hasFailedOcr
                                ? Icon(Icons.block, size: 18, color: Colors.red.shade700)
                                : (!hasSelectedReceipts
                                    ? Icon(Icons.checklist_rounded, size: 18, color: Colors.grey.shade500)
                                    : const Icon(Icons.send_rounded, size: 18)))),
                    label: Text(
                      _isSaving
                          ? 'Menyimpan...'
                          : (hasPendingOcr
                              ? 'Menunggu Scan AI Selesai...'
                              : (hasFailedOcr
                                  ? 'Lepas Struk Gagal/Buram Dulu (${selectedFailedReceipts.length})'
                                  : (!hasSelectedReceipts
                                      ? 'Pilih Minimal 1 Struk'
                                      : 'Ajukan Langsung ke Finance'))),
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 13.5,
                        color: canSubmitToFinance
                            ? Colors.white
                            : (hasFailedOcr
                                ? Colors.red.shade800
                                : (hasPendingOcr ? const Color(0xFF1565C0) : Colors.grey.shade600)),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ─── Info Banner ───────────────────────────────────
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFE3F2FD),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: const Color(0xFF90CAF9)),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(Icons.layers_rounded, color: Color(0xFF1565C0), size: 20),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'Laporan Dinas memungkinkan Anda mengelompokkan beberapa struk perjalanan dinas menjadi 1 bundle klaim agar dapat disetujui sekaligus oleh Finance.',
                        style: TextStyle(
                          fontSize: 12,
                          color: Colors.blue.shade900,
                          height: 1.35,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // ─── Judul Laporan ──────────────────────────────────
              const Text(
                'Judul Laporan Dinas *',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 6),
              TextFormField(
                controller: _titleController,
                decoration: InputDecoration(
                  hintText: 'Misal: Dinas Site Visit Surabaya 3 Hari',
                  hintStyle: TextStyle(fontSize: 13, color: Colors.grey.shade400),
                  filled: true,
                  fillColor: Colors.white,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10),
                    borderSide: BorderSide(color: Colors.grey.shade300),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10),
                    borderSide: BorderSide(color: Colors.grey.shade300),
                  ),
                ),
                validator: (val) {
                  if (val == null || val.trim().isEmpty) {
                    return 'Judul laporan dinas wajib diisi.';
                  }
                  return null;
                },
              ),
              const SizedBox(height: 16),

              // ─── Periode Tanggal Dinas ─────────────────────────
              const Text(
                'Periode Dinas (Opsional)',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 6),
              InkWell(
                onTap: _pickDateRange,
                borderRadius: BorderRadius.circular(10),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.grey.shade300),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.calendar_month_rounded, size: 20, color: Color(0xFF1E88E5)),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          _dateRange != null
                              ? '${formatDateIndonesian(_dateRange!.start)} s/d ${formatDateIndonesian(_dateRange!.end)}'
                              : 'Pilih Tanggal Mulai s/d Selesai Dinas',
                          style: TextStyle(
                            fontSize: 13,
                            color: _dateRange != null ? Colors.black87 : Colors.grey.shade500,
                          ),
                        ),
                      ),
                      if (_dateRange != null)
                        GestureDetector(
                          onTap: () => setState(() => _dateRange = null),
                          child: const Icon(Icons.close, size: 18, color: Colors.grey),
                        ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // ─── Keperluan / Deskripsi ──────────────────────────
              const Text(
                'Keperluan / Catatan Dinas (Opsional)',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 6),
              TextFormField(
                controller: _purposeController,
                maxLines: 3,
                decoration: InputDecoration(
                  hintText: 'Misal: Instalasi perangkat jaringan dan meeting koordinasi cabang',
                  hintStyle: TextStyle(fontSize: 13, color: Colors.grey.shade400),
                  filled: true,
                  fillColor: Colors.white,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10),
                    borderSide: BorderSide(color: Colors.grey.shade300),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10),
                    borderSide: BorderSide(color: Colors.grey.shade300),
                  ),
                ),
              ),
              const SizedBox(height: 20),

              // ─── Tombol Cepat: Ambil Foto / Pilih Galeri ─────────
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: const Color(0xFFF3E5F5),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xFFCE93D8)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.add_a_photo_outlined, size: 20, color: Color(0xFF6A1B9A)),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text(
                                'Tambah Struk Langsung ke Laporan',
                                style: TextStyle(
                                  fontSize: 13,
                                  fontWeight: FontWeight.bold,
                                  color: Color(0xFF4A148C),
                                ),
                              ),
                              Text(
                                'Foto struk baru atau pilih banyak dari galeri tanpa keluar dari formulir ini.',
                                style: TextStyle(fontSize: 11.5, color: Colors.purple.shade700),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    if (_isUploadingNewReceipts)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: Row(
                          children: [
                            const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF6A1B9A)),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                _uploadStatusText,
                                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF4A148C)),
                              ),
                            ),
                          ],
                        ),
                      )
                    else
                      Row(
                        children: [
                          Expanded(
                            child: ElevatedButton.icon(
                              onPressed: _isSaving ? null : _handleCaptureFromCamera,
                              icon: const Icon(Icons.camera_alt_outlined, size: 17),
                              label: const Text('Foto Struk'),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFF7B1FA2),
                                foregroundColor: Colors.white,
                                elevation: 0,
                                padding: const EdgeInsets.symmetric(vertical: 10),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                              ),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: OutlinedButton.icon(
                              onPressed: _isSaving ? null : _handlePickFromGallery,
                              icon: const Icon(Icons.photo_library_outlined, size: 17),
                              label: const Text('Dari Galeri'),
                              style: OutlinedButton.styleFrom(
                                foregroundColor: const Color(0xFF6A1B9A),
                                side: const BorderSide(color: Color(0xFF8E24AA)),
                                padding: const EdgeInsets.symmetric(vertical: 10),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                              ),
                            ),
                          ),
                        ],
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // ─── Daftar Struk untuk Dimasukkan ──────────────────
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Pilih Struk untuk Bundle (${candidateReceipts.length} Tersedia)',
                    style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold),
                  ),
                  if (candidateReceipts.isNotEmpty)
                    TextButton(
                      onPressed: () {
                        setState(() {
                          final validCandidates = candidateReceipts.where((r) => !r.isOcrFailed && !r.isBlurry).toList();
                          final validIds = validCandidates.map((r) => r.id).toSet();
                          final allValidSelected = validIds.isNotEmpty && _selectedReceiptIds.containsAll(validIds);

                          if (allValidSelected) {
                            _selectedReceiptIds.clear();
                          } else {
                            _selectedReceiptIds.clear();
                            _selectedReceiptIds.addAll(validIds);
                          }
                        });
                      },
                      style: TextButton.styleFrom(
                        padding: EdgeInsets.zero,
                        minimumSize: const Size(50, 30),
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ),
                      child: Text(
                        candidateReceipts.where((r) => !r.isOcrFailed && !r.isBlurry).isNotEmpty &&
                                _selectedReceiptIds.containsAll(candidateReceipts.where((r) => !r.isOcrFailed && !r.isBlurry).map((r) => r.id))
                            ? 'Batal Pilih Semua'
                            : 'Pilih Semua',
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 8),

              if (receiptProv.loading && candidateReceipts.isEmpty)
                const ShimmerLoading(
                  child: Column(
                    children: [
                      SkeletonReceiptItemCard(),
                      SkeletonReceiptItemCard(),
                      SkeletonReceiptItemCard(),
                    ],
                  ),
                )
              else if (candidateReceipts.isEmpty)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade50,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Colors.grey.shade200),
                  ),
                  child: Column(
                    children: [
                      Icon(Icons.receipt_long_outlined, size: 36, color: Colors.grey.shade400),
                      const SizedBox(height: 8),
                      Text(
                        'Belum ada struk yang dipilih',
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: Colors.grey.shade700,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Gunakan tombol "Foto Struk" atau "Dari Galeri" di atas untuk memasukkan struk langsung ke laporan ini sekarang.',
                        textAlign: TextAlign.center,
                        style: TextStyle(fontSize: 11.5, color: Colors.grey.shade600),
                      ),
                    ],
                  ),
                )
              else ...[
                Container(
                  margin: const EdgeInsets.only(bottom: 10),
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF1F8E9),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: const Color(0xFFC8E6C9)),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.info_outline, size: 16, color: Color(0xFF2E7D32)),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Klik struk atau tombol "Review Scan" untuk mengecek hasil OCR, item, & mengubah kategori/nominal sebelum diajukan.',
                          style: TextStyle(fontSize: 11.5, color: Colors.green.shade900),
                        ),
                      ),
                    ],
                  ),
                ),
                ListView.separated(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: candidateReceipts.length,
                  separatorBuilder: (_, i) => const SizedBox(height: 8),
                  itemBuilder: (context, index) {
                    final r = candidateReceipts[index];
                    final isChecked = _selectedReceiptIds.contains(r.id);
                    final isFailed = r.isOcrFailed || r.isBlurry;
                    final isBlurry = r.isBlurry;
                    final isNonReceipt = r.isNonReceipt;

                    return Container(
                      decoration: BoxDecoration(
                        color: isFailed
                            ? (isChecked ? const Color(0xFFFFF0F0) : const Color(0xFFFFF8F8))
                            : (isChecked ? const Color(0xFFF0F7FF) : Colors.white),
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(
                          color: isFailed
                              ? (isChecked ? Colors.red.shade600 : Colors.red.shade300)
                              : (isChecked ? const Color(0xFF1E88E5) : Colors.grey.shade200),
                          width: isChecked ? 1.5 : 1,
                        ),
                      ),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          Checkbox(
                            value: isChecked,
                            onChanged: (val) {
                              setState(() {
                                if (val == true) {
                                  _selectedReceiptIds.add(r.id);
                                } else {
                                  _selectedReceiptIds.remove(r.id);
                                }
                              });
                            },
                            activeColor: isFailed ? Colors.red.shade700 : const Color(0xFF1E88E5),
                            materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                          ),
                          GestureDetector(
                            onTap: () => _openReceiptDetail(r),
                            child: ReceiptThumbnail(
                              receiptId: r.id,
                              imagePath: r.imagePath,
                              size: 52,
                              borderRadius: BorderRadius.circular(8),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: InkWell(
                              onTap: () => _openReceiptDetail(r),
                              borderRadius: BorderRadius.circular(6),
                              child: Padding(
                                padding: const EdgeInsets.symmetric(vertical: 8),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Flexible(
                                          child: Text(
                                            r.receiptNumber,
                                            style: TextStyle(
                                              fontSize: 11,
                                              fontWeight: FontWeight.bold,
                                              color: isFailed ? Colors.red.shade700 : Colors.grey.shade600,
                                            ),
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                          ),
                                        ),
                                        const SizedBox(width: 4),
                                        if (isBlurry)
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                            decoration: BoxDecoration(
                                              color: Colors.red.shade100,
                                              borderRadius: BorderRadius.circular(4),
                                              border: Border.all(color: Colors.red.shade300),
                                            ),
                                            child: Row(
                                              mainAxisSize: MainAxisSize.min,
                                              children: [
                                                Icon(Icons.cancel_outlined, size: 10, color: Colors.red.shade700),
                                                const SizedBox(width: 3),
                                                Text(
                                                  'Foto Buram',
                                                  style: TextStyle(
                                                    fontSize: 9.5,
                                                    fontWeight: FontWeight.bold,
                                                    color: Colors.red.shade800,
                                                  ),
                                                ),
                                              ],
                                            ),
                                          )
                                        else if (isFailed)
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                            decoration: BoxDecoration(
                                              color: Colors.red.shade100,
                                              borderRadius: BorderRadius.circular(4),
                                              border: Border.all(color: Colors.red.shade300),
                                            ),
                                            child: Row(
                                              mainAxisSize: MainAxisSize.min,
                                              children: [
                                                Icon(Icons.error_outline_rounded, size: 10, color: Colors.red.shade700),
                                                const SizedBox(width: 3),
                                                Text(
                                                  isNonReceipt ? 'Bukan Struk' : 'Gagal OCR',
                                                  style: TextStyle(
                                                    fontSize: 9.5,
                                                    fontWeight: FontWeight.bold,
                                                    color: Colors.red.shade800,
                                                  ),
                                                ),
                                              ],
                                            ),
                                          )
                                        else if (r.isOcrPending)
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                            decoration: BoxDecoration(
                                              color: Colors.blue.shade50,
                                              borderRadius: BorderRadius.circular(4),
                                              border: Border.all(color: Colors.blue.shade200),
                                            ),
                                            child: const Row(
                                              mainAxisSize: MainAxisSize.min,
                                              children: [
                                                SizedBox(
                                                  width: 8,
                                                  height: 8,
                                                  child: CircularProgressIndicator(strokeWidth: 1.5, color: Color(0xFF1565C0)),
                                                ),
                                                SizedBox(width: 4),
                                                Text(
                                                  'Scan AI...',
                                                  style: TextStyle(
                                                    fontSize: 9.5,
                                                    fontWeight: FontWeight.w600,
                                                    color: Color(0xFF1565C0),
                                                  ),
                                                ),
                                              ],
                                            ),
                                          )
                                        else
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1.5),
                                            decoration: BoxDecoration(
                                              color: Colors.green.shade50,
                                              borderRadius: BorderRadius.circular(4),
                                            ),
                                            child: Text(
                                              '✓ Terbaca',
                                              style: TextStyle(
                                                fontSize: 9.5,
                                                fontWeight: FontWeight.w600,
                                                color: Colors.green.shade700,
                                              ),
                                            ),
                                          ),
                                      ],
                                    ),
                                    const SizedBox(height: 3),
                                    Text(
                                      isFailed && (r.merchantName == null || r.merchantName!.isEmpty)
                                          ? (isBlurry ? 'Struk Tidak Terbaca' : 'Bukan Struk / Gagal')
                                          : r.displayMerchant,
                                      style: TextStyle(
                                        fontSize: 13,
                                        fontWeight: FontWeight.w600,
                                        color: isFailed ? Colors.red.shade900 : Colors.black87,
                                      ),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                    const SizedBox(height: 2),
                                    if (isFailed)
                                      Text(
                                        r.ocrError ??
                                            (isBlurry
                                                ? 'Foto buram / tidak terbaca jelas. Klik untuk cek.'
                                                : 'Struk gagal terbaca atau bukan foto struk. Klik untuk cek.'),
                                        style: TextStyle(
                                          fontSize: 11,
                                          color: Colors.red.shade700,
                                        ),
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                      )
                                    else
                                      Text(
                                        '${r.category ?? 'Lain-lain'} · ${r.displayDate}${r.items.isNotEmpty ? ' · (${r.items.length} item)' : ''}',
                                        style: TextStyle(
                                          fontSize: 11,
                                          color: Colors.grey.shade500,
                                        ),
                                      ),
                                  ],
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 6),
                          if (isFailed)
                            Padding(
                              padding: const EdgeInsets.only(right: 8),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.end,
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  ElevatedButton.icon(
                                    onPressed: () => _handleRetakeReceipt(r),
                                    icon: const Icon(Icons.camera_alt, size: 11),
                                    label: const Text(
                                      'Foto Ulang',
                                      style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.bold),
                                    ),
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: const Color(0xFF7B1FA2),
                                      foregroundColor: Colors.white,
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                      minimumSize: Size.zero,
                                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                                      shape: RoundedRectangleBorder(
                                        borderRadius: BorderRadius.circular(6),
                                      ),
                                      elevation: 0,
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  GestureDetector(
                                    onTap: () => _handleDeleteDraftReceipt(r),
                                    child: Padding(
                                      padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 1),
                                      child: Text(
                                        'Hapus',
                                        style: TextStyle(
                                          fontSize: 10.5,
                                          color: Colors.red.shade700,
                                          fontWeight: FontWeight.w600,
                                          decoration: TextDecoration.underline,
                                        ),
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            )
                          else
                            Padding(
                              padding: const EdgeInsets.only(right: 8),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.end,
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  FittedBox(
                                    fit: BoxFit.scaleDown,
                                    child: Text(
                                      formatCurrency(r.displayAmount),
                                      style: const TextStyle(
                                        fontSize: 13,
                                        fontWeight: FontWeight.bold,
                                        color: Color(0xFF0D47A1),
                                      ),
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  InkWell(
                                    onTap: () => _openReceiptDetail(r),
                                    borderRadius: BorderRadius.circular(4),
                                    child: Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2.5),
                                      decoration: BoxDecoration(
                                        color: const Color(0xFFE3F2FD),
                                        borderRadius: BorderRadius.circular(4),
                                        border: Border.all(color: const Color(0xFFBBDEFB)),
                                      ),
                                      child: const Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Text(
                                            'Review Scan',
                                            style: TextStyle(
                                              fontSize: 10,
                                              fontWeight: FontWeight.w600,
                                              color: Color(0xFF1565C0),
                                            ),
                                          ),
                                          SizedBox(width: 2),
                                          Icon(Icons.chevron_right, size: 12, color: Color(0xFF1565C0)),
                                        ],
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                        ],
                      ),
                    );
                  },
                ),
              ],
              const SizedBox(height: 20),
            ],
          ),
        ),
      ),
    );
  }
}
