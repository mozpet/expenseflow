/// Model data slip gaji (payroll) untuk sisi karyawan.
///
/// CATATAN PENTING: kolom uang di backend memakai cast `decimal:2` yang
/// diserialisasi ke JSON sebagai STRING (mis. "10000000.00"). Karena itu
/// semua nilai uang di-parse defensif lewat [_toDouble] agar aman terhadap
/// String maupun num (int/double) maupun null.
library;

/// Parse nilai apa pun (String/num/null) menjadi double dengan aman.
double _toDouble(dynamic value) {
  if (value == null) return 0;
  if (value is num) return value.toDouble();
  return double.tryParse(value.toString()) ?? 0;
}

/// Parse nilai apa pun menjadi int dengan aman.
int _toInt(dynamic value) {
  if (value == null) return 0;
  if (value is num) return value.toInt();
  return int.tryParse(value.toString()) ?? 0;
}

/// Nama bulan Indonesia (indeks 1..12).
const List<String> _namaBulan = [
  '',
  'Januari',
  'Februari',
  'Maret',
  'April',
  'Mei',
  'Juni',
  'Juli',
  'Agustus',
  'September',
  'Oktober',
  'November',
  'Desember',
];

/// Ubah bulan+tahun menjadi label periode ("Juni 2026").
String labelPeriode(int month, int year) {
  if (month < 1 || month > 12) return '$year';
  return '${_namaBulan[month]} $year';
}

/// Ringkasan slip gaji untuk tampilan daftar.
class Payslip {
  final int id;
  final int payrollId;
  final int periodMonth;
  final int periodYear;
  final double gross;
  final double totalDeduction;
  final double pph21;
  final double net;
  final String status;

  Payslip({
    required this.id,
    required this.payrollId,
    required this.periodMonth,
    required this.periodYear,
    required this.gross,
    required this.totalDeduction,
    required this.pph21,
    required this.net,
    required this.status,
  });

  /// Label periode siap tampil ("Juni 2026").
  String get periodLabel => labelPeriode(periodMonth, periodYear);

  factory Payslip.fromJson(Map<String, dynamic> json) {
    return Payslip(
      id: _toInt(json['id']),
      payrollId: _toInt(json['payroll_id']),
      periodMonth: _toInt(json['period_month']),
      periodYear: _toInt(json['period_year']),
      gross: _toDouble(json['gross']),
      totalDeduction: _toDouble(json['total_deduction']),
      pph21: _toDouble(json['pph21']),
      net: _toDouble(json['net']),
      status: (json['status'] ?? '').toString(),
    );
  }
}

/// Satu baris rincian slip (pendapatan atau potongan).
class PayslipItem {
  final String label;
  final String type; // earning | deduction
  final double amount;
  final bool isTaxable;
  final bool isStatutory;
  final String? notes;

  PayslipItem({
    required this.label,
    required this.type,
    required this.amount,
    required this.isTaxable,
    required this.isStatutory,
    this.notes,
  });

  bool get isEarning => type == 'earning';
  bool get isDeduction => type == 'deduction';

  factory PayslipItem.fromJson(Map<String, dynamic> json) {
    return PayslipItem(
      label: (json['label'] ?? '-').toString(),
      type: (json['type'] ?? '').toString(),
      amount: _toDouble(json['amount']),
      isTaxable: json['is_taxable'] == true || json['is_taxable'] == 1,
      isStatutory: json['is_statutory'] == true || json['is_statutory'] == 1,
      notes: json['notes']?.toString(),
    );
  }
}

/// Rincian lengkap satu slip gaji: header slip, periode, daftar pendapatan &
/// potongan. Dibangun dari respons `GET /employee/payslips/{id}`.
class PayslipDetail {
  final int id;
  final int payrollId;
  final String period; // label periode dari backend (period_label)

  // Snapshot identitas karyawan (immutable pasca-approve).
  final String employeeName;
  final String? employeeCode;
  final String? positionName;
  final String? departmentName;
  final String? npwpMasked;
  final String? ptkpStatus;

  // Rekening bank pembayaran.
  final String? bankName;
  final String? bankAccountNo;
  final String? bankAccountHolder;

  // Nilai finansial.
  final double basicSalary;
  final double totalEarning;
  final double gross;
  final double taxableIncome;
  final double pph21;
  final double totalDeduction;
  final double net;

  // Ringkasan kehadiran.
  final int workingDays;
  final int presentDays;
  final int absentDays;
  final double overtimeHours;

  final String status;
  final String? notes;

  final List<PayslipItem> earnings;
  final List<PayslipItem> deductions;

  PayslipDetail({
    required this.id,
    required this.payrollId,
    required this.period,
    required this.employeeName,
    this.employeeCode,
    this.positionName,
    this.departmentName,
    this.npwpMasked,
    this.ptkpStatus,
    this.bankName,
    this.bankAccountNo,
    this.bankAccountHolder,
    required this.basicSalary,
    required this.totalEarning,
    required this.gross,
    required this.taxableIncome,
    required this.pph21,
    required this.totalDeduction,
    required this.net,
    required this.workingDays,
    required this.presentDays,
    required this.absentDays,
    required this.overtimeHours,
    required this.status,
    this.notes,
    required this.earnings,
    required this.deductions,
  });

  factory PayslipDetail.fromJson(Map<String, dynamic> json) {
    final slip = (json['payslip'] as Map?)?.cast<String, dynamic>() ?? {};

    List<PayslipItem> parseItems(dynamic raw) {
      if (raw is! List) return [];
      return raw
          .whereType<Map>()
          .map((e) => PayslipItem.fromJson(e.cast<String, dynamic>()))
          .toList();
    }

    return PayslipDetail(
      id: _toInt(slip['id']),
      payrollId: _toInt(slip['payroll_id']),
      period: (json['period'] ??
              labelPeriode(
                _toInt(slip['period_month']),
                _toInt(slip['period_year']),
              ))
          .toString(),
      employeeName: (slip['employee_name'] ?? '-').toString(),
      employeeCode: slip['employee_code']?.toString(),
      positionName: slip['position_name']?.toString(),
      departmentName: slip['department_name']?.toString(),
      npwpMasked: slip['npwp_masked']?.toString(),
      ptkpStatus: slip['ptkp_status']?.toString(),
      bankName: slip['bank_name']?.toString(),
      bankAccountNo: slip['bank_account_no']?.toString(),
      bankAccountHolder: slip['bank_account_holder']?.toString(),
      basicSalary: _toDouble(slip['basic_salary']),
      totalEarning: _toDouble(slip['total_earning']),
      gross: _toDouble(slip['gross']),
      taxableIncome: _toDouble(slip['taxable_income']),
      pph21: _toDouble(slip['pph21']),
      totalDeduction: _toDouble(slip['total_deduction']),
      net: _toDouble(slip['net']),
      workingDays: _toInt(slip['working_days']),
      presentDays: _toInt(slip['present_days']),
      absentDays: _toInt(slip['absent_days']),
      overtimeHours: _toDouble(slip['overtime_hours']),
      status: (slip['status'] ?? '').toString(),
      notes: slip['notes']?.toString(),
      earnings: parseItems(json['earnings']),
      deductions: parseItems(json['deductions']),
    );
  }
}
