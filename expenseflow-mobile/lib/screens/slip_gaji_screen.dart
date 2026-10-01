import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../models/payslip_model.dart';
import '../providers/payslip_provider.dart';
import '../utils.dart';
import '../widgets/skeleton.dart';
import 'detail_slip_gaji_screen.dart';

/// Layar daftar Slip Gaji karyawan.
///
/// Menampilkan slip gaji yang sudah final (batch payroll disetujui/dibayar).
/// Ketuk salah satu kartu untuk melihat rincian & mengunduh PDF.
class SlipGajiScreen extends StatefulWidget {
  const SlipGajiScreen({super.key});

  @override
  State<SlipGajiScreen> createState() => _SlipGajiScreenState();
}

class _SlipGajiScreenState extends State<SlipGajiScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Provider.of<PayslipProvider>(context, listen: false).fetchPayslips();
    });
  }

  Future<void> _refresh() async {
    await Provider.of<PayslipProvider>(context, listen: false)
        .fetchPayslips(forceRefresh: true);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.grey.shade50,
      appBar: AppBar(
        title: const Text(
          'Slip Gaji',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
        ),
        backgroundColor: Colors.white,
        foregroundColor: Colors.black87,
        elevation: 0.5,
      ),
      body: Consumer<PayslipProvider>(
        builder: (context, prov, _) {
          if (prov.loadingList && prov.payslips.isEmpty) {
            return _buildLoading();
          }
          if (prov.errorList != null && prov.payslips.isEmpty) {
            return _buildError(prov.errorList!);
          }
          return RefreshIndicator(
            onRefresh: _refresh,
            child: prov.payslips.isEmpty
                ? _buildEmpty()
                : ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
                    itemCount: prov.payslips.length,
                    itemBuilder: (context, i) =>
                        _buildPayslipCard(prov.payslips[i]),
                  ),
          );
        },
      ),
    );
  }

  Widget _buildLoading() {
    return ShimmerLoading(
      child: ListView(
        physics: const NeverScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
        children: const [
          SkeletonListTileItem(),
          SkeletonListTileItem(),
          SkeletonListTileItem(),
          SkeletonListTileItem(),
        ],
      ),
    );
  }

  Widget _buildError(String message) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        SizedBox(height: MediaQuery.of(context).size.height * 0.25),
        const Icon(Icons.cloud_off_rounded, size: 56, color: Colors.grey),
        const SizedBox(height: 12),
        const Center(
          child: Text(
            'Gagal memuat slip gaji',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
          ),
        ),
        const SizedBox(height: 6),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 32),
          child: Text(
            message,
            textAlign: TextAlign.center,
            style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
          ),
        ),
        const SizedBox(height: 16),
        Center(
          child: ElevatedButton.icon(
            onPressed: _refresh,
            icon: const Icon(Icons.refresh, size: 18),
            label: const Text('Coba Lagi'),
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.indigo,
              foregroundColor: Colors.white,
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildEmpty() {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        SizedBox(height: MediaQuery.of(context).size.height * 0.22),
        Icon(Icons.receipt_long_outlined,
            size: 64, color: Colors.grey.shade400),
        const SizedBox(height: 14),
        const Center(
          child: Text(
            'Belum ada slip gaji',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
          ),
        ),
        const SizedBox(height: 6),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 40),
          child: Text(
            'Slip gaji Anda akan muncul di sini setelah proses penggajian '
            'disetujui oleh bagian keuangan.',
            textAlign: TextAlign.center,
            style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
          ),
        ),
      ],
    );
  }

  Widget _buildPayslipCard(Payslip slip) {
    final statusInfo = _statusInfo(slip.status);

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFF1F5F9)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.02),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: () {
            Navigator.push(
              context,
              MaterialPageRoute(
                builder: (_) => DetailSlipGajiScreen(
                  payslipId: slip.id,
                  periodLabel: slip.periodLabel,
                ),
              ),
            );
          },
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Container(
                  width: 46,
                  height: 46,
                  decoration: BoxDecoration(
                    color: Colors.indigo.withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: const Icon(Icons.receipt_long,
                      color: Colors.indigo, size: 24),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        slip.periodLabel,
                        style: const TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 15,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Gaji bersih',
                        style: TextStyle(
                          fontSize: 11,
                          color: Colors.grey.shade500,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        formatCurrency(slip.net),
                        style: const TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 16,
                          color: Colors.green,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: statusInfo.$2.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text(
                        statusInfo.$1,
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w600,
                          color: statusInfo.$2,
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    Icon(Icons.chevron_right,
                        color: Colors.grey.shade400, size: 20),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Kembalikan (label, warna) untuk status slip.
  (String, Color) _statusInfo(String status) {
    switch (status) {
      case 'paid':
        return ('Dibayar', Colors.green);
      case 'approved':
        return ('Disetujui', Colors.indigo);
      default:
        if (status.isEmpty) return ('Final', Colors.blueGrey);
        return (status[0].toUpperCase() + status.substring(1), Colors.blueGrey);
    }
  }
}
