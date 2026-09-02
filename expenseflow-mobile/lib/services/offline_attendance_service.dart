import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_service.dart';

class OfflineAttendanceItem {
  final String id;
  final String type; // 'check_in' | 'check_out'
  final double latitude;
  final double longitude;
  final bool isMocked;
  final DateTime recordedAt;
  final String status; // 'pending' | 'synced' | 'failed'
  final String? errorMessage;

  OfflineAttendanceItem({
    required this.id,
    required this.type,
    required this.latitude,
    required this.longitude,
    required this.isMocked,
    required this.recordedAt,
    this.status = 'pending',
    this.errorMessage,
  });

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'type': type,
      'latitude': latitude,
      'longitude': longitude,
      'is_mocked': isMocked,
      'recorded_at': recordedAt.toIso8601String(),
      'status': status,
      'error_message': errorMessage,
    };
  }

  factory OfflineAttendanceItem.fromJson(Map<String, dynamic> json) {
    return OfflineAttendanceItem(
      id: json['id'] as String,
      type: json['type'] as String,
      latitude: (json['latitude'] as num).toDouble(),
      longitude: (json['longitude'] as num).toDouble(),
      isMocked: json['is_mocked'] == true,
      recordedAt: DateTime.parse(json['recorded_at'] as String),
      status: json['status'] as String? ?? 'pending',
      errorMessage: json['error_message'] as String?,
    );
  }

  OfflineAttendanceItem copyWith({
    String? status,
    String? errorMessage,
  }) {
    return OfflineAttendanceItem(
      id: id,
      type: type,
      latitude: latitude,
      longitude: longitude,
      isMocked: isMocked,
      recordedAt: recordedAt,
      status: status ?? this.status,
      errorMessage: errorMessage ?? this.errorMessage,
    );
  }
}

class OfflineAttendanceService {
  static const String _queueKey = 'offline_attendance_queue';

  /// Ambil daftar antrean presensi offline dari SharedPreferences
  static Future<List<OfflineAttendanceItem>> getQueue() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_queueKey);
    if (raw == null || raw.isEmpty) return [];

    try {
      final List<dynamic> decoded = jsonDecode(raw);
      return decoded
          .map((e) => OfflineAttendanceItem.fromJson(e as Map<String, dynamic>))
          .toList();
    } catch (_) {
      return [];
    }
  }

  /// Tambahkan item presensi baru ke antrean offline
  static Future<OfflineAttendanceItem> enqueue({
    required String type,
    required double latitude,
    required double longitude,
    bool isMocked = false,
    DateTime? recordedAt,
  }) async {
    final now = recordedAt ?? DateTime.now();
    final item = OfflineAttendanceItem(
      id: 'offline_${now.millisecondsSinceEpoch}_$type',
      type: type,
      latitude: latitude,
      longitude: longitude,
      isMocked: isMocked,
      recordedAt: now,
      status: 'pending',
    );

    final currentQueue = await getQueue();
    // Cegah duplikasi action yang sama persis
    currentQueue.removeWhere((q) => q.type == type && q.recordedAt.day == now.day);
    currentQueue.add(item);

    await _saveQueue(currentQueue);
    return item;
  }

  /// Hapus item berdasarkan ID
  static Future<void> removeItem(String id) async {
    final currentQueue = await getQueue();
    currentQueue.removeWhere((q) => q.id == id);
    await _saveQueue(currentQueue);
  }

  /// Bersihkan seluruh antrean
  static Future<void> clearQueue() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_queueKey);
  }

  /// Cek apakah ada antrean pending
  static Future<bool> hasPending() async {
    final queue = await getQueue();
    return queue.any((item) => item.status == 'pending');
  }

  /// Sinkronkan antrean offline ke backend
  static Future<Map<String, dynamic>> syncQueue() async {
    final queue = await getQueue();
    final pending = queue.where((item) => item.status == 'pending').toList();

    if (pending.isEmpty) {
      return {'synced': 0, 'failed': 0, 'message': 'Tidak ada antrean presensi pending.'};
    }

    final payload = pending.map((item) => {
      'id': item.id,
      'type': item.type,
      'latitude': item.latitude,
      'longitude': item.longitude,
      'is_mocked': item.isMocked,
      'recorded_at': item.recordedAt.toIso8601String(),
    }).toList();

    try {
      final res = await ApiService.syncOfflineAttendance(payload);
      final List<dynamic>? results = res['results'] as List<dynamic>?;

      final updatedQueue = <OfflineAttendanceItem>[];
      if (results != null) {
        for (final item in queue) {
          final match = results.firstWhere(
            (r) => r['id'] == item.id,
            orElse: () => null,
          );

          if (match != null) {
            final bool success = match['success'] == true;
            final int statusCode = (match['status_code'] as num?)?.toInt() ?? 0;
            // Jika sukses atau 409 (sudah pernah tercatat di backend), hapus dari antrean
            if (success || statusCode == 409) {
              continue;
            } else {
              updatedQueue.add(item.copyWith(
                status: 'failed',
                errorMessage: match['message'] as String?,
              ));
            }
          } else {
            updatedQueue.add(item);
          }
        }
      }

      await _saveQueue(updatedQueue);
      return res;
    } catch (e) {
      rethrow;
    }
  }

  static Future<void> _saveQueue(List<OfflineAttendanceItem> queue) async {
    final prefs = await SharedPreferences.getInstance();
    final jsonList = queue.map((e) => e.toJson()).toList();
    await prefs.setString(_queueKey, jsonEncode(jsonList));
  }
}
