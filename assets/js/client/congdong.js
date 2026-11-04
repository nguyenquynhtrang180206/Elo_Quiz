/* Trang Cộng đồng (client/congdong.php) */
(function () {
  // Chọn môn xong thì tự lọc, không cần bấm nút
  var chonMon = document.getElementById('loc_mon');
  if (chonMon) {
    chonMon.addEventListener('change', function () { chonMon.form.submit(); });
  }

  // Nút "Đăng bài viết" ở banner: mở khung viết bài và đưa con trỏ vào ô tiêu đề
  var khung = document.getElementById('dang-bai');
  function moKhung() {
    if (!khung) { return; }
    khung.open = true;
    var o = document.getElementById('tieu_de');
    if (o) { o.focus({ preventScroll: true }); }
  }
  document.querySelectorAll('[data-mo-form]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      moKhung();
      if (khung) { khung.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
  });
  if (location.hash === '#dang-bai') { moKhung(); }
})();