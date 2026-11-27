// Xác nhận thao tác trên trang Chi tiết người dùng (admin/nguoidung_chitiet.php).
document.addEventListener('click', function (e) {
  var nut = e.target.closest('[data-xac-nhan]');
  if (nut && !confirm(nut.getAttribute('data-xac-nhan'))) { e.preventDefault(); }
});
