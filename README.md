# Đấu Trường Tri Thức (Elo Quiz) - giao diện 

Thi đấu trắc nghiệm 1 vs 1, xếp hạng Elo theo môn. PHP 8.2 + MySQL (PDO), không dùng framework.

## Cài đặt (XAMPP)
1. Đặt thư mục dự án vào `htdocs/Elo_Quiz-main`, bật **Apache** và **MySQL**.
2. Import `csdl_elo_quiz.sql` (phpMyAdmin → Import). File này đã gói trọn 27 bảng, 3 môn học, tài khoản thử và 60 câu hỏi trắc nghiệm.
3. Sửa `config/config.php` (hoặc tạo `config/config.local.php`) cho khớp MySQL của bạn (host, user, **pass**, tên DB `elo_quiz`).
4. Kiểm tra: `http://localhost/Elo_Quiz-main/ketnoi.php` (cần 27 bảng), rồi mở `http://localhost/Elo_Quiz-main/`.

Tài khoản thử: sinh viên `an@test.com` / `123456`; quản trị `admin` / `admin123`.

## Kiến trúc (giữ nguyên)
- `core/` : bootstrap, PDO (`Database`), `Auth`, CSRF/escape (`helpers.php`), các lớp nghiệp vụ (Elo, ghép đôi, thi đấu...).
- `client/`, `admin/` : trang của sinh viên và quản trị; `layout/header.php`, `layout/footer.php` dùng chung.
- Mọi truy vấn dùng prepared statement; mật khẩu `password_hash`/`password_verify`; form POST có token CSRF.

## Giao diện / hiệu ứng
| File | Vai trò |
| `assets/style.css`, `assets/style_admin.css` | Design system (CSS variables, glass card, nút neon, form, bảng, phân trang, modal, toast), sidebar admin |
| `assets/css/cosmic-fix.css` | Ghi đè các màu sáng cố định trong CSS riêng từng trang |
| `assets/js/effects/cosmic-bg.js` | Three.js: tinh vân (shader), ~5000 hạt 3 lớp, hút xoáy theo chuột, vệt chuột, click nổ + shockwave, parallax |
| `assets/js/ui.js` | Toast, modal xác nhận (`data-xacnhan="..."`), dropdown |
| `assets/vendor/three.module.min.js` | Three.js r160 chạy cục bộ (không cần mạng) |

Hiệu năng/fallback: tự giảm số hạt và độ phân giải khi FPS thấp hoặc thiết bị cảm ứng; dừng khi tab ẩn;
`prefers-reduced-motion` chỉ vẽ một khung tĩnh; không có WebGL/JS thì dùng nền tĩnh (ảnh `uploads/img/cuc_quang.jpg` + gradient).
Nút góc phải dưới chuyển chế độ **Tự động / Nhẹ / Tắt** (lưu trong localStorage).

