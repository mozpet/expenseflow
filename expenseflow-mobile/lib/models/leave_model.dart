class LeaveRequestRecord {
  final int id;
  final String leaveType; // wfh | izin | sakit | cuti
  final String startDate;
  final String endDate;
  final int totalDays;
  final String reason;
  final String status; // pending | approved | rejected
  final String? rejectionReason;

  LeaveRequestRecord({
    required this.id,
    required this.leaveType,
    required this.startDate,
    required this.endDate,
    required this.totalDays,
    required this.reason,
    required this.status,
    this.rejectionReason,
  });
}

class LeaveBalanceRecord {
  final String leaveType;
  final int quota;
  final int used;
  int get remaining => quota - used;

  LeaveBalanceRecord({
    required this.leaveType,
    required this.quota,
    required this.used,
  });
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
