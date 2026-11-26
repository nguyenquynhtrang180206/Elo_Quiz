// Xác nhận gửi form trên trang Chi tiết trận đấu (admin/trandau_chitiet.php).
document.addEventListener('submit', function (e) {
  var cauHoi = e.target.getAttribute('data-xac-nhan');
  if (cauHoi && !confirm(cauHoi)) { e.preventDefault(); }
});
