import 'dart:io';

import 'package:flutter/material.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';

import '../models/payslip_model.dart';
import '../providers/payslip_provider.dart';
import '../services/api_service.dart';
import '../utils.dart';
import '../widgets/skeleton.dart';

/// Layar rincian satu Slip Gaji: identitas karyawan, ringkasan kehadiran,
/// daftar pendapatan & potongan, ringkasan neto, serta tombol Unduh & Bagikan PDF.
class DetailSlipGajiScreen extends StatefulWidget {
  final int payslipId;
  final String periodLabel;

  const DetailSlipGajiScreen({
    super.key,
    required this.payslipId,
    required this.periodLabel,
  });

  @override
  State<DetailSlipGajiScreen> createState() => _DetailSlipGajiScreenState();
}

class _DetailSlipGajiScreenState extends State<DetailSlipGajiScreen> {
  bool _downloading = false;
  bool _sharing = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Provider.of<PayslipProvider>(context, listen: false)
          .fetchDetail(widget.payslipId);
    });
  }

  Future<void> _refresh() async {
    await Provider.of<PayslipProvider>(context, listen: false)
        .fetchDetail(widget.payslipId, forceRefresh: true);
  }

  /// Ambil bytes PDF slip dari server & simpan ke direktori sementara.
  /// Dipakai bersama oleh _downloadPdf() (buka di viewer) & _sharePdf() (bagikan).
  Future<File> _fetchPdfFile() async {
    final bytes = await ApiService.downloadPayslipPdf(widget.payslipId);
    final dir = await getTemporaryDirectory();
    final fileName = 'slip-gaji-${widget.payslipId}.pdf';
    final file = File('${dir.path}/$fileName');
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }

  /// Unduh PDF slip → simpan ke direktori sementara → buka di viewer sistem.
  Future<void> _downloadPdf() async {
    if (_downloading) return;
    setState(() => _downloading = true);
    try {
      final file = await _fetchPdfFile();

      final result = await OpenFilex.open(file.path);
      if (!mounted) return;
      if (result.type != ResultType.done) {
        _showSnack(
          'Slip tersimpan, namun tidak ada aplikasi untuk membuka PDF.',
          isError: true,
        );
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      _showSnack(e.message, isError: true);
    } catch (_) {
      if (!mounted) return;
      _showSnack('Gagal mengunduh slip gaji. Silakan coba lagi.',
          isError: true);
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  /// Bagikan PDF slip ke aplikasi lain (WhatsApp, email, dll.).
  Future<void> _sharePdf() async {
    if (_sharing) return;
    setState(() => _sharing = true);
    try {
      final file = await _fetchPdfFile();
      await Share.shareXFiles(
        [XFile(file.path, mimeType: 'application/pdf')],
        text: 'Slip Gaji ${widget.periodLabel}',
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      _showSnack(e.message, isError: true);
    } catch (_) {
      if (!mounted) return;
      _showSnack('Gagal membagikan slip gaji. Silakan coba lagi.',
          isError: true);
    } finally {
      if (mounted) setState(() => _sharing = false);
    }
  }

  void _showSnack(String message, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? Colors.red.shade600 : Colors.green.shade600,
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.grey.shade50,
      appBar: AppBar(
        title: Text(
          'Slip ${widget.periodLabel}',
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17),
        ),
        backgroundColor: Colors.white,
        foregroundColor: Colors.black87,
        elevation: 0.5,
        actions: [
          Consumer<PayslipProvider>(
            builder: (context, prov, _) {
              if (prov.loadingDetail || prov.detail == null) {
                return const SizedBox.shrink();
              }
              return IconButton(
                tooltip: 'Bagikan PDF',
                onPressed: _sharing ? null : _sharePdf,
                icon: _sharing
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.share_rounded),
              );
            },
          ),
        ],
      ),
      body: Consumer<PayslipProvider>(
        builder: (context, prov, _) {
          if (prov.loadingDetail) {
            return _buildLoading();
          }
          if (prov.errorDetail != null) {
            return _buildError(prov.errorDetail!);
          }
          final detail = prov.detail;
          if (detail == null) {
            return _buildError('Data slip gaji tidak ditemukan.');
          }
          return _buildContent(detail);
        },
      ),
      bottomNavigationBar: Consumer<PayslipProvider>(
        builder: (context, prov, _) {
          if (prov.loadingDetail || prov.detail == null) {
            return const SizedBox.shrink();
          }
          return SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
              child: SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: _downloading ? null : _downloadPdf,
                  icon: _downloading
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : const Icon(Icons.download_rounded, size: 20),
                  label: Text(_downloading ? 'Mengunduh...' : 'Unduh PDF'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.indigo,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                    textStyle: const TextStyle(
                        fontSize: 15, fontWeight: FontWeight.bold),
                  ),
                ),
              ),
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
        padding: const EdgeInsets.all(16),
        children: const [
          SkeletonBox(width: double.infinity, height: 110, borderRadius: 16),
          SizedBox(height: 16),
          SkeletonBox(width: double.infinity, height: 160, borderRadius: 16),
          SizedBox(height: 16),
          SkeletonBox(width: double.infinity, height: 200, borderRadius: 16),
        ],
      ),
    );
  }

  Widget _buildError(String message) {
    return ListView(
      children: [
        SizedBox(height: MediaQuery.of(context).size.height * 0.25),
        const Icon(Icons.cloud_off_rounded, size: 56, color: Colors.grey),
        const SizedBox(height: 12),
        const Center(
          child: Text(
            'Gagal memuat rincian slip',
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

  Widget _buildContent(PayslipDetail d) {
    return RefreshIndicator(
      onRefresh: _refresh,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildNetHeader(d),
            const SizedBox(height: 16),
            _buildEmployeeCard(d),
            const SizedBox(height: 16),
            if (d.workingDays > 0 ||
                d.presentDays > 0 ||
                d.absentDays > 0 ||
                d.overtimeHours > 0) ...[
              _buildAttendanceCard(d),
              const SizedBox(height: 16),
            ],
            _buildItemsCard(
              title: 'Pendapatan',
              icon: Icons.add_circle_outline,
              iconColor: Colors.green,
              items: d.earnings,
              subtotalLabel: 'Total Pendapatan (Bruto)',
              subtotal: d.gross,
              subtotalColor: Colors.green.shade700,
              emptyText: 'Tidak ada komponen pendapatan.',
            ),
            const SizedBox(height: 16),
            _buildItemsCard(
              title: 'Potongan',
              icon: Icons.remove_circle_outline,
              iconColor: Colors.red,
              items: d.deductions,
              subtotalLabel: 'Total Potongan',
              subtotal: d.totalDeduction,
              subtotalColor: Colors.red.shade700,
              isDeduction: true,
              emptyText: 'Tidak ada potongan.',
            ),
            const SizedBox(height: 16),
            _buildSummaryCard(d),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  // ─── Header neto (take home pay) ────────────────────────────
  Widget _buildNetHeader(PayslipDetail d) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF4F46E5), Color(0xFF6366F1)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                d.period,
                style: const TextStyle(
                  color: Colors.white70,
                  fontSize: 13,
                  fontWeight: FontWeight.w500,
                ),
              ),
              _statusBadgeLight(d.status),
            ],
          ),
          const SizedBox(height: 14),
          const Text(
            'Gaji Bersih (Take Home Pay)',
            style: TextStyle(color: Colors.white70, fontSize: 12),
          ),
          const SizedBox(height: 4),
          Text(
            formatCurrency(d.net),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 26,
              fontWeight: FontWeight.bold,
            ),
          ),
        ],
      ),
    );
  }

  Widget _statusBadgeLight(String status) {
    String label;
    switch (status) {
      case 'paid':
        label = 'Dibayar';
        break;
      case 'approved':
        label = 'Disetujui';
        break;
      default:
        label = status.isEmpty
            ? 'Final'
            : status[0].toUpperCase() + status.substring(1);
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.2),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        label,
        style: const TextStyle(
          color: Colors.white,
          fontSize: 11,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }

  // ─── Kartu identitas karyawan ───────────────────────────────
  Widget _buildEmployeeCard(PayslipDetail d) {
    return _card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _cardHeader(Icons.person_outline, 'Data Karyawan'),
          const SizedBox(height: 12),
          _infoRow('Nama', d.employeeName),
          if (d.employeeCode != null && d.employeeCode!.isNotEmpty)
            _infoRow('NIK/Kode', d.employeeCode!),
          if (d.positionName != null && d.positionName!.isNotEmpty)
            _infoRow('Jabatan', d.positionName!),
          if (d.departmentName != null && d.departmentName!.isNotEmpty)
            _infoRow('Departemen', d.departmentName!),
          if (d.ptkpStatus != null && d.ptkpStatus!.isNotEmpty)
            _infoRow('Status PTKP', d.ptkpStatus!),
          if (d.npwpMasked != null && d.npwpMasked!.isNotEmpty)
            _infoRow('NPWP', d.npwpMasked!),
          if (d.bankName != null && d.bankName!.isNotEmpty)
            _infoRow('Bank', d.bankName!),
          if (d.bankAccountNo != null && d.bankAccountNo!.isNotEmpty)
            _infoRow('No. Rekening', d.bankAccountNo!),
          if (d.bankAccountHolder != null && d.bankAccountHolder!.isNotEmpty)
            _infoRow('Atas Nama', d.bankAccountHolder!),
        ],
      ),
    );
  }

  // ─── Kartu ringkasan kehadiran ──────────────────────────────
  Widget _buildAttendanceCard(PayslipDetail d) {
    return _card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _cardHeader(Icons.event_available_outlined, 'Ringkasan Kehadiran'),
          const SizedBox(height: 12),
          Row(
            children: [
              _statTile('Hari Kerja', '${d.workingDays}', Colors.blueGrey),
              _statTile('Hadir', '${d.presentDays}', Colors.green),
              _statTile('Absen', '${d.absentDays}', Colors.red),
              _statTile(
                'Lembur',
                '${d.overtimeHours.toStringAsFixed(d.overtimeHours % 1 == 0 ? 0 : 1)} jam',
                Colors.orange,
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _statTile(String label, String value, Color color) {
    return Expanded(
      child: Column(
        children: [
          Text(
            value,
            style: TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.bold,
              color: color,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 10, color: Colors.grey.shade600),
          ),
        ],
      ),
    );
  }

  // ─── Kartu daftar item (pendapatan / potongan) ──────────────
  Widget _buildItemsCard({
    required String title,
    required IconData icon,
    required Color iconColor,
    required List<PayslipItem> items,
    required String subtotalLabel,
    required double subtotal,
    required Color subtotalColor,
    required String emptyText,
    bool isDeduction = false,
  }) {
    return _card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _cardHeader(icon, title, iconColor: iconColor),
          const SizedBox(height: 8),
          if (items.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Text(
                emptyText,
                style: TextStyle(color: Colors.grey.shade500, fontSize: 12),
              ),
            )
          else
            ...items.map((item) => _amountRow(item, isDeduction: isDeduction)),
          const Divider(height: 20),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                subtotalLabel,
                style: const TextStyle(
                  fontWeight: FontWeight.w600,
                  fontSize: 13,
                ),
              ),
              Text(
                '${isDeduction ? '- ' : ''}${formatCurrency(subtotal)}',
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 14,
                  color: subtotalColor,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _amountRow(PayslipItem item, {required bool isDeduction}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        item.label,
                        style: const TextStyle(fontSize: 13),
                      ),
                    ),
                    if (item.isStatutory) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 6, vertical: 1),
                        decoration: BoxDecoration(
                          color: Colors.blueGrey.withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: const Text(
                          'Pajak/Wajib',
                          style: TextStyle(
                            fontSize: 9,
                            color: Colors.blueGrey,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
                if (item.notes != null && item.notes!.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Text(
                      item.notes!,
                      style: TextStyle(
                        fontSize: 10,
                        color: Colors.grey.shade500,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text(
            '${isDeduction ? '- ' : ''}${formatCurrency(item.amount)}',
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: isDeduction ? Colors.red.shade600 : Colors.black87,
            ),
          ),
        ],
      ),
    );
  }

  // ─── Kartu ringkasan akhir ──────────────────────────────────
  Widget _buildSummaryCard(PayslipDetail d) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.indigo.withValues(alpha: 0.04),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.indigo.withValues(alpha: 0.15)),
      ),
      child: Column(
        children: [
          _summaryRow('Total Pendapatan', d.gross, Colors.green.shade700),
          const SizedBox(height: 8),
          _summaryRow('Total Potongan', d.totalDeduction, Colors.red.shade700,
              isDeduction: true),
          const Divider(height: 20),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text(
                'Gaji Bersih',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
              ),
              Text(
                formatCurrency(d.net),
                style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 18,
                  color: Colors.indigo,
                ),
              ),
            ],
          ),
          if (d.notes != null && d.notes!.isNotEmpty) ...[
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Colors.amber.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                d.notes!,
                style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _summaryRow(String label, double value, Color color,
      {bool isDeduction = false}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(fontSize: 13)),
        Text(
          '${isDeduction ? '- ' : ''}${formatCurrency(value)}',
          style: TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w600,
            color: color,
          ),
        ),
      ],
    );
  }

  // ─── Helper UI umum ─────────────────────────────────────────
  Widget _card({required Widget child}) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFF1F5F9)),
      ),
      child: child,
    );
  }

  Widget _cardHeader(IconData icon, String title, {Color? iconColor}) {
    return Row(
      children: [
        Icon(icon, size: 18, color: iconColor ?? Colors.indigo),
        const SizedBox(width: 8),
        Text(
          title,
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
        ),
      ],
    );
  }

  Widget _infoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(
              label,
              style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
