<?php
// Tự dò cổng MySQL: thử lần lượt các cổng, cổng nào đang mở thì dùng.
if (!function_exists('detectMysqlPort')) {
    function detectMysqlPort(string $host, array $ports): int
    {
        static $cachedPort = null;
        if ($cachedPort !== null) {
            return $cachedPort;
        }
        foreach ($ports as $p) {
            $fp = @fsockopen($host, $p, $errno, $errstr, 0.3);
            if ($fp) {
                fclose($fp);
                return $cachedPort = $p;
            }
        }
        return $cachedPort = $ports[0]; // không dò được thì dùng cổng đầu tiên
    }
}

$dbHost = '127.0.0.1';

return [
    'db' => [
        'host'    => $dbHost,
        'port'    => detectMysqlPort($dbHost, [3307, 3306]),
        'name'    => 'elo_quiz',   // tên database (import file csdl_elo_quiz.sql để tạo)
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'timezone'   => 'Asia/Ho_Chi_Minh',
    'debug'      => true,   // true khi đang làm; đổi false khi nộp/chạy thật
    'upload_dir' => __DIR__ . '/../uploads',

    // Cấu hình gửi Email thực tế qua SMTP (VD: Gmail SMTP)
    'smtp' => [
        'enabled'    => true,                               // true: bật gửi qua SMTP; false: lưu trữ thư vào logs
        'host'       => 'smtp.gmail.com',                   // Máy chủ SMTP (smtp.gmail.com)
        'port'       => 587,                                // Cổng SMTP: 587 (TLS) hoặc 465 (SSL)
        'encryption' => 'tls',                              // 'tls' hoặc 'ssl'
        'username'   => 'buithutrang149506@gmail.com',      // Địa chỉ Gmail gửi tin
        'password'   => 'uhzobtahixlhpepn',                 // Mật khẩu ứng dụng 16 ký tự của Google (App Password)
        'from_email' => 'buithutrang149506@gmail.com',      // Địa chỉ hiển thị người gửi
        'from_name'  => 'QUIZUP ARENA',                     // Tên hiển thị người gửi
    ],
];