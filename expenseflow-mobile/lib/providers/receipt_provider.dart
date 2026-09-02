import 'dart:convert';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils.dart';

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
  final String createdAt;

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
    required this.createdAt,
  });

  factory ReceiptRecord.fromJson(Map<String, dynamic> m) {
    double? parseAmount(dynamic v) {
      if (v == null) return null;
      if (v is num) return v.toDouble();
      return double.tryParse(v.toString());
    }

    // rejection_reason: bisa dari field langsung (myReceipts) atau approvals (show)
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
      createdAt: (m['created_at'] ?? '').toString(),
    );
  }

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

  Future<void> fetchMyReceipts() async {
    _loading = true;
    notifyListeners();
    try {
      final res = await ApiService.myReceipts();
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
    String fileName,
  ) async {
    final res = await ApiService.uploadReceipt(imageBytes, fileName);
    final r = res['receipt'] as Map<String, dynamic>;
    return (
      id: (r['id'] as num).toInt(),
      receiptNumber: (r['receipt_number'] ?? '').toString(),
    );
  }

  // Poll GET /employee/receipts/{id} sampai ocr_status selesai (max 60s)
  Future<Map<String, dynamic>> pollOcrStatus(int id) async {
    for (int i = 0; i < 30; i++) {
      await Future.delayed(const Duration(seconds: 2));
      try {
        final res = await ApiService.getReceipt(id);
        final r = res['receipt'] as Map<String, dynamic>;
        final s = (r['ocr_status'] ?? 'pending').toString();
        if (s != 'pending' && s != 'processing') return r;
      } catch (_) {}
    }
    // Fallback: kembalikan state terakhir
    final res = await ApiService.getReceipt(id);
    return res['receipt'] as Map<String, dynamic>;
  }

  /// Hapus draft dari backend lalu update list lokal secara optimistik.
  Future<void> deleteDraft(int id) async {
    await ApiService.deleteReceipt(id);
    _receipts.removeWhere((r) => r.id == id);
    notifyListeners();
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
    final res = await ApiService.getReceipt(id);
    final record =
        ReceiptRecord.fromJson(res['receipt'] as Map<String, dynamic>);
    // Refresh list di background
    fetchMyReceipts();
    return record;
  }
}
