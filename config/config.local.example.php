<?php
// Mẫu file cấu hình riêng cho TiDB Cloud hoặc MySQL Online.
// Đổi tên file này thành "config.local.php" và điền thông số từ Cloud vào.
// File này đã được thêm vào .gitignore, không bị đẩy lên Git.

return [
    'db' => [
        'host'    => 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com',
        'port'    => 4000,
        'name'    => 'test',
        'user'    => 'xxxxxx.root',
        'pass'    => 'Mật_Khẩu_Của_Bạn',
        'charset' => 'utf8mb4',
        'ssl'     => true,
    ],
    'debug' => true,
];

