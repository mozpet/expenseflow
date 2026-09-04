import 'dart:async';

/// Entri cache tunggal di dalam memori RAM Dart.
class CacheEntry {
  final Map<String, dynamic> data;
  final DateTime createdAt;
  final Duration ttl;

  CacheEntry({
    required this.data,
    required this.createdAt,
    required this.ttl,
  });

  /// True jika masa aktif cache (TTL) telah lewat.
  bool get isExpired => DateTime.now().difference(createdAt) > ttl;
}

/// Aturan caching per pola URL endpoint.
class EndpointCacheRule {
  final RegExp pattern;
  final Duration ttl;

  const EndpointCacheRule({
    required this.pattern,
    required this.ttl,
  });
}

/// Aturan invalidasi cache otomatis saat terjadi mutasi (POST, PATCH, DELETE).
class MutationInvalidationRule {
  final RegExp pattern;
  final List<String> invalidates;

  const MutationInvalidationRule({
    required this.pattern,
    required this.invalidates,
  });
}

/// Layanan terpusat Smart In-Memory Caching untuk Flutter mobile.
/// Menyimpan data respons GET di RAM (0ms latency), mencegah request ganda,
/// dan otomatis membersihkan data yang terpengaruh saat mutasi.
class ApiCacheService {
  // ─── Penyimpanan RAM ────────────────────────────────────────────────────────
  static final Map<String, CacheEntry> _cacheStore = {};
  static final Map<String, Future<Map<String, dynamic>>> _inFlightRequests = {};

  // ─── Tabel Aturan TTL Endpoint (Mobile) ─────────────────────────────────────
  static final List<EndpointCacheRule> _rules = [
    // 1. Profil Pengguna Login (5 menit)
    EndpointCacheRule(
      pattern: RegExp(r'^/me$'),
      ttl: const Duration(minutes: 5),
    ),
    // 2. Status Presensi Hari Ini & Auto-checkout (15 detik)
    EndpointCacheRule(
      pattern: RegExp(r'^/attendance/status$'),
      ttl: const Duration(seconds: 15),
    ),
    // 3. Riwayat Presensi Saya (30 detik)
    EndpointCacheRule(
      pattern: RegExp(r'^/attendance/my$'),
      ttl: const Duration(seconds: 30),
    ),
    // 4. Saldo Cuti Saya (60 detik)
    EndpointCacheRule(
      pattern: RegExp(r'^/attendance/leave-balance$'),
      ttl: const Duration(seconds: 60),
    ),
    // 5. Riwayat Pengajuan Izin/Cuti Saya (30 detik)
    EndpointCacheRule(
      pattern: RegExp(r'^/attendance/my-leaves$'),
      ttl: const Duration(seconds: 30),
    ),
    // 6. Cuti Bersama Mendatang (60 detik)
    EndpointCacheRule(
      pattern: RegExp(r'^/attendance/collective-leaves$'),
      ttl: const Duration(seconds: 60),
    ),
    // 7. Riwayat Status Lembur Saya (30 detik)
    EndpointCacheRule(
      pattern: RegExp(r'^/attendance/my-overtime$'),
      ttl: const Duration(seconds: 30),
    ),
    // 8. Template Jadwal Shift Karyawan (3 menit)
    EndpointCacheRule(
      pattern: RegExp(r'^/employee/my-schedule$'),
      ttl: const Duration(minutes: 3),
    ),
    // 9. Kalender Jadwal Shift Bulanan per Tanggal (3 menit)
    EndpointCacheRule(
      pattern: RegExp(r'^/attendance/my-schedule-calendar$'),
      ttl: const Duration(minutes: 3),
    ),
    // 10. Notifikasi Update Jadwal Shift (30 detik)
    EndpointCacheRule(
      pattern: RegExp(r'^/attendance/shift-updates$'),
      ttl: const Duration(seconds: 30),
    ),
    // 11. Daftar Struk Reimbursement Saya (20 detik)
    EndpointCacheRule(
      pattern: RegExp(r'^/employee/receipts$'),
      ttl: const Duration(seconds: 20),
    ),
    // 12. Detail Struk (30 detik)
    EndpointCacheRule(
      pattern: RegExp(r'^/employee/receipts/\d+$'),
      ttl: const Duration(seconds: 30),
    ),
  ];

  // ─── Auto-Invalidation Registry ─────────────────────────────────────────────
  static final List<MutationInvalidationRule> _mutationRules = [
    // Presensi Check-In / Check-Out / Sync Offline
    MutationInvalidationRule(
      pattern: RegExp(r'^/attendance/(check-in|check-out|sync-offline)'),
      invalidates: [
        '/attendance/status',
        '/attendance/my',
        '/attendance/my-overtime',
      ],
    ),
    // Pengajuan Izin / Cuti
    MutationInvalidationRule(
      pattern: RegExp(r'^/attendance/leave-request'),
      invalidates: [
        '/attendance/my-leaves',
        '/attendance/leave-balance',
        '/attendance/status',
        '/attendance/my-schedule-calendar',
      ],
    ),
    // Respons Cuti Bersama
    MutationInvalidationRule(
      pattern: RegExp(r'^/attendance/collective-leave/'),
      invalidates: [
        '/attendance/collective-leaves',
        '/attendance/my-schedule-calendar',
      ],
    ),
    // Dismiss Notifikasi Pembatalan Cuti
    MutationInvalidationRule(
      pattern: RegExp(r'^/attendance/dismiss-cancellation/'),
      invalidates: [
        '/attendance/collective-leaves',
      ],
    ),
    // Klaim Lembur & Pembatalan Lembur
    MutationInvalidationRule(
      pattern: RegExp(r'^/attendance/\d+/(claim-overtime|decline-overtime)'),
      invalidates: [
        '/attendance/status',
        '/attendance/my',
        '/attendance/my-overtime',
      ],
    ),
    // Dismiss Notifikasi Update Shift
    MutationInvalidationRule(
      pattern: RegExp(r'^/attendance/dismiss-shift-update'),
      invalidates: [
        '/attendance/shift-updates',
      ],
    ),
    // Upload Struk / Update Claim / Submit / Hapus Struk
    MutationInvalidationRule(
      pattern: RegExp(r'^/employee/receipts'),
      invalidates: [
        '/employee/receipts',
      ],
    ),
  ];

  // ─── Key Builder ────────────────────────────────────────────────────────────
  /// Menghasilkan cache key unik berdasarkan path dan query params yang terurut.
  static String createCacheKey(String path, [Map<String, String>? query]) {
    if (query == null || query.isEmpty) {
      return 'GET:$path';
    }
    final sortedKeys = query.keys.toList()..sort();
    final queryString = sortedKeys
        .map((k) => '${Uri.encodeComponent(k)}=${Uri.encodeComponent(query[k] ?? '')}')
        .join('&');
    return 'GET:$path?$queryString';
  }

  // ─── Resolver TTL ───────────────────────────────────────────────────────────
  /// Menentukan apakah suatu path harus di-cache dan berapa TTL-nya.
  static Duration? resolveTtl(String path) {
    // Form preview cuti / polling OCR tidak pernah di-cache
    if (path == '/attendance/leave-preview') return null;

    for (final rule in _rules) {
      if (rule.pattern.hasMatch(path)) {
        return rule.ttl;
      }
    }
    return null;
  }

  // ─── Cache Operations ───────────────────────────────────────────────────────
  /// Mengambil data dari cache jika ada dan belum expired.
  static Map<String, dynamic>? get(String key) {
    final entry = _cacheStore[key];
    if (entry == null) return null;
    if (entry.isExpired) {
      _cacheStore.remove(key);
      return null;
    }
    return entry.data;
  }

  /// Menyimpan respons ke dalam in-memory cache.
  static void set(String key, Map<String, dynamic> data, Duration ttl) {
    _cacheStore[key] = CacheEntry(
      data: data,
      createdAt: DateTime.now(),
      ttl: ttl,
    );
  }

  /// Menghapus cache dengan key tertentu.
  static void delete(String key) {
    _cacheStore.remove(key);
  }

  /// Menghapus seluruh cache yang diawali oleh prefix tertentu.
  static void invalidate(String prefix) {
    final keysToRemove = <String>[];
    for (final key in _cacheStore.keys) {
      final rawPath = key.replaceFirst('GET:', '');
      if (rawPath.startsWith(prefix) || key.contains(prefix)) {
        keysToRemove.add(key);
      }
    }
    for (final k in keysToRemove) {
      _cacheStore.remove(k);
    }
  }

  /// Mengeksekusi invalidasi otomatis saat ada request mutasi.
  static void handleMutation(String path) {
    for (final rule in _mutationRules) {
      if (rule.pattern.hasMatch(path)) {
        for (final targetPrefix in rule.invalidates) {
          invalidate(targetPrefix);
        }
      }
    }
  }

  /// Hapus total seluruh cache di memori (dipanggil saat logout / session expired).
  static void clearAll() {
    _cacheStore.clear();
    _inFlightRequests.clear();
  }

  // ─── In-Flight Request Deduplication ────────────────────────────────────────
  /// Mengambil promise request yang sedang terbang (in-flight).
  static Future<Map<String, dynamic>>? getInFlight(String key) {
    return _inFlightRequests[key];
  }

  /// Mendaftarkan request in-flight.
  static void setInFlight(String key, Future<Map<String, dynamic>> future) {
    _inFlightRequests[key] = future;
  }

  /// Membersihkan request in-flight setelah selesai.
  static void clearInFlight(String key) {
    _inFlightRequests.remove(key);
  }
}
