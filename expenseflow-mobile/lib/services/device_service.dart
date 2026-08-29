import 'dart:io';
import 'dart:math';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

class DeviceService {
  static const String _persistentDeviceIdKey = 'persistent_device_id';
  static final DeviceInfoPlugin _deviceInfo = DeviceInfoPlugin();

  /// Mendapatkan Device ID unik yang persisten.
  static Future<String> getDeviceId() async {
    final prefs = await SharedPreferences.getInstance();

    try {
      if (Platform.isAndroid) {
        final androidInfo = await _deviceInfo.androidInfo;
        // Gunakan androidInfo.id atau serial/fingerprint
        final rawId = androidInfo.id;
        if (rawId.isNotEmpty) {
          return 'android_$rawId';
        }
      } else if (Platform.isIOS) {
        final iosInfo = await _deviceInfo.iosInfo;
        final rawId = iosInfo.identifierForVendor;
        if (rawId != null && rawId.isNotEmpty) {
          return 'ios_$rawId';
        }
      }
    } catch (_) {
      // Fallback jika terjadi error pada device_info plugin
    }

    // Fallback: Persistent ID di SharedPreferences
    String? persistentId = prefs.getString(_persistentDeviceIdKey);
    if (persistentId == null || persistentId.isEmpty) {
      final randomNum = Random().nextInt(900000) + 100000;
      persistentId = 'dev_${DateTime.now().millisecondsSinceEpoch}_$randomNum';
      await prefs.setString(_persistentDeviceIdKey, persistentId);
    }
    return persistentId;
  }

  /// Mendapatkan Nama Perangkat yang mudah dikenali (contoh: "Infinix X6853", "Samsung SM-A525F").
  static Future<String> getDeviceName() async {
    try {
      if (Platform.isAndroid) {
        final androidInfo = await _deviceInfo.androidInfo;
        final brand = androidInfo.brand.trim();
        final model = androidInfo.model.trim();
        if (model.toLowerCase().startsWith(brand.toLowerCase())) {
          return model;
        }
        return '$brand $model'.trim();
      } else if (Platform.isIOS) {
        final iosInfo = await _deviceInfo.iosInfo;
        return iosInfo.name.isNotEmpty ? iosInfo.name : 'iPhone';
      }
    } catch (_) {
      // Fallback
    }

    return Platform.isAndroid ? 'Android Device' : (Platform.isIOS ? 'iOS Device' : 'Mobile Device');
  }
}
