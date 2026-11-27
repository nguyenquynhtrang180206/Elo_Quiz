// Nút Xem thêm phân trang Elo theo môn trên trang Hồ sơ.
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