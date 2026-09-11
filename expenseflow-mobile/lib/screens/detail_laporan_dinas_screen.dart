import 'dart:async';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import '../providers/expense_report_provider.dart';
import '../providers/receipt_provider.dart';
import '../utils.dart';
import '../widgets/receipt_thumbnail.dart';
import '../widgets/skeleton.dart';
import 'detail_pengajuan_screen.dart';

class DetailLaporanDinasScreen extends StatefulWidget {
  final int reportId;

  const DetailLaporanDinasScreen({super.key, required this.reportId});

  @override
  State<DetailLaporanDinasScreen> createState() =>
      _DetailLaporanDinasScreenState();
}

class _DetailLaporanDinasScreenState extends State<DetailLaporanDinasScreen> {
  bool _isLoading = true;
  bool _isProcessingAction = false;
  ExpenseReportRecord? _report;
  final ImagePicker _picker = ImagePicker();
  Timer? _ocrPollingTimer;

  @override
  void initState() {
    super.initState();
    _loadDetail();
  }

  @override
  void dispose() {
    _ocrPollingTimer?.cancel();
    super.dispose();
  }

  void _startOcrPollingIfNeeded(List<ReceiptRecord> receipts) {
    final hasPending = receipts.any((r) => r.isOcrPending);
    if (hasPending && (_ocrPollingTimer == null || !_ocrPollingTimer!.isActive)) {
      _ocrPollingTimer = Timer.periodic(const Duration(seconds: 3), (timer) async {
        if (!mounted) {
          timer.cancel();
          return;
        }
        await _loadDetail(forceRefresh: true, silent: true);
        if (!mounted) return;
        final stillPending = _report?.receipts.any((r) => r.isOcrPending) ?? false;
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

  Future<void> _loadDetail({bool forceRefresh = false, bool silent = false}) async {
    final showFullLoading = !silent && _report == null;
    if (showFullLoading) setState(() => _isLoading = true);
    try {
      final prov = Provider.of<ExpenseReportProvider>(context, listen: false);
      final data = await prov.fetchReportDetail(widget.reportId, forceRefresh: forceRefresh);
      if (mounted) {
        setState(() {
          _report = data;
          if (showFullLoading) _isLoading = false;
        });
        if (data != null) {
          _startOcrPollingIfNeeded(data.receipts);
        }
      }
    } catch (_) {
      if (mounted && showFullLoading) {
        setState(() => _isLoading = false);
      }
    }
  }

  Future<void> _handleSubmitReport() async {
    if (_report == null) return;

    if (_report!.receipts.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Laporan belum memiliki struk. Tambahkan struk terlebih dahulu.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    // Cek apakah ada struk dalam proses OCR
    final pendingReceipts = _report!.receipts.where((r) => r.isOcrPending).toList();
    if (pendingReceipts.isNotEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Proses scan AI belum selesai. Harap tunggu hingga semua struk selesai discan.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    // Cek apakah ada struk gagal/buram di dalam laporan
    final failedReceipts = _report!.receipts.where((r) => r.isOcrFailed || r.isBlurry).toList();
    if (failedReceipts.isNotEmpty) {
      final firstFailed = failedReceipts.first;
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
                child: Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
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
                      'Sistem mendeteksi foto tidak terbaca atau bukan struk valid. Harap perbarui foto atau lepas struk ini sebelum mengajukan laporan.',
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
                Navigator.pop(ctx);
                _handleRemoveReceipt(firstFailed);
              },
              style: OutlinedButton.styleFrom(
                foregroundColor: Colors.red.shade700,
                side: BorderSide(color: Colors.red.shade300),
              ),
              child: const Text('Lepas Struk'),
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

    final expProv = Provider.of<ExpenseReportProvider>(context, listen: false);
    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Ajukan Laporan Dinas ke Finance?'),
        content: Text(
          'Laporan "${_report!.title}" dengan ${_report!.receipts.length} struk senilai ${formatCurrency(_report!.totalClaimedAmount)} akan dikirim ke Finance untuk direview.\n\nStruk di dalamnya akan dikunci dan tidak dapat diubah.',
          style: const TextStyle(fontSize: 13),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF1E88E5),
              foregroundColor: Colors.white,
            ),
            child: const Text('Ya, Ajukan Sekarang'),
          ),
        ],
      ),
    );

    if (confirm != true) return;
    if (!mounted) return;

    setState(() => _isProcessingAction = true);
    try {
      await expProv.submitReport(widget.reportId);
      await rcpProv.fetchMyReceipts(forceRefresh: true);
      await _loadDetail(forceRefresh: true);

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Laporan dinas berhasil diajukan ke Finance!'),
          backgroundColor: Colors.green,
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) setState(() => _isProcessingAction = false);
    }
  }

  Future<void> _handleDeleteReport() async {
    final expProv = Provider.of<ExpenseReportProvider>(context, listen: false);
    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Hapus Draf Laporan Dinas?'),
        content: const Text(
          'Draf laporan ini akan dihapus. Struk-struk di dalamnya akan dilepaskan kembali sebagai struk draf mandiri dan tidak akan hilang.',
          style: TextStyle(fontSize: 13),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.red,
              foregroundColor: Colors.white,
            ),
            child: const Text('Ya, Hapus'),
          ),
        ],
      ),
    );

    if (confirm != true) return;
    if (!mounted) return;

    setState(() => _isProcessingAction = true);
    try {
      await expProv.deleteReport(widget.reportId);
      await rcpProv.fetchMyReceipts(forceRefresh: true);

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Draf laporan dinas berhasil dihapus.')),
      );
      Navigator.pop(context);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) setState(() => _isProcessingAction = false);
    }
  }

  Future<void> _handleRemoveReceipt(ReceiptRecord receipt) async {
    final expProv = Provider.of<ExpenseReportProvider>(context, listen: false);
    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Lepas Struk dari Laporan?'),
        content: Text(
          'Lepas struk "${receipt.displayMerchant}" senilai ${formatCurrency(receipt.displayAmount)} dari laporan ini? Struk akan kembali menjadi draf mandiri.',
          style: const TextStyle(fontSize: 13),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.orange.shade800,
              foregroundColor: Colors.white,
            ),
            child: const Text('Ya, Lepaskan'),
          ),
        ],
      ),
    );

    if (confirm != true) return;
    if (!mounted) return;

    setState(() => _isProcessingAction = true);
    try {
      await expProv.removeReceipt(widget.reportId, receipt.id);
      await rcpProv.fetchMyReceipts(forceRefresh: true);
      await _loadDetail(forceRefresh: true);

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Struk berhasil dilepas dari laporan.')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
      );
    } finally {
      if (mounted) setState(() => _isProcessingAction = false);
    }
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

    setState(() => _isProcessingAction = true);
    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
    try {
      final bytes = await picked.readAsBytes();
      final name = picked.name.isNotEmpty
          ? picked.name
          : 'retake_${DateTime.now().millisecondsSinceEpoch}.jpg';
      await rcpProv.retakeReceipt(r.id, bytes, name);

      // Polling status OCR sebentar (maksimal ~3-4 detik)
      for (int i = 0; i < 3; i++) {
        await Future.delayed(const Duration(milliseconds: 1200));
        if (!mounted) break;
        await rcpProv.fetchMyReceipts(forceRefresh: true);
        final fresh = rcpProv.receipts.firstWhere((item) => item.id == r.id, orElse: () => r);
        if (!fresh.isOcrPending) break;
      }

      await _loadDetail(forceRefresh: true);
      await rcpProv.fetchMyReceipts(forceRefresh: true);

      if (!mounted) return;
      final updated = _report?.receipts.firstWhere((item) => item.id == r.id, orElse: () => r);
      if (updated != null && (updated.isBlurry || updated.isOcrFailed)) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              updated.isNonReceipt
                  ? 'Foto struk ${r.receiptNumber} bukan struk valid. Harap gunakan foto struk pengeluaran asli.'
                  : 'Foto struk ${r.receiptNumber} masih buram/gagal. Harap foto ulang di tempat yang lebih terang.',
            ),
            backgroundColor: Colors.orange.shade800,
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Struk ${r.receiptNumber} berhasil diperbarui & dibaca ulang!'),
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
      if (mounted) setState(() => _isProcessingAction = false);
    }
  }

  void _showAddReceiptsModal() {
    final rcpProv = Provider.of<ReceiptProvider>(context, listen: false);
    final available = rcpProv.receipts
        .where((r) => r.isDraft && (!r.isBundled || r.expenseReportId != widget.reportId))
        .toList();

    final selectedIds = <int>{};
    bool isUploading = false;
    String uploadStatus = '';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (modalCtx, setModalState) {
            Future<void> uploadDirectFiles(List<XFile> files) async {
              setModalState(() {
                isUploading = true;
                uploadStatus = 'Mengunggah 1 dari ${files.length} foto...';
              });

              int success = 0;
              for (int i = 0; i < files.length; i++) {
                setModalState(() {
                  uploadStatus = 'Mengunggah ${i + 1} dari ${files.length} foto...';
                });
                try {
                  final f = files[i];
                  final bytes = await f.readAsBytes();
                  final name = f.name.isNotEmpty ? f.name : 'struk_${DateTime.now().millisecondsSinceEpoch}.jpg';
                  await rcpProv.uploadReceipt(bytes, name, expenseReportId: widget.reportId);
                  success++;
                } catch (e) {
                  debugPrint('Error direct upload: $e');
                }
              }

              if (!mounted) return;
              await _loadDetail(forceRefresh: true);
              await rcpProv.fetchMyReceipts(forceRefresh: true);

              if (modalCtx.mounted) Navigator.pop(modalCtx);

              if (!mounted) return;
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text('$success foto struk baru berhasil ditambahkan ke laporan!'),
                  backgroundColor: Colors.green,
                ),
              );
            }

            return SafeArea(
              child: Container(
                padding: const EdgeInsets.all(16),
                height: MediaQuery.of(context).size.height * 0.8,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Tambah Struk ke Laporan',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close),
                          onPressed: () => Navigator.pop(ctx),
                        ),
                      ],
                    ),
                    const Divider(),

                    // Opsi 1: Foto Langsung / Pilih Galeri
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: const Color(0xFFF3E5F5),
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: const Color(0xFFCE93D8)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Foto Struk Baru (Langsung Masuk ke Laporan)',
                            style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: Color(0xFF4A148C)),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Ambil foto atau pilih banyak sekaligus dari galeri tanpa bolak-balik.',
                            style: TextStyle(fontSize: 11, color: Colors.purple.shade700),
                          ),
                          const SizedBox(height: 10),
                          if (isUploading)
                            Row(
                              children: [
                                const SizedBox(
                                  width: 16,
                                  height: 16,
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF6A1B9A)),
                                ),
                                const SizedBox(width: 10),
                                Text(
                                  uploadStatus,
                                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xFF4A148C)),
                                ),
                              ],
                            )
                          else
                            Row(
                              children: [
                                Expanded(
                                  child: ElevatedButton.icon(
                                    onPressed: () async {
                                      final file = await _picker.pickImage(
                                        source: ImageSource.camera,
                                        maxWidth: 1280,
                                        maxHeight: 1280,
                                        imageQuality: 80,
                                      );
                                      if (file != null) {
                                        await uploadDirectFiles([file]);
                                      }
                                    },
                                    icon: const Icon(Icons.camera_alt_outlined, size: 16),
                                    label: const Text('Foto Struk'),
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: const Color(0xFF7B1FA2),
                                      foregroundColor: Colors.white,
                                      elevation: 0,
                                      padding: const EdgeInsets.symmetric(vertical: 8),
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: OutlinedButton.icon(
                                    onPressed: () async {
                                      final files = await _picker.pickMultiImage(
                                        maxWidth: 1280,
                                        maxHeight: 1280,
                                        imageQuality: 80,
                                      );
                                      if (files.isNotEmpty) {
                                        await uploadDirectFiles(files);
                                      }
                                    },
                                    icon: const Icon(Icons.photo_library_outlined, size: 16),
                                    label: const Text('Pilih Banyak'),
                                    style: OutlinedButton.styleFrom(
                                      foregroundColor: const Color(0xFF6A1B9A),
                                      side: const BorderSide(color: Color(0xFF8E24AA)),
                                      padding: const EdgeInsets.symmetric(vertical: 8),
                                    ),
                                  ),
                                ),
                              ],
                            ),
                        ],
                      ),
                    ),

                    const SizedBox(height: 14),

                    // Opsi 2: Struk Draf yang Sudah Ada
                    Text(
                      'Atau Pilih dari Draf Tersedia (${available.length})',
                      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 6),

                    if (rcpProv.loading && available.isEmpty)
                      Expanded(
                        child: ShimmerLoading(
                          child: ListView.separated(
                            itemCount: 3,
                            separatorBuilder: (_, _) => const SizedBox(height: 8),
                            itemBuilder: (_, _) => const SkeletonReceiptItemCard(),
                          ),
                        ),
                      )
                    else if (available.isEmpty)
                      Expanded(
                        child: Center(
                          child: Text(
                            'Belum ada struk draf mandiri lainnya.\nGunakan tombol foto kamera / galeri di atas untuk menambah.',
                            textAlign: TextAlign.center,
                            style: TextStyle(fontSize: 12, color: Colors.grey.shade500),
                          ),
                        ),
                      )
                    else ...[
                      Expanded(
                        child: ListView.separated(
                          itemCount: available.length,
                          separatorBuilder: (_, index) => const SizedBox(height: 8),
                          itemBuilder: (_, idx) {
                            final r = available[idx];
                            final isChecked = selectedIds.contains(r.id);
                            final isFailed = r.isOcrFailed || r.isBlurry;
                            final isNonReceipt = r.isNonReceipt;

                            return InkWell(
                              onTap: () {
                                setModalState(() {
                                  if (isChecked) {
                                    selectedIds.remove(r.id);
                                  } else {
                                    selectedIds.add(r.id);
                                  }
                                });
                              },
                              child: Container(
                                padding: const EdgeInsets.all(8),
                                decoration: BoxDecoration(
                                  color: isFailed
                                      ? (isChecked ? const Color(0xFFFFF0F0) : const Color(0xFFFFF8F8))
                                      : (isChecked ? const Color(0xFFF0F7FF) : Colors.white),
                                  borderRadius: BorderRadius.circular(8),
                                  border: Border.all(
                                    color: isFailed
                                        ? (isChecked ? Colors.red.shade600 : Colors.red.shade300)
                                        : (isChecked ? const Color(0xFF1E88E5) : Colors.grey.shade300),
                                    width: isChecked ? 1.5 : 1,
                                  ),
                                ),
                                child: Row(
                                  children: [
                                    Checkbox(
                                      value: isChecked,
                                      onChanged: (val) {
                                        setModalState(() {
                                          if (val == true) {
                                            selectedIds.add(r.id);
                                          } else {
                                            selectedIds.remove(r.id);
                                          }
                                        });
                                      },
                                      activeColor: isFailed ? Colors.red.shade700 : const Color(0xFF1E88E5),
                                    ),
                                    ReceiptThumbnail(
                                      receiptId: r.id,
                                      imagePath: r.imagePath,
                                      size: 42,
                                      borderRadius: BorderRadius.circular(6),
                                    ),
                                    const SizedBox(width: 8),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Row(
                                            children: [
                                              Text(
                                                r.receiptNumber,
                                                style: TextStyle(
                                                  fontSize: 10.5,
                                                  fontWeight: FontWeight.bold,
                                                  color: isFailed ? Colors.red.shade700 : Colors.grey.shade600,
                                                ),
                                              ),
                                              const SizedBox(width: 4),
                                              if (isFailed)
                                                Container(
                                                  padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
                                                  decoration: BoxDecoration(
                                                    color: Colors.red.shade100,
                                                    borderRadius: BorderRadius.circular(3),
                                                  ),
                                                  child: Text(
                                                    isNonReceipt
                                                        ? 'Bukan Struk'
                                                        : (r.isBlurry ? 'Foto Buram' : 'Gagal OCR'),
                                                    style: TextStyle(
                                                      fontSize: 9,
                                                      fontWeight: FontWeight.bold,
                                                      color: Colors.red.shade800,
                                                    ),
                                                  ),
                                                ),
                                            ],
                                          ),
                                          const SizedBox(height: 2),
                                          Text(
                                            r.displayMerchant,
                                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12.5),
                                          ),
                                          Text(
                                            isFailed
                                                ? (r.ocrError ??
                                                    (isNonReceipt
                                                        ? 'Bukan foto struk valid'
                                                        : 'Foto buram / tidak terbaca'))
                                                : '${r.category ?? 'Lain-lain'} · ${r.displayDate}',
                                            style: TextStyle(
                                              fontSize: 10.5,
                                              color: isFailed ? Colors.red.shade700 : Colors.grey.shade500,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    Text(
                                      formatCurrency(r.displayAmount),
                                      style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        color: isFailed ? Colors.red.shade700 : const Color(0xFF0D47A1),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
                      ),
                      const SizedBox(height: 12),
                      SizedBox(
                        width: double.infinity,
                        height: 44,
                        child: ElevatedButton(
                          onPressed: selectedIds.isEmpty
                              ? null
                              : () async {
                                  Navigator.pop(ctx);
                                  setState(() => _isProcessingAction = true);
                                  try {
                                    final expProv = Provider.of<ExpenseReportProvider>(context, listen: false);
                                    await expProv.addReceipts(widget.reportId, selectedIds.toList());
                                    await rcpProv.fetchMyReceipts(forceRefresh: true);
                                    await _loadDetail(forceRefresh: true);
                                    if (!mounted) return;
                                    ScaffoldMessenger.of(context).showSnackBar(
                                      SnackBar(content: Text('${selectedIds.length} struk berhasil ditambahkan.')),
                                    );
                                  } catch (e) {
                                    if (!mounted) return;
                                    ScaffoldMessenger.of(context).showSnackBar(
                                      SnackBar(content: Text(e.toString()), backgroundColor: Colors.red),
                                    );
                                  } finally {
                                    if (mounted) setState(() => _isProcessingAction = false);
                                  }
                                },
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF1E88E5),
                            foregroundColor: Colors.white,
                          ),
                          child: Text('Tambahkan Draf (${selectedIds.length} Struk)'),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  Widget _buildStatusBanner(ExpenseReportRecord r) {
    Color bg;
    Color border;
    Color text;
    IconData icon;
    String title;
    String desc;

    switch (r.status) {
      case 'paid':
        bg = const Color(0xFFE0F2F1);
        border = const Color(0xFF80CBC4);
        text = const Color(0xFF004D40);
        icon = Icons.check_circle_rounded;
        title = 'Reimbursement Telah Dicairkan';
        desc = 'Dana laporan dinas ini telah ditransfer oleh Finance.';
        break;
      case 'approved':
        bg = const Color(0xFFE8F5E9);
        border = const Color(0xFFA5D6A7);
        text = const Color(0xFF2E7D32);
        icon = Icons.verified_rounded;
        title = 'Disetujui oleh Finance';
        desc = 'Seluruh pengeluaran dalam laporan ini telah disetujui.';
        break;
      case 'rejected':
        bg = const Color(0xFFFFEBEE);
        border = const Color(0xFFFFCDD2);
        text = const Color(0xFFC62828);
        icon = Icons.cancel_rounded;
        title = 'Laporan Ditolak oleh Finance';
        desc = r.rejectionReason != null && r.rejectionReason!.isNotEmpty
            ? 'Alasan: ${r.rejectionReason}'
            : 'Laporan tidak disetujui. Hubungi tim Finance untuk informasi lebih lanjut.';
        break;
      case 'submitted':
        bg = const Color(0xFFFFF3E0);
        border = const Color(0xFFFFE0B2);
        text = const Color(0xFFE65100);
        icon = Icons.hourglass_top_rounded;
        title = 'Menunggu Review Finance';
        desc = 'Seluruh struk terkunci dan sedang dalam proses audit Finance.';
        break;
      default: // draft
        bg = const Color(0xFFF5F5F5);
        border = Colors.grey.shade300;
        text = Colors.grey.shade800;
        icon = Icons.edit_note_rounded;
        title = 'Draf Laporan Dinas';
        desc = 'Laporan belum diajukan. Anda dapat menambah atau melepas struk.';
        break;
    }

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: text, size: 22),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: TextStyle(fontWeight: FontWeight.bold, color: text, fontSize: 13)),
                const SizedBox(height: 2),
                Text(desc, style: TextStyle(fontSize: 11.5, color: text.withValues(alpha: 0.9), height: 1.3)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return Scaffold(
        appBar: AppBar(title: const Text('Rincian Laporan Dinas')),
        body: const SkeletonExpenseReportDetail(),
      );
    }

    if (_report == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Rincian Laporan Dinas')),
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text('Laporan tidak ditemukan.'),
              const SizedBox(height: 12),
              ElevatedButton(
                onPressed: () => _loadDetail(forceRefresh: true),
                child: const Text('Coba Lagi'),
              ),
            ],
          ),
        ),
      );
    }

    final r = _report!;
    final hasPendingOcr = r.receipts.any((rcp) => rcp.isOcrPending);
    final hasFailedOcr = r.receipts.any((rcp) => rcp.isOcrFailed || rcp.isBlurry);
    final failedCount = r.receipts.where((rcp) => rcp.isOcrFailed || rcp.isBlurry).length;
    final canSubmitToFinance = !hasPendingOcr && !hasFailedOcr && r.receipts.isNotEmpty;

    return Scaffold(
      appBar: AppBar(
        title: Text(r.reportNumber),
        actions: [
          if (r.isDraft)
            IconButton(
              icon: const Icon(Icons.delete_outline),
              tooltip: 'Hapus Draf',
              onPressed: _isProcessingAction ? null : _handleDeleteReport,
            ),
        ],
      ),
      bottomNavigationBar: r.isDraft
          ? Container(
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
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (hasPendingOcr)
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                        color: Colors.blue.shade50,
                        child: const Row(
                          children: [
                            SizedBox(
                              width: 14,
                              height: 14,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF1565C0)),
                            ),
                            SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                'Sedang membaca struk otomatis (scan AI)...',
                                style: TextStyle(fontSize: 11.5, color: Color(0xFF0D47A1), fontWeight: FontWeight.w500),
                              ),
                            ),
                          ],
                        ),
                      )
                    else if (hasFailedOcr)
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                        color: Colors.red.shade50,
                        child: Row(
                          children: [
                            Icon(Icons.warning_amber_rounded, size: 16, color: Colors.red.shade700),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                'Ada $failedCount struk gagal/buram. Harap lepas atau foto ulang struk tersebut.',
                                style: TextStyle(fontSize: 11.5, color: Colors.red.shade800, fontWeight: FontWeight.w500),
                              ),
                            ),
                          ],
                        ),
                      ),
                    Padding(
                      padding: EdgeInsets.fromLTRB(
                        16,
                        10,
                        16,
                        MediaQuery.of(context).padding.bottom > 0 ? 8 : 16,
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: OutlinedButton.icon(
                              onPressed: _isProcessingAction ? null : _showAddReceiptsModal,
                              icon: const Icon(Icons.add, size: 18),
                              label: const Text('Tambah Struk', style: TextStyle(fontSize: 12.5)),
                              style: OutlinedButton.styleFrom(
                                padding: const EdgeInsets.symmetric(vertical: 12),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            flex: 2,
                            child: ElevatedButton.icon(
                              onPressed: (_isProcessingAction || !canSubmitToFinance)
                                  ? null
                                  : _handleSubmitReport,
                              icon: _isProcessingAction
                                  ? const SizedBox(
                                      width: 16,
                                      height: 16,
                                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                    )
                                  : Icon(
                                      hasPendingOcr
                                          ? Icons.hourglass_top_rounded
                                          : hasFailedOcr
                                              ? Icons.warning_amber_rounded
                                              : Icons.send_rounded,
                                      size: 18,
                                    ),
                              label: Text(
                                _isProcessingAction
                                    ? 'Memproses...'
                                    : hasPendingOcr
                                        ? 'Menunggu Scan AI...'
                                        : hasFailedOcr
                                            ? 'Lepas Struk Gagal ($failedCount)'
                                            : r.receipts.isEmpty
                                                ? 'Tambah Struk Dulu'
                                                : 'Kirim ke Finance',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                              ),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: canSubmitToFinance ? const Color(0xFF1E88E5) : Colors.grey.shade400,
                                foregroundColor: Colors.white,
                                disabledBackgroundColor: Colors.grey.shade300,
                                disabledForegroundColor: Colors.grey.shade600,
                                padding: const EdgeInsets.symmetric(vertical: 12),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            )
          : null,
      body: RefreshIndicator(
        onRefresh: () => _loadDetail(forceRefresh: true, silent: true),
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _buildStatusBanner(r),
              const SizedBox(height: 16),

              // ─── Kartu Informasi Laporan ───────────────────────
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: Colors.grey.shade200),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      r.title,
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        const Icon(Icons.calendar_month, size: 15, color: Color(0xFF1E88E5)),
                        const SizedBox(width: 6),
                        Text(
                          r.displayPeriod,
                          style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                        ),
                      ],
                    ),
                    if (r.description != null && r.description!.isNotEmpty) ...[
                      const SizedBox(height: 10),
                      Text(
                        r.description!,
                        style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                      ),
                    ],
                    const Divider(height: 24),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Total Klaim', style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
                            const SizedBox(height: 2),
                            Text(
                              formatCurrency(r.totalClaimedAmount),
                              style: const TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF0D47A1),
                              ),
                            ),
                          ],
                        ),
                        if (r.isApproved || r.isPaid)
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              Text('Disetujui', style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
                              const SizedBox(height: 2),
                              Text(
                                formatCurrency(r.totalApprovedAmount ?? r.totalClaimedAmount),
                                style: const TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                  color: Color(0xFF2E7D32),
                                ),
                              ),
                            ],
                          )
                        else if (r.isRejected)
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              Text('Status Laporan', style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
                              const SizedBox(height: 2),
                              Text(
                                'Ditolak',
                                style: TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.bold,
                                  color: Colors.red.shade700,
                                ),
                              ),
                            ],
                          ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(
                            color: Colors.grey.shade100,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text(
                            '${r.receipts.length} Struk',
                            style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // ─── Daftar Struk dalam Bundle ───────────────────────
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Daftar Struk Terlampir (${r.receipts.length})',
                    style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold),
                  ),
                  if (r.isDraft && r.receipts.isNotEmpty)
                    TextButton.icon(
                      onPressed: _isProcessingAction ? null : _showAddReceiptsModal,
                      icon: const Icon(Icons.add, size: 16),
                      label: const Text('Tambah Struk', style: TextStyle(fontSize: 12)),
                      style: TextButton.styleFrom(padding: EdgeInsets.zero),
                    ),
                ],
              ),
              const SizedBox(height: 8),

              if (r.receipts.isEmpty)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(24),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade50,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Colors.grey.shade200),
                  ),
                  child: Column(
                    children: [
                      Icon(Icons.receipt_long_outlined, size: 36, color: Colors.grey.shade400),
                      const SizedBox(height: 8),
                      const Text(
                        'Belum ada struk di dalam laporan ini',
                        style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Tekan tombol "+ Tambah Struk" di bawah untuk memasukkan struk draf Anda.',
                        textAlign: TextAlign.center,
                        style: TextStyle(fontSize: 11.5, color: Colors.grey.shade500),
                      ),
                    ],
                  ),
                )
              else
                ListView.separated(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: r.receipts.length,
                  separatorBuilder: (_, index) => const SizedBox(height: 8),
                  itemBuilder: (context, index) {
                    final rcp = r.receipts[index];
                    final isFailed = rcp.isOcrFailed || rcp.isBlurry;
                    final isNonReceipt = rcp.isNonReceipt;

                    // Status Verifikasi Struk oleh Finance
                    final isReceiptRejected = rcp.isRejected || (r.isRejected && !rcp.isApproved && !rcp.isPaid);
                    final isReceiptApproved = rcp.isApproved || (r.isApproved && !rcp.isRejected && !rcp.isPaid);
                    final isReceiptPaid = rcp.isPaid || (r.isPaid && !rcp.isRejected);
                    final isReceiptSubmitted = (rcp.isSubmitted || r.isSubmitted) && !isReceiptRejected && !isReceiptApproved && !isReceiptPaid;

                    // Alasan Penolakan Efektif
                    final effectiveRejection = (rcp.rejectionReason != null && rcp.rejectionReason!.trim().isNotEmpty)
                        ? rcp.rejectionReason!.trim()
                        : (r.isRejected && r.rejectionReason != null && r.rejectionReason!.trim().isNotEmpty
                            ? r.rejectionReason!.trim()
                            : null);

                    return InkWell(
                      onTap: () async {
                        await showModalBottomSheet(
                          context: context,
                          isScrollControlled: true,
                          backgroundColor: Colors.transparent,
                          builder: (_) => DetailPengajuanScreen(receipt: rcp),
                        );
                        if (mounted && r.isDraft) {
                          _loadDetail(forceRefresh: true, silent: true);
                        }
                      },
                      borderRadius: BorderRadius.circular(10),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        decoration: BoxDecoration(
                          color: isFailed
                              ? const Color(0xFFFFF8F8)
                              : isReceiptRejected
                                  ? const Color(0xFFFFF5F5)
                                  : (isReceiptApproved || isReceiptPaid)
                                      ? const Color(0xFFF9FDF9)
                                      : Colors.white,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(
                            color: isFailed
                                ? Colors.red.shade300
                                : isReceiptRejected
                                    ? Colors.red.shade200
                                    : (isReceiptApproved || isReceiptPaid)
                                        ? Colors.green.shade300
                                        : Colors.grey.shade200,
                            width: (isFailed || isReceiptRejected || isReceiptApproved || isReceiptPaid) ? 1.2 : 1,
                          ),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.center,
                          children: [
                            ReceiptThumbnail(
                              receiptId: rcp.id,
                              imagePath: rcp.imagePath,
                              size: 48,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      Flexible(
                                        child: Text(
                                          rcp.receiptNumber,
                                          style: TextStyle(
                                            fontSize: 11,
                                            fontWeight: FontWeight.bold,
                                            color: (isFailed || isReceiptRejected)
                                                ? Colors.red.shade700
                                                : Colors.grey.shade600,
                                          ),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                      const SizedBox(width: 4),

                                      // ─── BADGE STATUS APPROVAL / OCR ───
                                      if (isReceiptRejected)
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1.5),
                                          decoration: BoxDecoration(
                                            color: const Color(0xFFFFEBEE),
                                            borderRadius: BorderRadius.circular(4),
                                            border: Border.all(color: const Color(0xFFFFCDD2)),
                                          ),
                                          child: const Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              Icon(Icons.cancel_rounded, size: 9.5, color: Color(0xFFC62828)),
                                              SizedBox(width: 2.5),
                                              Text(
                                                'Ditolak',
                                                style: TextStyle(
                                                  fontSize: 9.5,
                                                  fontWeight: FontWeight.bold,
                                                  color: Color(0xFFB71C1C),
                                                ),
                                              ),
                                            ],
                                          ),
                                        )
                                      else if (isReceiptPaid)
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1.5),
                                          decoration: BoxDecoration(
                                            color: const Color(0xFFE0F2F1),
                                            borderRadius: BorderRadius.circular(4),
                                            border: Border.all(color: const Color(0xFF80CBC4)),
                                          ),
                                          child: const Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              Icon(Icons.payments_rounded, size: 9.5, color: Color(0xFF00695C)),
                                              SizedBox(width: 2.5),
                                              Text(
                                                'Dibayar',
                                                style: TextStyle(
                                                  fontSize: 9.5,
                                                  fontWeight: FontWeight.bold,
                                                  color: Color(0xFF004D40),
                                                ),
                                              ),
                                            ],
                                          ),
                                        )
                                      else if (isReceiptApproved)
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1.5),
                                          decoration: BoxDecoration(
                                            color: const Color(0xFFE8F5E9),
                                            borderRadius: BorderRadius.circular(4),
                                            border: Border.all(color: const Color(0xFFA5D6A7)),
                                          ),
                                          child: const Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              Icon(Icons.check_circle_rounded, size: 9.5, color: Color(0xFF2E7D32)),
                                              SizedBox(width: 2.5),
                                              Text(
                                                'Disetujui',
                                                style: TextStyle(
                                                  fontSize: 9.5,
                                                  fontWeight: FontWeight.bold,
                                                  color: Color(0xFF1B5E20),
                                                ),
                                              ),
                                            ],
                                          ),
                                        )
                                      else if (isReceiptSubmitted)
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1.5),
                                          decoration: BoxDecoration(
                                            color: const Color(0xFFFFF3E0),
                                            borderRadius: BorderRadius.circular(4),
                                            border: Border.all(color: const Color(0xFFFFE0B2)),
                                          ),
                                          child: const Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              Icon(Icons.hourglass_top_rounded, size: 9.5, color: Color(0xFFE65100)),
                                              SizedBox(width: 2.5),
                                              Text(
                                                'Menunggu',
                                                style: TextStyle(
                                                  fontSize: 9.5,
                                                  fontWeight: FontWeight.bold,
                                                  color: Color(0xFFBF360C),
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
                                              Icon(Icons.cancel_outlined, size: 10, color: Colors.red.shade700),
                                              const SizedBox(width: 3),
                                              Text(
                                                isNonReceipt
                                                    ? 'Bukan Struk'
                                                    : (rcp.isBlurry ? 'Foto Buram' : 'Gagal OCR'),
                                                style: TextStyle(
                                                  fontSize: 9.5,
                                                  fontWeight: FontWeight.bold,
                                                  color: Colors.red.shade800,
                                                ),
                                              ),
                                            ],
                                          ),
                                        )
                                      else if (rcp.isOcrPending)
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
                                    isFailed && (rcp.merchantName == null || rcp.merchantName!.isEmpty)
                                        ? (isNonReceipt ? 'Bukan Dokumen Struk' : 'Struk Tidak Terbaca')
                                        : rcp.displayMerchant,
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
                                      rcp.ocrError ??
                                          (isNonReceipt
                                              ? 'Foto bukan struk pengeluaran valid.'
                                              : 'Foto buram / tidak terbaca jelas.'),
                                      style: TextStyle(fontSize: 11, color: Colors.red.shade700),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    )
                                  else
                                    Text(
                                      '${rcp.category ?? 'Lain-lain'} · ${rcp.displayDate}${rcp.items.isNotEmpty ? ' · (${rcp.items.length} item)' : ''}',
                                      style: TextStyle(fontSize: 11, color: Colors.grey.shade500),
                                    ),

                                  // Catatan Alasan Penolakan Struk
                                  if (isReceiptRejected && effectiveRejection != null) ...[
                                    const SizedBox(height: 3),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                      decoration: BoxDecoration(
                                        color: const Color(0xFFFFEBEE),
                                        borderRadius: BorderRadius.circular(4),
                                        border: Border.all(color: const Color(0xFFFFCDD2), width: 0.8),
                                      ),
                                      child: Text(
                                        'Alasan: $effectiveRejection',
                                        style: const TextStyle(
                                          fontSize: 10,
                                          color: Color(0xFFB71C1C),
                                          fontWeight: FontWeight.w500,
                                        ),
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                    ),
                                  ],
                                ],
                              ),
                            ),
                            const SizedBox(width: 6),
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                if (isFailed) ...[
                                  if (r.isDraft)
                                    ElevatedButton.icon(
                                      onPressed: _isProcessingAction ? null : () => _handleRetakeReceipt(rcp),
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
                                  if (r.isDraft)
                                    GestureDetector(
                                      onTap: _isProcessingAction ? null : () => _handleRemoveReceipt(rcp),
                                      child: Padding(
                                        padding: const EdgeInsets.only(top: 4),
                                        child: Text(
                                          'Lepas',
                                          style: TextStyle(
                                            fontSize: 10.5,
                                            fontWeight: FontWeight.w600,
                                            color: Colors.red.shade700,
                                            decoration: TextDecoration.underline,
                                          ),
                                        ),
                                      ),
                                    ),
                                ] else ...[
                                  FittedBox(
                                    fit: BoxFit.scaleDown,
                                    child: Text(
                                      formatCurrency(rcp.displayAmount),
                                      style: TextStyle(
                                        fontSize: 13,
                                        fontWeight: FontWeight.bold,
                                        color: isReceiptRejected
                                            ? Colors.red.shade400
                                            : ((isReceiptApproved || isReceiptPaid)
                                                ? const Color(0xFF2E7D32)
                                                : const Color(0xFF0D47A1)),
                                        decoration: isReceiptRejected ? TextDecoration.lineThrough : null,
                                      ),
                                    ),
                                  ),
                                  if (isReceiptRejected)
                                    const Padding(
                                      padding: EdgeInsets.only(top: 2),
                                      child: Text(
                                        'Ditolak',
                                        style: TextStyle(
                                          fontSize: 10,
                                          fontWeight: FontWeight.bold,
                                          color: Color(0xFFC62828),
                                        ),
                                      ),
                                    )
                                  else if (isReceiptApproved)
                                    const Padding(
                                      padding: EdgeInsets.only(top: 2),
                                      child: Text(
                                        'Disetujui',
                                        style: TextStyle(
                                          fontSize: 10,
                                          fontWeight: FontWeight.bold,
                                          color: Color(0xFF2E7D32),
                                        ),
                                      ),
                                    )
                                  else if (isReceiptPaid)
                                    const Padding(
                                      padding: EdgeInsets.only(top: 2),
                                      child: Text(
                                        'Dibayar',
                                        style: TextStyle(
                                          fontSize: 10,
                                          fontWeight: FontWeight.bold,
                                          color: Color(0xFF00695C),
                                        ),
                                      ),
                                    ),
                                  if (r.isDraft)
                                    GestureDetector(
                                      onTap: _isProcessingAction ? null : () => _handleRemoveReceipt(rcp),
                                      child: Padding(
                                        padding: const EdgeInsets.only(top: 4),
                                        child: Text(
                                          'Lepas',
                                          style: TextStyle(
                                            fontSize: 11,
                                            fontWeight: FontWeight.w600,
                                            color: Colors.red.shade700,
                                          ),
                                        ),
                                      ),
                                    ),
                                ],
                              ],
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              const SizedBox(height: 30),
            ],
          ),
        ),
      ),
    );
  }
}
