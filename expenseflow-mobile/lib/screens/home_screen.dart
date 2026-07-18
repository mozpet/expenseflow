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

class HomeScreen extends StatefulWidget {
  final String userName;
  const HomeScreen({super.key, required this.userName});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  int _currentIndex = 0;
  final List<bool> _visited = [true, false, false, false, false];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Provider.of<ReceiptProvider>(context, listen: false).fetchMyReceipts();
      Provider.of<ShiftProvider>(context, listen: false).fetchMySchedule();
      // Sync status presensi saat pertama buka app
      Provider.of<PresensiProvider>(
        context,
        listen: false,
      ).syncStatusFromBackend();
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
      Provider.of<PresensiProvider>(
        context,
        listen: false,
      ).syncStatusFromBackend();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBody: true,
      body: Stack(
        children: [
          Positioned.fill(
            bottom: 70,
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
            height: 100,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Positioned(
                  left: 0,
                  right: 0,
                  bottom: 0,
                  height: 70,
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
    return Consumer2<AuthProvider, ShiftProvider>(
      builder: (context, auth, shiftProv, _) {
        final user = auth.user;
        final dept = (user?.department?.isNotEmpty == true)
            ? user!.department!
            : 'No Department';

        return SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
            child: Column(
              children: [
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
                const SizedBox(height: 32),
                // Card jadwal hari ini
                _buildTodayScheduleCard(shiftProv),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildTodayScheduleCard(ShiftProvider prov) {
    if (prov.loading) {
      return Container(
        width: double.infinity,
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: const [
            BoxShadow(color: Colors.black12, blurRadius: 8, offset: Offset(0, 2)),
          ],
        ),
        child: const Center(
          child: SizedBox(
            width: 20, height: 20,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
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
      shiftColor = const Color(0xFF6366f1);
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

  String _shortTime(String time) {
    final parts = time.split(':');
    if (parts.length >= 2) return '${parts[0]}:${parts[1]}';
    return time;
  }
}
