import 'package:flutter/material.dart';

class NotificationScreen extends StatelessWidget {
  const NotificationScreen({super.key});

  @override
  Widget build(BuildContext context) {
    // Scaffold without drawer or persistent appbar back button
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifikasi'),
        automaticallyImplyLeading: true,
      ),
      body: ListView(
        padding: const EdgeInsets.symmetric(vertical: 8),
        children: [
          _buildNotifItem(
            'Pengajuan Struk Ditolak',
            'Pengajuan #EXP-2026-0792 senilai Rp 250.000 di Pertamina SPBU ditolak oleh Finance.',
            'Hari ini, 14:02',
            Colors.red,
          ),
          _buildNotifItem(
            'Pengajuan Struk Disetujui',
            'Pengajuan #EXP-2026-0812 senilai Rp 320.000 di Grab Food telah disetujui untuk dicairkan.',
            'Kemarin, 16:30',
            Colors.green,
          ),
          _buildNotifItem(
            'Tips Sukses OCR Struk',
            'Pastikan mengambil foto struk di tempat terang dengan kamera tegak lurus untuk menekan tingkat kegagalan pembacaan sistem.',
            '26 Mei 2026',
            Colors.blue,
          ),
          _buildNotifItem(
            'Pengajuan Berhasil Dikirim',
            'Pengajuan #EXP-2026-0847 senilai Rp 187.500 telah dikunci & sedang diantrekan di Finance.',
            '26 Mei 2026',
            Colors.blue,
          ),
        ],
      ),
    );
  }

  Widget _buildNotifItem(
    String title,
    String desc,
    String time,
    Color dotColor,
  ) {
    return Container(
      decoration: const BoxDecoration(
        border: Border(
          bottom: BorderSide(color: Color(0xFFEEEEEE), width: 0.8),
        ),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 16.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Dot Indicator representing statuses
          Container(
            margin: const EdgeInsets.only(top: 4, right: 12),
            width: 8,
            height: 8,
            decoration: BoxDecoration(color: dotColor, shape: BoxShape.circle),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 14,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  desc,
                  style: TextStyle(
                    color: Colors.grey.shade700,
                    fontSize: 12,
                    height: 1.4,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  time,
                  style: TextStyle(color: Colors.grey.shade400, fontSize: 11),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
