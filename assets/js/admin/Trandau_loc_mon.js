// Ô lọc môn có tìm kiếm trên trang Trận đấu (hiện 5 môn đầu và nút Xem thêm).
(function () {
  'use strict';
  var goc = document.getElementById('tdMon');
  if (!goc) return;

  var gioiHan = parseInt(goc.getAttribute('data-gioi-han'), 10) || 5;
  var an      = goc.querySelector('input[type=hidden][name=mon]');
  var nut     = goc.querySelector('.td-mon-nut');
  var hop     = goc.querySelector('.td-mon-hop');
  var oTim    = goc.querySelector('.td-mon-tim');
  var rong    = goc.querySelector('.td-mon-rong');
  var them    = goc.querySelector('.td-mon-them');
  var form    = goc.closest('form');
  // Mục "Mọi môn" (data-v = 0) luôn hiện, không tính vào giới hạn
  var muc     = Array.prototype.slice.call(goc.querySelectorAll('.td-mon-cd'));
  var mucMon  = muc.filter(function (b) { return b.getAttribute('data-v') !== '0'; });
  var moRong  = false;   // đã bấm "Xem thêm"

  // Bỏ dấu tiếng Việt để gõ "toan" vẫn ra "Toán"
  function chuanHoa(s) {
    return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').trim();
  }
  var khoa = mucMon.map(function (b) { return chuanHoa(b.textContent); });

  function ve() {
    var tu = chuanHoa(oTim.value);
    var khop = [];
    mucMon.forEach(function (b, i) { if (tu === '' || khoa[i].indexOf(tu) !== -1) khop.push(b); });

    var hien = moRong ? khop.length : Math.min(gioiHan, khop.length);
    mucMon.forEach(function (b) { b.parentNode.hidden = true; });
    khop.forEach(function (b, i) { b.parentNode.hidden = i >= hien; });

    rong.hidden = khop.length !== 0;
    var con = khop.length - hien;
    them.hidden = con <= 0;
    if (con > 0) them.textContent = 'Xem thêm (' + con + ' môn)';
  }

  function mo() {
    hop.hidden = false;
    nut.setAttribute('aria-expanded', 'true');
    moRong = false;
    oTim.value = '';
    ve();
    oTim.focus();
  }
  function dong() {
    hop.hidden = true;
    nut.setAttribute('aria-expanded', 'false');
  }
  function chon(b) {
    an.value = b.getAttribute('data-v');
    form.submit();
  }

  nut.addEventListener('click', function () { hop.hidden ? mo() : dong(); });
  oTim.addEventListener('input', function () { moRong = false; ve(); });
  oTim.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {              // Enter chọn môn đầu tiên đang hiện, không gửi form tìm
      e.preventDefault();
      var dau = mucMon.filter(function (b) { return !b.parentNode.hidden; })[0];
      if (dau) chon(dau);
    } else if (e.key === 'Escape') {
      dong(); nut.focus();
    }
  });
  them.addEventListener('click', function () { moRong = true; ve(); oTim.focus(); });
  muc.forEach(function (b) { b.addEventListener('click', function () { chon(b); }); });

  document.addEventListener('click', function (e) { if (!goc.contains(e.target)) dong(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !hop.hidden) dong(); });

  ve();
})();