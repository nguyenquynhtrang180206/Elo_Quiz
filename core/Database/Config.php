<?php
// Đọc tham số luật chơi từ bảng CAUHINH: Config::get('SO_CAU'), Config::int('SO_CAU')
class Config
{
    private static ?array $cache = null;   // cache trong 1 request

    private static function load(): void
    {
        if (self::$cache === null) {
            self::$cache = [];
            $rows = Database::pdo()->query('SELECT TenCauHinh, GiaTri FROM CAUHINH')->fetchAll();
            foreach ($rows as $r) {
                self::$cache[$r['TenCauHinh']] = $r['GiaTri'];
            }
        }
    }

    public static function get(string $ten, ?string $macDinh = null): ?string
    {
        self::load();
        return self::$cache[$ten] ?? $macDinh;
    }

    public static function int(string $ten, int $macDinh = 0): int
    {
        return (int) self::get($ten, (string) $macDinh);
    }

    // Gọi sau khi Admin sửa cấu hình để lần đọc sau lấy giá trị mới
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
