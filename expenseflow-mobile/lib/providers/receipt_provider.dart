import 'dart:convert';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils.dart';
import '../widgets/receipt_thumbnail.dart';

class ReceiptItem {
  final String name;
  final int qty;
  final double price;
  final double total;

  const ReceiptItem({
    required this.name,
    this.qty = 1,
    this.price = 0.0,
    this.total = 0.0,
  });

  factory ReceiptItem.fromJson(Map<String, dynamic> json) {
    double parseNum(dynamic val) {
      if (val == null) return 0.0;
      if (val is num) return val.toDouble();
      return double.tryParse(val.toString()) ?? 0.0;
    }

    int parseQty(dynamic val) {
      if (val == null) return 1;
      if (val is num) return val.toInt();
      return int.tryParse(val.toString()) ?? 1;
    }

    final p = parseNum(json['price']);
    final q = parseQty(json['qty']);
    final t = json['total'] != null ? parseNum(json['total']) : (p * q);

    return ReceiptItem(
      name: (json['name'] ?? '').toString(),
      qty: q,
      price: p,
      total: t > 0 ? t : (p * q),
    );
  }
}

class ReceiptRecord {
  final int id;
  final String receiptNumber;
  final String? ocrRawAmount;
  final String? ocrRawMerchant;
  final String? ocrRawDate;
  final double? ocrRawSubtotal;
  final double? ocrRawTax;
  final double? ocrRawDiscount;
  final List<ReceiptItem> items;
  final double? claimedAmount;
  final double? approvedAmount;
  final bool isPotentialDuplicate;
  final int? duplicateReferenceId;
  final String? duplicateReceiptNumber;
  final String? duplicateReason;
  final String? paidAt;
  final String? paidBy;
  final String? paymentMethod;
  final String? paymentRefNo;
  final String? vendorName;
  final String? receiptDate;
  final String status;
  final String ocrStatus;
  final String? category;
  final String? notes;
  final String? rejectionReason;
  final String? ocrError;
  final String? imagePath;
  final String createdAt;
  final int? expenseReportId;
  final String? expenseReportTitle;
  final String? expenseReportNumber;

  const ReceiptRecord({
    required this.id,
    required this.receiptNumber,
    this.ocrRawAmount,
    this.ocrRawMerchant,
    this.ocrRawDate,
    this.ocrRawSubtotal,
    this.ocrRawTax,
    this.ocrRawDiscount,
    this.items = const [],
    this.claimedAmount,
    this.approvedAmount,
    this.isPotentialDuplicate = false,
    this.duplicateReferenceId,
    this.duplicateReceiptNumber,
    this.duplicateReason,
    this.paidAt,
    this.paidBy,
    this.paymentMethod,
    this.paymentRefNo,
    this.vendorName,
    this.receiptDate,
    required this.status,
    required this.ocrStatus,
    this.category,
    this.notes,
    this.rejectionReason,
    this.ocrError,
    this.imagePath,
    required this.createdAt,
    this.expenseReportId,
    this.expenseReportTitle,
    this.expenseReportNumber,
  });

  factory ReceiptRecord.fromJson(Map<String, dynamic> m) {
    double? parseAmount(dynamic v) {
      if (v == null) return null;
      if (v is num) return v.toDouble();
      return double.tryParse(v.toString());
    }

    // rejection_reason: bisa dari field langsung (myReceipts), approvals (show), atau ocr_error jika OCR ditolak
    String? rejection = m['rejection_reason']?.toString();
    if ((rejection == null || rejection.isEmpty) && m['approvals'] != null) {
      final approvals = m['approvals'] as List?;
      for (final a in approvals ?? []) {
        final ap = a as Map<String, dynamic>;
        if ((ap['status'] ?? '') == 'rejected') {
          rejection = ap['notes']?.toString();
          break;
        }
      }
    }
    if ((rejection == null || rejection.isEmpty) && m['ocr_error'] != null) {
      rejection = m['ocr_error']?.toString();
    }

    // Parse items
    final List<ReceiptItem> parsedItems = [];
    if (m['ocr_raw_items'] != null) {
      dynamic rawItems = m['ocr_raw_items'];
      if (rawItems is String) {
        try {
          rawItems = jsonDecode(rawItems);
        } catch (_) {}
      }
      if (rawItems is List) {
        for (final it in rawItems) {
          if (it is Map<String, dynamic>) {
            parsedItems.add(ReceiptItem.fromJson(it));
          } else if (it is Map) {
            parsedItems.add(ReceiptItem.fromJson(Map<String, dynamic>.from(it)));
          }
        }
      }
    }

    final dupRef = m['duplicate_reference'] is Map ? m['duplicate_reference'] as Map<String, dynamic> : null;
    final expRep = m['expense_report'] is Map ? m['expense_report'] as Map<String, dynamic> : null;
    final expRepId = (m['expense_report_id'] as num?)?.toInt() ?? (expRep?['id'] as num?)?.toInt();
    final expRepTitle = expRep?['title']?.toString();
    final expRepNumber = expRep?['report_number']?.toString();

    return ReceiptRecord(
      id: (m['id'] as num).toInt(),
      receiptNumber: (m['receipt_number'] ?? '').toString(),
      ocrRawAmount: m['ocr_raw_amount']?.toString(),
      ocrRawMerchant: m['ocr_raw_merchant']?.toString(),
      ocrRawDate: m['ocr_raw_date']?.toString(),
      ocrRawSubtotal: parseAmount(m['ocr_raw_subtotal']),
      ocrRawTax: parseAmount(m['ocr_raw_tax']),
      ocrRawDiscount: parseAmount(m['ocr_raw_discount']),
      items: parsedItems,
      claimedAmount: parseAmount(m['claimed_amount']),
      approvedAmount: parseAmount(m['approved_amount']),
      isPotentialDuplicate: m['is_potential_duplicate'] == true || m['is_potential_duplicate'] == 1,
      duplicateReferenceId: (m['duplicate_reference_id'] as num?)?.toInt(),
      duplicateReceiptNumber: dupRef?['receipt_number']?.toString(),
      duplicateReason: m['duplicate_reason']?.toString(),
      paidAt: m['paid_at']?.toString(),
      paidBy: m['paid_by']?.toString(),
      paymentMethod: m['payment_method']?.toString(),
      paymentRefNo: m['payment_ref_no']?.toString(),
      vendorName: m['vendor_name']?.toString(),
      receiptDate: m['receipt_date']?.toString(),
      status: (m['status'] ?? 'draft').toString(),
      ocrStatus: (m['ocr_status'] ?? 'pending').toString(),
      category: m['category']?.toString(),
      notes: m['notes']?.toString(),
      rejectionReason: (rejection?.isEmpty ?? true) ? null : rejection,
      ocrError: m['ocr_error']?.toString() ?? rejection,
      imagePath: m['image_path']?.toString(),
      createdAt: (m['created_at'] ?? '').toString(),
      expenseReportId: expRepId,
      expenseReportTitle: expRepTitle,
      expenseReportNumber: expRepNumber,
    );
  }

  bool get isBundled => expenseReportId != null;

  bool get isOcrPending => ocrStatus == 'pending' || ocrStatus == 'processing';
  bool get isOcrFailed => ocrStatus == 'failed';
  bool get isOcrSuccess => ocrStatus == 'done' || ocrStatus == 'success';
  bool get isBlurry {
    final err = (ocrError ?? rejectionReason ?? '').toLowerCase();
    return isOcrFailed && (
      err.contains('buram') ||
      err.contains('blur') ||
      err.contains('goyang') ||
      err.contains('bergoyang') ||
      err.contains('tidak terbaca') ||
      err.contains('tidak terdeteksi')
    );
  }

  bool get isNonReceipt {
    final err = (ocrError ?? rejectionReason ?? '').toLowerCase();
    return isOcrFailed && (
      err.contains('bukan') ||
      err.contains('tidak valid') ||
      err.contains('bukan struk') ||
      err.contains('bukan nota') ||
      err.contains('bukan foto') ||
      err.contains('tidak tampak') ||
      err.contains('tidak menyerupai') ||
      err.contains('bukan dokumen') ||
      err.contains('non-receipt')
    );
  }

  /// True jika struk ini gagal OCR (baik karena foto buram, bukan struk, maupun gagal membaca)
  bool get isOcrRejectedOrFailed => isOcrFailed || isBlurry;

  String? get merchantName => vendorName ?? ocrRawMerchant;
  double? get totalAmount => ocrRawSubtotal ?? claimedAmount;
  String get displayMerchant => vendorName ?? ocrRawMerchant ?? '-';

  double get displayAmount {
    if (approvedAmount != null && approvedAmount! > 0 && (status == 'approved' || status == 'paid')) {
      return approvedAmount!;
    }
    if (claimedAmount != null && claimedAmount! > 0) return claimedAmount!;
    if (ocrRawAmount != null) return double.tryParse(ocrRawAmount!) ?? 0;
    return 0;
  }

  String get displayDate {
    final raw = receiptDate ?? ocrRawDate ?? createdAt;
    return formatDateIndonesian(raw);
  }

  String get displayStatus {
    switch (status) {
      case 'paid': return 'Dibayar';
      case 'approved': return 'Pending';
      case 'rejected': return 'Ditolak';
      case 'submitted': return 'Menunggu';
      default: return 'Draf';
    }
  }

  bool get isPaid => status == 'paid';
  bool get isApproved => status == 'approved';
  bool get isRejected => status == 'rejected';
  bool get isSubmitted => status == 'submitted';
  bool get isDraft => status == 'draft';

  /// Peringatan potensi duplikat hanya ditampilkan jika struk belum disetujui finance (misal: masih menunggu/submitted atau draft).
  /// Jika sudah disetujui finance ('approved' atau 'paid'), peringatan disembunyikan.
  bool get showDuplicateWarning =>
      isPotentialDuplicate && status != 'approved' && status != 'paid';

  String get displayPaymentMethod {
    switch (paymentMethod) {
      case 'bank_transfer': return 'Transfer Bank';
      case 'cash': return 'Kasbon / Tunai';
      case 'payroll': return 'Slip Gaji / Payroll';
      default: return paymentMethod ?? 'Transfer Bank';
    }
  }
}

class ReceiptProvider extends ChangeNotifier {
  final List<ReceiptRecord> _receipts = [];
  bool _loading = false;

  List<ReceiptRecord> get receipts => List.unmodifiable(_receipts);
  bool get loading => _loading;

  List<ReceiptRecord> get recent => _receipts.take(5).toList();

  double get totalThisMonth {
    final now = DateTime.now();
    return _receipts
        .where((r) {
          if (r.status == 'draft') return false;
          final d = DateTime.tryParse(r.createdAt);
          return d != null && d.month == now.month && d.year == now.year;
        })
        .fold(0.0, (sum, r) => sum + r.displayAmount);
  }

  int get approvedCount =>
      _receipts.where((r) => r.status == 'approved').length;

  Future<void> fetchMyReceipts({bool forceRefresh = false}) async {
    _loading = true;
    notifyListeners();
    try {
      final res = await ApiService.myReceipts(forceRefresh: forceRefresh);
      final list = (res['data'] as List?) ?? [];
      _receipts
        ..clear()
        ..addAll(
          list.map((e) => ReceiptRecord.fromJson(e as Map<String, dynamic>)),
        );
    } catch (_) {}
    _loading = false;
    notifyListeners();
  }

  // Upload foto ke backend → return receipt id + receipt_number
  Future<({int id, String receiptNumber})> uploadReceipt(
    Uint8List imageBytes,
    String fileName, {
    List<Uint8List>? additionalPhotos,
    List<String>? additionalFileNames,
    int? expenseReportId,
  }) async {
    final res = await ApiService.uploadReceipt(
      imageBytes,
      fileName,
      additionalPhotos: additionalPhotos,
      additionalFileNames: additionalFileNames,
      expenseReportId: expenseReportId,
    );
    final r = res['receipt'] as Map<String, dynamic>;
    return (
      id: (r['id'] as num).toInt(),
      receiptNumber: (r['receipt_number'] ?? '').toString(),
    );
  }

  // Poll GET /employee/receipts/{id} sampai ocr_status selesai (max ~60s)
  Future<Map<String, dynamic>> pollOcrStatus(int id) async {
    // Polling adaptif: 1s pada 10 detik pertama (fase krusial penyelesaian OCR di backend),
    // kemudian 1.5s, dan 2s setelahnya agar responsif tanpa membebani server.
    for (int i = 0; i < 35; i++) {
      final delayMs = i < 10 ? 1000 : (i < 18 ? 1500 : 2000);
      await Future.delayed(Duration(milliseconds: delayMs));
      try {
        final res = await ApiService.getReceipt(id, forceRefresh: true);
        final r = res['receipt'] as Map<String, dynamic>;
        final s = (r['ocr_status'] ?? 'pending').toString();
        if (s != 'pending' && s != 'processing') return r;
      } catch (_) {}
    }
    // Fallback: kembalikan state terakhir
    final res = await ApiService.getReceipt(id, forceRefresh: true);
    return res['receipt'] as Map<String, dynamic>;
  }

  /// Foto ulang struk draf (replace foto & re-run OCR)
  Future<ReceiptRecord> retakeReceipt(
    int receiptId,
    Uint8List bytes,
    String fileName,
  ) async {
    final res = await ApiService.retakeReceipt(receiptId, bytes, fileName);
    final rcpJson = res['receipt'] is Map ? res['receipt'] as Map<String, dynamic> : res;
    final updated = ReceiptRecord.fromJson(rcpJson);

    // Evict thumbnail cache
    ReceiptThumbnail.evict(receiptId);

    // Update list lokal
    final idx = _receipts.indexWhere((r) => r.id == receiptId);
    if (idx != -1) {
      _receipts[idx] = updated;
      notifyListeners();
    }
    return updated;
  }

  /// Hapus draft dari backend lalu update list lokal secara optimistik.
  Future<void> deleteDraft(int id) async {
    await ApiService.deleteReceipt(id);
    ReceiptThumbnail.evict(id);
    _receipts.removeWhere((r) => r.id == id);
    notifyListeners();
  }

  /// Update data klaim struk draf (kategori, catatan, nominal klaim, dll.)
  Future<ReceiptRecord> updateDraftClaim({
    required int id,
    required String category,
    String? notes,
    double? claimedAmount,
    double? totalAmount,
    String? receiptDate,
    String? vendorName,
  }) async {
    await ApiService.updateClaim(
      id,
      category: category,
      notes: notes,
      claimedAmount: claimedAmount,
      totalAmount: totalAmount,
      receiptDate: receiptDate,
      vendorName: vendorName,
    );
    final res = await ApiService.getReceipt(id, forceRefresh: true);
    final record =
        ReceiptRecord.fromJson(res['receipt'] as Map<String, dynamic>);
    final idx = _receipts.indexWhere((r) => r.id == id);
    if (idx != -1) {
      _receipts[idx] = record;
    }
    notifyListeners();
    return record;
  }

  // Setelah polling: updateClaim + submit + refresh list → return ReceiptRecord
  Future<ReceiptRecord> finalizeAndSubmit({
    required int id,
    required String category,
    String? notes,
    // Manual fields (hanya jika OCR gagal)
    double? claimedAmount,
    double? totalAmount,
    String? receiptDate,
    String? vendorName,
  }) async {
    await ApiService.updateClaim(
      id,
      category: category,
      notes: notes,
      claimedAmount: claimedAmount,
      totalAmount: totalAmount,
      receiptDate: receiptDate,
      vendorName: vendorName,
    );
    await ApiService.submitReceipt(id);
    final res = await ApiService.getReceipt(id, forceRefresh: true);
    final record =
        ReceiptRecord.fromJson(res['receipt'] as Map<String, dynamic>);
    // Refresh list di background
    fetchMyReceipts(forceRefresh: true);
    return record;
  }
}
