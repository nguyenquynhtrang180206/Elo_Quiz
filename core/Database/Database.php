<?php
// Kết nối PDO dùng chung: Database::pdo()
class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfgFile = file_exists(__DIR__ . '/../../config/config.php')
                ? __DIR__ . '/../../config/config.php'
                : __DIR__ . '/../config/config.php';
            $c = require $cfgFile;
            $d = $c['db'];
            $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
            self::$pdo = new PDO($dsn, $d['user'], $d['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // lỗi SQL -> ném exception
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,                    // prepared statement thật (chống SQL Injection)
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",
            ]);
            require_once __DIR__ . '/DbSchema.php';
            DbSchema::damBao(self::$pdo);   // tự tạo bảng/cột còn thiếu (chỉ chạy 1 lần mỗi phiên bản)
        }
        return self::$pdo;
    }
}
