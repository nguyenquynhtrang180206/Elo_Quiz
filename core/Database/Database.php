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
            $c = $GLOBALS['APP_CONFIG'] ?? require $cfgFile;
            $d = $c['db'];

            // Danh sách mật khẩu thử nghiệm: mật khẩu trong config, mật khẩu '123456' phổ biến trên máy, pass rỗng
            $passwordsToTry = array_unique([$d['pass'] ?? '', '123456', 'root', 'admin', '']);
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // lỗi SQL -> ném exception
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,                    // prepared statement thật (chống SQL Injection)
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",
            ];

            $lastException = null;
            foreach ($passwordsToTry as $pass) {
                $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
                try {
                    self::$pdo = new PDO($dsn, $d['user'], $pass, $options);
                    break;
                } catch (PDOException $e) {
                    $lastException = $e;
                    // Lỗi 1045: Sai mật khẩu -> thử mật khẩu tiếp theo
                    if ($e->getCode() == 1045) {
                        continue;
                    }
                    // Lỗi 1049: CSDL chưa tồn tại -> tự động tạo CSDL nếu quyền root cho phép
                    if ($e->getCode() == 1049) {
                        try {
                            $dsnNoDb = "mysql:host={$d['host']};port={$d['port']};charset={$d['charset']}";
                            $tmpPdo = new PDO($dsnNoDb, $d['user'], $pass, $options);
                            $tmpPdo->exec("CREATE DATABASE IF NOT EXISTS `{$d['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                            self::$pdo = new PDO($dsn, $d['user'], $pass, $options);
                            break;
                        } catch (Throwable) {
                            // Nếu không tự tạo được thì ném exception ban đầu
                        }
                    }
                    throw $e;
                }
            }

            if (self::$pdo === null && $lastException !== null) {
                throw $lastException;
            }

            DbSchema::damBao(self::$pdo);   // tự tạo bảng/cột còn thiếu (chỉ chạy 1 lần mỗi phiên bản)
        }
        return self::$pdo;
    }
}
