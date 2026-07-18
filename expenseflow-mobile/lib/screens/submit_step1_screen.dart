import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../photo_provider.dart';
import 'submit_step2_screen.dart';

class SubmitStep1Screen extends StatefulWidget {
  const SubmitStep1Screen({super.key});

  @override
  State<SubmitStep1Screen> createState() => _SubmitStep1ScreenState();
}

class _SubmitStep1ScreenState extends State<SubmitStep1Screen> {
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
    if (photoProv.bytes != null) {
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (context) => SubmitStep2Screen(
            imageBytes: photoProv.bytes!,
            fileName: photoProv.fileName ?? 'struk.jpg',
          ),
        ),
      );
    } else if (photoProv.error != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(photoProv.error!)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => PhotoProvider(),
      child: Consumer<PhotoProvider>(
        builder: (context, photoProv, child) {
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
                  // Dashed Area Emulator
                  GestureDetector(
                    onTap: () => _handlePickPhoto(photoProv),
                    child: Container(
                      height: 180,
                      decoration: BoxDecoration(
                        color: const Color(0xFFE3F2FD).withValues(alpha: 0.5),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: Colors.blue, width: 2),
                      ),
                      child: RepaintBoundary(
                        child: Stack(
                          fit: StackFit.expand,
                          children: [
                            if (photoProv.bytes != null && !photoProv.isPdf)
                              // Preview gambar
                              ClipRRect(
                                borderRadius: BorderRadius.circular(14),
                                child: Image.memory(
                                  photoProv.bytes!,
                                  fit: BoxFit.cover,
                                  cacheWidth: 800,
                                ),
                              )
                            else if (photoProv.bytes != null && photoProv.isPdf)
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
                                    Icons.upload_file_outlined,
                                    color: Theme.of(context).primaryColor,
                                    size: 48,
                                  ),
                                  const SizedBox(height: 8),
                                  const Text(
                                    'Foto, Galeri, atau File PDF',
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      color: Colors.blueAccent,
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  const Text(
                                    'JPG · PNG · WEBP · PDF',
                                    style: TextStyle(fontSize: 11, color: Colors.grey),
                                  ),
                                ],
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

                  // Warning Banner
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF9C4),
                      borderRadius: BorderRadius.circular(12),
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
