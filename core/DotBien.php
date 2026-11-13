<?php
// Hệ thống Vòng Quay Đột Biến Không Gian (Match Anomalies / Mutator Mode)
class DotBien
{
    public const DANH_SACH = [
        'chuan' => [
            'id'        => 'chuan',
            'ten'       => 'Đấu Trường Chuẩn',
            'icon'      => '⚔️',
            'mau'       => '#00D9FF',
            'moTa'      => 'Luật thi đấu xếp hạng cổ điển. Trả lời đúng nhận điểm, trả lời nhanh nhận thêm thưởng.',
            'tag'       => 'CỔ ĐIỂN',
            'heSoDiem'  => 1.0,
            'thoiGian'  => 15
        ],
        'dao_nguoc' => [
            'id'        => 'dao_nguoc',
            'ten'       => 'Ngày Đảo Ngược (Opposite Day)',
            'icon'      => '🔄',
            'mau'       => '#F43F5E',
            'moTa'      => 'HACK NÃO: Bạn phải chọn ĐÁP ÁN SAI! Chọn đáp án đúng sẽ bị 0 điểm!',
            'tag'       => 'HACK NÃO',
            'heSoDiem'  => 1.2,
            'thoiGian'  => 15
        ],
        'sieu_toc' => [
            'id'        => 'sieu_toc',
            'ten'       => 'Siêu Tốc Bàn Thờ (Speed Demon)',
            'icon'      => '⚡',
            'mau'       => '#F59E0B',
            'moTa'      => 'Thời gian mỗi câu rút ngắn chỉ còn 7 giây! Toàn bộ điểm số được x2!',
            'tag'       => 'CHỚP NHOÁNG',
            'heSoDiem'  => 2.0,
            'thoiGian'  => 7
        ],
        'dot_tu' => [
            'id'        => 'dot_tu',
            'ten'       => 'Tử Thần 1 Máu (Sudden Death)',
            'icon'      => '💀',
            'mau'       => '#A855F7',
            'moTa'      => 'ÁP LỰC CỰC ĐẠI: Bất kỳ câu trả lời sai nào cũng bị trừ 50% số điểm!',
            'tag'       => 'NGHẸT THỞ',
            'heSoDiem'  => 1.5,
            'thoiGian'  => 15
        ],
        'jackpot' => [
            'id'        => 'jackpot',
            'ten'       => 'Xổ Số Lật Kèo (Jackpot Round)',
            'icon'      => '🎰',
            'mau'       => '#10B981',
            'moTa'      => 'Riêng câu số 10 cuối cùng nhận x300% điểm thưởng, cơ hội lật kèo ngoạn mục!',
            'tag'       => 'LẬT KÈO',
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
        $rand = rand(1, 100);
        if ($rand <= 25) {
            return 'chuan';
        }
        $otherKeys = ['dao_nguoc', 'sieu_toc', 'dot_tu', 'jackpot'];
        return $otherKeys[array_rand($otherKeys)];
    }
}

