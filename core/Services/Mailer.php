<?php
// Gửi email HTML qua SMTP socket thuần PHP (hỗ trợ STARTTLS/SSL và log local khi debug).

class Mailer
{
    /** Gửi mã OTP khôi phục mật khẩu đến email người dùng. */
    public static function guiOtpQuenMatKhau(string $toEmail, string $hoTen, string $otp): array
    {
        return self::guiOtpEmail(
            $toEmail,
            $hoTen,
            $otp,
            "[QuizUp Arena] Mã xác thực OTP khôi phục mật khẩu: {$otp}",
            'Xác thực khôi phục mật khẩu 🔐',
            'Hệ thống vừa nhận được yêu cầu đặt lại mật khẩu cho tài khoản gắn với email này. Dưới đây là mã bảo mật OTP 6 chữ số của bạn:',
            'Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email. Mật khẩu hiện tại của bạn vẫn được giữ an toàn.'
        );
    }

    /** Gửi mã OTP xác thực đăng ký tài khoản mới. */
    public static function guiOtpDangKy(string $toEmail, string $hoTen, string $otp): array
    {
        return self::guiOtpEmail(
            $toEmail,
            $hoTen,
            $otp,
            "[QuizUp Arena] Mã xác thực OTP đăng ký tài khoản: {$otp}",
            'Xác thực đăng ký tài khoản ✨',
            'Cảm ơn bạn đã đăng ký tham gia Đấu Trường Tri Thức! Dưới đây là mã bảo mật OTP 6 chữ số để kích hoạt tài khoản của bạn:',
            'Nếu bạn không thực hiện đăng ký tài khoản bằng email này, vui lòng bỏ qua thư này.'
        );
    }

    /** Gửi mã OTP khôi phục mật khẩu Quản trị viên. */
    public static function guiOtpQuenMatKhauAdmin(string $toEmail, string $hoTen, string $tenDangNhap, string $otp): array
    {
        return self::guiOtpEmail(
            $toEmail,
            $hoTen . ' (' . $tenDangNhap . ')',
            $otp,
            "[QuizUp Arena - Quản Trị] Mã xác thực OTP khôi phục mật khẩu Admin: {$otp}",
            'Khôi phục mật khẩu Quản trị viên 🛡️',
            "Hệ thống vừa nhận được yêu cầu đặt lại mật khẩu cho tài khoản Quản trị viên ({$tenDangNhap}). Dưới đây là mã bảo mật OTP 6 chữ số của bạn:",
            'Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email. Mật khẩu quản trị hiện tại vẫn được giữ an toàn.'
        );
    }

    /** Gửi mã OTP xác thực 2FA cho Quản trị viên trên thiết bị mới. */
    public static function guiOtpDangNhapAdmin(string $toEmail, string $hoTen, string $tenDangNhap, string $otp): array
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
        return self::guiOtpEmail(
            $toEmail,
            $hoTen . ' (' . $tenDangNhap . ')',
            $otp,
            "[QuizUp Arena - Quản Trị] Mã OTP xác thực đăng nhập 2 lớp (2FA): {$otp}",
            'Xác thực đăng nhập Quản trị viên (2FA) 🔐',
            "Hệ thống ghi nhận yêu cầu đăng nhập vào cổng Quản trị ({$tenDangNhap}) từ địa chỉ IP {$ip}. Vui lòng nhập mã bảo mật OTP 6 chữ số dưới đây để hoàn tất đăng nhập:",
            'Nếu không phải bạn vừa đăng nhập, hãy đổi mật khẩu quản trị ngay lập tức vì mật khẩu của bạn có thể đã bị lộ.'
        );
    }

    /** Template email HTML chứa mã OTP 6 số. */
    private static function guiOtpEmail(
        string $toEmail,
        string $hoTen,
        string $otp,
        string $tieuDe,
        string $tieuDeHop,
        string $moTa,
        string $loiNhacCuoi
    ): array {
        $noiDungHtml = '
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>' . htmlspecialchars($tieuDe, ENT_QUOTES, 'UTF-8') . '</title>
</head>
<body style="margin:0; padding:0; background-color:#050817; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; color:#F5F8FF;">
  <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#050817; padding:30px 10px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width:540px; background:#0A1430; border:1.5px solid #00D9FF; border-radius:16px; overflow:hidden; box-shadow:0 0 25px rgba(0,217,255,0.25);">
          <!-- Header Email -->
          <tr>
            <td style="padding:28px 24px 20px; text-align:center; background:linear-gradient(180deg, rgba(8,123,255,0.25) 0%, rgba(10,20,48,0) 100%); border-bottom:1px solid rgba(138,245,255,0.15);">
              <div style="font-size:22px; font-weight:800; letter-spacing:2px; color:#FFFFFF; text-transform:uppercase;">
                QUIZUP <span style="color:#00D9FF;">ARENA</span>
              </div>
              <div style="font-size:12px; color:#8AF5FF; letter-spacing:1px; margin-top:4px;">
                ĐẤU TRƯỜNG TRI THỨC ĐỈNH CAO
              </div>
            </td>
          </tr>
          <!-- Body Email -->
          <tr>
            <td style="padding:28px 28px 24px;">
              <h2 style="margin:0 0 12px; font-size:18px; color:#FFFFFF; font-weight:700;">
                ' . htmlspecialchars($tieuDeHop, ENT_QUOTES, 'UTF-8') . '
              </h2>
              <p style="margin:0 0 14px; font-size:14.5px; line-height:1.6; color:#CBD5E1;">
                Xin chào <strong style="color:#FFFFFF;">' . htmlspecialchars($hoTen, ENT_QUOTES, 'UTF-8') . '</strong>,
              </p>
              <p style="margin:0 0 20px; font-size:14.5px; line-height:1.6; color:#94A3B8;">
                ' . htmlspecialchars($moTa, ENT_QUOTES, 'UTF-8') . '
              </p>
              
              <!-- Khung hiển thị OTP -->
              <div style="background:rgba(5,10,32,0.85); border:1.5px dashed #00D9FF; border-radius:12px; padding:20px; text-align:center; margin:20px 0;">
                <div style="font-size:11px; text-transform:uppercase; letter-spacing:1.5px; color:#FDE047; font-weight:700; margin-bottom:6px;">
                  MÃ XÁC THỰC BẢO MẬT (OTP)
                </div>
                <div style="font-family:\'Courier New\', Courier, monospace; font-size:36px; font-weight:800; letter-spacing:10px; color:#00D9FF; text-shadow:0 0 15px rgba(0,217,255,0.6); padding-left:10px;">
                  ' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '
                </div>
                <div style="font-size:12px; color:#94A3B8; margin-top:8px;">
                  (Hiệu lực trong vòng <strong>15 phút</strong>)
                </div>
              </div>

              <p style="margin:0 0 14px; font-size:13.5px; line-height:1.6; color:#E2E8F0;">
                ⚠️ <strong>Lưu ý an toàn:</strong> Tuyệt đối không chia sẻ mã này cho bất kỳ ai, kể cả quản trị viên để bảo vệ tài khoản của bạn.
              </p>
              <p style="margin:0; font-size:13px; line-height:1.5; color:#64748B;">
                ' . htmlspecialchars($loiNhacCuoi, ENT_QUOTES, 'UTF-8') . '
              </p>
            </td>
          </tr>
          <!-- Footer Email -->
          <tr>
            <td style="padding:18px 24px; text-align:center; background:#060C1E; border-top:1px solid rgba(138,245,255,0.1); font-size:12px; color:#64748B;">
              © ' . date('Y') . ' QUIZUP ARENA. Email tự động từ hệ thống, vui lòng không phản hồi thư này.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

        return self::guiMail($toEmail, $hoTen, $tieuDe, $noiDungHtml);
    }

    /**
     * Gửi Email HTML qua SMTP Socket thuần PHP
     */
    public static function guiMail(string $toEmail, string $toName, string $subject, string $htmlBody): array
    {
        $cfg = $GLOBALS['APP_CONFIG'] ?? require __DIR__ . '/../../config/config.php';
        $smtp = $cfg['smtp'] ?? [];

        $enabled  = !empty($smtp['enabled']);
        $host     = trim((string) ($smtp['host'] ?? 'smtp.gmail.com'));
        $port     = (int) ($smtp['port'] ?? 587);
        $encrypt  = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
        $user     = trim((string) ($smtp['username'] ?? ''));
        $pass     = trim((string) ($smtp['password'] ?? ''));
        $fromMail = trim((string) ($smtp['from_email'] ?? 'no-reply@quizup.edu.vn'));
        $fromName = trim((string) ($smtp['from_name'] ?? 'QUIZUP ARENA'));
        $adminMail = $fromMail !== '' ? $fromMail : $user;

        // Nhận diện email thử nghiệm / không có thật hoặc tên miền nội bộ -> tự động chuyển hướng về mail chủ hệ thống
        $laMailKhongThuc = self::laEmailThuNghiemHoacKhongThuc($toEmail);
        $dichThucTe      = ($laMailKhongThuc && $adminMail !== '') ? $adminMail : $toEmail;

        // Nếu đang ở môi trường Debug/Demo (hoặc email thử nghiệm) và người nhận khác mail chủ hệ thống:
        // gắn thêm nhãn thông tin tài khoản gốc vào tiêu đề để chủ hệ thống dễ nhận biết khi nhận thư
        $subjectGui = $subject;
        if (strcasecmp($dichThucTe, $toEmail) !== 0) {
            $subjectGui = "[Test: {$toEmail}] " . $subject;
        }

        // Nếu đã cấu hình tài khoản SMTP -> Gửi thật qua mạng
        if ($enabled && $user !== '' && $pass !== '') {
            try {
                // Nếu là email bình thường khác với mail chủ hệ thống và đang ở chế độ debug,
                // đồng thời gửi kèm bản sao (BCC) về Hộp thư đến của chủ hệ thống để luôn nhận được OTP khi demo
                $bccAdmin = (!empty($cfg['debug']) && $adminMail !== '' && strcasecmp($dichThucTe, $adminMail) !== 0)
                    ? $adminMail
                    : null;

                self::chaySocketSmtp(
                    $host,
                    $port,
                    $encrypt,
                    $user,
                    $pass,
                    $adminMail,
                    $fromName,
                    $dichThucTe,
                    $toName,
                    $subjectGui,
                    $htmlBody,
                    $bccAdmin
                );
                return [
                    'ok'   => true,
                    'mode' => 'smtp',
                    'msg'  => 'Mã OTP đã được gửi thành công đến email của bạn.'
                ];
            } catch (Throwable $e) {
                error_log('[Mailer SMTP Error] ' . $e->getMessage());
                self::ghiNhatKyMail($toEmail, $subject, $htmlBody);
                return [
                    'ok'   => true, // Vẫn trả về true kèm chế độ log để người dùng không bị kẹt
                    'mode' => 'fallback',
                    'msg'  => 'Đã tạo mã OTP cho email của bạn (SMTP tạm thời bận).',
                    'loi'  => $e->getMessage()
                ];
            }
        }

        // Chế độ mô phỏng / Lưu trữ cục bộ khi chưa bật SMTP
        self::ghiNhatKyMail($toEmail, $subject, $htmlBody);
        return [
            'ok'   => true,
            'mode' => 'simulated',
            'msg'  => 'Mã OTP đã được cấp cho tài khoản email của bạn.'
        ];
    }

    /**
     * Kiểm tra địa chỉ email có phải là email thử nghiệm / không có máy chủ nhận thư thực tế hay không.
     */
    private static function laEmailThuNghiemHoacKhongThuc(string $email): bool
    {
        $parts = explode('@', strtolower(trim($email)));
        if (count($parts) !== 2) {
            return true;
        }
        [$local, $domain] = $parts;

        // Tên miền thử nghiệm / nội bộ
        $tenMienThuNghiem = ['test.com', 'example.com', 'localhost', 'local', 'elo.local', 'quizup.arena', 'invalid', 'test'];
        foreach ($tenMienThuNghiem as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                return true;
            }
        }

        // Tài khoản demo / kiểm thử
        if (preg_match('/^(test|demo|nguoikiemtra|kiemtra|sinhvien|sv|user|acc|fake|clone|abc|xyz)[0-9_]*$/i', $local)) {
            return true;
        }

        // Kiểm tra DNS MX Record của tên miền
        if (function_exists('checkdnsrr') && !in_array($domain, ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com'], true)) {
            if (!@checkdnsrr($domain, 'MX') && !@checkdnsrr($domain, 'A')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Động cơ gửi thư qua giao thức SMTP Socket thuần (hỗ trợ STARTTLS, SSL và BCC về hộp thư chủ)
     */
    private static function chaySocketSmtp(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $bccEmail = null
    ): void {
        $timeout = 10;
        $prefix  = ($encryption === 'ssl' || $port === 465) ? 'ssl://' : 'tcp://';
        $target  = $prefix . $host . ':' . $port;

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            throw new RuntimeException("Không thể kết nối đến máy chủ SMTP {$host}:{$port} ({$errstr})");
        }
        stream_set_timeout($socket, $timeout);

        $docPhanHoi = function (int $maKyVong) use ($socket): string {
            $phanHoi = '';
            while ($dong = fgets($socket, 512)) {
                $phanHoi .= $dong;
                if (isset($dong[3]) && $dong[3] === ' ') {
                    break;
                }
            }
            $maThucTe = (int) substr($phanHoi, 0, 3);
            if ($maThucTe !== $maKyVong) {
                throw new RuntimeException("Lỗi phản hồi SMTP (kỳ vọng {$maKyVong}, nhận {$maThucTe}): {$phanHoi}");
            }
            return $phanHoi;
        };

        $guiLenh = function (string $lenh, int $maKyVong) use ($socket, $docPhanHoi): string {
            fwrite($socket, $lenh . "\r\n");
            return $docPhanHoi($maKyVong);
        };

        $docPhanHoi(220);
        $guiLenh('EHLO ' . gethostname(), 250);

        if ($encryption === 'tls' || $port === 587) {
            $guiLenh('STARTTLS', 220);
            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if (!$crypto) {
                throw new RuntimeException('Không thể thiết lập mã hóa bảo mật STARTTLS với máy chủ email.');
            }
            $guiLenh('EHLO ' . gethostname(), 250);
        }

        $guiLenh('AUTH LOGIN', 334);
        $guiLenh(base64_encode($username), 334);
        $guiLenh(base64_encode($password), 235);

        $guiLenh("MAIL FROM:<{$fromEmail}>", 250);
        $guiLenh("RCPT TO:<{$toEmail}>", 250);
        if ($bccEmail !== null && $bccEmail !== '' && strcasecmp($bccEmail, $toEmail) !== 0) {
            try {
                $guiLenh("RCPT TO:<{$bccEmail}>", 250);
            } catch (Throwable) {
                // Bỏ qua nếu RCPT TO phụ lỗi
            }
        }

        $guiLenh('DATA', 354);

        $subEncoded  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromEncoded = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
        $toEncoded   = '=?UTF-8?B?' . base64_encode($toName) . '?= <' . $toEmail . '>';

        $headers = [
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'From: ' . $fromEncoded,
            'To: ' . $toEncoded,
            'Subject: ' . $subEncoded,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: QuizUp-Arena-Mailer',
        ];

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody));
        // Kết thúc DATA bằng dấu chấm trên dòng riêng
        $guiLenh($payload . "\r\n.", 250);

        $guiLenh('QUIT', 221);
        fclose($socket);
    }

    /**
     * Ghi tệp lưu trữ nhật ký email trong thư mục uploads/mail_logs (Chỉ ghi ở môi trường Debug/Local và khóa truy cập web)
     */
    private static function ghiNhatKyMail(string $toEmail, string $subject, string $htmlBody): void
    {
        try {
            $cfg = $GLOBALS['APP_CONFIG'] ?? [];
            if (empty($cfg['debug'])) {
                return; // Môi trường Production: tuyệt đối không lưu mã OTP ra file tĩnh
            }
            $dir = __DIR__ . '/../../uploads/mail_logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $htaccess = $dir . '/.htaccess';
            if (!is_file($htaccess)) {
                @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
            }
            $safeEmail = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $toEmail);
            $filename  = $dir . '/mail_' . $safeEmail . '_' . date('Ymd_His') . '.html';
            file_put_contents($filename, $htmlBody);
        } catch (Throwable $e) {
            // Không chặn ứng dụng nếu ghi log thất bại
        }
    }
}

