/* Trang Chi tiết người dùng (admin/nguoidung_chitiet.php): hỏi xác nhận trước khi thực hiện nút có thuộc tính data-xac-nhan */
document.addEventListener('click', function (e) {
  var nut = e.target.closest('[data-xac-nhan]');
  if (nut && !confirm(nut.getAttribute('data-xac-nhan'))) { e.preventDefault(); }
});
