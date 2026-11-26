<?php
// Cấu hình thể thức thi đấu
class DotBien
{
    public const DANH_SACH = [
        'chuan' => [
            'id'        => 'chuan',
            'ten'       => 'Đấu Trường Tiêu Chuẩn',
            'icon'      => '⚔️',
            'mau'       => '#00D9FF',
            'moTa'      => 'Luật thi đấu xếp hạng tiêu chuẩn. Trả lời đúng nhận điểm, trả lời nhanh nhận điểm tốc độ.',
            'tag'       => 'TIÊU CHUẨN',
            'heSoDiem'  => 1.0,
            'thoiGian'  => 15
        ]
    ];

    public static function layDotBien(?string $id): array
    {
        return self::DANH_SACH[$id ?? 'chuan'] ?? self::DANH_SACH['chuan'];
    }

    public static function ngauNhien(): string
    {
        // Mặc định dùng thể thức tiêu chuẩn
        return 'chuan';
    }
}

