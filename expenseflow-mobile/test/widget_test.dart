// This is a basic Flutter widget test.
//
// To perform an interaction with a widget in your test, use the WidgetTester
// utility in the flutter_test package. For example, you can send tap and scroll
// gestures. You can also use WidgetTester to find child widgets in the widget
// tree, read text, and verify that the values of widget properties are correct.

import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:cobain/main.dart';
import 'package:cobain/presensi_provider.dart';
import 'package:cobain/providers/auth_provider.dart';
import 'package:cobain/providers/receipt_provider.dart';
import 'package:cobain/providers/shift_provider.dart';

void main() {
  testWidgets('ExpenseFlow login screen smoke test', (WidgetTester tester) async {
    SharedPreferences.setMockInitialValues({});

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => AuthProvider()),
          ChangeNotifierProvider(create: (_) => PresensiProvider()),
          ChangeNotifierProvider(create: (_) => ReceiptProvider()),
          ChangeNotifierProvider(create: (_) => ShiftProvider()),
        ],
        child: const ExpenseFlowApp(),
      ),
    );
    await tester.pumpAndSettle();

    // Verify that the login screen title is shown.
    expect(find.text('ExpenseFlow'), findsOneWidget);
    expect(find.text('Portal Presensi & Keuangan Karyawan'), findsOneWidget);
  });
}
