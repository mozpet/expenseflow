import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:permission_handler/permission_handler.dart' show openAppSettings;
import 'package:provider/provider.dart';
import '../presensi_provider.dart';

enum _LocationState { requesting, loading, ready, denied, disabled }

class PresensiMapScreen extends StatefulWidget {
  const PresensiMapScreen({super.key});

  @override
  State<PresensiMapScreen> createState() => _PresensiMapScreenState();
}

class _PresensiMapScreenState extends State<PresensiMapScreen> {
  _LocationState _state = _LocationState.requesting;
  Position? _position;
  StreamSubscription<Position>? _positionStream;
  // MapController harus sama instance antara yang di-pass ke FlutterMap
  // dan yang kita panggil .move(). Jangan buat instance baru di onMapReady.
  final MapController _mapController = MapController();
  // Flag: true setelah onMapReady dipanggil flutter_map (late field sudah init)
  bool _mapReady = false;

  /// Memanggil _mapController.move() hanya jika map sudah siap.
  /// try-catch sebagai safety net untuk kasus edge (hot-reload, dll).
  void _safeMove(LatLng point, double zoom) {
    if (!_mapReady) return;
    try {
      _mapController.move(point, zoom);
    } catch (_) {
      // Controller belum siap — abaikan, posisi akan di-sync saat onMapReady
    }
  }


  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _initLocation());
  }

  @override
  void dispose() {
    _positionStream?.cancel();
    super.dispose();
  }

  Future<void> _initLocation() async {
    setState(() => _state = _LocationState.requesting);

    final serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      if (mounted) setState(() => _state = _LocationState.disabled);
      return;
    }

    LocationPermission permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      if (mounted) setState(() => _state = _LocationState.denied);
      return;
    }

    setState(() => _state = _LocationState.loading);

    _positionStream = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 5,
      ),
    ).listen(
      (pos) {
        if (!mounted) return;
        setState(() {
          _position = pos;
          _state = _LocationState.ready; // langsung ready begitu dapat koordinat
        });
        // Panggil hanya jika controller sudah di-attach oleh FlutterMap
        _safeMove(LatLng(pos.latitude, pos.longitude), 16);
      },
      onError: (_) {
        if (mounted) setState(() => _state = _LocationState.denied);
      },
    );
  }

  bool _submitting = false;

  Future<void> _simpanPresensi() async {
    if (_position == null || _submitting) return;
    final prov = Provider.of<PresensiProvider>(context, listen: false);
    final wasCheckIn = prov.canCheckIn;

    setState(() => _submitting = true);
    try {
      await prov.simpanPresensi(
          _position!.latitude, _position!.longitude);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(wasCheckIn
              ? 'Presensi masuk berhasil dicatat!'
              : 'Presensi pulang berhasil dicatat!'),
          backgroundColor: Colors.green,
          duration: const Duration(seconds: 2),
        ),
      );
      Navigator.pop(context);
    } catch (e) {
      if (!mounted) return;
      setState(() => _submitting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: Colors.red,
          duration: const Duration(seconds: 3),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<PresensiProvider>(context);
    final isCompleted = !prov.canCheckIn && !prov.canCheckOut;
    final actionLabel = prov.canCheckIn
        ? 'Simpan Presensi Masuk'
        : prov.canCheckOut
            ? 'Simpan Presensi Pulang'
            : 'Presensi Hari Ini Selesai';

    final hasPosition = _position != null;
    final center = hasPosition
        ? LatLng(_position!.latitude, _position!.longitude)
        : const LatLng(-6.2088, 106.8456); // fallback Jakarta

    return Scaffold(
      appBar: AppBar(
        title: const Text('Presensi'),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back),
          onPressed: () => Navigator.pop(context),
        ),
      ),
      body: Column(
        children: [
          // ── Peta OSM (60% layar) ────────────────────────────
          Expanded(
            flex: 6,
            child: Stack(
              children: [
                FlutterMap(
                  mapController: _mapController,
                  options: MapOptions(
                    initialCenter: center,
                    initialZoom: 15,
                    interactionOptions: const InteractionOptions(
                      flags: InteractiveFlag.all,
                    ),
                    onMapReady: () {
                      // Set flag SETELAH flutter_map menginisialisasi internal
                      // 'late _local' — baru aman memanggil .move()
                      _mapReady = true;
                      // Jika posisi sudah tersedia sebelum map siap, langsung pindah
                      if (_position != null) {
                        _safeMove(
                          LatLng(_position!.latitude, _position!.longitude),
                          16,
                        );
                      }
                    },
                  ),
                  children: [
                    TileLayer(
                      urlTemplate:
                          'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                      userAgentPackageName: 'com.expenseflow.cobain',
                    ),
                    if (hasPosition)
                      MarkerLayer(
                        markers: [
                          Marker(
                            point: center,
                            width: 56,
                            height: 56,
                            child: Column(
                              children: const [
                                Icon(Icons.location_on,
                                    color: Colors.red, size: 40),
                                SizedBox(height: 4),
                              ],
                            ),
                          ),
                        ],
                      ),
                  ],
                ),

                // Overlay status saat loading / error
                if (!hasPosition) _buildMapOverlay(),

                  if (hasPosition)
                  Positioned(
                    top: 12,
                    right: 12,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(20),
                        boxShadow: [
                          BoxShadow(
                              color: Colors.black.withValues(alpha: 0.12),
                              blurRadius: 6)
                        ],
                      ),
                      child: const Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.gps_fixed, size: 14, color: Colors.green),
                          SizedBox(width: 5),
                          Text(
                            'Lokasi terdeteksi',
                            style: TextStyle(
                                fontSize: 12, fontWeight: FontWeight.bold),
                          ),
                        ],
                      ),
                    ),
                  ),

                // Tombol re-center (kanan bawah peta)
                if (hasPosition)
                  Positioned(
                    bottom: 12,
                    right: 12,
                    child: FloatingActionButton.small(
                      heroTag: 'recenter',
                      backgroundColor: Colors.white,
                      foregroundColor: const Color(0xFF1E88E5),
                      elevation: 4,
                      onPressed: () => _safeMove(center, 16),
                      child: const Icon(Icons.my_location, size: 20),
                    ),
                  ),
              ],
            ),
          ),

          // ── Panel bawah (40% layar) ──────────────────────────
          Expanded(
            flex: 4,
            child: Container(
              color: Colors.white,
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Koordinat
                  if (hasPosition)
                    Row(
                      children: [
                        const Icon(Icons.location_on_outlined,
                            size: 16, color: Color(0xFF1E88E5)),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            '${_position!.latitude.toStringAsFixed(6)},  '
                            '${_position!.longitude.toStringAsFixed(6)}',
                            style: const TextStyle(
                                fontSize: 13,
                                color: Colors.black87,
                                fontWeight: FontWeight.w500),
                          ),
                        ),
                      ],
                    )
                  else
                    Text(
                      _stateMessage,
                      style:
                          const TextStyle(fontSize: 13, color: Colors.grey),
                    ),

                  const Spacer(),

                  // Baris masuk / pulang
                  Container(
                    padding: const EdgeInsets.symmetric(
                        vertical: 12, horizontal: 16),
                    decoration: BoxDecoration(
                      color: const Color(0xFF0066CC),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                      children: [
                        _TimeChip(
                            label: 'Masuk',
                            value: prov.todayMasuk ?? '--:--',
                            icon: Icons.login),
                        Container(
                            width: 1, height: 30, color: Colors.white30),
                        _TimeChip(
                            label: 'Pulang',
                            value: prov.todayPulang ?? '--:--',
                            icon: Icons.logout),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),

                  // Tombol simpan
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      onPressed: (_state == _LocationState.ready &&
                              !isCompleted &&
                              !_submitting)
                          ? _simpanPresensi
                          : null,
                      icon: _submitting
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(
                                  strokeWidth: 2, color: Colors.white),
                            )
                          : Icon(
                              isCompleted
                                  ? Icons.check_circle
                                  : prov.canCheckIn
                                      ? Icons.login
                                      : Icons.logout,
                              size: 20,
                            ),
                      label: Text(_submitting ? 'Menyimpan...' : actionLabel,
                          style: const TextStyle(
                              fontSize: 15, fontWeight: FontWeight.bold)),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF0088FF),
                        foregroundColor: Colors.white,
                        disabledBackgroundColor: Colors.grey.shade300,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12)),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildMapOverlay() {
    final isLoading = _state == _LocationState.requesting ||
        _state == _LocationState.loading;

    return Container(
      color: Colors.black.withValues(alpha: 0.35),
      child: Center(
        child: Container(
          margin: const EdgeInsets.symmetric(horizontal: 32),
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (isLoading) ...[
                const CircularProgressIndicator(color: Color(0xFF0088FF)),
                const SizedBox(height: 12),
                Text(
                  _state == _LocationState.requesting
                      ? 'Meminta izin lokasi...'
                      : 'Mendeteksi posisi GPS...',
                  style: const TextStyle(fontSize: 13, color: Colors.grey),
                  textAlign: TextAlign.center,
                ),
              ] else if (_state == _LocationState.denied) ...[
                const Icon(Icons.location_off, color: Colors.red, size: 36),
                const SizedBox(height: 10),
                const Text(
                  'Izin lokasi ditolak.\nAktifkan di pengaturan aplikasi.',
                  style: TextStyle(fontSize: 13, color: Colors.black54),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    OutlinedButton(
                      onPressed: _initLocation,
                      child: const Text('Coba Lagi'),
                    ),
                    const SizedBox(width: 8),
                    ElevatedButton(
                      onPressed: openAppSettings,
                      style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFF0088FF),
                          foregroundColor: Colors.white),
                      child: const Text('Pengaturan'),
                    ),
                  ],
                ),
              ] else if (_state == _LocationState.disabled) ...[
                const Icon(Icons.location_disabled,
                    color: Colors.grey, size: 36),
                const SizedBox(height: 10),
                const Text(
                  'GPS tidak aktif.\nNyalakan GPS di perangkat Anda.',
                  style: TextStyle(fontSize: 13, color: Colors.black54),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 12),
                ElevatedButton.icon(
                  onPressed: () async {
                    await Geolocator.openLocationSettings();
                    _initLocation();
                  },
                  icon: const Icon(Icons.gps_fixed, size: 16),
                  label: const Text('Nyalakan GPS'),
                  style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF0088FF),
                      foregroundColor: Colors.white),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  String get _stateMessage {
    switch (_state) {
      case _LocationState.requesting:
        return 'Meminta izin lokasi...';
      case _LocationState.loading:
        return 'Mendeteksi posisi GPS...';
      case _LocationState.denied:
        return 'Izin lokasi ditolak.';
      case _LocationState.disabled:
        return 'GPS tidak aktif.';
      default:
        return '';
    }
  }
}

class _TimeChip extends StatelessWidget {
  final String label;
  final String value;
  final IconData icon;
  const _TimeChip(
      {required this.label, required this.value, required this.icon});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, color: Colors.white70, size: 16),
        const SizedBox(width: 6),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label,
                style:
                    const TextStyle(color: Colors.white60, fontSize: 10)),
            Text(value,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.bold)),
          ],
        ),
      ],
    );
  }
}
