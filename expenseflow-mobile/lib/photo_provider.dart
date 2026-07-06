import 'package:flutter/foundation.dart';
import 'package:file_picker/file_picker.dart';
import 'package:image_picker/image_picker.dart';

/// Provider foto/file struk — berbasis bytes agar jalan di semua platform.
/// Mendukung gambar (jpg/png/webp) dari kamera/galeri, serta PDF dari file picker.
class PhotoProvider extends ChangeNotifier {
  Uint8List? bytes;
  String? fileName;
  bool isLoading = false;
  String? error;
  bool isPdf = false;

  final ImagePicker _picker = ImagePicker();

  Future<void> pickFromCamera() async {
    await _pickImage(ImageSource.camera);
  }

  Future<void> pickFromGallery() async {
    await _pickImage(ImageSource.gallery);
  }

  /// Pilih file dari penyimpanan — mendukung gambar (jpg/png/webp) dan PDF.
  Future<void> pickFromFile() async {
    try {
      isLoading = true;
      error = null;
      notifyListeners();

      final result = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'],
        withData: true, // muat bytes langsung, jalan di web & mobile
      );

      if (result == null || result.files.isEmpty) {
        isLoading = false;
        notifyListeners();
        return;
      }

      final file = result.files.first;
      bytes    = file.bytes;
      fileName = file.name.isNotEmpty ? file.name : 'struk.pdf';
      isPdf    = fileName!.toLowerCase().endsWith('.pdf');
    } catch (e) {
      error = 'Gagal memilih file: $e';
    } finally {
      isLoading = false;
      notifyListeners();
    }
  }

  Future<void> _pickImage(ImageSource source) async {
    try {
      isLoading = true;
      error = null;
      notifyListeners();

      final XFile? picked = await _picker.pickImage(
        source: source,
        maxWidth: 1280,
        maxHeight: 1280,
        imageQuality: 80,
      );

      if (picked == null) {
        isLoading = false;
        notifyListeners();
        return;
      }

      bytes    = await picked.readAsBytes();
      isPdf    = false;
      final name = picked.name;
      fileName = name.contains('.') ? name : 'struk.jpg';
    } catch (e) {
      error = e.toString();
    } finally {
      isLoading = false;
      notifyListeners();
    }
  }

  void clear() {
    bytes    = null;
    fileName = null;
    isPdf    = false;
    error    = null;
    notifyListeners();
  }
}
