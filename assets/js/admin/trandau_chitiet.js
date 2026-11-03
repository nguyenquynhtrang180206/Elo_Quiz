/* Trang Chi tiết trận đấu (admin/trandau_chitiet.php): hỏi xác nhận trước khi gửi form có thuộc tính data-xac-nhan */
document.addEventListener('submit', function (e) {
  var cauHoi = e.target.getAttribute('data-xac-nhan');
  if (cauHoi && !confirm(cauHoi)) { e.preventDefault(); }
});
