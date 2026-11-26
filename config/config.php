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

$baseConfig = [
    'db' => [
        'host'    => getenv('DB_HOST') ?: $dbHost,
        'port'    => (int) (getenv('DB_PORT') ?: detectMysqlPort($dbHost, [3307, 3306])),
        'name'    => getenv('DB_NAME') ?: 'elo_quiz',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],
    'timezone'   => 'Asia/Ho_Chi_Minh',
    'debug'      => getenv('APP_DEBUG') !== false ? filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN) : true,
    'upload_dir' => __DIR__ . '/../uploads',
    'app_secret' => getenv('APP_SECRET') ?: 'quizup-arena-hmac-secret-key-2026-v2',

    // Cấu hình gửi Email qua SMTP
    'smtp' => [
        'enabled'    => getenv('SMTP_ENABLED') !== false ? filter_var(getenv('SMTP_ENABLED'), FILTER_VALIDATE_BOOLEAN) : true,
        'host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
        'port'       => (int) (getenv('SMTP_PORT') ?: 587),
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
        'username'   => getenv('SMTP_USER') ?: 'buithutrang149506@gmail.com',
        'password'   => getenv('SMTP_PASS') ?: 'uhzobtahixlhpepn',
        'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'buithutrang149506@gmail.com',
        'from_name'  => getenv('SMTP_FROM_NAME') ?: 'QUIZUP ARENA',
    ],
];

// Ghi đè cấu hình từ config.local.php nếu có
if (is_file(__DIR__ . '/config.local.php')) {
    $localConfig = require __DIR__ . '/config.local.php';
    if (is_array($localConfig)) {
        $baseConfig = array_replace_recursive($baseConfig, $localConfig);
    }
}

return $baseConfig;