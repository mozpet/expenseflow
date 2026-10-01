class LeaveRequestRecord {
  final int id;
  final String leaveType; // wfh | izin | sakit | cuti
  final String? halfDaySession; // morning | afternoon
  final String startDate;
  final String endDate;
  final int totalDays;
  final String reason;
  final String status; // pending | approved | rejected
  final String? rejectionReason;
  final String currentStep; // spv | hrd
  final String? spvName;
  final String? spvApprovedAt;
  final String? spvNotes;
  final String? createdAt;

  LeaveRequestRecord({
    required this.id,
    required this.leaveType,
    this.halfDaySession,
    required this.startDate,
    required this.endDate,
    required this.totalDays,
    required this.reason,
    required this.status,
    this.rejectionReason,
    this.currentStep = 'spv',
    this.spvName,
    this.spvApprovedAt,
    this.spvNotes,
    this.createdAt,
  });
}

class LeaveBalanceRecord {
  final String leaveType;
  final String leaveTypeLabel;
  final int quota;
  final int used;
  final int? remainingQuota;
  final bool active;
  final bool isUnlimited;
  final bool isDisabled;

  LeaveBalanceRecord({
    required this.leaveType,
    String? leaveTypeLabel,
    required this.quota,
    required this.used,
    int? remaining,
    this.active = true,
    this.isUnlimited = false,
    this.isDisabled = false,
  })  : leaveTypeLabel = (leaveTypeLabel != null && leaveTypeLabel.isNotEmpty)
            ? leaveTypeLabel
            : leaveType,
        remainingQuota = remaining;

  int get remaining => isUnlimited ? 0 : (remainingQuota ?? (quota - used));
  int? get nullableRemaining => isUnlimited ? null : (remainingQuota ?? (quota - used));

  factory LeaveBalanceRecord.fromJson(Map<String, dynamic> json) {
    final quotaVal = (json['quota'] as num?)?.toInt() ?? 0;
    final usedVal = (json['used'] as num?)?.toInt() ?? 0;
    final remainingRaw = json['remaining'];
    final int? remainingVal =
        remainingRaw != null ? (remainingRaw as num).toInt() : null;
    final isUnlimited =
        json['is_unlimited'] == true || json['is_unlimited'] == 1;

    return LeaveBalanceRecord(
      leaveType: (json['leave_type'] ?? '').toString(),
      leaveTypeLabel: (json['leave_type_label'] ??
              json['leave_type'] ??
              '')
          .toString(),
      quota: quotaVal,
      used: usedVal,
      remaining: isUnlimited ? null : (remainingVal ?? (quotaVal - usedVal)),
      active: json['active'] == true || json['active'] == 1,
      isUnlimited: isUnlimited,
      isDisabled: json['is_disabled'] == true || json['is_disabled'] == 1,
    );
  }
}

class CollectiveLeaveRecord {
  final int id;
  final String date;
  final String name;
  final int totalDays;
  final String collectiveStatus; // pending | accepted | declined
  final int remainingQuota;
  final String policy; // block | debt | free
  final bool showBanner;

  CollectiveLeaveRecord({
    required this.id,
    required this.date,
    required this.name,
    required this.totalDays,
    required this.collectiveStatus,
    required this.remainingQuota,
    required this.policy,
    required this.showBanner,
  });

  factory CollectiveLeaveRecord.fromJson(Map<String, dynamic> json) {
    return CollectiveLeaveRecord(
      id: (json['id'] as num?)?.toInt() ?? 0,
      date: (json['date'] ?? '').toString(),
      name: (json['name'] ?? '').toString(),
      totalDays: (json['total_days'] as num?)?.toInt() ?? 0,
      collectiveStatus: (json['collective_status'] ?? 'pending').toString(),
      remainingQuota: (json['remaining_quota'] as num?)?.toInt() ?? 0,
      policy: (json['policy'] ?? 'block').toString(),
      showBanner: json['show_banner'] == true || json['show_banner'] == 1,
    );
  }
}

class LeaveCancellationRecord {
  final String id;
  final String type; // collective_leave_cancelled | personal_leave_cancelled
  final String title;
  final String name;
  final String date;
  final String dateLabel;
  final String message;
  final int refundedDays;
  final String createdAt;

  LeaveCancellationRecord({
    required this.id,
    required this.type,
    required this.title,
    required this.name,
    required this.date,
    required this.dateLabel,
    required this.message,
    required this.refundedDays,
    required this.createdAt,
  });

  factory LeaveCancellationRecord.fromJson(Map<String, dynamic> json) {
    return LeaveCancellationRecord(
      id: (json['id'] ?? '').toString(),
      type: (json['type'] ?? 'collective_leave_cancelled').toString(),
      title: (json['title'] ?? 'Cuti Bersama Dibatalkan').toString(),
      name: (json['name'] ?? '').toString(),
      date: (json['date'] ?? '').toString(),
      dateLabel: (json['date_label'] ?? json['date'] ?? '').toString(),
      message: (json['message'] ?? '').toString(),
      refundedDays: (json['refunded_days'] as num?)?.toInt() ?? 0,
      createdAt: (json['created_at'] ?? '').toString(),
    );
  }
}

class LeaveQuotaAdjustmentRecord {
  final int id;
  final String leaveType;
  final String leaveTypeLabel;
  final int year;
  final int oldQuota;
  final int newQuota;
  final int difference;
  final String? reason;
  final String adjustedByName;
  final String? createdAt;

  LeaveQuotaAdjustmentRecord({
    required this.id,
    required this.leaveType,
    required this.leaveTypeLabel,
    required this.year,
    required this.oldQuota,
    required this.newQuota,
    required this.difference,
    this.reason,
    required this.adjustedByName,
    this.createdAt,
  });

  factory LeaveQuotaAdjustmentRecord.fromJson(Map<String, dynamic> json) {
    return LeaveQuotaAdjustmentRecord(
      id: (json['id'] as num?)?.toInt() ?? 0,
      leaveType: (json['leave_type'] ?? '').toString(),
      leaveTypeLabel:
          (json['leave_type_label'] ?? json['leave_type'] ?? '').toString(),
      year: (json['year'] as num?)?.toInt() ?? DateTime.now().year,
      oldQuota: (json['old_quota'] as num?)?.toInt() ?? 0,
      newQuota: (json['new_quota'] as num?)?.toInt() ?? 0,
      difference: (json['difference'] as num?)?.toInt() ?? 0,
      reason: json['reason'] as String?,
      adjustedByName: (json['adjusted_by_name'] ?? 'HRD').toString(),
      createdAt: json['created_at']?.toString(),
    );
  }
}


