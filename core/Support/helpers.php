<?php
// Hàm tiện ích bảo mật dùng chung

// Thoát HTML để chống XSS: luôn dùng khi in dữ liệu ra trang. Ví dụ: echo e($ten);
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// ---- CSRF ----
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// Đặt trong mọi form method="post" bằng: echo csrf_field();
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

// Gọi đầu mọi xử lý POST; sai token thì dừng
function csrf_check(): void
{
    $gui = $_POST['csrf'] ?? '';
    if (!is_string($gui) || !hash_equals($_SESSION['csrf'] ?? '', $gui)) {
        http_response_code(419);
        exit('Phiên làm việc không hợp lệ hoặc đã hết hạn. Hãy tải lại trang.');
    }
}