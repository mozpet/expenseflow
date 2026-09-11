import 'package:flutter_test/flutter_test.dart';
import 'package:cobain/providers/receipt_provider.dart';

void main() {
  group('ReceiptRecord OCR & Blurry Status Tests', () {
    test('identifies blurry receipt correctly from ocrError keyword buram', () {
      final receipt = ReceiptRecord(
        id: 1,
        receiptNumber: 'RCP-20260911-0008',
        status: 'draft',
        ocrStatus: 'failed',
        ocrError: 'Foto struk buram dan tidak terbaca jelas',
        createdAt: '2026-09-11',
      );

      expect(receipt.isOcrFailed, isTrue);
      expect(receipt.isBlurry, isTrue);
      expect(receipt.isOcrSuccess, isFalse);
      expect(receipt.isOcrPending, isFalse);
    });

    test('identifies blurry receipt from blur / bergoyang keywords', () {
      final receipt = ReceiptRecord(
        id: 2,
        receiptNumber: 'RCP-20260911-0009',
        status: 'draft',
        ocrStatus: 'failed',
        ocrError: 'Gambar bergoyang (motion blur) terdeteksi',
        createdAt: '2026-09-11',
      );

      expect(receipt.isBlurry, isTrue);
    });

    test('non-blurry failure is not marked as isBlurry', () {
      final receipt = ReceiptRecord(
        id: 3,
        receiptNumber: 'RCP-20260911-0010',
        status: 'draft',
        ocrStatus: 'failed',
        ocrError: 'Server AI timeout',
        createdAt: '2026-09-11',
      );

      expect(receipt.isOcrFailed, isTrue);
      expect(receipt.isBlurry, isFalse);
    });

    test('successful ocr is marked as isOcrSuccess', () {
      final receipt = ReceiptRecord(
        id: 4,
        receiptNumber: 'RCP-20260911-0011',
        status: 'draft',
        ocrStatus: 'done',
        createdAt: '2026-09-11',
      );

      expect(receipt.isOcrSuccess, isTrue);
      expect(receipt.isOcrFailed, isFalse);
      expect(receipt.isBlurry, isFalse);
    });

    test('pending / processing ocr is marked as isOcrPending', () {
      final pending = ReceiptRecord(
        id: 5,
        receiptNumber: 'RCP-20260911-0012',
        status: 'draft',
        ocrStatus: 'pending',
        createdAt: '2026-09-11',
      );
      final processing = ReceiptRecord(
        id: 6,
        receiptNumber: 'RCP-20260911-0013',
        status: 'draft',
        ocrStatus: 'processing',
        createdAt: '2026-09-11',
      );

      expect(pending.isOcrPending, isTrue);
      expect(processing.isOcrPending, isTrue);
    });

    test('identifies non-receipt correctly from keywords in ocrError', () {
      final nonReceipt1 = ReceiptRecord(
        id: 7,
        receiptNumber: 'RCP-20260911-0014',
        status: 'draft',
        ocrStatus: 'failed',
        ocrError: 'Foto terdeteksi bukan struk pengeluaran valid',
        createdAt: '2026-09-11',
      );
      final nonReceipt2 = ReceiptRecord(
        id: 8,
        receiptNumber: 'RCP-20260911-0015',
        status: 'draft',
        ocrStatus: 'failed',
        ocrError: 'Gambar tidak tampak seperti struk atau nota pembayaran',
        createdAt: '2026-09-11',
      );

      expect(nonReceipt1.isNonReceipt, isTrue);
      expect(nonReceipt1.isOcrRejectedOrFailed, isTrue);
      expect(nonReceipt2.isNonReceipt, isTrue);
      expect(nonReceipt2.isOcrRejectedOrFailed, isTrue);
    });

    test('isOcrRejectedOrFailed returns true for any failed or blurry receipt', () {
      final blurry = ReceiptRecord(
        id: 9,
        receiptNumber: 'RCP-20260911-0016',
        status: 'draft',
        ocrStatus: 'failed',
        ocrError: 'Foto struk buram',
        createdAt: '2026-09-11',
      );
      final otherFailure = ReceiptRecord(
        id: 10,
        receiptNumber: 'RCP-20260911-0017',
        status: 'draft',
        ocrStatus: 'failed',
        ocrError: 'OCR extraction failed',
        createdAt: '2026-09-11',
      );
      final valid = ReceiptRecord(
        id: 11,
        receiptNumber: 'RCP-20260911-0018',
        status: 'draft',
        ocrStatus: 'done',
        createdAt: '2026-09-11',
      );

      expect(blurry.isOcrRejectedOrFailed, isTrue);
      expect(otherFailure.isOcrRejectedOrFailed, isTrue);
      expect(valid.isOcrRejectedOrFailed, isFalse);
    });

    test('fromJson parses ocr_error, ocr_status, and image_path correctly', () {
      final json = {
        'id': 101,
        'receipt_number': 'RCP-20260911-0099',
        'status': 'draft',
        'ocr_status': 'failed',
        'ocr_error': 'Foto struk buram atau tidak terbaca jelas',
        'image_path': 'receipts/xyz.jpg',
        'created_at': '2026-09-11 10:00:00',
      };

      final record = ReceiptRecord.fromJson(json);
      expect(record.id, 101);
      expect(record.receiptNumber, 'RCP-20260911-0099');
      expect(record.isBlurry, isTrue);
      expect(record.isOcrRejectedOrFailed, isTrue);
      expect(record.imagePath, 'receipts/xyz.jpg');
      expect(record.ocrError, contains('buram'));
    });
  });
}
