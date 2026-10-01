import 'package:flutter/material.dart';
import '../services/api_service.dart';

class SpvLeaveItem {
  final int id;
  final int userId;
  final String userName;
  final String? employeeCode;
  final String divisionName;
  final String positionName;
  final String leaveType; // 'cuti', 'izin', 'sakit', 'wfh'
  final String startDate;
  final String endDate;
  final int totalDays;
  final String reason;
  final String status; // 'pending', 'approved', 'rejected'
  final String currentStep; // 'spv', 'hrd'
  final String? spvName;
  final String? spvApprovedAt;
  final String? spvNotes;
  final String? rejectionReason;
  final String? notes;
  final String? documentPath;
  final String? halfDaySession;
  final String? createdAt;

  SpvLeaveItem({
    required this.id,
    required this.userId,
    required this.userName,
    this.employeeCode,
    required this.divisionName,
    required this.positionName,
    required this.leaveType,
    this.halfDaySession,
    required this.startDate,
    required this.endDate,
    required this.totalDays,
    required this.reason,
    required this.status,
    required this.currentStep,
    this.spvName,
    this.spvApprovedAt,
    this.spvNotes,
    this.rejectionReason,
    this.notes,
    this.documentPath,
    this.createdAt,
  });

  factory SpvLeaveItem.fromJson(Map<String, dynamic> json) {
    final user = json['user'] as Map<String, dynamic>?;
    final division = user?['division'] as Map<String, dynamic>?;
    final position = user?['position'] as Map<String, dynamic>?;
    final spv = json['spv'] as Map<String, dynamic>?;

    return SpvLeaveItem(
      id: json['id'] is num ? (json['id'] as num).toInt() : int.tryParse('${json['id']}') ?? 0,
      userId: json['user_id'] is num ? (json['user_id'] as num).toInt() : int.tryParse('${json['user_id']}') ?? 0,
      userName: json['user_name'] as String? ?? user?['name'] as String? ?? 'Karyawan',
      employeeCode: json['employee_code'] as String? ?? user?['employee_code'] as String?,
      divisionName: json['division_name'] as String? ?? division?['name'] as String? ?? '-',
      positionName: json['position_name'] as String? ?? position?['name'] as String? ?? 'Staf',
      leaveType: (json['leave_type'] ?? 'cuti').toString().toLowerCase(),
      startDate: (json['start_date'] ?? '').toString().split('T').first,
      endDate: (json['end_date'] ?? '').toString().split('T').first,
      totalDays: json['total_days'] is num ? (json['total_days'] as num).toInt() : int.tryParse('${json['total_days']}') ?? 1,
      reason: json['reason'] as String? ?? '-',
      status: json['status'] as String? ?? 'pending',
      currentStep: json['current_step'] as String? ?? 'spv',
      spvName: json['spv_name'] as String? ?? spv?['name'] as String?,
      spvApprovedAt: json['spv_approved_at'] as String?,
      spvNotes: json['spv_notes'] as String?,
      rejectionReason: json['rejection_reason'] as String?,
      notes: json['notes'] as String?,
      documentPath: json['document_path'] as String? ?? json['attachment_path'] as String?,
      halfDaySession: json['half_day_session'] as String?,
      createdAt: json['created_at'] as String?,
    );
  }
}

class SpvLeaveProvider extends ChangeNotifier {
  int _pendingCount = 0;
  List<SpvLeaveItem> _approvals = [];
  bool _isLoading = false;
  bool _isActionLoading = false;
  String? _errorMessage;
  String _selectedStatusFilter = 'pending'; // 'pending', 'approved', 'rejected', 'all'

  int get pendingCount => _pendingCount;
  List<SpvLeaveItem> get approvals => _approvals;
  bool get isLoading => _isLoading;
  bool get isActionLoading => _isActionLoading;
  String? get errorMessage => _errorMessage;
  String get selectedStatusFilter => _selectedStatusFilter;

  /// Ambil jumlah pending approval izin/cuti untuk badge counter di beranda
  Future<void> fetchPendingCount({bool forceRefresh = false}) async {
    final count = await ApiService.spvPendingLeaveCount(forceRefresh: forceRefresh);
    _pendingCount = count;
    notifyListeners();
  }

  /// Ambil daftar pengajuan izin/cuti bawahan
  Future<void> fetchApprovals({String? status, bool forceRefresh = false}) async {
    _isLoading = true;
    _errorMessage = null;
    if (status != null) _selectedStatusFilter = status;
    notifyListeners();

    try {
      final res = await ApiService.spvLeaveApprovals(
        status: _selectedStatusFilter == 'all' ? null : _selectedStatusFilter,
        forceRefresh: forceRefresh,
      );

      final rawList = res['approvals'] as List? ?? res['data'] as List? ?? [];
      _approvals = rawList
          .map((item) => SpvLeaveItem.fromJson(item as Map<String, dynamic>))
          .toList();

      if (res['pending_count'] is num) {
        _pendingCount = (res['pending_count'] as num).toInt();
      }
    } catch (e) {
      _errorMessage = e.toString();
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Setujui pengajuan izin/cuti bawahan (Tahap 1: SPV)
  Future<bool> approve(int id, {String? notes}) async {
    _isActionLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      await ApiService.spvApproveLeave(id, notes: notes);
      if (_pendingCount > 0) _pendingCount--;
      await fetchApprovals(forceRefresh: true);
      return true;
    } catch (e) {
      _errorMessage = e.toString();
      notifyListeners();
      return false;
    } finally {
      _isActionLoading = false;
      notifyListeners();
    }
  }

  /// Tolak pengajuan izin/cuti bawahan (Tahap 1: SPV)
  Future<bool> reject(int id, {required String reason}) async {
    _isActionLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      await ApiService.spvRejectLeave(id, reason: reason);
      if (_pendingCount > 0) _pendingCount--;
      await fetchApprovals(forceRefresh: true);
      return true;
    } catch (e) {
      _errorMessage = e.toString();
      notifyListeners();
      return false;
    } finally {
      _isActionLoading = false;
      notifyListeners();
    }
  }
}
