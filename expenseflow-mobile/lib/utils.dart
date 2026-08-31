/// Shared utility functions
library;

/// Formats a numeric amount into Indonesian Rupiah notation.
/// Example: 187500 → 'Rp 187.500'
String formatCurrency(double amount) {
  return 'Rp ${amount.toStringAsFixed(0).replaceAllMapped(RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'), (Match m) => '${m[1]}.')}';
}

/// Mengubah DateTime atau string tanggal (YYYY-MM-DD) menjadi format Indonesia lengkap.
/// Contoh: '2023-06-12' atau DateTime(2023, 6, 12) → '12 Juni 2023'
String formatDateIndonesian(dynamic date) {
  if (date == null) return '-';
  DateTime? dt;
  if (date is DateTime) {
    dt = date;
  } else if (date is String) {
    if (date.isEmpty) return '-';
    final raw = date.length >= 10 ? date.substring(0, 10) : date;
    dt = DateTime.tryParse(raw);
  }
  if (dt == null) return date.toString();
  const months = [
    '',
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
  ];
  return '${dt.day} ${months[dt.month]} ${dt.year}';
}

/// Mengubah rentang tanggal menjadi format Indonesia lengkap.
/// Contoh: '2023-06-12' s.d. '2023-06-14' → '12 Juni 2023 — 14 Juni 2023'
/// Jika tanggal sama → '12 Juni 2023'
String formatDateIndonesianRange(dynamic start, dynamic end) {
  final s = formatDateIndonesian(start);
  final e = formatDateIndonesian(end);
  if (s == e || end == null) return s;
  return '$s — $e';
}
