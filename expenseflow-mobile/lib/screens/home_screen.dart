import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'submit_step1_screen.dart';
import 'riwayat_screen.dart';
import 'presensi_history_screen.dart';
import 'presensi_map_screen.dart';
import 'izin_cuti_screen.dart';
import 'profile_screen.dart';
import '../presensi_provider.dart';
import '../providers/auth_provider.dart';
import '../providers/receipt_provider.dart';

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
      // Sync status presensi saat pertama buka app
      Provider.of<PresensiProvider>(context, listen: false).syncStatusFromBackend();
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
      Provider.of<PresensiProvider>(context, listen: false).syncStatusFromBackend();
    }
  }

  @override
  Widget build(BuildContext context) {
    final wfhEnabled = Provider.of<PresensiProvider>(context).wfhEnabled;

    return Scaffold(
      body: IndexedStack(
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
      floatingActionButton: _buildFab(wfhEnabled),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _currentIndex,
        onTap: (index) {
          setState(() {
            _currentIndex = index;
            _visited[index] = true;
          });
          // Tab Struk: refresh data saat dibuka
          if (index == 1) {
            Provider.of<ReceiptProvider>(context, listen: false)
                .fetchMyReceipts();
          }
          // Tab Presensi: sync status dari backend (deteksi auto-checkout)
          if (index == 2) {
            Provider.of<PresensiProvider>(context, listen: false)
                .syncStatusFromBackend();
          }
          // Tab Izin & Cuti: selalu ambil data terbaru dari backend
          if (index == 3) {
            final prov =
                Provider.of<PresensiProvider>(context, listen: false);
            prov.fetchLeaveRequests();
            prov.fetchLeaveBalance();
          }
        },
        type: BottomNavigationBarType.fixed,
        selectedItemColor: Theme.of(context).primaryColor,
        unselectedItemColor: Colors.grey,
        showUnselectedLabels: true,
        items: const [
          BottomNavigationBarItem(
            icon: Icon(Icons.home_outlined),
            activeIcon: Icon(Icons.home),
            label: 'Beranda',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.receipt_long_outlined),
            activeIcon: Icon(Icons.receipt_long),
            label: 'Struk',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.assignment_ind_outlined),
            activeIcon: Icon(Icons.assignment_ind),
            label: 'Presensi',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.event_note_outlined),
            activeIcon: Icon(Icons.event_note),
            label: 'Izin & Cuti',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.person_outline),
            activeIcon: Icon(Icons.person),
            label: 'Profil',
          ),
        ],
      ),
    );
  }

  Widget? _buildFab(bool wfhEnabled) {
    // FAB kamera scan struk — tab Struk (index 1)
    if (_currentIndex == 1) {
      return FloatingActionButton(
        onPressed: () => Navigator.push(context,
            MaterialPageRoute(builder: (_) => const SubmitStep1Screen())),
        backgroundColor: Theme.of(context).primaryColor,
        foregroundColor: Colors.white,
        shape: const CircleBorder(),
        child: const Icon(Icons.photo_camera, size: 28),
      );
    }
    // FAB presensi — tab Presensi (index 2), hanya jika HRD izinkan WFH
    if (_currentIndex == 2) {
      if (!wfhEnabled) return null;
      return FloatingActionButton(
        onPressed: () => Navigator.push(context,
            MaterialPageRoute(builder: (_) => const PresensiMapScreen())),
        backgroundColor: const Color(0xFF0088FF),
        foregroundColor: Colors.white,
        shape: const CircleBorder(),
        child: const Icon(Icons.add, size: 28),
      );
    }
    return null;
  }

  // ─── Beranda: hanya welcome + departemen ─────────────────────────────────
  Widget _buildBerandaTab() {
    return Consumer<AuthProvider>(
      builder: (context, auth, _) {
        final user = auth.user;
        final dept = (user?.department?.isNotEmpty == true)
            ? user!.department!
            : 'No Department';

        return SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Ikon akun
                  Container(
                    width: 80,
                    height: 80,
                    decoration: BoxDecoration(
                      color: Theme.of(context).primaryColor.withValues(alpha: 0.1),
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
                        horizontal: 14, vertical: 6),
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
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}
