<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode OTP Reset Password</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f1f5f9;
            margin: 0;
            padding: 24px;
            color: #1e293b;
        }
        .container {
            max-width: 520px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid #e2e8f0;
        }
        .header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            padding: 32px 24px;
            text-align: center;
        }
        .logo-box {
            display: inline-block;
            width: 44px;
            height: 44px;
            background: #2563eb;
            border-radius: 12px;
            margin-bottom: 12px;
            line-height: 44px;
            font-weight: bold;
            font-size: 20px;
            color: #ffffff;
        }
        .brand-title {
            color: #ffffff;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.5px;
        }
        .brand-subtitle {
            color: #94a3b8;
            font-size: 12px;
            margin-top: 4px;
        }
        .content {
            padding: 32px 28px;
        }
        .greeting {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .text {
            font-size: 14px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 24px;
        }
        .otp-wrapper {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 24px;
        }
        .otp-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 8px;
        }
        .otp-code {
            font-size: 36px;
            font-weight: 800;
            letter-spacing: 8px;
            color: #2563eb;
            font-family: 'Courier New', Courier, monospace;
        }
        .warning-box {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 12px;
            color: #92400e;
            line-height: 1.5;
            margin-bottom: 24px;
        }
        .footer {
            background: #f8fafc;
            padding: 20px 24px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo-box">EF</div>
            <h1 class="brand-title">ExpenseFlow</h1>
            <div class="brand-subtitle">Sistem Keuangan & Operasional Perusahaan</div>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">Halo, {{ $userName }}!</div>
            <p class="text">
                Kami menerima permintaan untuk mereset kata sandi (password) akun ExpenseFlow Anda. Gunakan kode OTP di bawah ini untuk melanjutkan:
            </p>

            <div class="otp-wrapper">
                <div class="otp-label">Kode Verifikasi OTP</div>
                <div class="otp-code">{{ $otp }}</div>
            </div>

            <div class="warning-box">
                ⏱️ <strong>Penting:</strong> Kode OTP ini hanya berlaku selama <strong>5 menit</strong>. Jangan pernah membagikan kode ini kepada siapa pun termasuk pihak ExpenseFlow.
            </div>

            <p class="text" style="font-size: 13px; margin-bottom: 0;">
                Jika Anda tidak merasa melakukan permintaan ini, abaikan email ini. Akun dan password Anda tetap aman.
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            &copy; {{ date('Y') }} ExpenseFlow Enterprise. Seluruh hak cipta dilindungi undang-undang.
        </div>
    </div>
</body>
</html>
