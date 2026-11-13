<?php
// Án phạt AFK khi tìm trận (lưu trong bảng PHATAFK).
// Người chơi không bấm Sẵn sàng khi tìm thấy trận sẽ bị khóa tìm trận trong 3 phút.
class PhatAfk
{
    public const THOI_GIAN_PHAT_MAC_DINH = 180;
    private static array $cache = [];

    public static function phat(int $maND, int $soGiay = self::THOI_GIAN_PHAT_MAC_DINH, string $lyDo = 'Không xác nhận sẵn sàng khi tìm thấy trận'): void
    {
        if ($maND <= 0 || $soGiay <= 0) {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO PHATAFK (MaND, LyDo, SoGiay, HetHan) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        )->execute([$maND, mb_substr($lyDo, 0, 255), $soGiay, $soGiay]);
        unset(self::$cache[$maND]);
    }

    /** @return array ['biPhat' => bool, 'conLai' => int] */
    public static function kiemTra(int $maND): array
    {
        if ($maND <= 0) {
            return ['biPhat' => false, 'conLai' => 0];
        }
        if (isset(self::$cache[$maND])) {
            return self::$cache[$maND];
        }
        $st = Database::pdo()->prepare(
            'SELECT MAX(TIMESTAMPDIFF(SECOND, NOW(), HetHan)) FROM PHATAFK WHERE MaND = ? AND DaGo = 0 AND HetHan > NOW()'
        );
        $st->execute([$maND]);
        $conLai = (int) $st->fetchColumn();
        $res = ['biPhat' => $conLai > 0, 'conLai' => max(0, $conLai)];
        self::$cache[$maND] = $res;
        return $res;
    }

    /** Gỡ án phạt đang hiệu lực (quản trị / reset), vẫn giữ lịch sử. */
    public static function xoa(int $maND): void
    {
        Database::pdo()->prepare('UPDATE PHATAFK SET DaGo = 1 WHERE MaND = ? AND DaGo = 0 AND HetHan > NOW()')
            ->execute([$maND]);
        unset(self::$cache[$maND]);
    }

    public static function dinhDangThoiGian(int $giay): string
    {
        if (class_exists('GioiHanTanSuat')) {
            return GioiHanTanSuat::dinhDangThoiGian($giay);
        }
        return RateLimiter::dinhDangThoiGian($giay);
    }
}

// Tương thích ngược: Định danh AfkPenalty trỏ tới PhatAfk
if (!class_exists('AfkPenalty', false)) {
    class_alias('PhatAfk', 'AfkPenalty');
}
