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
  final int quota; // Alokasi karyawan (bisa diubah HRD per karyawan)
  final int officeQuota; // Standar kantor (dari Pengaturan Jenis Cuti kantor)
  final int used;
  final int? remainingQuota;
  final bool active;
  final bool isUnlimited;
  final bool isDisabled;

  final int carriedOverQuota; // Hari bawaan dari periode lalu yang masih dipegang
  final String? carryOverExpiredAt; // 'YYYY-MM-DD' atau null

  LeaveBalanceRecord({
    required this.leaveType,
    String? leaveTypeLabel,
    required this.quota,
    int? officeQuota,
    required this.used,
    int? remaining,
    this.active = true,
    this.isUnlimited = false,
    this.isDisabled = false,
    this.carriedOverQuota = 0,
    this.carryOverExpiredAt,
  })  : leaveTypeLabel = (leaveTypeLabel != null && leaveTypeLabel.isNotEmpty)
            ? leaveTypeLabel
            : leaveType,
        officeQuota = officeQuota ?? quota,
        remainingQuota = remaining;

  int get remaining => isUnlimited ? 0 : (remainingQuota ?? (quota - used));
  int? get nullableRemaining => isUnlimited ? null : (remainingQuota ?? (quota - used));

  /// Kuota utama periode berjalan (di luar hari bawaan periode lalu)
  int get currentPeriodQuota {
    if (isUnlimited) return 0;
    final base = quota - carriedOverQuota;
    return base > 0 ? base : 0;
  }

  factory LeaveBalanceRecord.fromJson(Map<String, dynamic> json) {
    final quotaVal = (json['quota'] as num?)?.toInt() ?? 0;
    final usedVal = (json['used'] as num?)?.toInt() ?? 0;
    final remainingRaw = json['remaining'];
    final int? remainingVal =
        remainingRaw != null ? (remainingRaw as num).toInt() : null;
    final isUnlimited =
        json['is_unlimited'] == true || json['is_unlimited'] == 1;
    final carriedOver = (json['carried_over_quota'] as num?)?.toInt() ?? 0;
    final carryOverExpiredAt = json['carry_over_expired_at'] as String?;

    return LeaveBalanceRecord(
      leaveType: (json['leave_type'] ?? '').toString(),
      leaveTypeLabel: (json['leave_type_label'] ??
              json['leave_type'] ??
              '')
          .toString(),
      quota: quotaVal,
      officeQuota: (json['office_quota'] as num?)?.toInt(),
      used: usedVal,
      remaining: isUnlimited ? null : (remainingVal ?? (quotaVal - usedVal)),
      active: json['active'] == true || json['active'] == 1,
      isUnlimited: isUnlimited,
      isDisabled: json['is_disabled'] == true || json['is_disabled'] == 1,
      carriedOverQuota: carriedOver,
      carryOverExpiredAt: carryOverExpiredAt,
    );
  }
}

class CarryOverPolicy {
  final bool enabled;
  final int maxDays;
  final int? expiryMonths; // null = tidak pernah kedaluwarsa, 0 = takdir di tanggal reset, N = hidup N bulan

  const CarryOverPolicy({
    required this.enabled,
    required this.maxDays,
    this.expiryMonths,
  });

  factory CarryOverPolicy.fromJson(Map<String, dynamic> json) {
    final rawExpiry = json['expiry_months'];
    final int? expiryMonths = rawExpiry != null ? (rawExpiry as num).toInt() : null;

    return CarryOverPolicy(
      enabled: json['enabled'] == true || json['enabled'] == 1,
      maxDays: (json['max_days'] as num?)?.toInt() ?? 0,
      expiryMonths: expiryMonths,
    );
  }
}

class EncashmentPolicy {
  final bool enabled;
  final double ratePerDay;
  final int? maxDays; // null = tanpa pagu

  const EncashmentPolicy({
    required this.enabled,
    required this.ratePerDay,
    this.maxDays,
  });

  factory EncashmentPolicy.fromJson(Map<String, dynamic> json) {
    final rawMax = json['max_days'];
    final int? maxDays = rawMax != null ? (rawMax as num).toInt() : null;

    return EncashmentPolicy(
      enabled: json['enabled'] == true || json['enabled'] == 1,
      ratePerDay: (json['rate_per_day'] as num?)?.toDouble() ?? 0.0,
      maxDays: maxDays,
    );
  }
}

class LeaveResetInfo {
  final String? leaveResetDate;
  final int defaultLeaveQuota;
  final CarryOverPolicy carryOver;
  final EncashmentPolicy encashment;
  final String remainingLeavePolicyLabel;

  const LeaveResetInfo({
    this.leaveResetDate,
    required this.defaultLeaveQuota,
    required this.carryOver,
    required this.encashment,
    required this.remainingLeavePolicyLabel,
  });

  factory LeaveResetInfo.fromJson(Map<String, dynamic> json) {
    final carryOverRaw = json['carry_over'];
    final encashmentRaw = json['encashment'];

    final carryOverMap = carryOverRaw is Map<String, dynamic>
        ? carryOverRaw
        : (carryOverRaw is Map ? Map<String, dynamic>.from(carryOverRaw) : null);
    final encashmentMap = encashmentRaw is Map<String, dynamic>
        ? encashmentRaw
        : (encashmentRaw is Map ? Map<String, dynamic>.from(encashmentRaw) : null);

    return LeaveResetInfo(
      leaveResetDate: json['leave_reset_date']?.toString(),
      defaultLeaveQuota: (json['default_leave_quota'] as num?)?.toInt() ?? 12,
      carryOver: carryOverMap != null
          ? CarryOverPolicy.fromJson(carryOverMap)
          : const CarryOverPolicy(enabled: false, maxDays: 0, expiryMonths: null),
      encashment: encashmentMap != null
          ? EncashmentPolicy.fromJson(encashmentMap)
          : const EncashmentPolicy(enabled: false, ratePerDay: 0.0, maxDays: null),
      remainingLeavePolicyLabel: (json['remaining_leave_policy_label'] ?? '').toString(),
    );
  }

  /// Mempertahankan kompatibilitas jika diakses dengan bracket notation
  dynamic operator [](String key) {
    switch (key) {
      case 'leave_reset_date':
        return leaveResetDate;
      case 'default_leave_quota':
        return defaultLeaveQuota;
      case 'remaining_leave_policy_label':
        return remainingLeavePolicyLabel;
      case 'carry_over':
        return {
          'enabled': carryOver.enabled,
          'max_days': carryOver.maxDays,
          'expiry_months': carryOver.expiryMonths,
        };
      case 'encashment':
        return {
          'enabled': encashment.enabled,
          'rate_per_day': encashment.ratePerDay,
          'max_days': encashment.maxDays,
        };
      default:
        return null;
    }
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


