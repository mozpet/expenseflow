/// Konfigurasi alamat backend Laravel.
///
/// Ganti [baseUrl] sesuai target test:
/// - Browser (Flutter Web) / Desktop  : http://127.0.0.1:8000/api/v1   (default)
/// - Emulator Android                 : http://10.0.2.2:8000/api/v1
/// - HP fisik (WiFi sama)             : http://[IP-LAN-komputer]:8000/api/v1
///
/// Catatan: untuk HP fisik, jalankan backend dengan:
///   php artisan serve --host=0.0.0.0
/// lalu cek IP komputer (mis. 192.168.1.5) dan pakai di sini.
class ApiConfig {
  static const String baseUrl = 'http://127.0.0.1:8000/api/v1';
  // static const String baseUrl = 'http://10.0.2.2:8000/api/v1';
  // static const String baseUrl = 'http://192.168.1.5:8000/api/v1';
}
