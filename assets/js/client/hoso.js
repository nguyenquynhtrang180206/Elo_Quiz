/* Trang hồ sơ: nút "Xem thêm" ở chân trang Elo theo môn - hiện các số trang từ 3 trở đi. */
(function () {
  'use strict';
  var nut = document.querySelector('.elo-xemthem');
  if (!nut) return;
  var them = document.getElementById(nut.getAttribute('aria-controls'));
  if (!them) return;
  nut.addEventListener('click', function () {
    them.hidden = false;
    nut.remove();
  });
})();