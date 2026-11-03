/* Trang Tìm trận (client/timtran.php) */
(function () {
  // Đang chờ ghép đối: tải lại trang định kỳ để cập nhật thời gian chờ và thử ghép đôi (polling).
  var dangCho = document.querySelector('[data-tai-lai]');
  if (dangCho) {
    setTimeout(function () { location.reload(); }, parseInt(dangCho.getAttribute('data-tai-lai'), 10) || 2000);
  }

  // Chọn môn xong thì tự gửi form để hiện các loại kỳ thi của môn đó.
  var chonMon = document.getElementById('mon');
  if (chonMon) {
    chonMon.addEventListener('change', function () { chonMon.form.submit(); });
  }
})();
