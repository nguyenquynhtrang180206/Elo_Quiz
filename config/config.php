<?php
// Tự dò cổng MySQL: thử lần lượt các cổng, cổng nào đang mở thì dùng.
if (!function_exists('detectMysqlPort')) {
    function detectMysqlPort(string $host, array $ports): int
    {
        foreach ($ports as $p) {
            $fp = @fsockopen($host, $p, $errno, $errstr, 0.3);
            if ($fp) {
                fclose($fp);
                return $p;
            }
        }
        return $ports[0]; // không dò được thì dùng cổng đầu tiên
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
];