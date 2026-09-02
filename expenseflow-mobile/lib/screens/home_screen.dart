import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:provider/provider.dart';
import 'riwayat_screen.dart';
import 'presensi_history_screen.dart';
import 'izin_cuti_screen.dart';
import 'profile_screen.dart';
import 'jadwal_shift_screen.dart';
import 'submit_step1_screen.dart';
import 'ajukan_izin_screen.dart';
import 'presensi_map_screen.dart';
import '../presensi_provider.dart';
import '../providers/auth_provider.dart';
import '../providers/receipt_provider.dart';
import '../providers/shift_provider.dart';
import '../utils.dart';
import '../widgets/skeleton.dart';

class HomeScreen extends StatefulWidget {
  final String userName;
  const HomeScreen({super.key, required this.userName});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  int _currentIndex = 0;
  final List<bool> _visited = [true, false, false, false, false];
  bool _isReloading = false;

  Future<void> _refreshHomeData() async {
    if (_isReloading) return;
    setState(() => _isReloading = true);
    try {
      final receiptProv = Provider.of<ReceiptProvider>(context, listen: false);
      final shiftProv = Provider.of<ShiftProvider>(context, listen: false);
      final presensiProv = Provider.of<PresensiProvider>(context, listen: false);

      await Future.wait([
        receiptProv.fetchMyReceipts(),
        shiftProv.fetchMySchedule(),
        shiftProv.checkShiftUpdates(),
        presensiProv.syncStatusFromBackend(),
        presensiProv.fetchCollectiveLeaves(),
      ]);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Data beranda berhasil diperbarui'),
            duration: Duration(seconds: 1),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    } catch (_) {
    } finally {
      if (mounted) {
        setState(() => _isReloading = false);
      }
    }
  }

  Future<void> _requestLocationPermission() async {
    if (kIsWeb) return;
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        // GPS belum aktif di HP
        return;
      }
      LocationPermission permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        await Geolocator.requestPermission();
      }
    } catch (_) {}
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _requestLocationPermission();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Provider.of<ReceiptProvider>(context, listen: false).fetchMyReceipts();
      Provider.of<ShiftProvider>(context, listen: false).fetchMySchedule();
      // Cek apakah shift diubah HRD → tampilkan banner di beranda
      Provider.of<ShiftProvider>(
        context,
        listen: false,
      ).checkShiftUpdates();
      // Sync status presensi saat pertama buka app
      final presensiProv = Provider.of<PresensiProvider>(
        context,
        listen: false,
      );
      presensiProv.syncStatusFromBackend();
      // Cek cuti bersama H-7 → tampilkan banner Ya/Tidak di beranda
      presensiProv.fetchCollectiveLeaves();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Saat app kembali ke foreground, cek apakah ada auto-checkout dari backend
    if (state == AppLifecycleState.resumed) {
      final presensiProv = Provider.of<PresensiProvider>(
        context,
        listen: false,
      );
      presensiProv.syncStatusFromBackend();
      presensiProv.fetchCollectiveLeaves();
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomPadding = MediaQuery.of(context).padding.bottom;
    // Adaptif: jika ada navigasi gestur gunakan bottomPadding, jika 3 tombol sistem beri padding aman 8px
    final safeBottomPadding = bottomPadding > 0 ? bottomPadding : 8.0;
    final navBarHeight = 62.0 + safeBottomPadding;
    final totalBarHeight = 84.0 + safeBottomPadding;

    return Scaffold(
      extendBody: true,
      body: Stack(
        children: [
          Positioned.fill(
            bottom: navBarHeight,
            child: IndexedStack(
              index: _currentIndex,
              children: [
                _buildBerandaTab(),
                _visited[1] ? const RiwayatScreen() : const SizedBox.shrink(),
                _visited[2]
                    ? const PresensiHistoryScreen()
                    : const SizedBox.shrink(),
                _visited[3] ? const IzinCutiScreen() : const SizedBox.shrink(),
                _visited[4] ? const ProfileScreen() : const SizedBox.shrink(),
              ],
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            height: totalBarHeight,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Positioned(
                  left: 0,
                  right: 0,
                  bottom: 0,
                  height: navBarHeight,
                  child: Container(
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: const BorderRadius.vertical(
                        top: Radius.circular(20),
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.06),
                          blurRadius: 16,
                          offset: const Offset(0, -4),
                        ),
                      ],
                    ),
                  ),
                ),
                Positioned.fill(
                  child: Padding(
                    padding: EdgeInsets.only(bottom: safeBottomPadding),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        _buildTabItem(0, Icons.home_outlined, Icons.home_rounded, 'Home'),
                        _buildTabItem(
                          1,
                          Icons.receipt_long_outlined,
                          Icons.receipt_long_rounded,
                          'Struk',
                        ),
                        _buildTabItem(
                          2,
                          Icons.fingerprint_rounded,
                          Icons.fingerprint_rounded,
                          'Presensi',
                        ),
                        _buildTabItem(
                          3,
                          Icons.event_note_outlined,
                          Icons.event_note_rounded,
                          'Izin/Cuti',
                        ),
                        _buildTabItem(
                          4,
                          Icons.person_outline_rounded,
                          Icons.person_rounded,
                          'Profil',
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  void _onTabTapped(int index) {
    setState(() {
      _currentIndex = index;
      _visited[index] = true;
    });
    // Tab Struk: refresh data
    if (index == 1) {
      Provider.of<ReceiptProvider>(context, listen: false).fetchMyReceipts();
    }
    // Tab Presensi: muat riwayat dan sync status WFH/presensi dari backend
    if (index == 2) {
      Provider.of<PresensiProvider>(
        context,
        listen: false,
      ).fetchMyAttendance();
    }
    // Tab Izin & Cuti: ambil data terbaru
    if (index == 3) {
      final prov = Provider.of<PresensiProvider>(context, listen: false);
      prov.fetchLeaveRequests();
      prov.fetchLeaveBalance();
    }
  }

  Widget _buildTabItem(
    int index,
    IconData iconOff,
    IconData iconOn,
    String label,
  ) {
    final isSelected = _currentIndex == index;
    return Expanded(
      child: GestureDetector(
        onTap: () => _onTabTapped(index),
        behavior: HitTestBehavior.opaque,
        child: SizedBox(
          height: 84,
          child: Stack(
            alignment: Alignment.bottomCenter,
            clipBehavior: Clip.none,
            children: [
              AnimatedPositioned(
                duration: const Duration(milliseconds: 250),
                curve: Curves.easeOutCubic,
                bottom: 8,
                child: Text(
                  label,
                  style: TextStyle(
                    color: isSelected
                        ? const Color(0xFF1E88E5)
                        : Colors.grey.shade500,
                    fontSize: 10.5,
                    fontWeight: isSelected
                        ? FontWeight.w700
                        : FontWeight.w500,
                  ),
                ),
              ),
              AnimatedPositioned(
                duration: const Duration(milliseconds: 250),
                curve: Curves.easeOutCubic,
                bottom: isSelected ? 28 : 26,
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 250),
                  curve: Curves.easeOutCubic,
                  width: isSelected ? 44 : 28,
                  height: isSelected ? 44 : 28,
                  decoration: BoxDecoration(
                    color: isSelected
                        ? const Color(0xFF1E88E5)
                        : Colors.transparent,
                    shape: BoxShape.circle,
                    boxShadow: isSelected
                        ? [
                            BoxShadow(
                              color: const Color(0xFF1E88E5).withValues(alpha: 0.35),
                              blurRadius: 10,
                              offset: const Offset(0, 4),
                            ),
                          ]
                        : const [],
                  ),
                  child: Icon(
                    isSelected ? iconOn : iconOff,
                    color: isSelected ? Colors.white : Colors.grey.shade600,
                    size: isSelected ? 24 : 23,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _getGreeting() {
    final hour = DateTime.now().hour;
    if (hour >= 4 && hour < 11) return 'Selamat pagi';
    if (hour >= 11 && hour < 15) return 'Selamat siang';
    if (hour >= 15 && hour < 18) return 'Selamat sore';
    return 'Selamat malam';
  }

  void _goToPresensi() {
    final presensiProv = Provider.of<PresensiProvider>(context, listen: false);
    if (presensiProv.wfhEnabled || presensiProv.canCheckOut) {
      Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => const PresensiMapScreen()),
      ).then((_) {
        if (!mounted) return;
        presensiProv.syncStatusFromBackend();
      });
    } else {
      _onTabTapped(2);
    }
  }

  // ─── Beranda: welcome + jadwal hari ini ─────────────────────────────────
  Widget _buildBerandaTab() {
    return Consumer4<AuthProvider, ShiftProvider, PresensiProvider, ReceiptProvider>(
      builder: (context, auth, shiftProv, presensiProv, receiptProv, _) {
        final user = auth.user;
        final dept = (user?.department?.isNotEmpty == true)
            ? user!.department!
            : 'No Department';
        final totalUnread = presensiProv.unreadNotificationCount;

        return SafeArea(
          child: RefreshIndicator(
            onRefresh: _refreshHomeData,
            color: const Color(0xFF1E88E5),
            child: SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // ─── Header: Profil Ringkas + Notifikasi ────────────
                  Row(
                    children: [
                      GestureDetector(
                        onTap: () => _onTabTapped(4),
                        child: Container(
                          width: 44,
                          height: 44,
                          decoration: BoxDecoration(
                            color: const Color(0xFF1E88E5).withValues(alpha: 0.1),
                            shape: BoxShape.circle,
                            border: Border.all(
                              color: const Color(0xFF1E88E5).withValues(alpha: 0.2),
                            ),
                          ),
                          child: Center(
                            child: Text(
                              widget.userName.isNotEmpty
                                  ? widget.userName.substring(0, 1).toUpperCase()
                                  : 'U',
                              style: const TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF1E88E5),
                              ),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '${_getGreeting()},',
                              style: TextStyle(
                                fontSize: 12.5,
                                color: Colors.grey.shade600,
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                            Text(
                              widget.userName,
                              style: const TextStyle(
                                fontSize: 17,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF0F172A),
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            const SizedBox(height: 2),
                            Text(
                              dept,
                              style: TextStyle(
                                fontSize: 11.5,
                                color: Colors.grey.shade500,
                                fontWeight: FontWeight.w500,
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ],
                        ),
                      ),
                      // Tombol Notifikasi
                      Stack(
                        clipBehavior: Clip.none,
                        children: [
                          Container(
                            width: 40,
                            height: 40,
                            decoration: BoxDecoration(
                              color: Colors.white,
                              shape: BoxShape.circle,
                              border: Border.all(color: const Color(0xFFE2E8F0)),
                            ),
                            child: IconButton(
                              padding: EdgeInsets.zero,
                              icon: const Icon(
                                Icons.notifications_outlined,
                                color: Color(0xFF334155),
                                size: 20,
                              ),
                              onPressed: () {
                                _showCollectiveLeavesBottomSheet(context);
                              },
                            ),
                          ),
                          if (totalUnread > 0)
                            Positioned(
                              right: -2,
                              top: -2,
                              child: Container(
                                padding: const EdgeInsets.all(4),
                                decoration: const BoxDecoration(
                                  color: Color(0xFFE53935),
                                  shape: BoxShape.circle,
                                ),
                                constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                                child: Text(
                                  '$totalUnread',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 9.5,
                                    fontWeight: FontWeight.bold,
                                  ),
                                  textAlign: TextAlign.center,
                                ),
                              ),
                            ),
                        ],
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),

                  // ─── Banner Sistem (Bila Aktif) ─────────────────────
                  if (presensiProv.hasPendingOfflineSync) ...[
                    _buildOfflineSyncBanner(presensiProv),
                    const SizedBox(height: 14),
                  ],
                  if (shiftProv.hasShiftUpdate) ...[
                    _buildShiftUpdateBanner(shiftProv),
                    const SizedBox(height: 14),
                  ],

                  // ─── Card Utama: Status Presensi Hari Ini ────────────
                  _buildAttendanceCard(presensiProv),
                  const SizedBox(height: 22),

                  // ─── Menu Cepat (Quick Access) ──────────────────────
                  _buildQuickActions(),
                  const SizedBox(height: 22),

                  // ─── Card Jadwal Shift Hari Ini ─────────────────────
                  _buildTodayScheduleCard(shiftProv),
                  const SizedBox(height: 22),

                  // ─── Klaim Struk Terkini ────────────────────────────
                  _buildRecentReceipts(receiptProv),
                  const SizedBox(height: 16),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildAttendanceCard(PresensiProvider presensiProv) {
    final hasCheckedIn = presensiProv.todayMasuk != null;
    final hasCheckedOut = presensiProv.todayPulang != null;

    String statusTitle;
    Color statusBg;
    Color statusText;
    IconData statusIcon;

    if (hasCheckedOut) {
      statusTitle = 'Selesai Hari Ini';
      statusBg = const Color(0xFFECFDF5);
      statusText = const Color(0xFF059669);
      statusIcon = Icons.check_circle_rounded;
    } else if (hasCheckedIn) {
      statusTitle = 'Aktif Bekerja';
      statusBg = const Color(0xFFEFF6FF);
      statusText = const Color(0xFF2563EB);
      statusIcon = Icons.timer_outlined;
    } else {
      statusTitle = 'Belum Presensi';
      statusBg = const Color(0xFFF1F5F9);
      statusText = const Color(0xFF64748B);
      statusIcon = Icons.info_outline_rounded;
    }

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.03),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header Baris: Tanggal & Badge Status
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  Icon(Icons.calendar_today_rounded, size: 14, color: Colors.grey.shade500),
                  const SizedBox(width: 6),
                  Text(
                    presensiProv.todayDateFormatted,
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: Colors.grey.shade700,
                    ),
                  ),
                ],
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: statusBg,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(statusIcon, size: 12, color: statusText),
                    const SizedBox(width: 4),
                    Text(
                      statusTitle,
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: statusText,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          // 2 Kolom: Jam Masuk & Jam Pulang
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: const Color(0xFFF8FAFC),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Jam Masuk',
                        style: TextStyle(fontSize: 11, color: Colors.grey.shade500, fontWeight: FontWeight.w500),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        presensiProv.todayMasuk ?? '-- : --',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: hasCheckedIn ? const Color(0xFF0F172A) : Colors.grey.shade400,
                        ),
                      ),
                      if (presensiProv.todayLateMinutes > 0)
                        Padding(
                          padding: const EdgeInsets.only(top: 2),
                          child: Text(
                            'Telat ${presensiProv.todayLateMinutes} mnt',
                            style: const TextStyle(fontSize: 10, color: Colors.amber, fontWeight: FontWeight.bold),
                          ),
                        ),
                    ],
                  ),
                ),
                Container(
                  height: 36,
                  width: 1,
                  color: Colors.grey.shade200,
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Jam Pulang',
                        style: TextStyle(fontSize: 11, color: Colors.grey.shade500, fontWeight: FontWeight.w500),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        presensiProv.todayPulang ?? '-- : --',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: hasCheckedOut ? const Color(0xFF0F172A) : Colors.grey.shade400,
                        ),
                      ),
                      if (hasCheckedOut)
                        Padding(
                          padding: const EdgeInsets.only(top: 2),
                          child: Text(
                            presensiProv.todayTotalJamKerja,
                            style: const TextStyle(fontSize: 10, color: Color(0xFF059669), fontWeight: FontWeight.bold),
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          // Tombol Tindakan
          if (presensiProv.canCheckIn)
            SizedBox(
              width: double.infinity,
              height: 44,
              child: ElevatedButton.icon(
                onPressed: _goToPresensi,
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E88E5),
                  foregroundColor: Colors.white,
                  elevation: 0,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                icon: const Icon(Icons.fingerprint_rounded, size: 20),
                label: const Text(
                  'Catat Presensi Masuk',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                ),
              ),
            )
          else if (presensiProv.canCheckOut)
            SizedBox(
              width: double.infinity,
              height: 44,
              child: ElevatedButton.icon(
                onPressed: _goToPresensi,
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFFE53935),
                  foregroundColor: Colors.white,
                  elevation: 0,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                icon: const Icon(Icons.logout_rounded, size: 20),
                label: const Text(
                  'Catat Presensi Pulang',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                ),
              ),
            )
          else
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 10),
              decoration: BoxDecoration(
                color: const Color(0xFFECFDF5),
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(Icons.check_circle_rounded, color: Color(0xFF059669), size: 16),
                  SizedBox(width: 6),
                  Text(
                    'Presensi Hari Ini Sudah Lengkap',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: Color(0xFF059669),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildQuickActions() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Akses Cepat',
          style: TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.bold,
            color: Color(0xFF1E293B),
          ),
        ),
        const SizedBox(height: 12),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            _buildActionItem(
              icon: Icons.fingerprint_rounded,
              label: 'Presensi',
              bgColor: const Color(0xFFEFF6FF),
              iconColor: const Color(0xFF2563EB),
              onTap: _goToPresensi,
            ),
            _buildActionItem(
              icon: Icons.camera_alt_outlined,
              label: 'Foto Struk',
              bgColor: const Color(0xFFF0FDF4),
              iconColor: const Color(0xFF16A34A),
              onTap: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const SubmitStep1Screen()),
                ).then((_) {
                  if (!mounted) return;
                  Provider.of<ReceiptProvider>(context, listen: false).fetchMyReceipts();
                });
              },
            ),
            _buildActionItem(
              icon: Icons.event_note_rounded,
              label: 'Ajukan Izin',
              bgColor: const Color(0xFFFFFBEB),
              iconColor: const Color(0xFFD97706),
              onTap: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const AjukanIzinScreen()),
                );
              },
            ),
            _buildActionItem(
              icon: Icons.calendar_month_rounded,
              label: 'Jadwal Shift',
              bgColor: const Color(0xFFFAF5FF),
              iconColor: const Color(0xFF9333EA),
              onTap: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const JadwalShiftScreen()),
                );
              },
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildActionItem({
    required IconData icon,
    required String label,
    required Color bgColor,
    required Color iconColor,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: SizedBox(
        width: 72,
        child: Column(
          children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                color: bgColor,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: iconColor.withValues(alpha: 0.15)),
              ),
              child: Icon(icon, color: iconColor, size: 26),
            ),
            const SizedBox(height: 8),
            Text(
              label,
              style: const TextStyle(
                fontSize: 11.5,
                fontWeight: FontWeight.w600,
                color: Color(0xFF334155),
              ),
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildRecentReceipts(ReceiptProvider receiptProv) {
    final receipts = receiptProv.receipts;
    if (receiptProv.loading && receipts.isEmpty) {
      return const SizedBox.shrink();
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text(
              'Klaim Struk Terkini',
              style: TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.bold,
                color: Color(0xFF1E293B),
              ),
            ),
            GestureDetector(
              onTap: () => _onTabTapped(1),
              child: const Text(
                'Lihat Semua',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF1E88E5),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        if (receipts.isEmpty)
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    shape: BoxShape.circle,
                  ),
                  child: Icon(Icons.receipt_long_outlined, size: 20, color: Colors.grey.shade400),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Belum ada pengajuan struk',
                        style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Colors.grey.shade700),
                      ),
                      Text(
                        'Foto struk untuk membuat klaim baru.',
                        style: TextStyle(fontSize: 11, color: Colors.grey.shade500),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          )
        else
          ...receipts.take(2).map((r) => _buildMiniReceiptCard(r)),
      ],
    );
  }

  Widget _buildMiniReceiptCard(ReceiptRecord r) {
    Color statusColor;
    Color statusBg;
    switch (r.status) {
      case 'paid':
        statusColor = const Color(0xFF00695C);
        statusBg = const Color(0xFFE0F2F1);
        break;
      case 'approved':
        statusColor = const Color(0xFF1565C0);
        statusBg = const Color(0xFFE3F2FD);
        break;
      case 'rejected':
        statusColor = const Color(0xFFC62828);
        statusBg = const Color(0xFFFFEBEE);
        break;
      case 'pending':
      case 'submitted':
      default:
        statusColor = const Color(0xFFE65100);
        statusBg = const Color(0xFFFFF3E0);
        break;
    }

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: const Color(0xFFF1F5F9),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.receipt_outlined, color: Color(0xFF64748B), size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  r.displayMerchant,
                  style: const TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                    color: Color(0xFF1E293B),
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 2),
                Text(
                  formatDateIndonesian(r.receiptDate ?? r.createdAt),
                  style: TextStyle(fontSize: 11, color: Colors.grey.shade500),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                formatCurrency(r.displayAmount),
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF0F172A),
                ),
              ),
              const SizedBox(height: 4),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: statusBg,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  r.displayStatus,
                  style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    color: statusColor,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildOfflineSyncBanner(PresensiProvider prov) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: Colors.amber.shade50,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.amber.shade300),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: Colors.amber.shade100,
              shape: BoxShape.circle,
            ),
            child: Icon(Icons.cloud_off_rounded, color: Colors.amber.shade900, size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Presensi Offline (${prov.offlineQueue.length})',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                    color: Colors.amber.shade900,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  'Tersimpan lokal, siap disinkronkan ke server.',
                  style: TextStyle(
                    fontSize: 11.5,
                    color: Colors.amber.shade800,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          prov.isSyncingOffline
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.amber),
                )
              : TextButton(
                  onPressed: () async {
                    try {
                      final res = await prov.syncOfflineQueue();
                      if (!mounted) return;
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(
                          content: Text('Sinkronisasi selesai: ${res['synced'] ?? 0} data berhasil dikirim.'),
                          backgroundColor: Colors.green,
                        ),
                      );
                    } catch (e) {
                      if (!mounted) return;
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(
                          content: Text('Gagal menghubungkan ke server. Silakan coba lagi saat sinyal stabil.'),
                          backgroundColor: Colors.red,
                        ),
                      );
                    }
                  },
                  style: TextButton.styleFrom(
                    backgroundColor: Colors.amber.shade800,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                  child: const Text('Sync', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                ),
        ],
      ),
    );
  }

  Widget _buildTodayScheduleCard(ShiftProvider prov) {
    if (prov.loading) {
      return const ShimmerLoading(
        child: SkeletonShiftCard(),
      );
    }

    if (prov.source == 'none' || prov.shiftInfo == null) {
      return const SizedBox.shrink();
    }

    final todayDow = DateTime.now().weekday % 7; // 0=Minggu
    final schedule = prov.getScheduleForDayOfWeek(todayDow);
    final isOff = schedule?.isOff ?? false;

    Color shiftColor;
    try {
      final hex = (prov.shiftInfo!.color).replaceAll('#', '');
      shiftColor = Color(int.parse('FF$hex', radix: 16));
    } catch (_) {
      shiftColor = const Color(0xFF1E88E5);
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text(
              'Jadwal Shift',
              style: TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.bold,
                color: Color(0xFF1E293B),
              ),
            ),
            GestureDetector(
              onTap: () => Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const JadwalShiftScreen()),
              ),
              child: const Text(
                'Lihat Jadwal',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF1E88E5),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        GestureDetector(
          onTap: () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const JadwalShiftScreen()),
          ),
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: const Color(0xFFE2E8F0)),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.03),
                  blurRadius: 10,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: shiftColor.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Icon(
                    isOff ? Icons.weekend_outlined : Icons.schedule_rounded,
                    color: shiftColor,
                    size: 24,
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        prov.shiftInfo!.name,
                        style: const TextStyle(
                          fontSize: 14.5,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF0F172A),
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        isOff
                            ? 'Hari Libur Shift'
                            : schedule != null && schedule.workStartTime != null
                                ? '${_shortTime(schedule.workStartTime!)} — ${_shortTime(schedule.workEndTime!)} WIB'
                                : 'Tidak ada jadwal',
                        style: TextStyle(
                          fontSize: 12.5,
                          color: isOff ? Colors.red.shade600 : Colors.grey.shade600,
                          fontWeight: isOff ? FontWeight.bold : FontWeight.w500,
                        ),
                      ),
                    ],
                  ),
                ),
                const Icon(Icons.chevron_right_rounded, color: Color(0xFF94A3B8), size: 22),
              ],
            ),
          ),
        ),
      ],
    );
  }

  // ─── Banner: notifikasi shift diubah oleh HRD ──────────────────────────
  Widget _buildShiftUpdateBanner(ShiftProvider prov) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFE3F2FD),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFF90CAF9)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.campaign, color: Color(0xFF1E88E5), size: 22),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Jadwal shift Anda diperbarui!',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                    color: const Color(0xFF0D47A1),
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  prov.shiftUpdateNote ?? '',
                  style: const TextStyle(
                    fontSize: 12,
                    color: Color(0xFF1565C0),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          // Tombol dismiss — tandai sudah dilihat
          GestureDetector(
            onTap: () => prov.dismissShiftUpdate(),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              decoration: BoxDecoration(
                color: const Color(0xFF1E88E5),
                borderRadius: BorderRadius.circular(8),
              ),
              child: const Text(
                'OK',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.bold,
                  color: Colors.white,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  String _shortTime(String time) {
    final parts = time.split(':');
    if (parts.length >= 2) return '${parts[0]}:${parts[1]}';
    return time;
  }

  void _showCollectiveLeavesBottomSheet(BuildContext context) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        return Container(
          height: MediaQuery.of(ctx).size.height * 0.7,
          decoration: const BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
          ),
          child: Column(
            children: [
              Container(
                margin: const EdgeInsets.symmetric(vertical: 12),
                height: 4,
                width: 40,
                decoration: BoxDecoration(
                  color: Colors.grey[300],
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const Padding(
                padding: EdgeInsets.all(16.0),
                child: Text(
                  'Pesan & Notifikasi',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
              const Divider(height: 1),
              Expanded(
                child: Consumer<PresensiProvider>(
                  builder: (context, presensiProv, _) {
                    final banners = presensiProv.activeCollectiveLeaveBanners;
                    final cancellations = presensiProv.leaveCancellations;
                    if (banners.isEmpty && cancellations.isEmpty) {
                      return const Center(
                        child: Text(
                          'Tidak ada pesan baru.',
                          style: TextStyle(color: Colors.grey),
                        ),
                      );
                    }
                    return ListView(
                      padding: const EdgeInsets.all(16),
                      children: [
                        // Kartu notifikasi pembatalan cuti bersama / cuti mandiri
                        ...cancellations.map((c) => Padding(
                          padding: const EdgeInsets.only(bottom: 16),
                          child: _buildCancellationBanner(presensiProv, c),
                        )),
                        // Kartu ajakan cuti bersama mendatang
                        ...banners.map((b) => Padding(
                          padding: const EdgeInsets.only(bottom: 16),
                          child: _buildCollectiveLeaveBanner(presensiProv, b),
                        )),
                      ],
                    );
                  },
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  // ─── Banner: pemberitahuan cuti bersama / cuti mandiri dibatalkan ───
  Widget _buildCancellationBanner(
    PresensiProvider prov,
    LeaveCancellationRecord c,
  ) {
    final bool isCollective = c.type == 'collective_leave_cancelled';
    final Color headerColor = isCollective ? const Color(0xFFC62828) : const Color(0xFFD84315);
    final Color bgColor = isCollective ? const Color(0xFFFDECEA) : const Color(0xFFFBE9E7);
    final Color borderColor = isCollective ? const Color(0xFFEF9A9A) : const Color(0xFFFFCCBC);

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                isCollective ? Icons.event_busy : Icons.cancel_outlined,
                color: headerColor,
                size: 22,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  c.title,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                    color: headerColor,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          if (c.name.isNotEmpty) ...[
            Text(
              c.name,
              style: const TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.bold,
                color: Color(0xFF37474F),
              ),
            ),
            const SizedBox(height: 2),
          ],
          if (c.date.isNotEmpty) ...[
            Text(
              c.dateLabel.isNotEmpty ? c.dateLabel : _formatDateLabel(c.date),
              style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
            ),
            const SizedBox(height: 8),
          ],
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: borderColor),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.info_outline, size: 16, color: headerColor),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    c.message.isNotEmpty
                        ? c.message
                        : 'Cuti bersama dibatalkan oleh HRD. Saldo cuti Anda telah dikembalikan.',
                    style: const TextStyle(
                      fontSize: 12,
                      height: 1.4,
                      color: Color(0xFF37474F),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: headerColor,
                foregroundColor: Colors.white,
                elevation: 0,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10),
                ),
                padding: const EdgeInsets.symmetric(vertical: 12),
              ),
              onPressed: () => prov.dismissCancellation(c.id),
              child: const Text(
                'Mengerti',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ─── Banner: cuti bersama mendatang, karyawan pilih Ya (ikut) / Tidak ───
  Widget _buildCollectiveLeaveBanner(
    PresensiProvider prov,
    CollectiveLeaveRecord c,
  ) {
    // Hitung sisa hari menuju tanggal cuti bersama
    int daysLeft = 0;
    try {
      final target = DateTime.parse(c.date);
      final today = DateTime(
        DateTime.now().year,
        DateTime.now().month,
        DateTime.now().day,
      );
      daysLeft = target.difference(today).inDays;
    } catch (_) {}

    // Saldo cuti tidak cukup → user tidak bisa ikut cuti bersama.
    // (Kebijakan ditetapkan 'block' sejak 2026-08-20: ikut hanya jika saldo cukup.)
    final bool saldoCukup = c.remainingQuota >= c.totalDays;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF8E1),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFFFB300)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.campaign, color: Color(0xFFE65100), size: 22),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Cuti Bersama Mendatang',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                    color: const Color(0xFFE65100),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            c.name,
            style: const TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.bold,
              color: Color(0xFF5D4037),
            ),
          ),
          const SizedBox(height: 2),
          Text(
            '${_formatDateLabel(c.date)} • H-${daysLeft < 0 ? 0 : daysLeft}',
            style: const TextStyle(fontSize: 12, color: Color(0xFF6D4C41)),
          ),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: const Color(0xFFFFB300)),
            ),
            child: Text(
              'Sisa saldo cuti Anda: ${c.remainingQuota} hari',
              style: const TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w600,
                color: Color(0xFF6D4C41),
              ),
            ),
          ),
          if (!saldoCukup) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: const Color(0xFFFDECEA),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFFE53935)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.error_outline,
                      color: Color(0xFFC62828), size: 18),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      'Saldo Anda tidak cukup untuk mengikuti cuti bersama '
                      '(butuh ${c.totalDays} hari, sisa ${c.remainingQuota} hari).',
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFFC62828),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFFE53935),
                    foregroundColor: Colors.white,
                    elevation: 0,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  onPressed: () => _respondCollective(prov, c.id, 'declined'),
                  child: const Text(
                    'Tidak Ikut',
                    style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: saldoCukup
                        ? const Color(0xFF2E7D32)
                        : Colors.grey.shade400,
                    foregroundColor: Colors.white,
                    elevation: 0,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  onPressed: saldoCukup
                      ? () => _respondCollective(prov, c.id, 'accepted')
                      : null,
                  child: const Text(
                    'Ya, Saya Ikut',
                    style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _respondCollective(
    PresensiProvider prov,
    int holidayId,
    String response,
  ) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      await prov.respondCollectiveLeave(holidayId, response);
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            response == 'accepted'
                ? 'Anda terdaftar ikut cuti bersama.'
                : 'Anda tidak ikut cuti bersama.',
          ),
          backgroundColor:
              response == 'accepted'
                  ? const Color(0xFF2E7D32)
                  : const Color(0xFFE53935),
          behavior: SnackBarBehavior.floating,
        ),
      );
    } catch (e) {
      // Tampilkan pesan error dari API (mis. saldo tidak cukup)
      String msg = 'Gagal menyimpan pilihan. Coba lagi.';
      if (e.toString().isNotEmpty) {
        // ApiException biasanya membawa pesan dari backend
        final raw = e.toString().replaceAll('Exception: ', '');
        if (raw.isNotEmpty) msg = raw;
      }
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(
          content: Text(msg),
          backgroundColor: const Color(0xFFC62828),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  String _formatDateLabel(String dateStr) {
    try {
      const bulan = [
        'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
      ];
      final d = DateTime.parse(dateStr);
      return '${d.day} ${bulan[d.month - 1]} ${d.year}';
    } catch (_) {
      return dateStr;
    }
  }
}
