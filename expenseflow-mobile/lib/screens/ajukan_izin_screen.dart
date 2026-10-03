import 'dart:async';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart';
import 'package:provider/provider.dart';
import '../presensi_provider.dart';
import '../utils.dart';
import '../widgets/custom_date_picker_dialog.dart';

class AjukanIzinScreen extends StatefulWidget {
  const AjukanIzinScreen({super.key});

  @override
  State<AjukanIzinScreen> createState() => _AjukanIzinScreenState();
}

class _AjukanIzinScreenState extends State<AjukanIzinScreen> {
  String _selectedType = 'izin';
  String _halfDaySession = 'morning'; // 'morning' | 'afternoon'
  late DateTime _startDate;
  late DateTime _endDate;
  bool _isRandomDates = false; // Mode tanggal acak / tidak berurutan
  List<DateTime> _randomDates = [];
  final _reasonController = TextEditingController();
  bool _isLoading = false;

  // Preview hari EFEKTIF dari backend (skip libur/off-day/bentrok).
  // null = belum termuat / gagal → UI fallback ke hitungan kalender sederhana.
  int? _effectiveDays;
  List<Map<String, dynamic>> _skippedDates = [];
  bool _previewLoading = false;
  Timer? _previewDebounce;
  bool _showSkippedInfo = false;

  @override
  void initState() {
    super.initState();
    final prov = Provider.of<PresensiProvider>(context, listen: false);
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final tomorrow = today.add(const Duration(days: 1));

    // Hari H (hari ini) diperbolehkan jika belum check-in hari ini. Jika sudah check-in, minimal besok.
    final initialDate = prov.hasCheckedInToday ? tomorrow : today;
    _startDate = initialDate;
    _endDate = initialDate;
    _randomDates = [_startDate];

    // Sinkronkan status presensi hari ini secara aktual dari backend lalu muat preview awal
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await prov.syncStatusFromBackend();
      if (!mounted) return;
      if (prov.hasCheckedInToday && _startDate.isAtSameMomentAs(today)) {
        setState(() {
          _startDate = tomorrow;
          _endDate = tomorrow;
          _randomDates = [_startDate];
        });
      }
      _fetchPreview();
    });
  }

  // Ambil hitungan efektif dari backend (dengan debounce agar tidak spam API
  // saat user menelusuri tanggal). Gagal → biarkan null, fallback kalender.
  void _fetchPreview() {
    _previewDebounce?.cancel();
    _previewDebounce = Timer(const Duration(milliseconds: 400), () async {
      if (!mounted) return;
      setState(() => _previewLoading = true);
      final prov = Provider.of<PresensiProvider>(context, listen: false);
      final res = await prov.fetchLeavePreview(
        startDate: _isRandomDates ? null : _toStr(_startDate),
        endDate: _isRandomDates ? null : _toStr(_endDate),
        dates: _isRandomDates ? _randomDates.map(_toStr).toList() : null,
        leaveType: _selectedType,
      );
      if (!mounted) return;
      setState(() {
        _previewLoading = false;
        if (res != null) {
          _effectiveDays = (res['total_days'] ?? 0) as int;
          _skippedDates = ((res['skipped_dates'] as List?) ?? [])
              .whereType<Map<String, dynamic>>()
              .toList();
          _showSkippedInfo = _skippedDates.isNotEmpty;
        } else {
          _effectiveDays = null;
          _skippedDates = [];
        }
      });
    });
  }

  String _toStr(DateTime dt) =>
      '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}';

  @override
  void dispose() {
    _previewDebounce?.cancel();
    _reasonController.dispose();
    super.dispose();
  }

  // Lampiran dokumen pendukung (wajib untuk sakit/cuti_hamil/keguguran/haji_umrah)
  Uint8List? _docBytes;
  String? _docFileName;
  bool _docIsPdf = false;

  (Color, IconData) _styleForType(String type) {
    switch (type) {
      case 'cuti':
        return (const Color(0xFF00695C), Icons.beach_access_outlined);
      case 'izin':
        return (const Color(0xFF7B1FA2), Icons.event_busy_outlined);
      case 'sakit':
        return (const Color(0xFFE65100), Icons.local_hospital_outlined);
      case 'wfh':
        return (const Color(0xFF1E88E5), Icons.home_work_outlined);
      case 'cuti_hamil':
        return (const Color(0xFFC2185B), Icons.pregnant_woman_outlined);
      case 'cuti_keguguran':
        return (const Color(0xFFAD1457), Icons.healing_outlined);
      case 'cuti_ayah':
        return (const Color(0xFF1565C0), Icons.family_restroom_outlined);
      case 'cuti_haid':
        return (const Color(0xFFD81B60), Icons.water_drop_outlined);
      case 'cuti_menikah':
        return (const Color(0xFF6A1B9A), Icons.favorite_outline);
      case 'cuti_menikahkan_anak':
        return (const Color(0xFF4A148C), Icons.celebration_outlined);
      case 'cuti_khitan_baptis_anak':
        return (const Color(0xFF00838F), Icons.child_care_outlined);
      case 'cuti_duka_keluarga_inti':
      case 'cuti_duka_serumah':
        return (const Color(0xFF37474F), Icons.sentiment_very_dissatisfied_outlined);
      case 'cuti_ibadah_haji_umrah':
        return (const Color(0xFF2E7D32), Icons.flight_takeoff_outlined);
      case 'cuti_setengah_hari':
        return (const Color(0xFF0277BD), Icons.timelapse_outlined);
      default:
        return (const Color(0xFF455A64), Icons.assignment_outlined);
    }
  }

  bool _isDocumentRequired(String type) {
    return type == 'sakit' ||
        type == 'cuti_hamil' ||
        type == 'cuti_keguguran' ||
        type == 'cuti_ibadah_haji_umrah';
  }

  String _documentPrompt(String type) {
    switch (type) {
      case 'sakit':
        return 'Surat Keterangan Dokter';
      case 'cuti_hamil':
        return 'Surat Dokter Kandungan / Bidan';
      case 'cuti_keguguran':
        return 'Surat Keterangan Medis Keguguran';
      case 'cuti_ibadah_haji_umrah':
        return 'Bukti Pendaftaran / Keberangkatan';
      default:
        return 'Dokumen / Surat Pendukung';
    }
  }

  int get _totalDays =>
      _isRandomDates ? _randomDates.length : (_endDate.difference(_startDate).inDays + 1);

  // Ringkasan alasan skip utk banner, contoh:
  // "karena tanggal 6 Sep adalah Hari Libur: X" /
  // "karena tanggal 6 Sep (Hari Libur: X) dan 7 Sep (Libur jadwal kerja)"
  String get _skippedReasonSummary {
    if (_skippedDates.isEmpty) return '';
    final parts = _skippedDates.map((s) {
      final tgl = _formatDate(DateTime.parse(s['date'] as String));
      final label = (s['label'] as String?) ?? 'hari libur';
      return 'tanggal $tgl adalah $label';
    }).toList();
    if (parts.length == 1) return 'karena ${parts[0]}';
    final head = parts.sublist(0, parts.length - 1).join(', ');
    return 'karena $head dan ${parts.last}';
  }

  Future<void> _pickDate({required bool isStart}) async {
    final prov = Provider.of<PresensiProvider>(context, listen: false);
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final tomorrow = today.add(const Duration(days: 1));

    // Hari H diperbolehkan HANYA jika belum check-in hari ini.
    // Jika sudah check-in hari ini, tanggal minimal adalah besok.
    final minDate = prov.hasCheckedInToday ? tomorrow : today;
    final first = isStart ? minDate : _startDate;
    final initial = isStart ? _startDate : _endDate;

    final picked = await showCustomDatePicker(
      context: context,
      initialDate: initial.isBefore(first) ? first : initial,
      firstDate: first,
      lastDate: DateTime.now().add(const Duration(days: 365)),
      leaveType: _selectedType,
    );
    if (picked == null) return;
    setState(() {
      if (isStart) {
        _startDate = picked;
        if (_selectedType == 'cuti_setengah_hari' || _endDate.isBefore(_startDate)) {
          _endDate = _startDate;
        }
      } else {
        _endDate = _selectedType == 'cuti_setengah_hari' ? _startDate : picked;
      }
    });
    // Tanggal berubah → muat ulang hitungan efektif dari backend
    _fetchPreview();
  }

  Future<void> _pickRandomDates() async {
    final prov = Provider.of<PresensiProvider>(context, listen: false);
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final tomorrow = today.add(const Duration(days: 1));
    final minDate = prov.hasCheckedInToday ? tomorrow : today;

    final picked = await showCustomMultiDatePicker(
      context: context,
      initialDates: _randomDates.isNotEmpty ? _randomDates : [_startDate],
      firstDate: minDate,
      lastDate: DateTime.now().add(const Duration(days: 365)),
      leaveType: _selectedType,
    );

    if (picked == null || picked.isEmpty) return;
    setState(() {
      _randomDates = picked;
    });
    _fetchPreview();
  }

  String _formatDate(DateTime dt) => formatDateIndonesian(dt);

  // Pilih surat dokter / dokumen lampiran dari penyimpanan (gambar/PDF).
  Future<void> _pickDocument() async {
    try {
      final result = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'],
        withData: true,
      );
      if (result == null || result.files.isEmpty) return;
      final file = result.files.first;
      if (file.bytes == null) return;
      setState(() {
        _docBytes = file.bytes;
        _docFileName = file.name;
        _docIsPdf = file.name.toLowerCase().endsWith('.pdf');
      });
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Gagal memilih file: $e'), backgroundColor: Colors.red),
      );
    }
  }

  void _submit() async {
    final prov = Provider.of<PresensiProvider>(context, listen: false);
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);

    if (_isRandomDates) {
      if (_randomDates.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Pilih minimal satu tanggal pengajuan.'),
            backgroundColor: Colors.red,
          ),
        );
        return;
      }

      final hasPast = _randomDates.any((d) => d.isBefore(today));
      if (hasPast) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Pengajuan tidak dapat dilakukan untuk tanggal yang sudah lewat.'),
            backgroundColor: Colors.red,
          ),
        );
        return;
      }

      final hasToday = _randomDates.any(
          (d) => d.year == today.year && d.month == today.month && d.day == today.day);
      if (hasToday && prov.hasCheckedInToday) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Anda sudah melakukan check-in hari ini, sehingga tidak dapat mengajukan izin atau cuti untuk hari ini.'),
            backgroundColor: Colors.red,
          ),
        );
        return;
      }
    } else {
      if (_startDate.isBefore(today)) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Pengajuan tidak dapat dilakukan untuk tanggal yang sudah lewat.'),
            backgroundColor: Colors.red,
          ),
        );
        return;
      }

      if (_startDate.isAtSameMomentAs(today) && prov.hasCheckedInToday) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Anda sudah melakukan check-in hari ini, sehingga tidak dapat mengajukan izin atau cuti untuk hari ini.'),
            backgroundColor: Colors.red,
          ),
        );
        return;
      }
    }

    if (_reasonController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Alasan tidak boleh kosong.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    if (_effectiveDays != null && _effectiveDays! <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(_isRandomDates
              ? 'Tanggal-tanggal yang dipilih tidak memiliki hari kerja efektif (semua adalah libur/off-day atau sudah WFH/diajukan).'
              : 'Rentang tanggal tidak memiliki hari kerja efektif (semua hari adalah libur/off-day atau sudah WFH/diajukan).'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    final selectedBal = prov.leaveBalances
        .where((b) => b.leaveType == _selectedType)
        .firstOrNull;

    // Cegah submit jenis cuti yang dinonaktifkan di kantor
    if (selectedBal != null && selectedBal.isDisabled) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Jenis cuti "${selectedBal.leaveTypeLabel}" sedang dinonaktifkan oleh kantor Anda.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    // Cegah submit kuota 0 untuk tipe berkuota
    if (selectedBal != null && !selectedBal.isUnlimited && selectedBal.remaining <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Sisa kuota "${selectedBal.leaveTypeLabel}" Anda tidak mencukupi (0 hari).'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    // Validasi dokumen jika jenis cuti mewajibkan
    if (_isDocumentRequired(_selectedType) && _docBytes == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('${_documentPrompt(_selectedType)} wajib dilampirkan untuk jenis pengajuan ini.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    setState(() => _isLoading = true);

    final startStr =
        '${_startDate.year}-${_startDate.month.toString().padLeft(2, '0')}-${_startDate.day.toString().padLeft(2, '0')}';
    final endStr =
        '${_endDate.year}-${_endDate.month.toString().padLeft(2, '0')}-${_endDate.day.toString().padLeft(2, '0')}';

    final String sessionName = _halfDaySession == 'morning' ? 'Sesi 1' : 'Sesi 2';
    final String formattedReason = _selectedType == 'cuti_setengah_hari'
        ? '[$sessionName] ${_reasonController.text.trim()}'
        : _reasonController.text.trim();

    try {
      await prov.submitLeave(
        leaveType: _selectedType,
        startDate: _isRandomDates ? null : startStr,
        endDate: _isRandomDates ? null : endStr,
        dates: _isRandomDates ? _randomDates.map(_toStr).toList() : null,
        totalDays: _selectedType == 'cuti_setengah_hari' ? 1 : (_effectiveDays ?? _totalDays),
        reason: formattedReason,
        documentBytes: _docBytes,
        documentFileName: _docFileName,
        halfDaySession: _selectedType == 'cuti_setengah_hari' ? _halfDaySession : null,
      );

      // Refresh saldo cuti & daftar pengajuan agar sinkron seketika
      prov.fetchLeaveBalance(forceRefresh: true);
      prov.fetchLeaveRequests(forceRefresh: true);

      if (!mounted) return;
      setState(() => _isLoading = false);
      Navigator.pop(context);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content:
              Text('Pengajuan berhasil dikirim. Menunggu persetujuan.'),
          backgroundColor: Colors.green,
          duration: Duration(seconds: 3),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _isLoading = false);
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
    final balances = prov.leaveBalances;

    // Bangun daftar tipe cuti secara dinamis berdasarkan data saldo / kantor
    final List<_LeaveTypeItem> typeItems = [];
    if (balances.isNotEmpty) {
      for (final b in balances) {
        final style = _styleForType(b.leaveType);
        typeItems.add(_LeaveTypeItem(
          key: b.leaveType,
          label: b.leaveTypeLabel,
          icon: style.$2,
          color: style.$1,
          isDisabled: b.isDisabled,
          isUnlimited: b.isUnlimited,
          remaining: b.remaining,
          quota: b.quota,
          used: b.used,
        ));
      }
      // Tambahkan WFH bila belum ada
      if (!typeItems.any((t) => t.key == 'wfh')) {
        typeItems.add(const _LeaveTypeItem(
          key: 'wfh',
          label: 'Work From Home',
          icon: Icons.home_work_outlined,
          color: Color(0xFF1E88E5),
          isUnlimited: true,
        ));
      }
    } else {
      typeItems.addAll(const [
        _LeaveTypeItem(
          key: 'izin',
          label: 'Izin',
          icon: Icons.event_busy_outlined,
          color: Color(0xFF7B1FA2),
          isUnlimited: true,
        ),
        _LeaveTypeItem(
          key: 'cuti',
          label: 'Cuti Tahunan',
          icon: Icons.beach_access_outlined,
          color: Color(0xFF00695C),
          remaining: 12,
          quota: 12,
        ),
        _LeaveTypeItem(
          key: 'sakit',
          label: 'Cuti Sakit',
          icon: Icons.local_hospital_outlined,
          color: Color(0xFFE65100),
          remaining: 14,
          quota: 14,
        ),
        _LeaveTypeItem(
          key: 'wfh',
          label: 'Work From Home',
          icon: Icons.home_work_outlined,
          color: Color(0xFF1E88E5),
          isUnlimited: true,
        ),
      ]);
    }

    final availableItem = typeItems.where((t) => t.key == _selectedType && !t.isDisabled).firstOrNull
        ?? typeItems.where((t) => !t.isDisabled).firstOrNull
        ?? typeItems.firstOrNull;
    if (availableItem != null && _selectedType != availableItem.key) {
      _selectedType = availableItem.key;
    }

    final selectedItem = availableItem;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Ajukan Izin / Cuti'),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 36),
          child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Jenis Pengajuan (Dropdown)
            const Text('Jenis Pengajuan',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(height: 8),
            Container(
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.grey.shade300),
              ),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  value: availableItem?.key,
                  isExpanded: true,
                  icon: const Icon(Icons.keyboard_arrow_down_rounded,
                      color: Colors.blueGrey, size: 24),
                  items: typeItems.map((t) {
                final isSelected = _selectedType == t.key;
                final isDisabled = t.isDisabled;
                return DropdownMenuItem<String>(
                  value: t.key,
                  enabled: !isDisabled,
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(6),
                        decoration: BoxDecoration(
                          color: isDisabled
                              ? Colors.grey.shade100
                              : t.color.withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Icon(
                          isDisabled ? Icons.lock_outline : t.icon,
                          size: 16,
                          color: isDisabled ? Colors.grey.shade400 : t.color,
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          t.label,
                          style: TextStyle(
                            fontSize: 13.5,
                            fontWeight:
                                isSelected ? FontWeight.bold : FontWeight.w500,
                            color: isDisabled
                                ? Colors.grey.shade400
                                : Colors.black87,
                          ),
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      if (isDisabled) ...[
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: Colors.grey.shade200,
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: const Text(
                            'Nonaktif',
                            style: TextStyle(
                              fontSize: 10,
                              color: Colors.grey,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ] else if (t.isUnlimited) ...[
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: const Color(0xFFF3E5F5),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: const Text(
                            'Unlimited',
                            style: TextStyle(
                              fontSize: 10,
                              color: Color(0xFF7B1FA2),
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ] else ...[
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: t.remaining > 0
                                ? const Color(0xFFE0F2F1)
                                : const Color(0xFFFFEBEE),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: Text(
                            'Sisa ${t.remaining} hari',
                            style: TextStyle(
                              fontSize: 10,
                              color: t.remaining > 0
                                  ? const Color(0xFF00695C)
                                  : const Color(0xFFC62828),
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                );
              }).toList(),
              onChanged: (newVal) {
                if (newVal == null || newVal == _selectedType) return;
                setState(() {
                  _selectedType = newVal;
                  if (_selectedType == 'cuti_setengah_hari') {
                    _isRandomDates = false;
                    _endDate = _startDate;
                  }
                });
                _fetchPreview();
              },
            ),
          ),
        ),

            // Card status kuota jenis yang sedang dipilih
            if (selectedItem != null) ...[
              const SizedBox(height: 12),
              Container(
                width: double.infinity,
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                decoration: BoxDecoration(
                  color: selectedItem.isDisabled
                      ? const Color(0xFFFAFAFA)
                      : (selectedItem.isUnlimited
                          ? const Color(0xFFF3E5F5)
                          : (selectedItem.remaining > 0
                              ? const Color(0xFFE0F2F1)
                              : const Color(0xFFFFEBEE))),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(
                    color: selectedItem.isDisabled
                        ? const Color(0xFFCFD8DC)
                        : (selectedItem.isUnlimited
                            ? const Color(0xFFCE93D8)
                            : (selectedItem.remaining > 0
                                ? const Color(0xFF80CBC4)
                                : const Color(0xFFFFCDD2))),
                  ),
                ),
                child: Row(
                  children: [
                    Icon(
                      selectedItem.isDisabled
                          ? Icons.lock_outline
                          : (selectedItem.isUnlimited
                              ? Icons.all_inclusive
                              : (selectedItem.remaining > 0
                                  ? Icons.check_circle_outline
                                  : Icons.warning_amber_rounded)),
                      size: 18,
                      color: selectedItem.isDisabled
                          ? const Color(0xFF78909C)
                          : (selectedItem.isUnlimited
                              ? const Color(0xFF7B1FA2)
                              : (selectedItem.remaining > 0
                                  ? const Color(0xFF00695C)
                                  : const Color(0xFFC62828))),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        selectedItem.isDisabled
                            ? 'Jenis cuti ini sedang dinonaktifkan di kantor Anda dan tidak dapat diajukan.'
                            : (selectedItem.isUnlimited
                                ? (selectedItem.key == 'wfh'
                                    ? 'Work From Home fleksibel (tanpa batas kuota).'
                                    : (selectedItem.key == 'izin'
                                        ? 'Izin tanpa batas kuota (selalu aktif & fleksibel).'
                                        : '${selectedItem.label} tanpa batas kuota (mode akumulasi).'))
                                : (selectedItem.remaining > 0
                                    ? 'Sisa kuota ${selectedItem.label}: ${selectedItem.remaining} hari (Terpakai: ${selectedItem.used}/${selectedItem.quota} hari)'
                                    : 'Sisa kuota ${selectedItem.label} Anda adalah 0 hari (Belum aktif / kuota habis).')),
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: selectedItem.isDisabled
                              ? const Color(0xFF546E7A)
                              : (selectedItem.isUnlimited
                                  ? const Color(0xFF4A148C)
                                  : (selectedItem.remaining > 0
                                      ? const Color(0xFF004D40)
                                      : const Color(0xFFB71C1C))),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
            // Pilihan Sesi Cuti Setengah Hari (Sesi 1 / Sesi 2)
            if (_selectedType == 'cuti_setengah_hari') ...[
              const SizedBox(height: 20),
              const Text(
                'Pilihan Sesi Cuti',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: _SessionCard(
                      title: 'Sesi 1',
                      subtitle: 'Cuti paruh pertama',
                      icon: Icons.wb_twilight_rounded,
                      isSelected: _halfDaySession == 'morning',
                      onTap: () => setState(() => _halfDaySession = 'morning'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _SessionCard(
                      title: 'Sesi 2',
                      subtitle: 'Cuti paruh kedua',
                      icon: Icons.wb_sunny_rounded,
                      isSelected: _halfDaySession == 'afternoon',
                      onTap: () => setState(() => _halfDaySession = 'afternoon'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                decoration: BoxDecoration(
                  color: const Color(0xFFF0FDF4),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: const Color(0xFFBBF7D0)),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.info_outline_rounded, size: 16, color: Colors.green.shade800),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _halfDaySession == 'morning'
                            ? '☀️ Sesi 1: Anda libur di paruh pertama jam kerja. Anda masuk di pertengahan jam kerja dan bekerja hingga jam pulang normal.'
                            : '🌤️ Sesi 2: Anda masuk normal di awal jam kerja. Anda diperbolehkan pulang di pertengahan jam kerja tanpa sanksi pulang cepat.',
                        style: TextStyle(
                          fontSize: 11.5,
                          color: Colors.green.shade900,
                          height: 1.4,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],

            const SizedBox(height: 24),

            // Periode & Switch Tanggal Acak
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Periode',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                ),
                if (_selectedType != 'cuti_setengah_hari')
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        'Pilih Tanggal Acak',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight:
                              _isRandomDates ? FontWeight.bold : FontWeight.w500,
                          color: _isRandomDates
                              ? const Color(0xFF0088FF)
                              : Colors.grey.shade700,
                        ),
                      ),
                      const SizedBox(width: 4),
                      Switch(
                        value: _isRandomDates,
                        activeThumbColor: const Color(0xFF0088FF),
                        materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        onChanged: (val) {
                          setState(() {
                            _isRandomDates = val;
                            if (_isRandomDates && _randomDates.isEmpty) {
                              _randomDates = [_startDate];
                            }
                          });
                          _fetchPreview();
                        },
                      ),
                    ],
                  ),
              ],
            ),
            const SizedBox(height: 12),
            if (_selectedType == 'cuti_setengah_hari') ...[
              _DateButton(
                label: 'Tanggal Cuti Setengah Hari',
                value: _formatDate(_startDate),
                onTap: () => _pickDate(isStart: true),
              ),
            ] else if (_isRandomDates) ...[
              // Button launcher untuk Multi-Date Picker
              InkWell(
                onTap: _pickRandomDates,
                borderRadius: BorderRadius.circular(10),
                child: Container(
                  width: double.infinity,
                  padding:
                      const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF0F7FF),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: const Color(0xFFBAE6FD)),
                  ),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: const Color(0xFF0088FF).withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: const Icon(
                          Icons.edit_calendar_outlined,
                          color: Color(0xFF0088FF),
                          size: 20,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _randomDates.isEmpty
                                  ? 'Pilih Tanggal Acak'
                                  : '${_randomDates.length} Tanggal Dipilih',
                              style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 13.5,
                                color: Color(0xFF0F172A),
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              _randomDates.isEmpty
                                  ? 'Ketuk untuk memilih tanggal di kalender'
                                  : 'Ketuk untuk menambah atau mengubah tanggal',
                              style: TextStyle(
                                fontSize: 11,
                                color: Colors.grey.shade600,
                              ),
                            ),
                          ],
                        ),
                      ),
                      const Icon(
                        Icons.chevron_right_rounded,
                        color: Colors.blueGrey,
                        size: 22,
                      ),
                    ],
                  ),
                ),
              ),
              if (_randomDates.isNotEmpty) ...[
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: _randomDates.map((date) {
                    return InputChip(
                      label: Text(
                        _formatDate(date),
                        style: const TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w600,
                          color: Color(0xFF1E40AF),
                        ),
                      ),
                      backgroundColor: const Color(0xFFDBEAFE),
                      deleteIcon: const Icon(
                        Icons.cancel,
                        size: 16,
                        color: Color(0xFF3B82F6),
                      ),
                      onDeleted: _randomDates.length > 1
                          ? () {
                              setState(() {
                                _randomDates.remove(date);
                              });
                              _fetchPreview();
                            }
                          : null,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8),
                        side: BorderSide(color: Colors.blue.shade200),
                      ),
                    );
                  }).toList(),
                ),
              ],
            ] else ...[
              Row(
                children: [
                  Expanded(
                    child: _DateButton(
                      label: 'Mulai',
                      value: _formatDate(_startDate),
                      onTap: () => _pickDate(isStart: true),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _DateButton(
                      label: 'Selesai',
                      value: _formatDate(_endDate),
                      onTap: () => _pickDate(isStart: false),
                    ),
                  ),
                ],
              ),
            ],
            const SizedBox(height: 8),

            // Badge "Total N hari" — memakai hitungan EFEKTIF dari backend
            // (skip libur/off-day/bentrok). Fallback kalender saat backend gagal.
            Center(
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                    decoration: BoxDecoration(
                      color: const Color(0xFFE3F2FD),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: _previewLoading && _effectiveDays == null
                        ? const SizedBox(
                            width: 14,
                            height: 14,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              if (_previewLoading) ...[
                                const SizedBox(
                                  width: 12,
                                  height: 12,
                                  child: CircularProgressIndicator(strokeWidth: 2),
                                ),
                                const SizedBox(width: 8),
                              ],
                              Text(
                                _selectedType == 'cuti_setengah_hari'
                                    ? 'Total 0.5 hari (${_halfDaySession == 'morning' ? 'Sesi 1' : 'Sesi 2'})'
                                    : 'Total ${_effectiveDays ?? _totalDays} hari',
                                style: const TextStyle(
                                    color: Color(0xFF1565C0),
                                    fontWeight: FontWeight.bold,
                                    fontSize: 13),
                              ),
                            ],
                          ),
                  ),
                  if ((_effectiveDays ?? _totalDays) != _totalDays && !_previewLoading) ...[
                    const SizedBox(width: 6),
                    GestureDetector(
                      onTap: () {
                        setState(() {
                          _showSkippedInfo = !_showSkippedInfo;
                        });
                      },
                      child: Container(
                        padding: const EdgeInsets.all(5),
                        decoration: BoxDecoration(
                          color: _showSkippedInfo
                              ? const Color(0xFFE65100)
                              : const Color(0xFFFFF3E0),
                          shape: BoxShape.circle,
                          border: Border.all(
                            color: const Color(0xFFFFB74D),
                            width: 1.2,
                          ),
                        ),
                        child: Icon(
                          Icons.priority_high_rounded,
                          size: 14,
                          color: _showSkippedInfo ? Colors.white : const Color(0xFFE65100),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),

            // Pemberitahuan: tanggal yang tidak dihitung + alasannya (toggleable).
            if ((_effectiveDays ?? _totalDays) != _totalDays &&
                !_previewLoading &&
                _showSkippedInfo) ...[
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFFFF8E1),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: const Color(0xFFFFE082)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(Icons.info_outline,
                            size: 16, color: Colors.orange.shade800),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            'Total ${_effectiveDays ?? _totalDays} hari '
                            '$_skippedReasonSummary',
                            style: TextStyle(
                              fontSize: 12,
                              color: Colors.orange.shade900,
                              fontWeight: FontWeight.w600,
                              height: 1.4,
                            ),
                          ),
                        ),
                        const SizedBox(width: 6),
                        GestureDetector(
                          onTap: () => setState(() => _showSkippedInfo = false),
                          child: Icon(
                            Icons.close_rounded,
                            size: 16,
                            color: Colors.orange.shade800,
                          ),
                        ),
                      ],
                    ),
                    // Rincian per tanggal yang di-skip
                    for (final s in _skippedDates) ...[
                      const SizedBox(height: 4),
                      Padding(
                        padding: const EdgeInsets.only(left: 22),
                        child: Text(
                          '• ${_formatDate(DateTime.parse(s['date']))} — '
                          '${s['label'] ?? 'hari libur'}',
                          style: TextStyle(
                            fontSize: 11.5,
                            color: Colors.orange.shade800,
                            height: 1.35,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
            const SizedBox(height: 24),

            // Alasan
            const Text('Alasan',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(height: 8),
            TextField(
              controller: _reasonController,
              maxLines: 4,
              decoration: InputDecoration(
                hintText: 'Jelaskan alasan pengajuan izin/cuti Anda...',
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(8),
                ),
                contentPadding: const EdgeInsets.all(14),
              ),
            ),
            const SizedBox(height: 12),

            // Surat dokter / Dokumen Pendukung — Dinamis sesuai jenis cuti
            Builder(builder: (context) {
              final isReq = _isDocumentRequired(_selectedType);
              final prompt = _documentPrompt(_selectedType);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Text(prompt,
                          style: const TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 14)),
                      const SizedBox(width: 6),
                      if (isReq)
                        Text('*wajib',
                            style: TextStyle(
                                color: Colors.red.shade600,
                                fontWeight: FontWeight.bold,
                                fontSize: 12))
                      else
                        Text('(opsional)',
                            style: TextStyle(
                                color: Colors.grey.shade600,
                                fontSize: 12)),
                    ],
                  ),
                  const SizedBox(height: 8),
                  GestureDetector(
                    onTap: _pickDocument,
                    child: Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: _docBytes != null
                            ? const Color(0xFFE8F5E9)
                            : (isReq
                                ? const Color(0xFFFFF3E0)
                                : const Color(0xFFF5F5F5)),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(
                          color: _docBytes != null
                              ? const Color(0xFFA5D6A7)
                              : (isReq
                                  ? Colors.orange.shade200
                                  : Colors.grey.shade300),
                        ),
                      ),
                      child: _docBytes == null
                          // Belum ada file
                          ? Row(
                              children: [
                                Icon(
                                  Icons.upload_file_outlined,
                                  color: isReq
                                      ? Colors.orange.shade700
                                      : const Color(0xFF455A64),
                                  size: 28,
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        'Unggah $prompt',
                                        style: const TextStyle(
                                            fontWeight: FontWeight.bold,
                                            fontSize: 13),
                                      ),
                                      const SizedBox(height: 2),
                                      const Text(
                                        'Foto/gambar (JPG, PNG, WEBP) atau PDF · maks 10 MB',
                                        style: TextStyle(
                                            fontSize: 11, color: Colors.grey),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            )
                          // File sudah dipilih
                          : Row(
                              children: [
                                // Preview kecil
                                ClipRRect(
                                  borderRadius: BorderRadius.circular(8),
                                  child: _docIsPdf
                                      ? Container(
                                          width: 44,
                                          height: 44,
                                          color: Colors.red.shade50,
                                          child: Icon(
                                              Icons.picture_as_pdf_outlined,
                                              color: Colors.red.shade600,
                                              size: 26),
                                        )
                                      : Image.memory(
                                          _docBytes!,
                                          width: 44,
                                          height: 44,
                                          fit: BoxFit.cover,
                                          cacheWidth: 100,
                                          cacheHeight: 100,
                                        ),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        _docFileName ?? 'dokumen_pendukung',
                                        style: const TextStyle(
                                            fontWeight: FontWeight.bold,
                                            fontSize: 13),
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                      const SizedBox(height: 2),
                                      Row(
                                        children: [
                                          Icon(Icons.check_circle,
                                              color: Colors.green.shade600,
                                              size: 13),
                                          const SizedBox(width: 4),
                                          const Text('Siap diunggah',
                                              style: TextStyle(
                                                  fontSize: 11,
                                                  color: Colors.green)),
                                        ],
                                      ),
                                    ],
                                  ),
                                ),
                                // Tombol ganti / hapus
                                TextButton(
                                  onPressed: _pickDocument,
                                  child: const Text('Ganti'),
                                ),
                                IconButton(
                                  icon: Icon(Icons.close,
                                      size: 18, color: Colors.grey.shade600),
                                  onPressed: () => setState(() {
                                    _docBytes = null;
                                    _docFileName = null;
                                    _docIsPdf = false;
                                  }),
                                ),
                              ],
                            ),
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
              );
            }),

            // Info
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFFFF9C4),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFFFFF59D)),
              ),
              child: const Text(
                'Pengajuan akan diproses oleh HRD. Anda akan mendapat notifikasi setelah disetujui atau ditolak.',
                style: TextStyle(
                    fontSize: 12,
                    color: Color(0xFF5D4037),
                    height: 1.4),
              ),
            ),
            const SizedBox(height: 32),

            // Submit button
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: _isLoading ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF0088FF),
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
                child: _isLoading
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white))
                    : const Text('Kirim Pengajuan',
                        style: TextStyle(
                            fontSize: 16, fontWeight: FontWeight.bold)),
              ),
            ),
          ],
        ),
      ),
    ),
  );
}
}

class _DateButton extends StatelessWidget {
  final String label;
  final String value;
  final VoidCallback onTap;

  const _DateButton(
      {required this.label, required this.value, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
        decoration: BoxDecoration(
          border: Border.all(color: Colors.grey.shade300),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Row(
          children: [
            const Icon(Icons.calendar_month_outlined,
                size: 18, color: Colors.blueGrey),
            const SizedBox(width: 8),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    label,
                    style: const TextStyle(
                        fontSize: 10, color: Colors.grey),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text(
                      value,
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.bold),
                      maxLines: 1,
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
}

class _LeaveTypeItem {
  final String key;
  final String label;
  final IconData icon;
  final Color color;
  final bool isDisabled;
  final bool isUnlimited;
  final int remaining;
  final int quota;
  final int used;

  const _LeaveTypeItem({
    required this.key,
    required this.label,
    required this.icon,
    required this.color,
    this.isDisabled = false,
    this.isUnlimited = false,
    this.remaining = 0,
    this.quota = 0,
    this.used = 0,
  });
}

class _SessionCard extends StatelessWidget {
  final String title;
  final String subtitle;
  final IconData icon;
  final bool isSelected;
  final VoidCallback onTap;

  const _SessionCard({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.isSelected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        decoration: BoxDecoration(
          color: isSelected ? const Color(0xFFE8F5E9) : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: isSelected ? const Color(0xFF2E7D32) : Colors.grey.shade300,
            width: isSelected ? 1.8 : 1,
          ),
          boxShadow: isSelected
              ? [
                  BoxShadow(
                    color: const Color(0xFF2E7D32).withValues(alpha: 0.12),
                    blurRadius: 8,
                    offset: const Offset(0, 2),
                  ),
                ]
              : null,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(
                    color: isSelected
                        ? const Color(0xFF2E7D32)
                        : Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Icon(
                    icon,
                    size: 16,
                    color: isSelected ? Colors.white : Colors.grey.shade700,
                  ),
                ),
                Icon(
                  isSelected
                      ? Icons.radio_button_checked
                      : Icons.radio_button_off,
                  size: 18,
                  color: isSelected
                      ? const Color(0xFF2E7D32)
                      : Colors.grey.shade400,
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              title,
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.bold,
                color: isSelected ? const Color(0xFF1B5E20) : Colors.black87,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              subtitle,
              style: TextStyle(
                fontSize: 11,
                color: isSelected ? const Color(0xFF2E7D32) : Colors.grey.shade600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

