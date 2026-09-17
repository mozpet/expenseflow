import 'package:flutter/foundation.dart' show kIsWeb, kDebugMode;
import 'package:safe_device/safe_device.dart';
import 'dart:io' show Platform;

/// Layanan deteksi integritas perangkat (Root/Jailbreak & Emulator).
///
/// Menggunakan package `safe_device` untuk memeriksa apakah perangkat:
/// - Telah di-root (Android) atau jailbreak (iOS)
/// - Berjalan di dalam emulator/simulator (Nox, Bluestacks, dsb)
///
/// Hasil dicache selama sesi aplikasi berjalan karena status root/emulator
/// tidak berubah saat runtime — cukup cek sekali per cold start.
class DeviceIntegrityService {
  static bool? _isRooted;
  static bool? _isEmulator;
  static bool _initialized = false;

  /// Menjalankan semua pemeriksaan integritas perangkat.
  /// Dipanggil otomatis oleh [checkIntegrity], atau bisa dipanggil
  /// manual saat app startup untuk pre-cache hasil.
  static Future<void> initialize() async {
    if (_initialized) return;

    // Di Web, tidak ada konsep root/emulator — skip semua
    if (kIsWeb) {
      _isRooted = false;
      _isEmulator = false;
      _initialized = true;
      return;
    }

    try {
      // Cek root/jailbreak
      _isRooted = await SafeDevice.isJailBroken;
    } catch (e) {
      // Jika plugin gagal (misal permission issue), anggap aman
      // tapi log untuk debugging
      if (kDebugMode) {
        // ignore: avoid_print
        print('[DeviceIntegrityService] Root/jailbreak check failed: $e');
      }
      _isRooted = false;
    }

    try {
      // Cek emulator/simulator
      // SafeDevice.isRealDevice returns true jika perangkat fisik
      final isReal = await SafeDevice.isRealDevice;
      _isEmulator = !isReal;
    } catch (e) {
      if (kDebugMode) {
        // ignore: avoid_print
        print('[DeviceIntegrityService] Emulator check failed: $e');
      }
      _isEmulator = false;
    }

    _initialized = true;
  }

  /// Menjalankan pemeriksaan integritas dan mengembalikan hasil.
  /// Thread-safe dan cached — aman dipanggil berkali-kali.
  static Future<DeviceIntegrityResult> checkIntegrity() async {
    await initialize();
    return DeviceIntegrityResult(
      isRooted: _isRooted ?? false,
      isEmulator: _isEmulator ?? false,
    );
  }

  /// Cek cepat: apakah perangkat aman untuk presensi?
  /// Returns `true` jika perangkat TIDAK di-root DAN bukan emulator.
  static Future<bool> isSafeForAttendance() async {
    final result = await checkIntegrity();
    return !result.isRooted && !result.isEmulator;
  }

  /// Reset cache — berguna saat testing atau jika user
  /// melepas root saat app masih berjalan (edge case).
  static void resetCache() {
    _isRooted = null;
    _isEmulator = null;
    _initialized = false;
  }

  /// Mendapatkan nama platform saat ini untuk pesan error.
  static String get platformName {
    if (kIsWeb) return 'Web';
    if (Platform.isAndroid) return 'Android';
    if (Platform.isIOS) return 'iOS';
    return 'Unknown';
  }
}

/// Hasil pemeriksaan integritas perangkat.
class DeviceIntegrityResult {
  final bool isRooted;
  final bool isEmulator;

  const DeviceIntegrityResult({
    required this.isRooted,
    required this.isEmulator,
  });

  /// `true` jika ada masalah integritas (root ATAU emulator).
  bool get hasIssue => isRooted || isEmulator;

  /// Pesan penjelasan untuk ditampilkan ke pengguna.
  String get message {
    if (isRooted && isEmulator) {
      return 'Perangkat terdeteksi telah di-root/jailbreak dan berjalan di dalam emulator. '
          'Presensi diblokir demi keamanan data.';
    } else if (isRooted) {
      return 'Perangkat terdeteksi telah di-root (Android) / jailbreak (iOS). '
          'Presensi diblokir karena perangkat yang di-root rentan terhadap manipulasi lokasi dan data.';
    } else if (isEmulator) {
      return 'Aplikasi terdeteksi berjalan di dalam Emulator atau Simulator (bukan perangkat fisik). '
          'Presensi hanya dapat dilakukan pada perangkat HP asli.';
    }
    return '';
  }

  /// Pesan singkat untuk ditampilkan di banner.
  String get bannerMessage {
    if (isRooted && isEmulator) {
      return 'Perangkat di-root/jailbreak & berjalan di emulator. Presensi diblokir.';
    } else if (isRooted) {
      return 'Perangkat di-root/jailbreak terdeteksi. Presensi diblokir.';
    } else if (isEmulator) {
      return 'Emulator terdeteksi. Presensi hanya bisa di HP asli.';
    }
    return '';
  }

  /// Daftar langkah-langkah solusi untuk pengguna.
  String get solutionSteps {
    final steps = <String>[];
    if (isRooted) {
      steps.add(
        '1. Hapus aplikasi root manager (seperti Magisk, KingRoot, SuperSU) dari perangkat Anda.\n'
        '2. Lakukan "Unroot" melalui aplikasi root manager sebelum menghapusnya.\n'
        '3. Jika menggunakan Custom ROM, pasang ROM resmi dari produsen HP Anda.\n'
        '4. Restart HP Anda setelah proses unroot selesai.',
      );
    }
    if (isEmulator) {
      steps.add(
        '${isRooted ? "5" : "1"}. Pastikan Anda menjalankan aplikasi di HP asli, bukan di komputer.\n'
        '${isRooted ? "6" : "2"}. Jangan gunakan NoxPlayer, BlueStacks, LDPlayer, atau emulator lainnya.\n'
        '${isRooted ? "7" : "3"}. Install aplikasi melalui Play Store / App Store di HP Anda.',
      );
    }
    return steps.join('\n');
  }
}
