<?php
// Hệ thống Thể Thức Thi Đấu (Match Format / Rules)
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
        // Mặc định luôn là Đấu Trường Tiêu Chuẩn (Chuẩn mực học thuật, không biến dạng luật lung tung)
        return 'chuan';
    }
}

