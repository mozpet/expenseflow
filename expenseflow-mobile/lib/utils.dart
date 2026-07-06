/// Shared utility functions
library;

/// Formats a numeric amount into Indonesian Rupiah notation.
/// Example: 187500 → 'Rp 187.500'
String formatCurrency(double amount) {
  return 'Rp ${amount.toStringAsFixed(0).replaceAllMapped(RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'), (Match m) => '${m[1]}.')}';
}
