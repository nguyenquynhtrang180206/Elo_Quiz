<?php
// Gửi email HTML qua SMTP socket thuần PHP (hỗ trợ 2 hướng: email thật gửi thẳng, email ảo chuyển về mail chủ).

class Mailer
{
    // --- 1. API gửi OTP theo nghiệp vụ ---

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

    private static function guiOtpEmail(
        string $toEmail,
        string $hoTen,
        string $otp,
        string $tieuDe,
        string $tieuDeHop,
        string $moTa,
        string $loiNhacCuoi
    ): array {
        $html = self::renderOtpHtml($hoTen, $otp, $tieuDe, $tieuDeHop, $moTa, $loiNhacCuoi);
        return self::guiMail($toEmail, $hoTen, $tieuDe, $html);
    }

    // --- 2. Điều phối 2 hướng gửi email (email thật / email ảo) ---

    /** Gửi email HTML qua SMTP hoặc ghi log cục bộ khi SMTP tắt/lỗi. */
    public static function guiMail(string $toEmail, string $toName, string $subject, string $htmlBody): array
    {
        $smtp = self::docCauHinhSmtp();
        $dich = self::chonDichGui($toEmail, $subject, $smtp['admin_email']);

        if ($smtp['enabled'] && $smtp['username'] !== '' && $smtp['password'] !== '') {
            try {
                self::guiQuaSmtp($smtp, [
                    'to_email'  => $dich['email'],
                    'to_name'   => $toName,
                    'subject'   => $dich['subject'],
                    'html_body' => $htmlBody,
                ]);

                return [
                    'ok'   => true,
                    'mode' => 'smtp',
                    'msg'  => 'Mã OTP đã được gửi thành công đến email của bạn.',
                ];
            } catch (Throwable $e) {
                error_log('[Mailer SMTP Error] ' . $e->getMessage());
                self::ghiNhatKyMail($toEmail, $subject, $htmlBody);

                return [
                    // Production không che giấu lỗi SMTP khi không thể ghi log OTP.
                    'ok'   => $smtp['debug'],
                    'mode' => 'fallback',
                    'msg'  => $smtp['debug']
                        ? 'Đã tạo mã OTP cho email của bạn (SMTP tạm thời bận).'
                        : 'Không thể gửi email xác thực lúc này. Vui lòng thử lại sau.',
                    'loi'  => $e->getMessage(),
                ];
            }
        }

        self::ghiNhatKyMail($toEmail, $subject, $htmlBody);

        return [
            'ok'   => true,
            'mode' => 'simulated',
            'msg'  => 'Mã OTP đã được cấp cho tài khoản email của bạn.',
        ];
    }

    private static function docCauHinhSmtp(): array
    {
        $cfg      = $GLOBALS['APP_CONFIG'] ?? require __DIR__ . '/../../config/config.php';
        $smtp     = $cfg['smtp'] ?? [];
        $username = trim((string) ($smtp['username'] ?? ''));
        $fromMail = trim((string) ($smtp['from_email'] ?? 'no-reply@quizup.edu.vn'));

        return [
            'enabled'     => !empty($smtp['enabled']),
            'debug'       => !empty($cfg['debug']),
            'host'        => trim((string) ($smtp['host'] ?? 'smtp.gmail.com')),
            'port'        => (int) ($smtp['port'] ?? 587),
            'encryption'  => strtolower(trim((string) ($smtp['encryption'] ?? 'tls'))),
            'username'    => $username,
            'password'    => trim((string) ($smtp['password'] ?? '')),
            'from_email'  => $fromMail,
            'from_name'   => trim((string) ($smtp['from_name'] ?? 'QUIZUP ARENA')),
            'admin_email' => $fromMail !== '' ? $fromMail : $username,
        ];
    }

    private static function chonDichGui(string $toEmail, string $subject, string $adminEmail): array
    {
        if (self::laEmailAo($toEmail) && $adminEmail !== '') {
            return [
                'email'   => $adminEmail,
                'subject' => "[Test: {$toEmail}] {$subject}",
            ];
        }

        return [
            'email'   => $toEmail,
            'subject' => $subject,
        ];
    }

    private static function laEmailAo(string $email): bool
    {
        $parts = explode('@', strtolower(trim($email)));
        if (count($parts) !== 2) {
            return true;
        }
        [$local, $domain] = $parts;

        $tenMienAo = ['test.com', 'example.com', 'localhost', 'local', 'elo.local', 'quizup.arena', 'invalid', 'test'];
        foreach ($tenMienAo as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                return true;
            }
        }

        if (preg_match('/^(test|demo|nguoikiemtra|kiemtra|sinhvien|sv|user|acc|fake|clone|abc|xyz)[0-9_]*$/i', $local)) {
            return true;
        }

        $mienPhoBien = ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com'];
        if (function_exists('checkdnsrr') && !in_array($domain, $mienPhoBien, true)) {
            if (!@checkdnsrr($domain, 'MX') && !@checkdnsrr($domain, 'A')) {
                return true;
            }
        }

        return false;
    }

    // --- 3. Giao tiếp SMTP socket (STARTTLS / SSL) ---

    private static function guiQuaSmtp(array $smtp, array $mail): void
    {
        $timeout = 10;
        $prefix  = ($smtp['encryption'] === 'ssl' || $smtp['port'] === 465) ? 'ssl://' : 'tcp://';
        $target  = $prefix . $smtp['host'] . ':' . $smtp['port'];

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => !$smtp['debug'],
                'verify_peer_name'  => !$smtp['debug'],
                'allow_self_signed' => $smtp['debug'],
            ],
        ]);

        $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            throw new RuntimeException("Không thể kết nối đến máy chủ SMTP {$smtp['host']}:{$smtp['port']} ({$errstr})");
        }

        try {
            stream_set_timeout($socket, $timeout);
            $ehloHost = gethostname() ?: 'localhost';

            self::docPhanHoiSmtp($socket, 220);
            self::guiLenhSmtp($socket, 'EHLO ' . $ehloHost, 250);

            if ($smtp['encryption'] === 'tls' || $smtp['port'] === 587) {
                self::guiLenhSmtp($socket, 'STARTTLS', 220);
                $crypto = @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
                if (!$crypto) {
                    throw new RuntimeException('Không thể thiết lập mã hóa STARTTLS với máy chủ email.');
                }
                self::guiLenhSmtp($socket, 'EHLO ' . $ehloHost, 250);
            }

            self::guiLenhSmtp($socket, 'AUTH LOGIN', 334);
            self::guiLenhSmtp($socket, base64_encode($smtp['username']), 334);
            self::guiLenhSmtp($socket, base64_encode($smtp['password']), 235);

            $fromEmail = $smtp['admin_email'];
            $toEmail   = str_replace(["\r", "\n"], '', $mail['to_email']);

            self::guiLenhSmtp($socket, "MAIL FROM:<{$fromEmail}>", 250);
            self::guiLenhSmtp($socket, "RCPT TO:<{$toEmail}>", 250);
            self::guiLenhSmtp($socket, 'DATA', 354);

            $headers = [
                'MIME-Version: 1.0',
                'Date: ' . date('r'),
                'From: =?UTF-8?B?' . base64_encode($smtp['from_name']) . '?= <' . $fromEmail . '>',
                'To: =?UTF-8?B?' . base64_encode($mail['to_name']) . '?= <' . $toEmail . '>',
                'Subject: =?UTF-8?B?' . base64_encode($mail['subject']) . '?=',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                'X-Mailer: QuizUp-Arena-Mailer',
            ];

            $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($mail['html_body']));
            // Kết thúc phần DATA bằng dấu chấm trên dòng riêng theo chuẩn SMTP.
            self::guiLenhSmtp($socket, $payload . "\r\n.", 250);
            self::guiLenhSmtp($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private static function docPhanHoiSmtp($socket, int $maKyVong): string
    {
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
    }

    private static function guiLenhSmtp($socket, string $lenh, int $maKyVong): string
    {
        fwrite($socket, $lenh . "\r\n");
        return self::docPhanHoiSmtp($socket, $maKyVong);
    }

    // --- 4. Template HTML & ghi log cục bộ khi debug ---

    private static function renderOtpHtml(
        string $hoTen,
        string $otp,
        string $tieuDe,
        string $tieuDeHop,
        string $moTa,
        string $loiNhacCuoi
    ): string {
        $eTitle   = htmlspecialchars($tieuDe, ENT_QUOTES, 'UTF-8');
        $eHeading = htmlspecialchars($tieuDeHop, ENT_QUOTES, 'UTF-8');
        $eName    = htmlspecialchars($hoTen, ENT_QUOTES, 'UTF-8');
        $eDesc    = htmlspecialchars($moTa, ENT_QUOTES, 'UTF-8');
        $eOtp     = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $eNote    = htmlspecialchars($loiNhacCuoi, ENT_QUOTES, 'UTF-8');
        $year     = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>{$eTitle}</title>
</head>
<body style="margin:0; padding:0; background-color:#050817; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#F5F8FF;">
  <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#050817; padding:30px 10px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width:540px; background:#0A1430; border:1.5px solid #00D9FF; border-radius:16px; overflow:hidden; box-shadow:0 0 25px rgba(0,217,255,0.25);">
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
          <tr>
            <td style="padding:28px 28px 24px;">
              <h2 style="margin:0 0 12px; font-size:18px; color:#FFFFFF; font-weight:700;">
                {$eHeading}
              </h2>
              <p style="margin:0 0 14px; font-size:14.5px; line-height:1.6; color:#CBD5E1;">
                Xin chào <strong style="color:#FFFFFF;">{$eName}</strong>,
              </p>
              <p style="margin:0 0 20px; font-size:14.5px; line-height:1.6; color:#94A3B8;">
                {$eDesc}
              </p>
              <div style="background:rgba(5,10,32,0.85); border:1.5px dashed #00D9FF; border-radius:12px; padding:20px; text-align:center; margin:20px 0;">
                <div style="font-size:11px; text-transform:uppercase; letter-spacing:1.5px; color:#FDE047; font-weight:700; margin-bottom:6px;">
                  MÃ XÁC THỰC BẢO MẬT (OTP)
                </div>
                <div style="font-family:'Courier New', Courier, monospace; font-size:36px; font-weight:800; letter-spacing:10px; color:#00D9FF; text-shadow:0 0 15px rgba(0,217,255,0.6); padding-left:10px;">
                  {$eOtp}
                </div>
                <div style="font-size:12px; color:#94A3B8; margin-top:8px;">
                  (Hiệu lực trong vòng <strong>15 phút</strong>)
                </div>
              </div>
              <p style="margin:0 0 14px; font-size:13.5px; line-height:1.6; color:#E2E8F0;">
                ⚠️ <strong>Lưu ý an toàn:</strong> Tuyệt đối không chia sẻ mã này cho bất kỳ ai, kể cả quản trị viên để bảo vệ tài khoản của bạn.
              </p>
              <p style="margin:0; font-size:13px; line-height:1.5; color:#64748B;">
                {$eNote}
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 24px; text-align:center; background:#060C1E; border-top:1px solid rgba(138,245,255,0.1); font-size:12px; color:#64748B;">
              © {$year} QUIZUP ARENA. Email tự động từ hệ thống, vui lòng không phản hồi thư này.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private static function ghiNhatKyMail(string $toEmail, string $subject, string $htmlBody): void
    {
        try {
            $cfg = $GLOBALS['APP_CONFIG'] ?? [];
            if (empty($cfg['debug'])) {
                return;
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
        } catch (Throwable) {
            // Không chặn luồng chính nếu ghi log thất bại.
        }
    }
}
