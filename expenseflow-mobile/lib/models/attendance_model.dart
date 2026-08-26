class PresensiRecord {
  final int id; // attendance ID — untuk mapping overtime approval
  final String date;
  final String masukTime;
  final String pulangTime;
  final String? checkInType; // 'wfh', 'onsite', 'field'
  final int overtimeMinutes;
  final bool isHoliday;
  final bool isAutoCheckout;
  final int lateMinutes;
  // null = belum ada lembur / belum diproses; 'pending'/'approved'/'rejected'
  final String? overtimeStatus;

  PresensiRecord({
    this.id = 0,
    required this.date,
    required this.masukTime,
    required this.pulangTime,
    this.checkInType,
    this.overtimeMinutes = 0,
    this.isHoliday = false,
    this.isAutoCheckout = false,
    this.lateMinutes = 0,
    this.overtimeStatus,
  });

  PresensiRecord copyWith({
    int? id,
    String? date,
    String? masukTime,
    String? pulangTime,
    String? checkInType,
    int? overtimeMinutes,
    bool? isHoliday,
    bool? isAutoCheckout,
    int? lateMinutes,
    String? overtimeStatus,
  }) {
    return PresensiRecord(
      id: id ?? this.id,
      date: date ?? this.date,
      masukTime: masukTime ?? this.masukTime,
      pulangTime: pulangTime ?? this.pulangTime,
      checkInType: checkInType ?? this.checkInType,
      overtimeMinutes: overtimeMinutes ?? this.overtimeMinutes,
      isHoliday: isHoliday ?? this.isHoliday,
      isAutoCheckout: isAutoCheckout ?? this.isAutoCheckout,
      lateMinutes: lateMinutes ?? this.lateMinutes,
      overtimeStatus: overtimeStatus ?? this.overtimeStatus,
    );
  }

  String get totalJamKerja => hitungDurasiKerja(masukTime, pulangTime);

  String get totalLembur {
    if (overtimeMinutes <= 0) return '';
    final j = overtimeMinutes ~/ 60;
    final m = overtimeMinutes % 60;
    if (j == 0) return '${m}m';
    if (m == 0) return '${j}j';
    return '${j}j ${m}m';
  }
}

/// Hitung durasi kerja dari "HH:mm" masuk ke "HH:mm" pulang.
/// Kembalikan format "Xj Ym" atau "-" jika data tidak lengkap.
String hitungDurasiKerja(String masuk, String pulang) {
  if (masuk == '-' || pulang == '-') return '-';
  final mp = masuk.split(':');
  final pp = pulang.split(':');
  if (mp.length < 2 || pp.length < 2) return '-';
  final masukMenit =
      (int.tryParse(mp[0]) ?? 0) * 60 + (int.tryParse(mp[1]) ?? 0);
  final pulangMenit =
      (int.tryParse(pp[0]) ?? 0) * 60 + (int.tryParse(pp[1]) ?? 0);
  var diff = pulangMenit - masukMenit;
  // Shift lintas tengah malam (mis. masuk 23:00, pulang 07:00): tambah 24 jam.
  if (diff < 0) diff += 24 * 60;
  if (diff == 0) return '-';
  final jam = diff ~/ 60;
  final menit = diff % 60;
  if (menit == 0) return '${jam}j';
  return '${jam}j ${menit}m';
}
