<?php
// Cấu hình chung. Sửa lại cho khớp máy của bạn (XAMPP mặc định: root, không mật khẩu).
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'elo_quiz',   // tên database (import file csdl_elo_quiz.sql để tạo)
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'timezone'   => 'Asia/Ho_Chi_Minh',
    'debug'      => true,   // true khi đang làm; đổi false khi nộp/chạy thật
    'upload_dir' => __DIR__ . '/../uploads',
];
