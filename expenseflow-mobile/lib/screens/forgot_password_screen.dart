import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../services/api_service.dart';

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  // Step: 1 = Input Email, 2 = Input OTP, 3 = New Password, 4 = Success
  int _currentStep = 1;

  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();

  // 6 digit controllers & focus nodes for OTP
  final List<TextEditingController> _otpControllers =
      List.generate(6, (_) => TextEditingController());
  final List<FocusNode> _otpFocusNodes = List.generate(6, (_) => FocusNode());

  bool _isLoading = false;
  bool _obscurePassword = true;
  bool _obscureConfirmPassword = true;

  // Anti-spam resend cooldown (detik)
  int _resendCountdown = 0;
  Timer? _countdownTimer;

  String? _resetToken;
  String? _debugOtp; // Hanya untuk development

  @override
  void dispose() {
    _countdownTimer?.cancel();
    _emailController.dispose();
    _passwordController.dispose();
    _confirmPasswordController.dispose();
    for (var c in _otpControllers) {
      c.dispose();
    }
    for (var f in _otpFocusNodes) {
      f.dispose();
    }
    super.dispose();
  }

  void _startResendCountdown(int seconds) {
    _countdownTimer?.cancel();
    setState(() => _resendCountdown = seconds);
    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      setState(() {
        _resendCountdown--;
        if (_resendCountdown <= 0) {
          timer.cancel();
          _resendCountdown = 0;
        }
      });
    });
  }

  void _showSnackBar(String message, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Row(
          children: [
            Icon(
              isError ? Icons.error_outline : Icons.check_circle_outline,
              color: Colors.white,
              size: 20,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                message,
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
              ),
            ),
          ],
        ),
        backgroundColor: isError ? const Color(0xFFE11D48) : const Color(0xFF10B981),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        margin: const EdgeInsets.all(16),
      ),
    );
  }

  // ─── STEP 1: Kirim OTP ──────────────────────────────────────
  Future<void> _handleSendOtp() async {
    final email = _emailController.text.trim();
    if (email.isEmpty || !email.contains('@')) {
      _showSnackBar('Masukkan format email yang valid.', isError: true);
      return;
    }

    FocusScope.of(context).unfocus();
    setState(() => _isLoading = true);

    try {
      final res = await ApiService.sendForgotPasswordOtp(email);
      if (!mounted) return;

      final cooldown = (res['cooldown_seconds'] as int?) ?? 60;
      _startResendCountdown(cooldown);

      // Simpan debug otp jika ada
      if (res['debug_otp'] != null) {
        _debugOtp = res['debug_otp'].toString();
      }

      setState(() {
        _currentStep = 2;
      });

      _showSnackBar(res['message'] ?? 'Kode OTP telah dikirim ke email Anda.');
    } on ApiException catch (e) {
      if (e.retryAfter != null && e.retryAfter! > 0) {
        _startResendCountdown(e.retryAfter!);
      }
      _showSnackBar(e.message, isError: true);
    } catch (e) {
      _showSnackBar('Terjadi kesalahan. Silakan coba lagi.', isError: true);
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  // ─── STEP 2: Verifikasi OTP ──────────────────────────────────
  String _getEnteredOtp() {
    return _otpControllers.map((c) => c.text.trim()).join();
  }

  Future<void> _handleVerifyOtp() async {
    final otp = _getEnteredOtp();
    if (otp.length < 6) {
      _showSnackBar('Masukkan 6 digit kode OTP secara lengkap.', isError: true);
      return;
    }

    FocusScope.of(context).unfocus();
    setState(() => _isLoading = true);

    try {
      final res = await ApiService.verifyForgotPasswordOtp(
        _emailController.text.trim(),
        otp,
      );
      if (!mounted) return;

      _resetToken = res['reset_token'] as String?;

      setState(() {
        _currentStep = 3;
      });

      _showSnackBar('Kode OTP berhasil diverifikasi.');
    } on ApiException catch (e) {
      _showSnackBar(e.message, isError: true);
    } catch (e) {
      _showSnackBar('Gagal memverifikasi OTP.', isError: true);
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  // ─── STEP 3: Reset Password Baru ───────────────────────────
  Future<void> _handleResetPassword() async {
    final password = _passwordController.text;
    final confirmPassword = _confirmPasswordController.text;

    if (password.length < 8) {
      _showSnackBar('Password minimal 8 karakter.', isError: true);
      return;
    }

    if (password != confirmPassword) {
      _showSnackBar('Konfirmasi password tidak cocok.', isError: true);
      return;
    }

    if (_resetToken == null) {
      _showSnackBar('Sesi reset tidak valid. Silakan ulangi dari awal.', isError: true);
      setState(() => _currentStep = 1);
      return;
    }

    FocusScope.of(context).unfocus();
    setState(() => _isLoading = true);

    try {
      final res = await ApiService.resetPasswordWithOtp(
        email: _emailController.text.trim(),
        resetToken: _resetToken!,
        password: password,
        passwordConfirmation: confirmPassword,
      );
      if (!mounted) return;

      setState(() {
        _currentStep = 4;
      });

      _showSnackBar(res['message'] ?? 'Password berhasil diperbarui.');
    } on ApiException catch (e) {
      _showSnackBar(e.message, isError: true);
    } catch (e) {
      _showSnackBar('Gagal memperbarui password.', isError: true);
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    const primaryColor = Color(0xFF2563EB);

    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        backgroundColor: Colors.white,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Color(0xFF0F172A), size: 20),
          onPressed: () {
            if (_currentStep == 2) {
              setState(() => _currentStep = 1);
            } else if (_currentStep == 3) {
              setState(() => _currentStep = 2);
            } else {
              Navigator.pop(context);
            }
          },
        ),
        title: Text(
          _currentStep == 4 ? 'Berhasil' : 'Reset Password',
          style: const TextStyle(
            color: Color(0xFF0F172A),
            fontSize: 17,
            fontWeight: FontWeight.w700,
          ),
        ),
        centerTitle: true,
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 24.0, vertical: 16.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Step Progress Indicator
              if (_currentStep < 4) ...[
                Row(
                  children: [
                    _buildStepIndicator(1, 'Email', _currentStep >= 1, _currentStep == 1),
                    _buildStepLine(_currentStep >= 2),
                    _buildStepIndicator(2, 'OTP', _currentStep >= 2, _currentStep == 2),
                    _buildStepLine(_currentStep >= 3),
                    _buildStepIndicator(3, 'Password', _currentStep >= 3, _currentStep == 3),
                  ],
                ),
                const SizedBox(height: 32),
              ],

              // Content according to current step
              if (_currentStep == 1) _buildStep1Email(primaryColor),
              if (_currentStep == 2) _buildStep2Otp(primaryColor),
              if (_currentStep == 3) _buildStep3NewPassword(primaryColor),
              if (_currentStep == 4) _buildStep4Success(primaryColor),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildStepIndicator(int step, String label, bool isCompleted, bool isCurrent) {
    const primaryColor = Color(0xFF2563EB);
    return Column(
      children: [
        Container(
          width: 28,
          height: 28,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: isCompleted ? primaryColor : const Color(0xFFE2E8F0),
            border: isCurrent
                ? Border.all(color: primaryColor.withValues(alpha: 0.3), width: 3)
                : null,
          ),
          child: Center(
            child: isCompleted && !isCurrent
                ? const Icon(Icons.check, size: 14, color: Colors.white)
                : Text(
                    '$step',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                      color: isCompleted ? Colors.white : const Color(0xFF64748B),
                    ),
                  ),
          ),
        ),
        const SizedBox(height: 4),
        Text(
          label,
          style: TextStyle(
            fontSize: 11,
            fontWeight: isCurrent ? FontWeight.w700 : FontWeight.w500,
            color: isCurrent ? primaryColor : const Color(0xFF64748B),
          ),
        ),
      ],
    );
  }

  Widget _buildStepLine(bool isActive) {
    const primaryColor = Color(0xFF2563EB);
    return Expanded(
      child: Container(
        height: 2,
        margin: const EdgeInsets.only(bottom: 16),
        color: isActive ? primaryColor : const Color(0xFFE2E8F0),
      ),
    );
  }

  // ─── WIDGET STEP 1: Input Email ─────────────────────────────
  Widget _buildStep1Email(Color primaryColor) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(
            color: primaryColor.withValues(alpha: 0.1),
            borderRadius: BorderRadius.circular(20),
          ),
          child: Icon(Icons.mark_email_unread_outlined, color: primaryColor, size: 32),
        ),
        const SizedBox(height: 20),
        const Text(
          'Lupa Password Akun Anda?',
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
        ),
        const SizedBox(height: 8),
        const Text(
          'Masukkan alamat email yang terdaftar. Kami akan mengirimkan 6 digit kode OTP untuk memverifikasi akun Anda.',
          style: TextStyle(fontSize: 13, color: Color(0xFF64748B), height: 1.5),
        ),
        const SizedBox(height: 28),

        // Input Email
        TextFormField(
          controller: _emailController,
          keyboardType: TextInputType.emailAddress,
          textInputAction: TextInputAction.done,
          onFieldSubmitted: (_) => _handleSendOtp(),
          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500, color: Color(0xFF0F172A)),
          decoration: InputDecoration(
            hintText: 'nama@perusahaan.co.id',
            prefixIcon: const Icon(Icons.mail_outline_rounded, color: Color(0xFF64748B), size: 20),
            filled: true,
            fillColor: const Color(0xFFF8FAFC),
            contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide(color: primaryColor, width: 1.8),
            ),
          ),
        ),
        const SizedBox(height: 28),

        // Submit Button
        _buildPrimaryButton(
          label: 'Kirim Kode OTP',
          isLoading: _isLoading,
          onPressed: _isLoading ? null : _handleSendOtp,
          primaryColor: primaryColor,
        ),
      ],
    );
  }

  // ─── WIDGET STEP 2: Input 6 Digit OTP ────────────────────────
  Widget _buildStep2Otp(Color primaryColor) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(
            color: const Color(0xFF10B981).withValues(alpha: 0.1),
            borderRadius: BorderRadius.circular(20),
          ),
          child: const Icon(Icons.shield_outlined, color: Color(0xFF10B981), size: 32),
        ),
        const SizedBox(height: 20),
        const Text(
          'Masukkan Kode OTP',
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
        ),
        const SizedBox(height: 8),
        RichText(
          text: TextSpan(
            style: const TextStyle(fontSize: 13, color: Color(0xFF64748B), height: 1.5),
            children: [
              const TextSpan(text: 'Kode verifikasi 6-digit telah dikirim ke '),
              TextSpan(
                text: _emailController.text.trim(),
                style: const TextStyle(fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
              ),
              const TextSpan(text: '. Kode berlaku selama 5 menit.'),
            ],
          ),
        ),
        const SizedBox(height: 28),

        // 6 OTP Box Inputs
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: List.generate(6, (index) {
            return SizedBox(
              width: 46,
              height: 54,
              child: TextFormField(
                controller: _otpControllers[index],
                focusNode: _otpFocusNodes[index],
                keyboardType: TextInputType.number,
                textAlign: TextAlign.center,
                maxLength: 1,
                style: const TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w800,
                  color: Color(0xFF0F172A),
                ),
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: InputDecoration(
                  counterText: '',
                  filled: true,
                  fillColor: const Color(0xFFF8FAFC),
                  contentPadding: EdgeInsets.zero,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: primaryColor, width: 2),
                  ),
                ),
                onChanged: (value) {
                  if (value.isNotEmpty && index < 5) {
                    _otpFocusNodes[index + 1].requestFocus();
                  } else if (value.isEmpty && index > 0) {
                    _otpFocusNodes[index - 1].requestFocus();
                  }
                  if (_getEnteredOtp().length == 6) {
                    _handleVerifyOtp();
                  }
                },
              ),
            );
          }),
        ),

        // Debug OTP auto fill helper (Dev Mode)
        if (_debugOtp != null) ...[
          const SizedBox(height: 16),
          InkWell(
            onTap: () {
              for (int i = 0; i < 6 && i < _debugOtp!.length; i++) {
                _otpControllers[i].text = _debugOtp![i];
              }
              _handleVerifyOtp();
            },
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: const Color(0xFFFEF3C7),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFFFCD34D)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.bug_report, size: 16, color: Color(0xFFD97706)),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Dev Auto-fill OTP: $_debugOtp (Klik untuk mengisi)',
                      style: const TextStyle(fontSize: 12, color: Color(0xFF92400E), fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],

        const SizedBox(height: 28),

        // Verify Button
        _buildPrimaryButton(
          label: 'Verifikasi Kode',
          isLoading: _isLoading,
          onPressed: _isLoading ? null : _handleVerifyOtp,
          primaryColor: primaryColor,
        ),
        const SizedBox(height: 20),

        // Resend Button with Countdown (Anti-spam)
        Center(
          child: _resendCountdown > 0
              ? Text(
                  'Kirim ulang kode dalam $_resendCountdown detik',
                  style: const TextStyle(
                    fontSize: 13,
                    color: Color(0xFF94A3B8),
                    fontWeight: FontWeight.w500,
                  ),
                )
              : TextButton.icon(
                  onPressed: _isLoading ? null : _handleSendOtp,
                  icon: const Icon(Icons.refresh_rounded, size: 16),
                  label: const Text(
                    'Kirim Ulang Kode OTP',
                    style: TextStyle(fontWeight: FontWeight.w700),
                  ),
                  style: TextButton.styleFrom(
                    foregroundColor: primaryColor,
                  ),
                ),
        ),
      ],
    );
  }

  // ─── WIDGET STEP 3: Input Password Baru ─────────────────────
  Widget _buildStep3NewPassword(Color primaryColor) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(
            color: primaryColor.withValues(alpha: 0.1),
            borderRadius: BorderRadius.circular(20),
          ),
          child: Icon(Icons.lock_reset_rounded, color: primaryColor, size: 32),
        ),
        const SizedBox(height: 20),
        const Text(
          'Buat Password Baru',
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
        ),
        const SizedBox(height: 8),
        const Text(
          'Gunakan minimal 8 karakter dengan kombinasi huruf dan angka agar akun Anda tetap aman.',
          style: TextStyle(fontSize: 13, color: Color(0xFF64748B), height: 1.5),
        ),
        const SizedBox(height: 28),

        // Password Baru
        const Text('Password Baru', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Color(0xFF334155))),
        const SizedBox(height: 8),
        TextFormField(
          controller: _passwordController,
          obscureText: _obscurePassword,
          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500, color: Color(0xFF0F172A)),
          decoration: InputDecoration(
            hintText: 'Minimal 8 karakter',
            prefixIcon: const Icon(Icons.lock_outline_rounded, color: Color(0xFF64748B), size: 20),
            suffixIcon: IconButton(
              icon: Icon(
                _obscurePassword ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                color: const Color(0xFF64748B),
                size: 20,
              ),
              onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
            ),
            filled: true,
            fillColor: const Color(0xFFF8FAFC),
            contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide(color: primaryColor, width: 1.8),
            ),
          ),
        ),
        const SizedBox(height: 20),

        // Konfirmasi Password Baru
        const Text('Konfirmasi Password Baru', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Color(0xFF334155))),
        const SizedBox(height: 8),
        TextFormField(
          controller: _confirmPasswordController,
          obscureText: _obscureConfirmPassword,
          textInputAction: TextInputAction.done,
          onFieldSubmitted: (_) => _handleResetPassword(),
          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500, color: Color(0xFF0F172A)),
          decoration: InputDecoration(
            hintText: 'Ulangi password baru Anda',
            prefixIcon: const Icon(Icons.lock_outline_rounded, color: Color(0xFF64748B), size: 20),
            suffixIcon: IconButton(
              icon: Icon(
                _obscureConfirmPassword ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                color: const Color(0xFF64748B),
                size: 20,
              ),
              onPressed: () => setState(() => _obscureConfirmPassword = !_obscureConfirmPassword),
            ),
            filled: true,
            fillColor: const Color(0xFFF8FAFC),
            contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide(color: primaryColor, width: 1.8),
            ),
          ),
        ),
        const SizedBox(height: 28),

        // Submit Reset Password
        _buildPrimaryButton(
          label: 'Simpan Password Baru',
          isLoading: _isLoading,
          onPressed: _isLoading ? null : _handleResetPassword,
          primaryColor: primaryColor,
        ),
      ],
    );
  }

  // ─── WIDGET STEP 4: Success ─────────────────────────────────
  Widget _buildStep4Success(Color primaryColor) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 40),
        Container(
          width: 88,
          height: 88,
          decoration: BoxDecoration(
            color: const Color(0xFF10B981).withValues(alpha: 0.15),
            shape: BoxShape.circle,
          ),
          child: const Center(
            child: Icon(Icons.check_circle_rounded, color: Color(0xFF10B981), size: 54),
          ),
        ),
        const SizedBox(height: 28),
        const Text(
          'Password Berhasil Diperbarui!',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: Color(0xFF0F172A)),
        ),
        const SizedBox(height: 12),
        const Text(
          'Kata sandi akun Anda telah berhasil diubah. Silakan gunakan kata sandi baru untuk masuk ke aplikasi.',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 14, color: Color(0xFF64748B), height: 1.5),
        ),
        const SizedBox(height: 36),

        _buildPrimaryButton(
          label: 'Kembali ke Login',
          isLoading: false,
          onPressed: () => Navigator.pop(context),
          primaryColor: primaryColor,
        ),
      ],
    );
  }

  Widget _buildPrimaryButton({
    required String label,
    required bool isLoading,
    required VoidCallback? onPressed,
    required Color primaryColor,
  }) {
    return Container(
      height: 52,
      decoration: BoxDecoration(
        gradient: onPressed == null
            ? null
            : const LinearGradient(
                colors: [Color(0xFF2563EB), Color(0xFF1D4ED8)],
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
        color: onPressed == null ? const Color(0xFF94A3B8) : null,
        borderRadius: BorderRadius.circular(14),
        boxShadow: onPressed == null
            ? null
            : [
                BoxShadow(
                  color: primaryColor.withValues(alpha: 0.3),
                  blurRadius: 12,
                  offset: const Offset(0, 4),
                ),
              ],
      ),
      child: ElevatedButton(
        onPressed: onPressed,
        style: ElevatedButton.styleFrom(
          backgroundColor: Colors.transparent,
          shadowColor: Colors.transparent,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        ),
        child: isLoading
            ? const SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2.5),
              )
            : Text(
                label,
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                  letterSpacing: 0.3,
                ),
              ),
      ),
    );
  }
}
