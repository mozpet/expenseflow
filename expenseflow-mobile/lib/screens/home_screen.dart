import 'package:flutter/material.dart';
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

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
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
    final navBarHeight = 70.0 + bottomPadding;
    final totalBarHeight = 100.0 + bottomPadding;

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
                    decoration: const BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.vertical(
                        top: Radius.circular(20),
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black12,
                          blurRadius: 10,
                          offset: Offset(0, -2),
                        ),
                      ],
                    ),
                  ),
                ),
                Positioned.fill(
                  child: Padding(
                    padding: EdgeInsets.only(bottom: bottomPadding),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        _buildTabItem(0, Icons.home_outlined, Icons.home, 'Home'),
                        _buildTabItem(
                          1,
                          Icons.receipt_long_outlined,
                          Icons.receipt_long,
                          'Struk',
                        ),
                        _buildTabItem(
                          2,
                          Icons.fingerprint,
                          Icons.fingerprint,
                          'Presensi',
                        ),
                        _buildTabItem(
                          3,
                          Icons.event_note_outlined,
                          Icons.event_note,
                          'Izin/Cuti',
                        ),
                        _buildTabItem(
                          4,
                          Icons.person_outline,
                          Icons.person,
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
    // Tab Presensi: sync status dari backend
    if (index == 2) {
      Provider.of<PresensiProvider>(
        context,
        listen: false,
      ).syncStatusFromBackend();
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
          height: 100,
          child: Stack(
            alignment: Alignment.bottomCenter,
            clipBehavior: Clip.none,
            children: [
              AnimatedPositioned(
                duration: const Duration(milliseconds: 300),
                curve: Curves.easeOutBack,
                bottom: isSelected ? 10 : 14,
                child: AnimatedOpacity(
                  duration: const Duration(milliseconds: 300),
                  opacity: isSelected ? 1.0 : 0.7,
                  child: Text(
                    label,
                    style: TextStyle(
                      color: isSelected
                          ? const Color(0xFF1E88E5)
                          : Colors.grey.shade600,
                      fontSize: 11,
                      fontWeight: isSelected
                          ? FontWeight.bold
                          : FontWeight.normal,
                    ),
                  ),
                ),
              ),
              AnimatedPositioned(
                duration: const Duration(milliseconds: 300),
                curve: Curves.easeOutBack,
                bottom: isSelected ? 38 : 32,
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 300),
                  curve: Curves.easeOutBack,
                  width: isSelected ? 56 : 32,
                  height: isSelected ? 56 : 32,
                  decoration: BoxDecoration(
                    color: isSelected
                        ? const Color(0xFF1E88E5)
                        : Colors.transparent,
                    shape: BoxShape.circle,
                    boxShadow: isSelected
                        ? const [
                            BoxShadow(
                              color: Color(0x661E88E5),
                              blurRadius: 8,
                              offset: Offset(0, 4),
                            ),
                          ]
                        : const [
                            BoxShadow(
                              color: Colors.transparent,
                              blurRadius: 8,
                              offset: Offset(0, 4),
                            ),
                          ],
                  ),
                  child: Icon(
                    isSelected ? iconOn : iconOff,
                    color: isSelected ? Colors.white : Colors.grey.shade600,
                    size: isSelected ? 32 : 26,
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
        final collectiveBanners = presensiProv.activeCollectiveLeaveBanners;

        return SafeArea(
          child: RefreshIndicator(
            onRefresh: _refreshHomeData,
            child: SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
              child: Column(
                children: [
                  // Baris Header: Reload Button + Notifikasi Icon
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      // Tombol Reload/Refresh Data
                      Container(
                        decoration: BoxDecoration(
                          color: Colors.grey.shade100,
                          shape: BoxShape.circle,
                        ),
                        child: IconButton(
                          icon: _isReloading
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(strokeWidth: 2),
                                )
                              : const Icon(Icons.refresh, color: Colors.black87),
                          tooltip: 'Muat Ulang Data',
                          onPressed: _refreshHomeData,
                        ),
                      ),
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
                          if (collectiveBanners.isNotEmpty)
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
                                  '${collectiveBanners.length}',
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
                    if (banners.isEmpty) {
                      return const Center(
                        child: Text(
                          'Tidak ada pesan baru.',
                          style: TextStyle(color: Colors.grey),
                        ),
                      );
                    }
                    return ListView.separated(
                      padding: const EdgeInsets.all(16),
                      itemCount: banners.length,
                      separatorBuilder: (context, index) => const SizedBox(height: 16),
                      itemBuilder: (context, index) {
                        return _buildCollectiveLeaveBanner(presensiProv, banners[index]);
                      },
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
