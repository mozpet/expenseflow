import 'package:flutter_test/flutter_test.dart';
import 'package:cobain/providers/receipt_provider.dart';

void main() {
  group('ReceiptRecord showDuplicateWarning Tests', () {
    test('shows duplicate warning when isPotentialDuplicate is true and status is submitted (menunggu)', () {
      final receipt = ReceiptRecord(
        id: 1,
        receiptNumber: 'RCP-001',
        isPotentialDuplicate: true,
        status: 'submitted',
        ocrStatus: 'done',
        createdAt: '2026-09-09',
      );

      expect(receipt.showDuplicateWarning, isTrue);
    });

    test('shows duplicate warning when isPotentialDuplicate is true and status is draft', () {
      final receipt = ReceiptRecord(
        id: 2,
        receiptNumber: 'RCP-002',
        isPotentialDuplicate: true,
        status: 'draft',
        ocrStatus: 'done',
        createdAt: '2026-09-09',
      );

      expect(receipt.showDuplicateWarning, isTrue);
    });

    test('hides duplicate warning when receipt is approved by finance', () {
      final receipt = ReceiptRecord(
        id: 3,
        receiptNumber: 'RCP-003',
        isPotentialDuplicate: true,
        status: 'approved',
        ocrStatus: 'done',
        createdAt: '2026-09-09',
      );

      expect(receipt.showDuplicateWarning, isFalse);
    });

    test('hides duplicate warning when receipt is paid / cair', () {
      final receipt = ReceiptRecord(
        id: 4,
        receiptNumber: 'RCP-004',
        isPotentialDuplicate: true,
        status: 'paid',
        ocrStatus: 'done',
        createdAt: '2026-09-09',
      );

      expect(receipt.showDuplicateWarning, isFalse);
    });

    test('returns false when isPotentialDuplicate is false regardless of status', () {
      final receipt = ReceiptRecord(
        id: 5,
        receiptNumber: 'RCP-005',
        isPotentialDuplicate: false,
        status: 'submitted',
        ocrStatus: 'done',
        createdAt: '2026-09-09',
      );

      expect(receipt.showDuplicateWarning, isFalse);
    });
  });
}
