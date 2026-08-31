import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:provider/provider.dart';
import 'riwayat_screen.dart';
import 'presensi_history_screen.dart';
import 'izin_cuti_screen.dart';
import 'profile_screen.dart';
import 'jadwal_shift_screen.dart';
import '../presensi_provider.dart';
import '../providers/auth_provider.dart';
import '../providers/receipt_provider.dart';
import '../providers/shift_provider.dart';
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

  // ─── Beranda: welcome + jadwal hari ini ─────────────────────────────────
  Widget _buildBerandaTab() {
    return Consumer3<AuthProvider, ShiftProvider, PresensiProvider>(
      builder: (context, auth, shiftProv, presensiProv, _) {
        final user = auth.user;
        final dept = (user?.department?.isNotEmpty == true)
            ? user!.department!
            : 'No Department';
        final totalUnread = presensiProv.unreadNotificationCount;

        return SafeArea(
          child: RefreshIndicator(
            onRefresh: _refreshHomeData,
            child: SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
              child: Column(
                children: [
                  // Baris Header: Notifikasi Icon
                  Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      // Notifikasi Icon
                      Stack(
                        clipBehavior: Clip.none,
                        children: [
                          Container(
                            decoration: BoxDecoration(
                              color: Colors.grey.shade100,
                              shape: BoxShape.circle,
                            ),
                            child: IconButton(
                              icon: const Icon(Icons.mail_outline, color: Colors.black87),
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
                                padding: const EdgeInsets.all(5),
                                decoration: const BoxDecoration(
                                  color: Color(0xFFE53935),
                                  shape: BoxShape.circle,
                                ),
                                child: Text(
                                  '$totalUnread',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ],
                  ),
                // Ikon akun
                Container(
                  width: 80,
                  height: 80,
                  decoration: BoxDecoration(
                    color: Theme.of(
                      context,
                    ).primaryColor.withValues(alpha: 0.1),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    Icons.account_circle_outlined,
                    size: 48,
                    color: Theme.of(context).primaryColor,
                  ),
                ),
                const SizedBox(height: 20),
                // Welcome
                Text(
                  'Welcome,',
                  style: TextStyle(
                    fontSize: 16,
                    color: Colors.grey.shade500,
                    fontWeight: FontWeight.w400,
                  ),
                ),
                const SizedBox(height: 4),
                // Nama user
                Text(
                  widget.userName,
                  style: TextStyle(
                    fontSize: 26,
                    fontWeight: FontWeight.bold,
                    color: Theme.of(context).primaryColor,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                // Departemen
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 14,
                    vertical: 6,
                  ),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    dept,
                    style: TextStyle(
                      fontSize: 14,
                      color: Colors.grey.shade600,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
                const SizedBox(height: 24),
                // Banner: shift baru diubah oleh HRD
                if (shiftProv.hasShiftUpdate) ...[
                  _buildShiftUpdateBanner(shiftProv),
                  const SizedBox(height: 12),
                ],
                // Card jadwal hari ini
                _buildTodayScheduleCard(shiftProv),
              ],
            ),
          ),
        ),
      );
      },
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
      shiftColor = const Color(0xFF9CA3AF);
    }

    return GestureDetector(
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => const JadwalShiftScreen()),
      ),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: const [
            BoxShadow(color: Colors.black12, blurRadius: 8, offset: Offset(0, 2)),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: shiftColor.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Icon(Icons.schedule, color: shiftColor, size: 20),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Jadwal Hari Ini',
                          style: TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                              color: Colors.grey.shade600)),
                      Text(prov.shiftInfo!.name,
                          style: TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                              color: shiftColor)),
                    ],
                  ),
                ),
                Icon(Icons.chevron_right, color: Colors.grey.shade400),
              ],
            ),
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: isOff ? Colors.red.shade50 : Colors.green.shade50,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: isOff ? Colors.red.shade200 : Colors.green.shade200,
                ),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    isOff ? Icons.weekend : Icons.access_time,
                    size: 18,
                    color: isOff ? Colors.red.shade600 : Colors.green.shade700,
                  ),
                  const SizedBox(width: 8),
                  Text(
                    isOff
                        ? 'Hari Libur Shift'
                        : schedule != null && schedule.workStartTime != null
                            ? '${_shortTime(schedule.workStartTime!)} — ${_shortTime(schedule.workEndTime!)}'
                            : 'Tidak ada jadwal',
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.bold,
                      color: isOff ? Colors.red.shade700 : Colors.green.shade800,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
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
