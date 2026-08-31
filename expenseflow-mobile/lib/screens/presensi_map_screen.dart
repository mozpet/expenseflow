import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:permission_handler/permission_handler.dart'
    show openAppSettings;
import 'package:provider/provider.dart';
import '../presensi_provider.dart';
import '../services/api_service.dart';

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
  // Flag: true saat sedang menyinkronkan status dari backend
  bool _syncingStatus = true;

  /// Posisi aktif yang digunakan (GPS asli)
  LatLng get _activeLatLng =>
      _position != null
          ? LatLng(_position!.latitude, _position!.longitude)
          : const LatLng(-6.2088, 106.8456);

  bool get _hasActivePosition => _position != null;

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
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _initLocation();
      // Sync status presensi dari backend agar shift lintas hari & data radius kantor terdeteksi
      _syncBackendStatus();
    });
  }

  /// Sinkronkan status dari backend saat halaman dibuka.
  /// Ini memastikan karyawan shift lintas hari mendapat tombol check-out yang benar
  /// dan radius lingkaran kantor berhasil dimuat.
  Future<void> _syncBackendStatus() async {
    final prov = Provider.of<PresensiProvider>(context, listen: false);
    try {
      await prov.syncStatusFromBackend();
    } catch (_) {
      // gagal sync — tidak crash, hanya tampilkan tombol sesuai state lokal
    }
    if (mounted) setState(() => _syncingStatus = false);
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

    if (mounted) setState(() => _state = _LocationState.loading);

    // 1. Coba ambil Last Known Position terlebih dahulu (instan ~10ms)
    try {
      final lastPos = await Geolocator.getLastKnownPosition();
      if (lastPos != null && mounted) {
        setState(() {
          _position = lastPos;
          _state = _LocationState.ready;
        });
        _safeMove(LatLng(lastPos.latitude, lastPos.longitude), 16);
      }
    } catch (_) {}

    // 2. Ambil Current Position dengan timeout agar tidak loading selamanya
    try {
      final currentPos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.medium,
          timeLimit: Duration(seconds: 4),
        ),
      );
      if (mounted) {
        setState(() {
          _position = currentPos;
          _state = _LocationState.ready;
        });
        _safeMove(LatLng(currentPos.latitude, currentPos.longitude), 16);
      }
    } catch (e) {
      // Timeout atau indoor: jika belum ready, set ready agar user tidak terblokir
      if (mounted && _state == _LocationState.loading) {
        setState(() {
          _state = _LocationState.ready;
        });
      }
    }

    // 3. Pasang stream GPS aktif untuk update posisi secara berkala
    _positionStream?.cancel();
    _positionStream = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 2,
      ),
    ).listen(
      (pos) {
        if (!mounted) return;
        setState(() {
          _position = pos;
          _state = _LocationState.ready;
        });
        _safeMove(LatLng(pos.latitude, pos.longitude), 16);
      },
      onError: (_) {
        if (mounted && _state == _LocationState.loading) {
          setState(() => _state = _LocationState.ready);
        }
      },
    );
  }

  bool _submitting = false;

  Future<void> _simpanPresensi() async {
    if (!_hasActivePosition || _submitting) return;
    final prov = Provider.of<PresensiProvider>(context, listen: false);
    final wasCheckIn = prov.canCheckIn;

    setState(() => _submitting = true);
    try {
      await prov.simpanPresensi(
          _activeLatLng.latitude, _activeLatLng.longitude);
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
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _submitting = false);
      // 409 = ada presensi masuk shift malam kemarin yang belum di-check-out
      if (e.statusCode == 409) {
        final prov = Provider.of<PresensiProvider>(context, listen: false);
        // Sinkronkan dulu dari backend agar state lokal menampilkan tombol check-out
        await prov.syncStatusFromBackend();
        if (!mounted) return;
        
        // Jika setelah sync, canCheckOut sudah true, beritahu user untuk klik check-out
        if (prov.canCheckOut) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: const Text('Anda memiliki shift malam yang belum di-checkout. Silakan klik "Simpan Presensi Pulang".'),
              backgroundColor: Colors.indigo.shade600,
              duration: const Duration(seconds: 4),
            ),
          );
        } else {
          await showDialog(
            context: context,
            barrierDismissible: false,
            builder: (ctx) => AlertDialog(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              title: Row(
                children: [
                  Icon(Icons.nightlight_round, color: Colors.indigo.shade600, size: 22),
                  const SizedBox(width: 10),
                  const Expanded(
                    child: Text('Shift Malam Belum Selesai',
                        style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                  ),
                ],
              ),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.indigo.shade50,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.indigo.shade100),
                    ),
                    child: Text(
                      e.message,
                      style: TextStyle(color: Colors.indigo.shade800, height: 1.5),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    'Kamu masih memiliki presensi shift malam dari kemarin yang terbuka. Lakukan check-out terlebih dahulu sebelum presensi masuk hari ini.',
                    style: TextStyle(color: Colors.grey.shade600, fontSize: 13, height: 1.5),
                  ),
                ],
              ),
              actions: [
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.indigo.shade600,
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                    ),
                    onPressed: () => Navigator.of(ctx).pop(),
                    icon: const Icon(Icons.logout, size: 18),
                    label: const Text('Mengerti, Akan Check-Out Dulu',
                        style: TextStyle(fontWeight: FontWeight.bold)),
                  ),
                ),
              ],
            ),
          );
        }
      } else if (e.statusCode == 422 && (e.message.contains('Minimal durasi kehadiran') || e.message.contains('Check-out belum dapat dilakukan'))) {
        await showDialog(
          context: context,
          barrierDismissible: true,
          builder: (ctx) => AlertDialog(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: Row(
              children: [
                Icon(Icons.hourglass_top_rounded, color: Colors.amber.shade700, size: 24),
                const SizedBox(width: 10),
                const Expanded(
                  child: Text('Jeda Waktu Check-Out',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                ),
              ],
            ),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.amber.shade50,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.amber.shade200),
                  ),
                  child: Text(
                    e.message,
                    style: TextStyle(color: Colors.amber.shade900, fontSize: 13, height: 1.4),
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  'Sistem memberikan jeda minimal agar Anda tidak sengaja check-out sesaat setelah check-in.',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 12, height: 1.4),
                ),
              ],
            ),
            actions: [
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.amber.shade700,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  onPressed: () => Navigator.of(ctx).pop(),
                  child: const Text('Mengerti', style: TextStyle(fontWeight: FontWeight.bold)),
                ),
              ),
            ],
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.message),
            backgroundColor: Colors.red,
            duration: const Duration(seconds: 3),
          ),
        );
      }
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

  OfficeArea? _getNearestOffice(List<OfficeArea> offices) {
    if (offices.isEmpty) return null;
    if (!_hasActivePosition) return offices.first;
    OfficeArea? nearest;
    double minDistance = double.infinity;
    for (final office in offices) {
      final dist = Geolocator.distanceBetween(
        _activeLatLng.latitude,
        _activeLatLng.longitude,
        office.latitude,
        office.longitude,
      );
      if (dist < minDistance) {
        minDistance = dist;
        nearest = office;
      }
    }
    return nearest;
  }

  double? _getDistanceToOffice(OfficeArea? office) {
    if (office == null || !_hasActivePosition) return null;
    return Geolocator.distanceBetween(
      _activeLatLng.latitude,
      _activeLatLng.longitude,
      office.latitude,
      office.longitude,
    );
  }

  @override
  Widget build(BuildContext context) {
    final prov = Provider.of<PresensiProvider>(context);
    
    // Tampilkan loading spinner saat sedang sinkronisasi status dari backend
    if (_syncingStatus) {
      return Scaffold(
        appBar: AppBar(title: const Text('Presensi')),
        body: const Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircularProgressIndicator(color: Color(0xFF0088FF)),
              SizedBox(height: 16),
              Text('Memeriksa status presensi...', style: TextStyle(color: Colors.grey)),
            ],
          ),
        ),
      );
    }
    
    final isCompleted = !prov.canCheckIn && !prov.canCheckOut;
    final actionLabel = prov.canCheckIn
        ? 'Simpan Presensi Masuk'
        : prov.canCheckOut
            ? 'Simpan Presensi Pulang'
            : 'Presensi Hari Ini Selesai';

    final userOffice = prov.primaryOffice ?? (prov.offices.isNotEmpty ? prov.offices.first : null);
    final displayedOffices = userOffice != null ? [userOffice] : prov.offices;

    final nearestOffice = _getNearestOffice(displayedOffices);
    final distanceToNearest = _getDistanceToOffice(nearestOffice);
    final isWithinRadius = distanceToNearest != null &&
        nearestOffice != null &&
        distanceToNearest <= nearestOffice.radiusMeters;

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
                    initialCenter: _activeLatLng,
                    initialZoom: 15,
                    interactionOptions: const InteractionOptions(
                      flags: InteractiveFlag.all,
                    ),
                    onMapReady: () {
                      // Set flag SETELAH flutter_map menginisialisasi internal
                      // 'late _local' — baru aman memanggil .move()
                      _mapReady = true;
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

                    // ── Lingkaran Radius Kantor Cabang User ───────────
                    if (displayedOffices.isNotEmpty)
                      CircleLayer(
                        circles: displayedOffices.map((office) {
                          return CircleMarker(
                            point: LatLng(office.latitude, office.longitude),
                            radius: office.radiusMeters,
                            useRadiusInMeter: true,
                            color: const Color(0x330088FF),
                            borderColor: const Color(0xFF0088FF),
                            borderStrokeWidth: 2.5,
                          );
                        }).toList(),
                      ),

                    // ── Marker Kantor Cabang & Marker User ────────────
                    MarkerLayer(
                      markers: [
                        // Marker untuk kantor cabang karyawan
                        ...displayedOffices.map((office) {
                          return Marker(
                            point: LatLng(office.latitude, office.longitude),
                            width: 150,
                            height: 64,
                            alignment: Alignment.topCenter,
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Container(
                                  padding: const EdgeInsets.symmetric(
                                      horizontal: 8, vertical: 3),
                                  decoration: BoxDecoration(
                                    color: const Color(0xFF0D47A1),
                                    borderRadius: BorderRadius.circular(10),
                                    boxShadow: [
                                      BoxShadow(
                                        color: Colors.black.withValues(alpha: 0.25),
                                        blurRadius: 4,
                                        offset: const Offset(0, 2),
                                      ),
                                    ],
                                  ),
                                  child: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      const Icon(Icons.apartment,
                                          color: Colors.white, size: 12),
                                      const SizedBox(width: 4),
                                      Flexible(
                                        child: Text(
                                          '${office.name} (${office.radiusMeters.toInt()}m)',
                                          style: const TextStyle(
                                            color: Colors.white,
                                            fontSize: 10,
                                            fontWeight: FontWeight.bold,
                                          ),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                const Icon(Icons.location_on,
                                    color: Color(0xFF0D47A1), size: 28),
                              ],
                            ),
                          );
                        }),

                        // Marker lokasi posisi user (GPS Real-Time)
                        if (_hasActivePosition)
                          Marker(
                            point: _activeLatLng,
                            width: 70,
                            height: 60,
                            alignment: Alignment.topCenter,
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Container(
                                  padding: const EdgeInsets.symmetric(
                                      horizontal: 6, vertical: 2),
                                  decoration: BoxDecoration(
                                    color: Colors.red.shade700,
                                    borderRadius: BorderRadius.circular(8),
                                    boxShadow: [
                                      BoxShadow(
                                        color: Colors.black.withValues(alpha: 0.25),
                                        blurRadius: 3,
                                      ),
                                    ],
                                  ),
                                  child: const Text(
                                    'Anda',
                                    style: TextStyle(
                                      color: Colors.white,
                                      fontSize: 9,
                                      fontWeight: FontWeight.bold,
                                    ),
                                  ),
                                ),
                                const Icon(
                                  Icons.person_pin_circle,
                                  color: Colors.red,
                                  size: 38,
                                ),
                              ],
                            ),
                          ),
                      ],
                    ),
                  ],
                ),

                // Overlay status saat loading / error
                if (!_hasActivePosition) _buildMapOverlay(),

                // Status GPS Real-Time
                if (_hasActivePosition)
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
                            'GPS Real-Time',
                            style: TextStyle(
                                fontSize: 11.5, fontWeight: FontWeight.bold),
                          ),
                        ],
                      ),
                    ),
                  ),

                // Tombol kontrol peta (kanan bawah peta)
                Positioned(
                  bottom: 12,
                  right: 12,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (displayedOffices.isNotEmpty) ...[
                        FloatingActionButton.small(
                          heroTag: 'office_focus',
                          backgroundColor: Colors.white,
                          foregroundColor: const Color(0xFF0D47A1),
                          elevation: 4,
                          tooltip: 'Fokus ke Kantor Cabang',
                          onPressed: () {
                            final target =
                                nearestOffice ?? displayedOffices.first;
                            _safeMove(
                                LatLng(target.latitude, target.longitude), 16);
                          },
                          child: const Icon(Icons.apartment, size: 20),
                        ),
                        const SizedBox(height: 8),
                      ],
                      if (_hasActivePosition)
                        FloatingActionButton.small(
                          heroTag: 'recenter',
                          backgroundColor: Colors.white,
                          foregroundColor: const Color(0xFF1E88E5),
                          elevation: 4,
                          tooltip: 'Pusatkan ke Titik Aktif',
                          onPressed: () => _safeMove(_activeLatLng, 16),
                          child: const Icon(Icons.my_location, size: 20),
                        ),
                    ],
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
              child: SafeArea(
                top: false,
                bottom: true,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 16, 20, 12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  // Koordinat Posisi User (GPS Real-Time)
                  if (_hasActivePosition)
                    Row(
                      children: [
                        const Icon(
                          Icons.location_on_outlined,
                          size: 16,
                          color: Color(0xFF1E88E5),
                        ),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            '${_activeLatLng.latitude.toStringAsFixed(6)},  ${_activeLatLng.longitude.toStringAsFixed(6)}',
                            style: const TextStyle(
                              fontSize: 13,
                              color: Colors.black87,
                              fontWeight: FontWeight.w600,
                            ),
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

                  // Informasi Jarak & Radius Kantor (Hanya aktif bila radius_enabled ON)
                  if (prov.radiusEnabled &&
                      _hasActivePosition &&
                      nearestOffice != null &&
                      distanceToNearest != null) ...[
                    Container(
                      margin: const EdgeInsets.only(top: 8),
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: isWithinRadius
                            ? const Color(0xFFE8F5E9)
                            : const Color(0xFFFFF3E0),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(
                          color: isWithinRadius
                              ? const Color(0xFFA5D6A7)
                              : const Color(0xFFFFB74D),
                        ),
                      ),
                      child: Row(
                        children: [
                          Icon(
                            isWithinRadius
                                ? Icons.check_circle
                                : Icons.warning_amber_rounded,
                            size: 16,
                            color: isWithinRadius
                                ? const Color(0xFF2E7D32)
                                : const Color(0xFFE65100),
                          ),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              isWithinRadius
                                  ? 'DALAM RADIUS: ${nearestOffice.name} (${distanceToNearest.round()}m / batas ${nearestOffice.radiusMeters.toInt()}m)'
                                  : 'DI LUAR RADIUS: ${nearestOffice.name} • Batas ${nearestOffice.radiusMeters.toInt()}m (Jarak: ${distanceToNearest >= 1000 ? '${(distanceToNearest / 1000).toStringAsFixed(1)} km' : '${distanceToNearest.round()} m'})',
                              style: TextStyle(
                                fontSize: 11.5,
                                fontWeight: FontWeight.bold,
                                color: isWithinRadius
                                    ? const Color(0xFF1B5E20)
                                    : const Color(0xFFBF360C),
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ] else if (!prov.radiusEnabled && _hasActivePosition) ...[
                    // Mode WFH (Radius OFF): Tampilkan info WFH tanpa peringatan radius
                    Container(
                      margin: const EdgeInsets.only(top: 8),
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: const Color(0xFFE3F2FD),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: const Color(0xFF90CAF9)),
                      ),
                      child: const Row(
                        children: [
                          Icon(Icons.home_work_outlined,
                              size: 16, color: Color(0xFF1565C0)),
                          SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              'Mode WFH Aktif (Presensi dari Rumah / Tanpa Batas Radius)',
                              style: TextStyle(
                                fontSize: 11.5,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF0D47A1),
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],

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
                      onPressed: (_hasActivePosition &&
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
                  'Izin lokasi ditolak.\nAktifkan izin lokasi di pengaturan aplikasi.',
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
        return 'Meminta izin lokasi GPS...';
      case _LocationState.loading:
        return 'Mendeteksi posisi GPS real-time...';
      case _LocationState.denied:
        return 'Izin lokasi ditolak. Silakan aktifkan izin lokasi.';
      case _LocationState.disabled:
        return 'GPS tidak aktif. Silakan nyalakan GPS di HP Anda.';
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
