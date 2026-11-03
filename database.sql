-- =====================================================================
-- DỮ LIỆU MẪU để thử hệ thống (chạy SAU file csdl_elo_quiz.sql)
-- Chạy MỘT LẦN trên database mới. Muốn chạy lại thì import lại file cấu trúc trước.
--
-- Tài khoản thử:
--   Sinh viên: an@test.com, binh@test.com, cuong@test.com, dung@test.com,
--              huy@test.com, linh@test.com      Mật khẩu: 123456
--   Quản trị : tên đăng nhập admin             Mật khẩu: admin123
--
-- Câu hỏi: 3 môn x 20 câu. Mỗi môn có 8 câu dùng cho cả hai loại kỳ thi
-- (MaLoaiKT rỗng), 6 câu giữa kỳ, 6 câu cuối kỳ => mỗi loại kỳ thi có 14 câu (>= 10).
-- =====================================================================
USE elo_quiz;

INSERT INTO MONHOC (MaMon, TenMon, MoTa, TrangThai) VALUES
  (1, 'Quản trị cơ sở dữ liệu', 'Môn CIT2521: SQL, giao dịch, chỉ mục, chuẩn hóa', 'Đang mở'),
  (2, 'Phân tích nghiệp vụ phần mềm', 'Môn PTNVPM: use case, yêu cầu, mô hình hóa', 'Đang mở'),
  (3, 'Lập trình PHP', 'PHP, PDO, session, bảo mật web cơ bản', 'Đang mở');

INSERT INTO ADMIN (TenDangNhap, MatKhau, HoTen, TrangThai) VALUES
  ('admin', '$2y$10$TeACaYhx5feO6rATfKBvZOxNiguhrOek2baislMlQTWetyVg9dia.', 'Quản trị viên', 'Hoạt động');

INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau, Lop, Khoa, TrangThai, TrangThaiKhaoSat) VALUES
  ('Nguyễn Văn An', 'an@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'CNTT01', 'Công nghệ thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Trần Thị Bình', 'binh@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'CNTT01', 'Công nghệ thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Lê Minh Cường', 'cuong@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'CNTT02', 'Công nghệ thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Phạm Thu Dung', 'dung@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'HTTT01', 'Hệ thống thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Hoàng Gia Huy', 'huy@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'HTTT01', 'Hệ thống thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Vũ Khánh Linh', 'linh@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'KTPM01', 'Kỹ thuật phần mềm', 'Hoạt động', 'Hoàn tất');

INSERT INTO CAUHOI (MaCauHoi, MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho, TrangThai) VALUES
  (1, 1, NULL, 'Lệnh SQL nào dùng để truy vấn dữ liệu từ bảng?', 'SELECT dùng để đọc dữ liệu; các lệnh còn lại thêm, sửa, xóa dữ liệu.', 'Dễ', 'Hiển thị'),
  (2, 1, NULL, 'Khóa chính (PRIMARY KEY) có đặc điểm nào?', 'Khóa chính định danh duy nhất mỗi dòng nên không trùng và không NULL.', 'Trung bình', 'Hiển thị'),
  (3, 1, NULL, 'Ràng buộc nào đảm bảo toàn vẹn tham chiếu giữa hai bảng?', 'FOREIGN KEY bắt giá trị phải tồn tại ở bảng cha.', 'Khó', 'Hiển thị'),
  (4, 1, NULL, 'Từ khóa nào loại bỏ các dòng trùng trong kết quả SELECT?', 'SELECT DISTINCT chỉ giữ các dòng khác nhau.', 'Dễ', 'Hiển thị'),
  (5, 1, NULL, 'Mệnh đề nào dùng để lọc các nhóm sau khi GROUP BY?', 'WHERE lọc dòng trước khi nhóm, HAVING lọc nhóm sau khi nhóm.', 'Trung bình', 'Hiển thị'),
  (6, 1, NULL, 'Trong tính chất ACID của giao dịch, chữ A là viết tắt của gì?', 'Atomicity: giao dịch hoặc thực hiện trọn vẹn, hoặc không thực hiện gì.', 'Khó', 'Hiển thị'),
  (7, 1, NULL, 'Lệnh nào hoàn tác các thay đổi của giao dịch hiện tại?', 'ROLLBACK hủy thay đổi chưa COMMIT.', 'Dễ', 'Hiển thị'),
  (8, 1, NULL, 'Mục đích chính của chỉ mục (INDEX) là gì?', 'Chỉ mục giúp tìm dòng nhanh hơn, đổi lại tốn thêm bộ nhớ và chậm khi ghi.', 'Trung bình', 'Hiển thị'),
  (9, 1, 1, 'Phép nối nào giữ lại cả các dòng không khớp của bảng bên trái?', 'LEFT JOIN trả mọi dòng bên trái, bên phải không khớp thì là NULL.', 'Khó', 'Hiển thị'),
  (10, 1, 1, 'Dạng chuẩn 1NF yêu cầu điều gì?', '1NF: giá trị nguyên tố; 2NF và 3NF xử lý phụ thuộc hàm.', 'Dễ', 'Hiển thị'),
  (11, 1, 1, 'Hàm nào đếm số dòng trong SQL?', 'COUNT(*) đếm số dòng, SUM cộng giá trị.', 'Trung bình', 'Hiển thị'),
  (12, 1, 1, 'Lệnh nào xóa bảng cùng toàn bộ cấu trúc của nó?', 'DROP TABLE xóa luôn định nghĩa bảng; DELETE và TRUNCATE chỉ xóa dữ liệu.', 'Khó', 'Hiển thị'),
  (13, 1, 1, 'TRUNCATE TABLE khác DELETE ở điểm nào?', 'TRUNCATE xóa sạch dữ liệu một lần, không lọc theo điều kiện.', 'Dễ', 'Hiển thị'),
  (14, 1, 1, 'Stored procedure là gì?', 'Procedure giúp tái sử dụng logic phía CSDL.', 'Trung bình', 'Hiển thị'),
  (15, 1, 2, 'Trigger là gì?', 'Trigger gắn với bảng và tự kích hoạt theo sự kiện.', 'Khó', 'Hiển thị'),
  (16, 1, 2, 'SELECT ... FOR UPDATE dùng để làm gì?', 'Các giao dịch khác phải chờ đến khi giao dịch này kết thúc.', 'Dễ', 'Hiển thị'),
  (17, 1, 2, 'Deadlock (khóa chết) là tình huống nào?', 'Giữ khóa theo thứ tự cố định giúp tránh deadlock.', 'Trung bình', 'Hiển thị'),
  (18, 1, 2, 'Công cụ nào thường dùng để sao lưu CSDL MySQL bằng dòng lệnh?', 'mysqldump xuất cấu trúc và dữ liệu ra file SQL.', 'Khó', 'Hiển thị'),
  (19, 1, 2, 'Kiểu dữ liệu nào phù hợp để lưu số tiền cần chính xác?', 'DECIMAL lưu số thập phân chính xác, FLOAT/DOUBLE có sai số làm tròn.', 'Dễ', 'Hiển thị'),
  (20, 1, 2, 'Mức cô lập giao dịch nào chặt nhất?', 'SERIALIZABLE chạy các giao dịch như thể tuần tự, ít lỗi đọc nhất nhưng chậm nhất.', 'Trung bình', 'Hiển thị'),
  (21, 2, NULL, 'Sơ đồ use case dùng để mô tả điều gì?', 'Use case cho biết ai dùng hệ thống để làm việc gì.', 'Dễ', 'Hiển thị'),
  (22, 2, NULL, 'Tác nhân (actor) trong use case là gì?', 'Actor có thể là người dùng hoặc hệ thống khác.', 'Trung bình', 'Hiển thị'),
  (23, 2, NULL, 'Yêu cầu "hệ thống phản hồi trong 1 giây" thuộc loại nào?', 'Phi chức năng mô tả chất lượng như tốc độ, bảo mật, độ tin cậy.', 'Khó', 'Hiển thị'),
  (24, 2, NULL, 'Quan hệ <<include>> giữa hai use case có nghĩa là gì?', 'Include là phần bắt buộc dùng lại; extend mới là tùy chọn.', 'Dễ', 'Hiển thị'),
  (25, 2, NULL, 'Quan hệ <<extend>> có nghĩa là gì?', 'Extend dành cho hành vi tùy chọn, có điều kiện.', 'Trung bình', 'Hiển thị'),
  (26, 2, NULL, 'Sơ đồ ERD dùng để làm gì?', 'ERD là nền tảng để thiết kế cơ sở dữ liệu.', 'Khó', 'Hiển thị'),
  (27, 2, NULL, 'Bản số 1 : n giữa A và B nghĩa là gì?', 'Ví dụ một môn học có nhiều câu hỏi.', 'Dễ', 'Hiển thị'),
  (28, 2, NULL, 'Một user story thường được viết theo mẫu nào?', 'Mẫu này nhấn mạnh người dùng và giá trị mang lại.', 'Trung bình', 'Hiển thị'),
  (29, 2, 1, 'Bên liên quan (stakeholder) là ai?', 'Stakeholder gồm người dùng, khách hàng, quản lý, đội phát triển...', 'Khó', 'Hiển thị'),
  (30, 2, 1, 'Kỹ thuật thu thập yêu cầu bằng cách hỏi trực tiếp người dùng là gì?', 'Phỏng vấn giúp làm rõ nhu cầu qua trao đổi trực tiếp.', 'Dễ', 'Hiển thị'),
  (31, 2, 1, 'Sơ đồ hoạt động (activity diagram) thường mô tả điều gì?', 'Activity diagram mô tả quy trình nghiệp vụ từng bước.', 'Trung bình', 'Hiển thị'),
  (32, 2, 1, 'Sơ đồ tuần tự (sequence diagram) nhấn mạnh điều gì?', 'Trục dọc của sơ đồ là thời gian.', 'Khó', 'Hiển thị'),
  (33, 2, 1, 'Tiền điều kiện (precondition) của use case là gì?', 'Ví dụ: sinh viên phải đăng nhập trước khi tìm trận.', 'Dễ', 'Hiển thị'),
  (34, 2, 1, 'Hậu điều kiện (postcondition) là gì?', 'Hậu điều kiện cho biết hệ thống đã thay đổi như thế nào.', 'Trung bình', 'Hiển thị'),
  (35, 2, 2, 'Luồng ngoại lệ trong mô tả use case dùng để làm gì?', 'Ví dụ: nhập sai mật khẩu, không đủ câu hỏi để tìm trận.', 'Khó', 'Hiển thị'),
  (36, 2, 2, 'Một yêu cầu phần mềm tốt cần có đặc điểm nào?', 'Yêu cầu đo lường được thì mới kiểm tra được hệ thống đã đáp ứng chưa.', 'Dễ', 'Hiển thị'),
  (37, 2, 2, 'Mô hình thác nước (waterfall) có đặc điểm nào?', 'Waterfall khó thay đổi yêu cầu giữa chừng.', 'Trung bình', 'Hiển thị'),
  (38, 2, 2, 'Trong Scrum, Sprint là gì?', 'Sprint thường kéo dài 1 đến 4 tuần.', 'Khó', 'Hiển thị'),
  (39, 2, 2, 'Product Backlog là gì?', 'Product Owner quản lý và sắp xếp Product Backlog.', 'Dễ', 'Hiển thị'),
  (40, 2, 2, 'Tài liệu SRS là gì?', 'SRS (Software Requirements Specification) ghi lại yêu cầu chức năng và phi chức năng.', 'Trung bình', 'Hiển thị'),
  (41, 3, NULL, 'PHP chủ yếu được thực thi ở phía nào?', 'Máy chủ chạy PHP rồi gửi HTML kết quả về trình duyệt.', 'Dễ', 'Hiển thị'),
  (42, 3, NULL, 'Biến trong PHP bắt đầu bằng ký tự nào?', 'Ví dụ: $ten = ''An'';', 'Trung bình', 'Hiển thị'),
  (43, 3, NULL, 'Toán tử nào dùng để nối chuỗi trong PHP?', 'Ví dụ: ''Xin '' . ''chào''.', 'Khó', 'Hiển thị'),
  (44, 3, NULL, 'Hàm nào dùng để băm mật khẩu trước khi lưu?', 'password_hash tạo băm có muối, an toàn hơn md5.', 'Dễ', 'Hiển thị'),
  (45, 3, NULL, 'Hàm nào kiểm tra mật khẩu người dùng nhập với chuỗi băm đã lưu?', 'password_verify so khớp mật khẩu với băm của password_hash.', 'Trung bình', 'Hiển thị'),
  (46, 3, NULL, 'Cách nào phòng chống SQL Injection hiệu quả nhất?', 'Prepared statement tách câu lệnh SQL khỏi dữ liệu.', 'Khó', 'Hiển thị'),
  (47, 3, NULL, 'Hàm nào dùng để chống XSS khi in dữ liệu ra HTML?', 'Hàm này đổi ký tự đặc biệt thành thực thể HTML.', 'Dễ', 'Hiển thị'),
  (48, 3, NULL, 'Dữ liệu từ form gửi bằng phương thức POST nằm ở đâu?', 'Form method="post" đưa dữ liệu vào $_POST.', 'Trung bình', 'Hiển thị'),
  (49, 3, 1, 'Hàm nào bắt đầu một phiên (session) trong PHP?', 'Phải gọi trước khi dùng $_SESSION.', 'Khó', 'Hiển thị'),
  (50, 3, 1, 'Làm thế nào để chuyển hướng sang trang khác trong PHP?', 'Nên gọi exit sau header để dừng xử lý.', 'Dễ', 'Hiển thị'),
  (51, 3, 1, 'Khác biệt giữa require và include là gì?', 'include chỉ cảnh báo còn require dừng chương trình.', 'Trung bình', 'Hiển thị'),
  (52, 3, 1, 'PDO trong PHP là gì?', 'PDO hỗ trợ MySQL, PostgreSQL, SQLite... bằng cùng một cách.', 'Khó', 'Hiển thị'),
  (53, 3, 1, 'Mảng kết hợp (associative array) trong PHP là gì?', 'Ví dụ: [''ten'' => ''An'', ''tuoi'' => 20].', 'Dễ', 'Hiển thị'),
  (54, 3, 1, 'Hàm nào đếm số phần tử của một mảng?', 'count($mang) trả về số phần tử.', 'Trung bình', 'Hiển thị'),
  (55, 3, 2, 'Từ khóa nào dùng để khai báo một lớp trong PHP?', 'Ví dụ: class NguoiDung { ... }.', 'Khó', 'Hiển thị'),
  (56, 3, 2, 'Từ khóa nào dùng để ném một ngoại lệ?', 'throw new Exception(''...''); catch bắt ngoại lệ.', 'Dễ', 'Hiển thị'),
  (57, 3, 2, 'Toán tử so sánh nào kiểm tra cả giá trị lẫn kiểu dữ liệu?', '=== trả về true chỉ khi giá trị và kiểu đều giống nhau.', 'Trung bình', 'Hiển thị'),
  (58, 3, 2, 'Hàm isset($x) trả về true khi nào?', 'Khác với empty(), isset chỉ kiểm tra tồn tại và khác NULL.', 'Khó', 'Hiển thị'),
  (59, 3, 2, 'Biến siêu toàn cục nào chứa thông tin file được tải lên?', 'Khi form có enctype multipart/form-data thì file nằm trong $_FILES.', 'Dễ', 'Hiển thị'),
  (60, 3, 2, 'Cách phổ biến để chống tấn công CSRF là gì?', 'Token chỉ trang của mình mới có nên trang giả mạo không gửi đúng được.', 'Trung bình', 'Hiển thị');

INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES
  (1, 'UPDATE', 0),
  (1, 'DELETE', 0),
  (1, 'INSERT', 0),
  (1, 'SELECT', 1),
  (2, 'Giá trị duy nhất và không được NULL', 1),
  (2, 'Luôn là kiểu chuỗi', 0),
  (2, 'Được phép NULL nhưng không trùng', 0),
  (2, 'Có thể trùng nhưng không NULL', 0),
  (3, 'FOREIGN KEY', 1),
  (3, 'UNIQUE', 0),
  (3, 'CHECK', 0),
  (3, 'DEFAULT', 0),
  (4, 'ONLY', 0),
  (4, 'GROUP', 0),
  (4, 'DISTINCT', 1),
  (4, 'UNIQUE', 0),
  (5, 'ORDER BY', 0),
  (5, 'LIMIT', 0),
  (5, 'WHERE', 0),
  (5, 'HAVING', 1),
  (6, 'Accuracy', 0),
  (6, 'Availability', 0),
  (6, 'Atomicity (nguyên tử)', 1),
  (6, 'Authority', 0),
  (7, 'UNDO', 0),
  (7, 'COMMIT', 0),
  (7, 'ROLLBACK', 1),
  (7, 'SAVE', 0),
  (8, 'Tăng tốc độ tìm kiếm và truy vấn', 1),
  (8, 'Mã hóa dữ liệu', 0),
  (8, 'Giảm dung lượng dữ liệu', 0),
  (8, 'Tự động sao lưu dữ liệu', 0),
  (9, 'INNER JOIN', 0),
  (9, 'LEFT JOIN', 1),
  (9, 'CROSS JOIN', 0),
  (9, 'SELF JOIN', 0),
  (10, 'Bảng không có khóa ngoại', 0),
  (10, 'Không có phụ thuộc bắc cầu', 0),
  (10, 'Mỗi thuộc tính chỉ chứa giá trị nguyên tố, không đa trị hay lặp', 1),
  (10, 'Mọi thuộc tính phụ thuộc đầy đủ vào khóa chính', 0),
  (11, 'LENGTH()', 0),
  (11, 'COUNT()', 1),
  (11, 'SUM()', 0),
  (11, 'TOTAL()', 0),
  (12, 'REMOVE TABLE', 0),
  (12, 'DELETE FROM', 0),
  (12, 'DROP TABLE', 1),
  (12, 'TRUNCATE TABLE', 0),
  (13, 'Xóa toàn bộ dòng nhanh và không dùng được WHERE', 1),
  (13, 'Chỉ xóa các dòng bị trùng', 0),
  (13, 'Xóa luôn cấu trúc bảng', 0),
  (13, 'Cho phép hoàn tác trong mọi trường hợp', 0),
  (14, 'Một kiểu dữ liệu', 0),
  (14, 'Bản sao lưu của CSDL', 0),
  (14, 'Một loại chỉ mục', 0),
  (14, 'Tập lệnh SQL được lưu sẵn trong CSDL và gọi bằng tên', 1),
  (15, 'Công cụ phân quyền', 0),
  (15, 'Một dạng khóa ngoại', 0),
  (15, 'Lệnh dùng để sao lưu', 0),
  (15, 'Đoạn mã tự chạy khi có INSERT, UPDATE hoặc DELETE', 1),
  (16, 'Khóa các dòng được đọc trong giao dịch để tránh xung đột ghi', 1),
  (16, 'Cập nhật tự động các dòng được chọn', 0),
  (16, 'Tăng tốc truy vấn', 0),
  (16, 'Sao chép dòng sang bảng khác', 0),
  (17, 'Hai giao dịch chờ khóa của nhau nên không bên nào tiếp tục được', 1),
  (17, 'Một giao dịch chạy quá lâu', 0),
  (17, 'Mất kết nối đến máy chủ', 0),
  (17, 'Bảng bị đầy dung lượng', 0),
  (18, 'mysqlcheck', 0),
  (18, 'mysqladmin ping', 0),
  (18, 'mysqlshow', 0),
  (18, 'mysqldump', 1),
  (19, 'TEXT', 0),
  (19, 'DOUBLE', 0),
  (19, 'DECIMAL', 1),
  (19, 'FLOAT', 0),
  (20, 'READ COMMITTED', 0),
  (20, 'SERIALIZABLE', 1),
  (20, 'READ UNCOMMITTED', 0),
  (20, 'REPEATABLE READ', 0),
  (21, 'Cấu trúc các bảng dữ liệu', 0),
  (21, 'Thứ tự thông điệp theo thời gian', 0),
  (21, 'Các chức năng của hệ thống theo góc nhìn của tác nhân', 1),
  (21, 'Cách triển khai mã nguồn', 0),
  (22, 'Đối tượng bên ngoài tương tác với hệ thống', 1),
  (22, 'Một bảng trong CSDL', 0),
  (22, 'Một module của hệ thống', 0),
  (22, 'Một lớp trong mã nguồn', 0),
  (23, 'Yêu cầu phi chức năng (hiệu năng)', 1),
  (23, 'Yêu cầu chức năng', 0),
  (23, 'Yêu cầu về dữ liệu', 0),
  (23, 'Ràng buộc pháp lý', 0),
  (24, 'Use case gốc luôn gọi use case được include', 1),
  (24, 'Use case con chỉ chạy khi có điều kiện', 0),
  (24, 'Use case con kế thừa use case cha', 0),
  (24, 'Hai use case loại trừ nhau', 0),
  (25, 'Hai tác nhân dùng chung use case', 0),
  (25, 'Use case mở rộng chỉ chạy khi thỏa điều kiện', 1),
  (25, 'Use case luôn được gọi', 0),
  (25, 'Use case bị xóa khỏi hệ thống', 0),
  (26, 'Lập lịch dự án', 0),
  (26, 'Mô hình hóa các thực thể dữ liệu và quan hệ giữa chúng', 1),
  (26, 'Mô tả luồng công việc', 0),
  (26, 'Mô tả giao diện người dùng', 0),
  (27, 'Nhiều A liên kết nhiều B', 0),
  (27, 'Một A chỉ có đúng một B', 0),
  (27, 'A và B không liên quan', 0),
  (27, 'Một bản ghi của A liên kết nhiều bản ghi của B', 1),
  (28, 'Nếu <điều kiện> thì <kết quả>', 0),
  (28, 'Là một <vai trò>, tôi muốn <việc cần làm> để <lợi ích>', 1),
  (28, 'Hệ thống phải <hành động>', 0),
  (28, 'Bảng <tên> gồm các cột <danh sách>', 0),
  (29, 'Người có lợi ích hoặc bị ảnh hưởng bởi hệ thống', 1),
  (29, 'Chỉ lập trình viên của dự án', 0),
  (29, 'Máy chủ chạy hệ thống', 0),
  (29, 'Chỉ khách hàng trả tiền', 0),
  (30, 'Phỏng vấn', 1),
  (30, 'Quan sát tài liệu', 0),
  (30, 'Prototype', 0),
  (30, 'Brainstorm nhóm', 0),
  (31, 'Quan hệ giữa các bảng', 0),
  (31, 'Luồng công việc hoặc quy trình gồm các bước và rẽ nhánh', 1),
  (31, 'Cấu trúc thư mục dự án', 0),
  (31, 'Danh sách tác nhân', 0),
  (32, 'Thứ tự các thông điệp giữa các đối tượng theo thời gian', 1),
  (32, 'Chi phí dự án', 0),
  (32, 'Quan hệ giữa các bảng dữ liệu', 0),
  (32, 'Cấu trúc phân cấp tổ chức', 0),
  (33, 'Số lần use case được dùng', 0),
  (33, 'Danh sách lỗi có thể xảy ra', 0),
  (33, 'Kết quả sau khi use case chạy xong', 0),
  (33, 'Điều kiện phải đúng trước khi use case bắt đầu', 1),
  (34, 'Tên của tác nhân chính', 0),
  (34, 'Điều kiện để use case bắt đầu', 0),
  (34, 'Trạng thái hệ thống sau khi use case thực hiện thành công', 1),
  (34, 'Giao diện của use case', 0),
  (35, 'Liệt kê các tác nhân', 0),
  (35, 'Mô tả luồng chính thành công', 0),
  (35, 'Mô tả các tình huống lỗi hoặc khác thường', 1),
  (35, 'Liệt kê yêu cầu phi chức năng', 0),
  (36, 'Chứa chi tiết cài đặt mã nguồn', 0),
  (36, 'Rõ ràng, không mơ hồ và kiểm thử được', 1),
  (36, 'Viết càng ngắn càng tốt', 0),
  (36, 'Dùng nhiều thuật ngữ chuyên ngành', 0),
  (37, 'Các giai đoạn thực hiện tuần tự, xong giai đoạn này mới sang giai đoạn sau', 1),
  (37, 'Luôn bắt đầu từ lập trình', 0),
  (37, 'Không có giai đoạn kiểm thử', 0),
  (37, 'Lặp lại ngắn và liên tục nhận phản hồi', 0),
  (38, 'Một vai trò trong nhóm', 0),
  (38, 'Khoảng thời gian cố định để hoàn thành một phần công việc', 1),
  (38, 'Danh sách các lỗi', 0),
  (38, 'Một cuộc họp cuối dự án', 0),
  (39, 'Tài liệu thiết kế CSDL', 0),
  (39, 'Danh sách lỗi đã sửa', 0),
  (39, 'Biên bản họp nhóm', 0),
  (39, 'Danh sách yêu cầu của sản phẩm được sắp xếp theo mức ưu tiên', 1),
  (40, 'Kế hoạch kiểm thử', 0),
  (40, 'Báo cáo tài chính dự án', 0),
  (40, 'Tài liệu hướng dẫn cài đặt', 0),
  (40, 'Đặc tả yêu cầu phần mềm', 1),
  (41, 'Card mạng', 0),
  (41, 'Hệ điều hành của người dùng', 0),
  (41, 'Máy chủ (server-side)', 1),
  (41, 'Trình duyệt', 0),
  (42, 'Dấu $', 1),
  (42, 'Dấu @', 0),
  (42, 'Dấu &', 0),
  (42, 'Dấu #', 0),
  (43, 'Dấu phẩy (,)', 0),
  (43, 'Dấu gạch nối (-)', 0),
  (43, 'Dấu chấm (.)', 1),
  (43, 'Dấu cộng (+)', 0),
  (44, 'md5()', 0),
  (44, 'password_hash()', 1),
  (44, 'base64_encode()', 0),
  (44, 'strrev()', 0),
  (45, 'hash_equals()', 0),
  (45, 'password_verify()', 1),
  (45, 'strcmp()', 0),
  (45, 'password_check()', 0),
  (46, 'Ẩn thông báo lỗi', 0),
  (46, 'Dùng md5 cho dữ liệu nhập', 0),
  (46, 'Dùng prepared statement với tham số (PDO)', 1),
  (46, 'Giới hạn độ dài ô nhập', 0),
  (47, 'trim()', 0),
  (47, 'strtolower()', 0),
  (47, 'htmlspecialchars()', 1),
  (47, 'addslashes()', 0),
  (48, '$_COOKIE', 0),
  (48, '$_POST', 1),
  (48, '$_GET', 0),
  (48, '$_SERVER', 0),
  (49, 'session_open()', 0),
  (49, 'session_start()', 1),
  (49, 'begin_session()', 0),
  (49, 'start_session()', 0),
  (50, 'redirect_to(''trang.php'')', 0),
  (50, 'echo ''Location: trang.php''', 0),
  (50, 'goto(''trang.php'')', 0),
  (50, 'header(''Location: trang.php'')', 1),
  (51, 'Hai hàm hoàn toàn giống nhau', 0),
  (51, 'require chỉ dùng được với file .html', 0),
  (51, 'include dừng chương trình khi thiếu file', 0),
  (51, 'require gây lỗi nghiêm trọng và dừng nếu không tìm thấy file', 1),
  (52, 'Một thư viện tạo biểu đồ', 0),
  (52, 'Một framework giao diện', 0),
  (52, 'Một công cụ gửi email', 0),
  (52, 'Lớp truy cập CSDL thống nhất cho nhiều hệ quản trị', 1),
  (53, 'Mảng dùng cặp khóa => giá trị', 1),
  (53, 'Mảng chỉ chứa số nguyên', 0),
  (53, 'Mảng có đúng hai phần tử', 0),
  (53, 'Mảng không thể thay đổi', 0),
  (54, 'length()', 0),
  (54, 'count()', 1),
  (54, 'size()', 0),
  (54, 'total()', 0),
  (55, 'struct', 0),
  (55, 'class', 1),
  (55, 'object', 0),
  (55, 'module', 0),
  (56, 'catch', 0),
  (56, 'throw', 1),
  (56, 'error', 0),
  (56, 'raise', 0),
  (57, '===', 1),
  (57, '==', 0),
  (57, '=', 0),
  (57, '<=>', 0),
  (58, 'Biến $x có giá trị khác 0', 0),
  (58, 'Biến $x đã được khai báo và không phải NULL', 1),
  (58, 'Biến $x là chuỗi không rỗng', 0),
  (58, 'Biến $x là kiểu số', 0),
  (59, '$_REQUEST', 0),
  (59, '$_UPLOAD', 0),
  (59, '$_POST', 0),
  (59, '$_FILES', 1),
  (60, 'Mã hóa mật khẩu bằng md5', 0),
  (60, 'Chỉ cho phép phương thức GET', 0),
  (60, 'Tắt cookie của trình duyệt', 0),
  (60, 'Gắn token bí mật vào form và kiểm tra ở máy chủ', 1);















  -- Dữ liệu thử nghiệm: 2 môn, mỗi môn 12 câu (loại kỳ thi để trống = dùng cho cả Giữa kỳ và Cuối kỳ).
-- Chạy MỘT LẦN trên CSDL elo_quiz (cần đã có bảng và dữ liệu LOAIKYTHI, CAUHINH).
-- Muốn xóa sau khi thử: xem phần cuối file.
SET NAMES utf8mb4;

INSERT INTO MONHOC (TenMon, MoTa, TrangThai) VALUES ('Tin học cơ bản (thử nghiệm)', 'Môn mẫu để thử ghép đôi và thi đấu', 'Đang mở');
SET @mon1 = LAST_INSERT_ID();
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, '1 byte bằng bao nhiêu bit?', '1 byte gồm 8 bit.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '4', 0),(@c, '8', 1),(@c, '16', 0),(@c, '32', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'CPU là viết tắt của cụm từ nào?', 'CPU là bộ xử lý trung tâm.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Central Processing Unit', 1),(@c, 'Computer Personal Unit', 0),(@c, 'Central Program Utility', 0),(@c, 'Core Processing Update', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Hệ nhị phân dùng những chữ số nào?', 'Hệ nhị phân chỉ có hai chữ số 0 và 1.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '0 đến 9', 0),(@c, '1 và 2', 0),(@c, '0 và 1', 1),(@c, '0, 1 và 2', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Phần mở rộng phổ biến của tệp trang web là gì?', 'Trang web thường lưu ở tệp .html.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '.exe', 0),(@c, '.mp3', 0),(@c, '.docx', 0),(@c, '.html', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'HTTP là viết tắt của cụm từ nào?', 'HTTP là giao thức truyền siêu văn bản.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'HyperText Transfer Protocol', 1),(@c, 'High Transfer Text Program', 0),(@c, 'Host Text Transmission Path', 0),(@c, 'HyperText Transmission Process', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Thiết bị nào sau đây là thiết bị nhập?', 'Bàn phím dùng để đưa dữ liệu vào máy tính.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Máy in', 0),(@c, 'Bàn phím', 1),(@c, 'Loa', 0),(@c, 'Màn hình', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'RAM có đặc điểm nào sau đây?', 'RAM là bộ nhớ tạm, mất dữ liệu khi tắt nguồn.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Giữ dữ liệu khi tắt nguồn', 0),(@c, 'Chỉ để đọc, không ghi được', 0),(@c, 'Mất dữ liệu khi tắt nguồn', 1),(@c, 'Chỉ dùng để lưu ảnh', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Ngôn ngữ nào dùng để truy vấn cơ sở dữ liệu quan hệ?', 'SQL là ngôn ngữ truy vấn có cấu trúc.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'HTML', 0),(@c, 'CSS', 0),(@c, 'JSON', 0),(@c, 'SQL', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Phím tắt sao chép trong Windows là gì?', 'Ctrl + C để sao chép, Ctrl + V để dán.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Ctrl + C', 1),(@c, 'Ctrl + V', 0),(@c, 'Ctrl + X', 0),(@c, 'Ctrl + Z', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, '1 KB bằng bao nhiêu byte (theo cách tính nhị phân)?', '1 KB = 1024 byte.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '1000', 0),(@c, '1024', 1),(@c, '2048', 0),(@c, '512', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Địa chỉ IPv4 gồm mấy nhóm số?', 'IPv4 gồm 4 nhóm số, ví dụ 192.168.1.1.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '2', 0),(@c, '3', 0),(@c, '4', 1),(@c, '6', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Hệ điều hành nào sau đây là mã nguồn mở?', 'Linux là hệ điều hành mã nguồn mở.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Windows', 0),(@c, 'macOS', 0),(@c, 'iOS', 0),(@c, 'Linux', 1);

INSERT INTO MONHOC (TenMon, MoTa, TrangThai) VALUES ('Toán cơ bản (thử nghiệm)', 'Môn mẫu để thử ghép đôi và thi đấu', 'Đang mở');
SET @mon2 = LAST_INSERT_ID();
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '15 + 27 bằng bao nhiêu?', '15 + 27 = 42.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '42', 1),(@c, '41', 0),(@c, '43', 0),(@c, '52', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '9 × 8 bằng bao nhiêu?', '9 × 8 = 72.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '63', 0),(@c, '81', 0),(@c, '72', 1),(@c, '64', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Căn bậc hai của 144 là bao nhiêu?', '12 × 12 = 144.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '11', 0),(@c, '14', 0),(@c, '16', 0),(@c, '12', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '100 − 37 bằng bao nhiêu?', '100 − 37 = 63.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '73', 0),(@c, '63', 1),(@c, '67', 0),(@c, '53', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '2 mũ 5 bằng bao nhiêu?', '2^5 = 2 × 2 × 2 × 2 × 2 = 32.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '32', 1),(@c, '25', 0),(@c, '16', 0),(@c, '64', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '25% của 80 là bao nhiêu?', '80 × 25 / 100 = 20.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '25', 0),(@c, '40', 0),(@c, '20', 1),(@c, '16', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Chu vi hình vuông cạnh 6 là bao nhiêu?', 'Chu vi = 4 × 6 = 24.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '12', 0),(@c, '36', 0),(@c, '18', 0),(@c, '24', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Diện tích hình chữ nhật có chiều dài 7 và chiều rộng 5 là bao nhiêu?', 'Diện tích = 7 × 5 = 35.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '35', 1),(@c, '24', 0),(@c, '12', 0),(@c, '30', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '3/4 + 1/4 bằng bao nhiêu?', '3/4 + 1/4 = 4/4 = 1.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '4/8', 0),(@c, '1', 1),(@c, '3/16', 0),(@c, '1/2', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Số nguyên tố nhỏ nhất là số nào?', '2 là số nguyên tố nhỏ nhất.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '0', 0),(@c, '1', 0),(@c, '3', 0),(@c, '2', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '7 × 6 − 10 bằng bao nhiêu?', '42 − 10 = 32.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '32', 1),(@c, '42', 0),(@c, '36', 0),(@c, '30', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Trung bình cộng của 4, 8 và 12 là bao nhiêu?', '(4 + 8 + 12) / 3 = 8.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '6', 0),(@c, '12', 0),(@c, '8', 1),(@c, '10', 0);

-- XÓA dữ liệu thử nghiệm (chỉ chạy khi môn chưa có trận nào; đã có trận thì chỉ nên ẩn môn):
-- DELETE d FROM DAPAN d JOIN CAUHOI c ON c.MaCauHoi = d.MaCauHoi JOIN MONHOC m ON m.MaMon = c.MaMon WHERE m.TenMon LIKE '%(thử nghiệm)';
-- DELETE c FROM CAUHOI c JOIN MONHOC m ON m.MaMon = c.MaMon WHERE m.TenMon LIKE '%(thử nghiệm)';
-- DELETE FROM MONHOC WHERE TenMon LIKE '%(thử nghiệm)';


-- Chạy MỘT LẦN trên CSDL đã có sẵn (nếu bạn không import lại file csdl_elo_quiz.sql mới).
ALTER TABLE TRANDAU
  MODIFY LyDoKetThuc ENUM('Hoàn thành','Đối thủ bỏ trận (xử thua)','Hủy do lỗi hệ thống',
                          'Hủy do không vào phòng','Quản trị viên hủy','Chịu thua') NULL;
