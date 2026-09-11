import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils.dart';
import 'receipt_provider.dart';

class ExpenseReportRecord {
  final int id;
  final String reportNumber;
  final String title;
  final String? description;
  final String? startDate;
  final String? endDate;
  final String status;
  final double totalClaimedAmount;
  final double? totalApprovedAmount;
  final int totalReceipts;
  final List<ReceiptRecord> receipts;
  final String? rejectionReason;
  final String createdAt;

  const ExpenseReportRecord({
    required this.id,
    required this.reportNumber,
    required this.title,
    this.description,
    this.startDate,
    this.endDate,
    required this.status,
    required this.totalClaimedAmount,
    this.totalApprovedAmount,
    required this.totalReceipts,
    this.receipts = const [],
    this.rejectionReason,
    required this.createdAt,
  });

  factory ExpenseReportRecord.fromJson(Map<String, dynamic> m) {
    double parseAmount(dynamic v) {
      if (v == null) return 0.0;
      if (v is num) return v.toDouble();
      return double.tryParse(v.toString()) ?? 0.0;
    }

    double? parseNullableAmount(dynamic v) {
      if (v == null) return null;
      if (v is num) return v.toDouble();
      return double.tryParse(v.toString());
    }

    final List<ReceiptRecord> parsedReceipts = [];
    if (m['receipts'] is List) {
      for (final it in m['receipts']) {
        if (it is Map<String, dynamic>) {
          parsedReceipts.add(ReceiptRecord.fromJson(it));
        } else if (it is Map) {
          parsedReceipts.add(ReceiptRecord.fromJson(Map<String, dynamic>.from(it)));
        }
      }
    }

    final claimed = parseAmount(m['total_claimed_amount'] ?? m['totalClaimedAmount']);
    final approved = parseNullableAmount(m['total_approved_amount'] ?? m['totalApprovedAmount']);
    final count = (m['receipts_count'] as num?)?.toInt() ??
        (m['totalReceipts'] as num?)?.toInt() ??
        parsedReceipts.length;

    return ExpenseReportRecord(
      id: (m['id'] as num).toInt(),
      reportNumber: (m['report_number'] ?? m['reportNumber'] ?? '').toString(),
      title: (m['title'] ?? 'Laporan Pengeluaran').toString(),
      description: m['description']?.toString() ?? m['purpose']?.toString(),
      startDate: m['start_date']?.toString() ?? m['startDate']?.toString(),
      endDate: m['end_date']?.toString() ?? m['endDate']?.toString(),
      status: (m['status'] ?? 'draft').toString(),
      totalClaimedAmount: claimed,
      totalApprovedAmount: approved,
      totalReceipts: count,
      receipts: parsedReceipts,
      rejectionReason: m['rejection_reason']?.toString(),
      createdAt: (m['created_at'] ?? '').toString(),
    );
  }

  String get displayStatus {
    switch (status) {
      case 'paid':
        return 'Dibayar';
      case 'approved':
        return 'Disetujui';
      case 'rejected':
        return 'Ditolak';
      case 'submitted':
        return 'Menunggu';
      default:
        return 'Draf';
    }
  }

  bool get isDraft => status == 'draft';
  bool get isSubmitted => status == 'submitted';
  bool get isApproved => status == 'approved';
  bool get isRejected => status == 'rejected';
  bool get isPaid => status == 'paid';

  String get displayPeriod {
    if (startDate != null && startDate!.isNotEmpty && endDate != null && endDate!.isNotEmpty) {
      return '${formatDateIndonesian(startDate)} s/d ${formatDateIndonesian(endDate)}';
    }
    if (startDate != null && startDate!.isNotEmpty) {
      return 'Mulai ${formatDateIndonesian(startDate)}';
    }
    return formatDateIndonesian(createdAt);
  }
}

class ExpenseReportProvider extends ChangeNotifier {
  final List<ExpenseReportRecord> _reports = [];
  bool _loading = false;
  String? _error;

  List<ExpenseReportRecord> get reports => List.unmodifiable(_reports);
  bool get loading => _loading;
  String? get error => _error;

  int get draftCount => _reports.where((r) => r.isDraft).length;
  int get submittedCount => _reports.where((r) => r.isSubmitted).length;

  Future<void> fetchReports({bool forceRefresh = false}) async {
    _loading = true;
    _error = null;
    notifyListeners();

    try {
      final rawList = await ApiService.getExpenseReports(forceRefresh: forceRefresh);
      _reports.clear();
      for (final item in rawList) {
        if (item is Map<String, dynamic>) {
          _reports.add(ExpenseReportRecord.fromJson(item));
        } else if (item is Map) {
          _reports.add(ExpenseReportRecord.fromJson(Map<String, dynamic>.from(item)));
        }
      }
      _reports.sort((a, b) => b.id.compareTo(a.id));
    } catch (e) {
      _error = e.toString();
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  Future<ExpenseReportRecord?> fetchReportDetail(int id, {bool forceRefresh = false}) async {
    try {
      final res = await ApiService.getExpenseReportDetail(id, forceRefresh: forceRefresh);
      final raw = res['report'] ?? res['data'] ?? res;
      if (raw is Map<String, dynamic>) {
        final detail = ExpenseReportRecord.fromJson(raw);
        final idx = _reports.indexWhere((r) => r.id == id);
        if (idx >= 0) {
          _reports[idx] = detail;
          notifyListeners();
        }
        return detail;
      }
    } catch (e) {
      _error = e.toString();
    }
    return null;
  }

  Future<ExpenseReportRecord> createReport({
    required String title,
    String? purpose,
    String? startDate,
    String? endDate,
    List<int>? receiptIds,
  }) async {
    final res = await ApiService.createExpenseReport(
      title: title,
      purpose: purpose,
      startDate: startDate,
      endDate: endDate,
      receiptIds: receiptIds,
    );
    final raw = res['report'] ?? res['data'] ?? res;
    final created = ExpenseReportRecord.fromJson(Map<String, dynamic>.from(raw));
    _reports.insert(0, created);
    notifyListeners();
    return created;
  }

  Future<void> submitReport(int reportId) async {
    await ApiService.submitExpenseReport(reportId);
    await fetchReports(forceRefresh: true);
  }

  Future<void> deleteReport(int reportId) async {
    await ApiService.deleteExpenseReport(reportId);
    _reports.removeWhere((r) => r.id == reportId);
    notifyListeners();
  }

  Future<void> addReceipts(int reportId, List<int> receiptIds) async {
    await ApiService.addReceiptsToReport(reportId, receiptIds);
    await fetchReportDetail(reportId, forceRefresh: true);
    await fetchReports(forceRefresh: true);
  }

  Future<void> removeReceipt(int reportId, int receiptId) async {
    await ApiService.removeReceiptFromReport(reportId, receiptId);
    await fetchReportDetail(reportId, forceRefresh: true);
    await fetchReports(forceRefresh: true);
  }
}
