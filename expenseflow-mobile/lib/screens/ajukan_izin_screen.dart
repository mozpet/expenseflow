import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart';
import 'package:provider/provider.dart';
import '../presensi_provider.dart';

class AjukanIzinScreen extends StatefulWidget {
  const AjukanIzinScreen({super.key});

  @override
  State<AjukanIzinScreen> createState() => _AjukanIzinScreenState();
}

class _AjukanIzinScreenState extends State<AjukanIzinScreen> {
  String _selectedType = 'izin';
  DateTime _startDate = DateTime.now().add(const Duration(days: 1));
  DateTime _endDate = DateTime.now().add(const Duration(days: 1));
  final _reasonController = TextEditingController();
  bool _isLoading = false;

  // Lampiran surat dokter (wajib untuk jenis 'sakit')
  Uint8List? _docBytes;
  String? _docFileName;
  bool _docIsPdf = false;

  static const _types = [
    ('izin', 'Izin', Icons.event_busy_outlined, Colors.purple),
    ('sakit', 'Sakit', Icons.local_hospital_outlined, Colors.orange),
    ('cuti', 'Cuti', Icons.beach_access_outlined, Colors.teal),
    ('wfh', 'Work From Home', Icons.home_work_outlined, Color(0xFF1E88E5)),
  ];

  int get _totalDays => _endDate.difference(_startDate).inDays + 1;

  Future<void> _pickDate({required bool isStart}) async {
    final initial = isStart ? _startDate : _endDate;
    final first = isStart ? DateTime.now() : _startDate;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: first,
      lastDate: DateTime.now().add(const Duration(days: 365)),
      locale: const Locale('id', 'ID'),
    );
    if (picked == null) return;
    setState(() {
      if (isStart) {
        _startDate = picked;
        if (_endDate.isBefore(_startDate)) _endDate = _startDate;
      } else {
        _endDate = picked;
      }
    });
  }

  String _formatDate(DateTime dt) {
    const months = [
      'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
      'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
    ];
    return '${dt.day} ${months[dt.month - 1]} ${dt.year}';
  }

  // Pilih surat dokter dari penyimpanan (gambar/PDF).
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
    if (_reasonController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Alasan tidak boleh kosong.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    // Surat dokter wajib untuk jenis 'sakit'
    if (_selectedType == 'sakit' && _docBytes == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Surat dokter wajib dilampirkan untuk pengajuan sakit.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    setState(() => _isLoading = true);

    final prov = Provider.of<PresensiProvider>(context, listen: false);
    final startStr =
        '${_startDate.year}-${_startDate.month.toString().padLeft(2, '0')}-${_startDate.day.toString().padLeft(2, '0')}';
    final endStr =
        '${_endDate.year}-${_endDate.month.toString().padLeft(2, '0')}-${_endDate.day.toString().padLeft(2, '0')}';

    try {
      await prov.submitLeave(
        leaveType: _selectedType,
        startDate: startStr,
        endDate: endStr,
        totalDays: _totalDays,
        reason: _reasonController.text.trim(),
        documentBytes: _selectedType == 'sakit' ? _docBytes : null,
        documentFileName: _selectedType == 'sakit' ? _docFileName : null,
      );

      if (!mounted) return;
      setState(() => _isLoading = false);
      Navigator.pop(context);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content:
              Text('Pengajuan berhasil dikirim. Menunggu persetujuan HRD.'),
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
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Ajukan Izin / Cuti'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Tipe izin
            const Text('Jenis Pengajuan',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: _types.map((t) {
                final isSelected = _selectedType == t.$1;
                return GestureDetector(
                  onTap: () => setState(() => _selectedType = t.$1),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 150),
                    padding: const EdgeInsets.symmetric(
                        horizontal: 14, vertical: 10),
                    decoration: BoxDecoration(
                      color: isSelected
                          ? t.$4.withValues(alpha: 0.12)
                          : Colors.grey.shade100,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(
                        color: isSelected ? t.$4 : Colors.grey.shade200,
                        width: isSelected ? 1.5 : 1,
                      ),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(t.$3,
                            size: 18,
                            color: isSelected ? t.$4 : Colors.grey),
                        const SizedBox(width: 6),
                        Text(
                          t.$2,
                          style: TextStyle(
                            fontSize: 13,
                            fontWeight: isSelected
                                ? FontWeight.bold
                                : FontWeight.normal,
                            color: isSelected ? t.$4 : Colors.black54,
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              }).toList(),
            ),
            const SizedBox(height: 24),

            // Tanggal
            const Text('Periode',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: _DateButton(
                    label: 'Mulai',
                    value: _formatDate(_startDate),
                    onTap: () => _pickDate(isStart: true),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _DateButton(
                    label: 'Selesai',
                    value: _formatDate(_endDate),
                    onTap: () => _pickDate(isStart: false),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Center(
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                decoration: BoxDecoration(
                  color: const Color(0xFFE3F2FD),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  'Total $_totalDays hari',
                  style: const TextStyle(
                      color: Color(0xFF1565C0),
                      fontWeight: FontWeight.bold,
                      fontSize: 13),
                ),
              ),
            ),
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
                  borderRadius: BorderRadius.circular(12),
                ),
                contentPadding: const EdgeInsets.all(14),
              ),
            ),
            const SizedBox(height: 12),

            // Surat dokter — hanya muncul saat jenis = sakit (wajib)
            if (_selectedType == 'sakit') ...[
              const SizedBox(height: 12),
              Row(
                children: [
                  const Text('Surat Dokter',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  const SizedBox(width: 4),
                  Text('*wajib',
                      style: TextStyle(
                          color: Colors.red.shade400,
                          fontWeight: FontWeight.bold,
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
                        : const Color(0xFFFFF3E0),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: _docBytes != null
                          ? const Color(0xFFA5D6A7)
                          : Colors.orange.shade200,
                    ),
                  ),
                  child: _docBytes == null
                      // Belum ada file
                      ? Row(
                          children: [
                            Icon(Icons.upload_file_outlined,
                                color: Colors.orange.shade700, size: 28),
                            const SizedBox(width: 12),
                            const Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text('Lampirkan surat dokter',
                                      style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          fontSize: 13)),
                                  SizedBox(height: 2),
                                  Text('Foto/gambar atau PDF · maks 10 MB',
                                      style: TextStyle(
                                          fontSize: 11, color: Colors.grey)),
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
                                      child: Icon(Icons.picture_as_pdf_outlined,
                                          color: Colors.red.shade600, size: 26),
                                    )
                                  : Image.memory(
                                      _docBytes!,
                                      width: 44,
                                      height: 44,
                                      fit: BoxFit.cover,
                                    ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    _docFileName ?? 'surat_dokter',
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
              const SizedBox(height: 12),
            ],

            // Info
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFFFF9C4),
                borderRadius: BorderRadius.circular(12),
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
                  backgroundColor: Theme.of(context).primaryColor,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
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
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        decoration: BoxDecoration(
          border: Border.all(color: Colors.grey.shade300),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          children: [
            const Icon(Icons.calendar_month_outlined,
                size: 18, color: Colors.blueGrey),
            const SizedBox(width: 8),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label,
                    style: const TextStyle(
                        fontSize: 10, color: Colors.grey)),
                Text(value,
                    style: const TextStyle(
                        fontSize: 13, fontWeight: FontWeight.bold)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
