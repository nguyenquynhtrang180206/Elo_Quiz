// Trang Đổi mật khẩu Admin: ẩn/hiện mật khẩu và kiểm tra điều kiện theo thời gian thực.
(function () {
  'use strict';
  var form = document.querySelector('.dmk-form');
  if (!form) { return; }

  Array.prototype.forEach.call(form.querySelectorAll('.dmk-mat'), function (nut) {
    var o = nut.parentNode.querySelector('input');
    nut.addEventListener('click', function () {
      var hien = o.type === 'password';
      o.type = hien ? 'text' : 'password';
      nut.setAttribute('aria-pressed', hien ? 'true' : 'false');
      nut.setAttribute('aria-label', hien ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
      o.focus();
    });
  });

  var cu = document.getElementById('mat_khau_cu');
  var moi = document.getElementById('mat_khau_moi');
  var xn = document.getElementById('xac_nhan');
  var goiY = document.getElementById('dmkGoiY');
  if (!cu || !moi || !xn || !goiY) { return; }

  function dat(ma, ok) {
    var li = goiY.querySelector('[data-quy="' + ma + '"]');
    if (li) { li.classList.toggle('dat', ok); }
  }
  function kiemTra() {
    var m = moi.value;
    dat('dai', m.length >= 8 && m.length <= 72);
    dat('hoa_thuong', /[a-z]/.test(m) && /[A-Z]/.test(m));
    dat('so_ky_tu', /[0-9]/.test(m) && /[!@#$%^&*()_\-+=\[\]{};:,.<>?/~`|\\]/.test(m));
    dat('khac', m !== '' && m !== cu.value);
    dat('khop', m !== '' && m === xn.value);
  }
  [cu, moi, xn].forEach(function (o) { o.addEventListener('input', kiemTra); });
  kiemTra();
})();