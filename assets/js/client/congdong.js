/* Trang Cộng đồng (client/congdong.php) - Theme Cosmic Esports Arena */
(function () {
  // 1. Tự động submit lọc khi thay đổi môn
  var chonMon = document.getElementById('loc_mon');
  if (chonMon) {
    chonMon.addEventListener('change', function () { chonMon.form.submit(); });
  }

  // 2. Mở khung đăng bài viết nhanh
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

  // 3. Hiệu ứng nhấp nháy / nảy vui nhộn khi click vào Chibi Faker
  var fakerBtn = document.getElementById('cd-faker-btn');
  if (fakerBtn) {
    fakerBtn.addEventListener('click', function () {
      fakerBtn.classList.remove('faker-bounce');
      void fakerBtn.offsetWidth; // trigger reflow
      fakerBtn.classList.add('faker-bounce');
    });
  }
})();
