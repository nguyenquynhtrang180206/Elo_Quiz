/* Trang Cộng đồng (client/congdong.php): chọn môn xong thì tự lọc, không cần bấm nút */
(function () {
  var chonMon = document.getElementById('loc_mon');
  if (chonMon) {
    chonMon.addEventListener('change', function () { chonMon.form.submit(); });
  }
})();
