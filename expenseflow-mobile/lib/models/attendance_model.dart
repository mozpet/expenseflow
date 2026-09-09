class PresensiRecord {
  final int id; // attendance ID — untuk mapping overtime approval
  final String date;
  final String? rawDate; // YYYY-MM-DD dari backend atau DateTime lokal
  final String masukTime;
  final String pulangTime;
  final String? checkInType; // 'wfh', 'onsite', 'field'
  final int overtimeMinutes;
  final bool isHoliday;
  final bool isAutoCheckout;
  final int lateMinutes;
  final bool isOfflineSync;
  // null = belum ada lembur / belum diproses; 'pending'/'approved'/'rejected'
  final String? overtimeStatus;
  final String? overtimeReason;
  // Status presensi dari backend: 'present', 'late', 'early_leave', 'absent', 'wfh', dll.
  final String? status;

  PresensiRecord({
    this.id = 0,
    required this.date,
    this.rawDate,
    required this.masukTime,
    required this.pulangTime,
    this.checkInType,
    this.overtimeMinutes = 0,
    this.isHoliday = false,
    this.isAutoCheckout = false,
    this.lateMinutes = 0,
    this.isOfflineSync = false,
    this.overtimeStatus,
    this.overtimeReason,
    this.status,
  });

  bool get isEarlyLeave => status == 'early_leave';

  PresensiRecord copyWith({
    int? id,
    String? date,
    String? rawDate,
    String? masukTime,
    String? pulangTime,
    String? checkInType,
    int? overtimeMinutes,
    bool? isHoliday,
    bool? isAutoCheckout,
    int? lateMinutes,
    bool? isOfflineSync,
    String? overtimeStatus,
    String? overtimeReason,
    String? status,
  }) {
    return PresensiRecord(
      id: id ?? this.id,
      date: date ?? this.date,
      rawDate: rawDate ?? this.rawDate,
      masukTime: masukTime ?? this.masukTime,
      pulangTime: pulangTime ?? this.pulangTime,
      checkInType: checkInType ?? this.checkInType,
      overtimeMinutes: overtimeMinutes ?? this.overtimeMinutes,
      isHoliday: isHoliday ?? this.isHoliday,
      isAutoCheckout: isAutoCheckout ?? this.isAutoCheckout,
      lateMinutes: lateMinutes ?? this.lateMinutes,
      isOfflineSync: isOfflineSync ?? this.isOfflineSync,
      overtimeStatus: overtimeStatus ?? this.overtimeStatus,
      overtimeReason: overtimeReason ?? this.overtimeReason,
      status: status ?? this.status,
    );
  }

  /// Parse tanggal record menjadi DateTime (hanya year, month, day).
  DateTime? get parsedDate {
    if (rawDate != null && rawDate!.isNotEmpty) {
      final clean = rawDate!.length >= 10 ? rawDate!.substring(0, 10) : rawDate!;
      final dt = DateTime.tryParse(clean);
      if (dt != null) return DateTime(dt.year, dt.month, dt.day);
    }
    // Fallback: coba parsing langsung dari date jika format ISO
    final dtIso = DateTime.tryParse(date);
    if (dtIso != null) return DateTime(dtIso.year, dtIso.month, dtIso.day);

    // Fallback: parsing format teks Indonesia "6 September 2026"
    final parts = date.trim().split(RegExp(r'\s+'));
    if (parts.length >= 3) {
      final day = int.tryParse(parts[0]);
      final year = int.tryParse(parts[2]);
      const months = {
        'januari': 1, 'februari': 2, 'maret': 3, 'april': 4,
        'mei': 5, 'juni': 6, 'juli': 7, 'agustus': 8,
        'september': 9, 'oktober': 10, 'november': 11, 'desember': 12,
      };
      final month = months[parts[1].toLowerCase()];
      if (day != null && month != null && year != null) {
        return DateTime(year, month, day);
      }
    }
    return null;
  }

  bool get canClaimOvertime =>
      overtimeMinutes > 0 &&
      (overtimeStatus == null || overtimeStatus == 'unsubmitted');

  bool get hasSubmittedOvertime =>
      overtimeMinutes > 0 &&
      overtimeStatus != null &&
      overtimeStatus != 'unsubmitted';

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

/// Area kantor dan radius presensi yang ditentukan perusahaan.
class OfficeArea {
  final int id;
  final String name;
  final double latitude;
  final double longitude;
  final double radiusMeters;
  final bool requireSelfie;

  OfficeArea({
    required this.id,
    required this.name,
    required this.latitude,
    required this.longitude,
    required this.radiusMeters,
    this.requireSelfie = false,
  });

  factory OfficeArea.fromJson(Map<String, dynamic> json) {
    return OfficeArea(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: (json['name'] ?? json['office_name'] ?? 'Kantor').toString(),
      latitude:
          (json['latitude'] ?? json['office_latitude'] as num?)?.toDouble() ??
              0.0,
      longitude:
          (json['longitude'] ?? json['office_longitude'] as num?)?.toDouble() ??
              0.0,
      radiusMeters: (json['radius_meters'] as num?)?.toDouble() ?? 100.0,
      requireSelfie: json['require_selfie'] == true,
    );
  }
}
