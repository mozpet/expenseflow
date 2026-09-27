import 'package:flutter/material.dart';
import '../services/api_service.dart';

class SpvOvertimeItem {
  final int id;
  final int attendanceId;
  final int userId;
  final String userName;
  final String? employeeCode;
  final String department;
  final String divisionName;
  final String positionName;
  final String date;
  final String? checkInTime;
  final String? checkOutTime;
  final int overtimeMinutes;
  final String formattedOvertime;
  final String reason;
  final String status; // 'pending', 'approved', 'rejected'
  final String currentStep; // 'spv', 'hrd'
  final String? spvApprovedAt;
  final String? spvNotes;
  final String? notes;
  final String? createdAt;

  SpvOvertimeItem({
    required this.id,
    required this.attendanceId,
    required this.userId,
    required this.userName,
    this.employeeCode,
    required this.department,
    required this.divisionName,
    required this.positionName,
    required this.date,
    this.checkInTime,
    this.checkOutTime,
    required this.overtimeMinutes,
    required this.formattedOvertime,
    required this.reason,
    required this.status,
    required this.currentStep,
    this.spvApprovedAt,
    this.spvNotes,
    this.notes,
    this.createdAt,
  });

  factory SpvOvertimeItem.fromJson(Map<String, dynamic> json) {
    return SpvOvertimeItem(
      id: json['id'] is num ? (json['id'] as num).toInt() : int.tryParse('${json['id']}') ?? 0,
      attendanceId: json['attendance_id'] is num ? (json['attendance_id'] as num).toInt() : int.tryParse('${json['attendance_id']}') ?? 0,
      userId: json['user_id'] is num ? (json['user_id'] as num).toInt() : int.tryParse('${json['user_id']}') ?? 0,
      userName: json['user_name'] as String? ?? 'Karyawan',
      employeeCode: json['employee_code'] as String?,
      department: json['department'] as String? ?? '-',
      divisionName: json['division_name'] as String? ?? json['department'] as String? ?? '-',
      positionName: json['position_name'] as String? ?? 'Staf',
      date: json['date'] as String? ?? '',
      checkInTime: json['check_in_time'] as String?,
      checkOutTime: json['check_out_time'] as String?,
      overtimeMinutes: json['overtime_minutes'] is num ? (json['overtime_minutes'] as num).toInt() : int.tryParse('${json['overtime_minutes']}') ?? 0,
      formattedOvertime: json['formatted_overtime'] as String? ?? '${json['overtime_minutes'] ?? 0} Menit',
      reason: json['reason'] as String? ?? '-',
      status: json['status'] as String? ?? 'pending',
      currentStep: json['current_step'] as String? ?? 'spv',
      spvApprovedAt: json['spv_approved_at'] as String?,
      spvNotes: json['spv_notes'] as String?,
      notes: json['notes'] as String?,
      createdAt: json['created_at'] as String?,
    );
  }
}

class SpvOvertimeProvider extends ChangeNotifier {
  int _pendingCount = 0;
  List<SpvOvertimeItem> _approvals = [];
  bool _isLoading = false;
  bool _isActionLoading = false;
  String? _errorMessage;
  String _selectedStatusFilter = 'pending'; // 'pending', 'approved', 'rejected', 'all'

  int get pendingCount => _pendingCount;
  List<SpvOvertimeItem> get approvals => _approvals;
  bool get isLoading => _isLoading;
  bool get isActionLoading => _isActionLoading;
  String? get errorMessage => _errorMessage;
  String get selectedStatusFilter => _selectedStatusFilter;

  /// Ambil jumlah pending approval untuk badge counter di beranda
  Future<void> fetchPendingCount({bool forceRefresh = false}) async {
    final count = await ApiService.spvPendingOvertimeCount(forceRefresh: forceRefresh);
    _pendingCount = count;
    notifyListeners();
  }

  /// Ambil daftar pengajuan lembur bawahan
  Future<void> fetchApprovals({String? status, bool forceRefresh = false}) async {
    _isLoading = true;
    _errorMessage = null;
    if (status != null) _selectedStatusFilter = status;
    notifyListeners();

    try {
      final res = await ApiService.spvOvertimeApprovals(
        status: _selectedStatusFilter == 'all' ? null : _selectedStatusFilter,
        forceRefresh: forceRefresh,
      );

      final rawList = res['approvals'] as List? ?? res['data'] as List? ?? [];
      _approvals = rawList
          .map((item) => SpvOvertimeItem.fromJson(item as Map<String, dynamic>))
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

  /// Setujui pengajuan lembur bawahan (Tahap 1: SPV)
  Future<bool> approve(int id, {String? notes}) async {
    _isActionLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      await ApiService.spvApproveOvertime(id, notes: notes);
      // Kurangi pending count lokal
      if (_pendingCount > 0) _pendingCount--;
      // Refresh daftar
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

  /// Tolak pengajuan lembur bawahan (Tahap 1: SPV)
  Future<bool> reject(int id, {required String reason}) async {
    _isActionLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      await ApiService.spvRejectOvertime(id, reason: reason);
      // Kurangi pending count lokal
      if (_pendingCount > 0) _pendingCount--;
      // Refresh daftar
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
