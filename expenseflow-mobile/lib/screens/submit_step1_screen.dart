import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../photo_provider.dart';
import 'submit_step2_screen.dart';

class SubmitStep1Screen extends StatefulWidget {
  final int? preselectedReportId;
  const SubmitStep1Screen({super.key, this.preselectedReportId});

  @override
  State<SubmitStep1Screen> createState() => _SubmitStep1ScreenState();
}

class _SubmitStep1ScreenState extends State<SubmitStep1Screen> {
  int? _preselectedReportId;

  @override
  void initState() {
    super.initState();
    _preselectedReportId = widget.preselectedReportId;
  }
  // Tampilkan pilihan sumber (kamera / galeri / file PDF), lalu navigasi.
  Future<void> _handlePickPhoto(PhotoProvider photoProv) async {
    // Tipe pilihan: 0=kamera, 1=galeri, 2=file/pdf
    final choice = await showModalBottomSheet<int>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 8),
            Container(
              width: 36, height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 12),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 16),
              child: Text(
                'Pilih sumber file',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
              ),
            ),
            const SizedBox(height: 4),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 16),
              child: Text(
                'Format yang diterima: JPG, PNG, WEBP, PDF (maks 10 MB)',
                style: TextStyle(fontSize: 11, color: Colors.grey),
              ),
            ),
            const SizedBox(height: 8),
            ListTile(
              leading: const CircleAvatar(
                backgroundColor: Color(0xFFE3F2FD),
                child: Icon(Icons.camera_alt_outlined, color: Colors.blue),
              ),
              title: const Text('Kamera'),
              subtitle: const Text('Foto langsung dari kamera', style: TextStyle(fontSize: 11)),
              onTap: () => Navigator.pop(ctx, 0),
            ),
            ListTile(
              leading: const CircleAvatar(
                backgroundColor: Color(0xFFF3E5F5),
                child: Icon(Icons.photo_library_outlined, color: Colors.purple),
              ),
              title: const Text('Galeri'),
              subtitle: const Text('Pilih foto dari galeri', style: TextStyle(fontSize: 11)),
              onTap: () => Navigator.pop(ctx, 1),
            ),
            ListTile(
              leading: const CircleAvatar(
                backgroundColor: Color(0xFFFFEBEE),
                child: Icon(Icons.picture_as_pdf_outlined, color: Colors.red),
              ),
              title: const Text('File / PDF'),
              subtitle: const Text('Pilih file gambar atau PDF dari penyimpanan', style: TextStyle(fontSize: 11)),
              onTap: () => Navigator.pop(ctx, 2),
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );

    if (choice == null) return;

    if (choice == 0) {
      await photoProv.pickFromCamera();
    } else if (choice == 1) {
      await photoProv.pickFromGallery();
    } else {
      await photoProv.pickFromFile();
    }

    if (!mounted) return;
    if (photoProv.error != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(photoProv.error!)),
      );
    }
  }

  Future<void> _handlePickAdditionalPhoto(PhotoProvider photoProv) async {
    final choice = await showModalBottomSheet<int>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 8),
            Container(
              width: 36, height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 12),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 16),
              child: Text(
                'Tambah Foto Lampiran (Slip EDC / Rincian)',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
              ),
            ),
            const SizedBox(height: 4),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 16),
              child: Text(
                'Membantu Finance memverifikasi transaksi pembayaran (Maks. 3 foto)',
                style: TextStyle(fontSize: 11, color: Colors.grey),
              ),
            ),
            const SizedBox(height: 8),
            ListTile(
              leading: const CircleAvatar(
                backgroundColor: Color(0xFFE3F2FD),
                child: Icon(Icons.camera_alt_outlined, color: Colors.blue),
              ),
              title: const Text('Foto dari Kamera'),
              subtitle: const Text('Ambil foto slip EDC / nota tambahan', style: TextStyle(fontSize: 11)),
              onTap: () => Navigator.pop(ctx, 0),
            ),
            ListTile(
              leading: const CircleAvatar(
                backgroundColor: Color(0xFFF3E5F5),
                child: Icon(Icons.photo_library_outlined, color: Colors.purple),
              ),
              title: const Text('Pilih dari Galeri'),
              subtitle: const Text('Pilih foto dari album galeri', style: TextStyle(fontSize: 11)),
              onTap: () => Navigator.pop(ctx, 1),
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );

    if (choice == null) return;
    if (choice == 0) {
      await photoProv.pickAdditionalFromCamera();
    } else {
      await photoProv.pickAdditionalFromGallery();
    }

    if (!mounted) return;
    if (photoProv.error != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(photoProv.error!)),
      );
    }
  }

  void _goToStep2(PhotoProvider photoProv) async {
    if (photoProv.bytes == null) return;
    final result = await Navigator.push<Map<String, dynamic>?>(
      context,
      MaterialPageRoute(
        builder: (context) => SubmitStep2Screen(
          imageBytes: photoProv.bytes!,
          fileName: photoProv.fileName ?? 'struk.jpg',
          additionalPhotos: photoProv.additionalBytes.isNotEmpty
              ? List.from(photoProv.additionalBytes)
              : null,
          additionalFileNames: photoProv.additionalFileNames.isNotEmpty
              ? List.from(photoProv.additionalFileNames)
              : null,
          preselectedReportId: _preselectedReportId,
        ),
      ),
    );

    if (result != null && result['continueScan'] == true) {
      final nextReportId = result['reportId'] as int?;
      if (mounted) {
        setState(() {
          _preselectedReportId = nextReportId;
        });
        photoProv.clear();
        await photoProv.pickFromCamera();
        if (photoProv.bytes != null && mounted) {
          _goToStep2(photoProv);
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => PhotoProvider(),
      child: Consumer<PhotoProvider>(
        builder: (context, photoProv, child) {
          final hasMainPhoto = photoProv.bytes != null;

          return Scaffold(
            appBar: AppBar(
              title: const Text('Foto Struk'),
              bottom: PreferredSize(
                preferredSize: const Size.fromHeight(24),
                child: Container(
                  color: Colors.transparent,
                  padding: const EdgeInsets.only(bottom: 8),
                  child: const Text(
                    'Langkah 1 dari 2',
                    style: TextStyle(color: Colors.white70, fontSize: 12),
                  ),
                ),
              ),
            ),
            body: SingleChildScrollView(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_preselectedReportId != null) ...[
                    Container(
                      margin: const EdgeInsets.only(bottom: 14),
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                      decoration: BoxDecoration(
                        color: const Color(0xFFF5F3FF),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: const Color(0xFFDDD6FE)),
                      ),
                      child: const Row(
                        children: [
                          Icon(Icons.folder_shared_outlined, color: Color(0xFF7C3AED), size: 18),
                          SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'Mode Multi-Struk: Struk otomatis digabung ke Laporan Dinas',
                              style: TextStyle(
                                color: Color(0xFF6D28D9),
                                fontSize: 11.5,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                  // Dashed Area / Preview Area
                  GestureDetector(
                    onTap: () => _handlePickPhoto(photoProv),
                    child: Container(
                      height: 200,
                      decoration: BoxDecoration(
                        color: hasMainPhoto
                            ? Colors.black.withValues(alpha: 0.03)
                            : const Color(0xFFE3F2FD).withValues(alpha: 0.5),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                          color: hasMainPhoto ? Colors.blue.shade300 : Colors.blue,
                          width: 2,
                        ),
                      ),
                      child: RepaintBoundary(
                        child: Stack(
                          fit: StackFit.expand,
                          children: [
                            if (hasMainPhoto && !photoProv.isPdf)
                              // Preview gambar
                              ClipRRect(
                                borderRadius: BorderRadius.circular(10),
                                child: Image.memory(
                                  photoProv.bytes!,
                                  fit: BoxFit.cover,
                                  cacheWidth: 800,
                                ),
                              )
                            else if (hasMainPhoto && photoProv.isPdf)
                              // Preview PDF — tampilkan ikon + nama file
                              Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  const Icon(
                                    Icons.picture_as_pdf_outlined,
                                    color: Colors.red,
                                    size: 52,
                                  ),
                                  const SizedBox(height: 8),
                                  Text(
                                    photoProv.fileName ?? 'struk.pdf',
                                    style: const TextStyle(
                                      fontSize: 12,
                                      fontWeight: FontWeight.bold,
                                      color: Colors.black87,
                                    ),
                                    textAlign: TextAlign.center,
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                  const SizedBox(height: 4),
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                                    decoration: BoxDecoration(
                                      color: Colors.red.shade50,
                                      borderRadius: BorderRadius.circular(6),
                                    ),
                                    child: const Text(
                                      'PDF siap diunggah',
                                      style: TextStyle(fontSize: 11, color: Colors.red),
                                    ),
                                  ),
                                ],
                              )
                            else
                              Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(
                                    Icons.add_a_photo_outlined,
                                    color: Theme.of(context).primaryColor,
                                    size: 48,
                                  ),
                                  const SizedBox(height: 8),
                                  const Text(
                                    'Pilih Foto Struk Utama',
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      color: Colors.blueAccent,
                                      fontSize: 15,
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  const Text(
                                    'Kamera · Galeri · PDF (Maks 10 MB)',
                                    style: TextStyle(fontSize: 11, color: Colors.grey),
                                  ),
                                ],
                              ),
                            if (hasMainPhoto)
                              Positioned(
                                top: 8,
                                right: 8,
                                child: Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                  decoration: BoxDecoration(
                                    color: Colors.black.withValues(alpha: 0.65),
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  child: const Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Icon(Icons.check_circle, color: Colors.greenAccent, size: 14),
                                      SizedBox(width: 4),
                                      Text(
                                        'Struk Utama (OCR)',
                                        style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            if (hasMainPhoto)
                              Positioned(
                                bottom: 8,
                                right: 8,
                                child: Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                  decoration: BoxDecoration(
                                    color: Colors.white.withValues(alpha: 0.9),
                                    borderRadius: BorderRadius.circular(6),
                                    boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4)],
                                  ),
                                  child: const Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Icon(Icons.refresh, size: 13, color: Colors.blue),
                                      SizedBox(width: 4),
                                      Text(
                                        'Ketuk untuk ganti',
                                        style: TextStyle(fontSize: 11, color: Colors.blue, fontWeight: FontWeight.bold),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            if (photoProv.isLoading)
                              Container(
                                color: Colors.black26,
                                child: const Center(
                                  child: CircularProgressIndicator(),
                                ),
                              ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),

                  // Section Foto Tambahan (Slip EDC / Nota Rincian)
                  if (hasMainPhoto) ...[
                    Card(
                      elevation: 0,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(10),
                        side: BorderSide(color: Colors.grey.shade200),
                      ),
                      color: Colors.grey.shade50,
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                const Row(
                                  children: [
                                    Icon(Icons.attachment, size: 18, color: Colors.blueGrey),
                                    SizedBox(width: 6),
                                    Text(
                                      'Lampiran Tambahan (Opsional)',
                                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                    ),
                                  ],
                                ),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                  decoration: BoxDecoration(
                                    color: Colors.blue.shade50,
                                    borderRadius: BorderRadius.circular(10),
                                  ),
                                  child: Text(
                                    '${photoProv.additionalBytes.length}/3 Foto',
                                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.blue.shade800),
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 4),
                            const Text(
                              'Lampirkan Slip EDC Debit/Kredit, QRIS receipt, atau nota rincian item jika ada.',
                              style: TextStyle(fontSize: 11, color: Colors.black54),
                            ),
                            const SizedBox(height: 10),

                            // List Thumbnail Lampiran
                            if (photoProv.additionalBytes.isNotEmpty)
                              SizedBox(
                                height: 72,
                                child: ListView.separated(
                                  scrollDirection: Axis.horizontal,
                                  itemCount: photoProv.additionalBytes.length,
                                  separatorBuilder: (context, index) => const SizedBox(width: 8),
                                  itemBuilder: (context, idx) {
                                    return Stack(
                                      clipBehavior: Clip.none,
                                      children: [
                                        Container(
                                          width: 72,
                                          height: 72,
                                          decoration: BoxDecoration(
                                            borderRadius: BorderRadius.circular(8),
                                            border: Border.all(color: Colors.blue.shade200),
                                          ),
                                          child: ClipRRect(
                                            borderRadius: BorderRadius.circular(7),
                                            child: Image.memory(
                                              photoProv.additionalBytes[idx],
                                              fit: BoxFit.cover,
                                            ),
                                          ),
                                        ),
                                        Positioned(
                                          top: -6,
                                          right: -6,
                                          child: GestureDetector(
                                            onTap: () => photoProv.removeAdditionalPhoto(idx),
                                            child: Container(
                                              padding: const EdgeInsets.all(2),
                                              decoration: const BoxDecoration(
                                                color: Colors.red,
                                                shape: BoxShape.circle,
                                              ),
                                              child: const Icon(Icons.close, color: Colors.white, size: 14),
                                            ),
                                          ),
                                        ),
                                        Positioned(
                                          bottom: 2,
                                          left: 2,
                                          right: 2,
                                          child: Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 1),
                                            decoration: BoxDecoration(
                                              color: Colors.black.withValues(alpha: 0.6),
                                              borderRadius: BorderRadius.circular(4),
                                            ),
                                            child: Text(
                                              '#${idx + 1}',
                                              style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold),
                                              textAlign: TextAlign.center,
                                            ),
                                          ),
                                        ),
                                      ],
                                    );
                                  },
                                ),
                              ),

                            if (photoProv.additionalBytes.length < 3) ...[
                              if (photoProv.additionalBytes.isNotEmpty) const SizedBox(height: 8),
                              OutlinedButton.icon(
                                onPressed: () => _handlePickAdditionalPhoto(photoProv),
                                icon: const Icon(Icons.add_photo_alternate_outlined, size: 18),
                                label: Text(
                                  photoProv.additionalBytes.isEmpty
                                      ? '+ Tambah Slip EDC / Bukti Bayar'
                                      : '+ Tambah Foto Lagi (${3 - photoProv.additionalBytes.length} tersisa)',
                                  style: const TextStyle(fontSize: 12),
                                ),
                                style: OutlinedButton.styleFrom(
                                  visualDensity: VisualDensity.compact,
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),

                    // Tombol Lanjut ke Step 2
                    ElevatedButton.icon(
                      onPressed: () => _goToStep2(photoProv),
                      icon: const Icon(Icons.arrow_forward),
                      label: const Text(
                        'Lanjut ke Verifikasi & OCR',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                      ),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Theme.of(context).primaryColor,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10),
                        ),
                        elevation: 2,
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],

                  // Warning Banner
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF9C4),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: const Color(0xFFFFF59D)),
                    ),
                    child: const Text(
                      'File akan langsung dikunci & tidak bisa diganti setelah dikirim. Pastikan foto/PDF jelas dan terbaca.',
                      style: TextStyle(
                        fontSize: 12,
                        color: Color(0xFF827717),
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                  const SizedBox(height: 24),

                  // Checklist
                  const Text(
                    'Checklist sebelum foto',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                  ),
                  const SizedBox(height: 12),
                  _buildCheckItem('Struk tidak terlipat / terpotong'),
                  _buildCheckItem('Nominal dan tanggal terbaca jelas'),
                  _buildCheckItem('Pencahayaan cukup, tidak gelap'),
                  _buildCheckItem('Nama toko / merchant terlihat'),

                  const SizedBox(height: 24),

                  // OCR System Explanation
                  const Text(
                    'Cara kerja sistem',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                  ),
                  const SizedBox(height: 12),
                  _buildStepItem(
                    1,
                    'Foto dikunci otomatis',
                    'Gambar asli disimpan di server, tidak bisa dihapus atau diganti oleh siapapun',
                  ),
                  _buildStepItem(
                    2,
                    'OCR baca struk',
                    'Sistem otomatis baca nominal, merchant, tanggal - hasil ini tersimpan permanen',
                  ),
                  _buildStepItem(
                    3,
                    'Finance bandingkan',
                    'Finance melihat foto asli vs klaim Anda secara berdampingan sebelum approve',
                  ),

                  const SizedBox(height: 32),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildCheckItem(String label) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8.0),
      child: Row(
        children: [
          const Icon(Icons.check_circle, color: Colors.green, size: 20),
          const SizedBox(width: 8),
          Text(label, style: const TextStyle(fontSize: 14)),
        ],
      ),
    );
  }

  Widget _buildStepItem(int num, String title, String desc) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(
            radius: 12,
            backgroundColor: Colors.blue.shade800,
            child: Text(
              num.toString(),
              style: const TextStyle(
                color: Colors.white,
                fontSize: 12,
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
          const SizedBox(width: 12),
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
                const SizedBox(height: 2),
                Text(
                  desc,
                  style: const TextStyle(color: Colors.grey, fontSize: 12),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
