import 'dart:io';

class ImageService {
  /// Returns the file as-is (no compression for now)
  /// Image picker already compresses during capture
  static Future<File?> compressFile(
    File file, {
    int quality = 85,
    int maxWidth = 1280,
    int maxHeight = 1280,
  }) async {
    return file;
  }
}

