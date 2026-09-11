import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';
import '../services/api_service.dart';

/// Widget thumbnail foto struk yang aman (dengan autentikasi Bearer token)
/// dan dilengkapi in-memory cache serta zoom preview saat diketuk.
class ReceiptThumbnail extends StatefulWidget {
  final int receiptId;
  final String? imagePath;
  final double? size;
  final double width;
  final double height;
  final BorderRadius? borderRadius;
  final bool enableZoomPreview;

  const ReceiptThumbnail({
    super.key,
    required this.receiptId,
    this.imagePath,
    this.size,
    this.width = 46,
    this.height = 46,
    this.borderRadius,
    this.enableZoomPreview = true,
  });

  /// In-memory cache agar foto tidak diunduh berulang kali saat scroll list
  static final Map<int, Uint8List> _imageCache = {};

  /// Clear cache jika foto struk diperbarui
  static void evict(int receiptId) {
    _imageCache.remove(receiptId);
  }

  @override
  State<ReceiptThumbnail> createState() => _ReceiptThumbnailState();
}

class _ReceiptThumbnailState extends State<ReceiptThumbnail> {
  Uint8List? _bytes;
  bool _isLoading = false;
  bool _hasError = false;

  @override
  void initState() {
    super.initState();
    _loadImage();
  }

  @override
  void didUpdateWidget(covariant ReceiptThumbnail oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.receiptId != widget.receiptId) {
      _loadImage();
    }
  }

  Future<void> _loadImage() async {
    if (ReceiptThumbnail._imageCache.containsKey(widget.receiptId)) {
      if (mounted) {
        setState(() {
          _bytes = ReceiptThumbnail._imageCache[widget.receiptId];
          _isLoading = false;
          _hasError = false;
        });
      }
      return;
    }

    setState(() {
      _isLoading = true;
      _hasError = false;
    });

    try {
      final token = await ApiService.getToken();
      final uri = Uri.parse('${ApiConfig.baseUrl}/employee/receipts/${widget.receiptId}/image');
      final res = await http.get(uri, headers: {
        'Accept': 'image/*, application/json',
        if (token != null && token.isNotEmpty) 'Authorization': 'Bearer $token',
        'X-Platform': 'mobile',
      }).timeout(const Duration(seconds: 15));

      if (!mounted) return;

      if (res.statusCode == 200 && res.bodyBytes.isNotEmpty) {
        ReceiptThumbnail._imageCache[widget.receiptId] = res.bodyBytes;
        setState(() {
          _bytes = res.bodyBytes;
          _isLoading = false;
          _hasError = false;
        });
      } else {
        setState(() {
          _isLoading = false;
          _hasError = true;
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _isLoading = false;
          _hasError = true;
        });
      }
    }
  }

  void _showZoomPreview() {
    if (_bytes == null || !widget.enableZoomPreview) return;

    showDialog(
      context: context,
      builder: (ctx) => Dialog(
        backgroundColor: Colors.transparent,
        insetPadding: const EdgeInsets.all(16),
        child: Stack(
          alignment: Alignment.center,
          children: [
            InteractiveViewer(
              minScale: 0.8,
              maxScale: 4.0,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.memory(
                  _bytes!,
                  fit: BoxFit.contain,
                ),
              ),
            ),
            Positioned(
              top: 8,
              right: 8,
              child: CircleAvatar(
                backgroundColor: Colors.black54,
                radius: 18,
                child: IconButton(
                  icon: const Icon(Icons.close, color: Colors.white, size: 18),
                  onPressed: () => Navigator.pop(ctx),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final effectiveWidth = widget.size ?? widget.width;
    final effectiveHeight = widget.size ?? widget.height;
    final radius = widget.borderRadius ?? BorderRadius.circular(8);

    Widget content;
    if (_isLoading) {
      content = Container(
        width: effectiveWidth,
        height: effectiveHeight,
        color: Colors.grey.shade200,
        child: Center(
          child: SizedBox(
            width: effectiveWidth * 0.35,
            height: effectiveWidth * 0.35,
            child: const CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    } else if (_hasError || _bytes == null) {
      content = Container(
        width: effectiveWidth,
        height: effectiveHeight,
        color: Colors.grey.shade100,
        child: Icon(
          Icons.receipt_outlined,
          size: effectiveWidth * 0.5,
          color: Colors.grey.shade400,
        ),
      );
    } else {
      content = Image.memory(
        _bytes!,
        width: effectiveWidth,
        height: effectiveHeight,
        fit: BoxFit.cover,
        cacheWidth: 300,
      );
    }

    return InkWell(
      onTap: (_bytes != null && widget.enableZoomPreview) ? _showZoomPreview : null,
      borderRadius: radius,
      child: ClipRRect(
        borderRadius: radius,
        child: Container(
          decoration: BoxDecoration(
            border: Border.all(color: Colors.grey.shade300, width: 0.8),
            borderRadius: radius,
          ),
          child: content,
        ),
      ),
    );
  }
}
