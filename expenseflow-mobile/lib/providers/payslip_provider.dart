import 'package:flutter/material.dart';
import '../models/payslip_model.dart';
import '../services/api_service.dart';

/// State management untuk fitur Slip Gaji (payroll) sisi karyawan.
///
/// Mengelola dua hal terpisah:
///  1. Daftar slip gaji milik karyawan ([payslips]).
///  2. Rincian satu slip yang sedang dibuka ([detail]).
class PayslipProvider extends ChangeNotifier {
  // ─── State daftar slip ──────────────────────────────────────
  bool _loadingList = false;
  String? _errorList;
  List<Payslip> _payslips = [];

  bool get loadingList => _loadingList;
  String? get errorList => _errorList;
  List<Payslip> get payslips => _payslips;

  // ─── State rincian slip ─────────────────────────────────────
  bool _loadingDetail = false;
  String? _errorDetail;
  PayslipDetail? _detail;

  bool get loadingDetail => _loadingDetail;
  String? get errorDetail => _errorDetail;
  PayslipDetail? get detail => _detail;

  /// Ambil daftar slip gaji milik karyawan.
  Future<void> fetchPayslips({bool forceRefresh = false}) async {
    _loadingList = true;
    _errorList = null;
    notifyListeners();

    try {
      final res = await ApiService.getMyPayslips(forceRefresh: forceRefresh);
      final list = res['data'];
      if (list is List) {
        _payslips = list
            .whereType<Map>()
            .map((e) => Payslip.fromJson(e.cast<String, dynamic>()))
            .toList();
      } else {
        _payslips = [];
      }
    } catch (e) {
      _errorList = e.toString();
    } finally {
      _loadingList = false;
      notifyListeners();
    }
  }

  /// Ambil rincian satu slip gaji berdasarkan [id].
  Future<void> fetchDetail(int id, {bool forceRefresh = false}) async {
    _loadingDetail = true;
    _errorDetail = null;
    _detail = null;
    notifyListeners();

    try {
      final res =
          await ApiService.getPayslipDetail(id, forceRefresh: forceRefresh);
      final data = res['data'];
      if (data is Map) {
        _detail = PayslipDetail.fromJson(data.cast<String, dynamic>());
      } else {
        _errorDetail = 'Data slip gaji tidak ditemukan.';
      }
    } catch (e) {
      _errorDetail = e.toString();
    } finally {
      _loadingDetail = false;
      notifyListeners();
    }
  }

  /// Bersihkan rincian saat keluar dari layar detail.
  void clearDetail() {
    _detail = null;
    _errorDetail = null;
    _loadingDetail = false;
  }
}
