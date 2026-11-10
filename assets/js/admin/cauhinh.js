// Trang Cấu hình hệ thống: đánh dấu hàng đã đổi, kiểm tra khoảng giá trị ngay khi gõ, hoàn tác, cảnh báo khi rời trang.
// Máy chủ (admin/cauhinh.php) vẫn là nơi kiểm tra cuối cùng; file này chỉ giúp người dùng thấy lỗi sớm.
// Đặt tại: assets/js/admin/cauhinh.js
(function () {
  'use strict';
  var form = document.getElementById('chForm');
  if (!form) { return; }

  var oNhap = Array.prototype.slice.call(form.querySelectorAll('input.ch-o'));
  var nhanTT = document.querySelectorAll('.js-trangthai');
  var nutHoanTac = document.getElementById('chHoanTac');
  var mucMenu = Array.prototype.slice.call(form.querySelectorAll('.ch-menu-muc'));
  var daGui = false;

  // Trả về câu lỗi ngắn, hoặc chuỗi rỗng nếu hợp lệ (cùng quy tắc với PHP).
  function kiemTra(o) {
    var v = o.value.trim();
    var min = parseInt(o.getAttribute('data-min'), 10);
    var max = parseInt(o.getAttribute('data-max'), 10);
    var dv = o.getAttribute('data-donvi') || '';
    if (v === '' || !/^\d+$/.test(v) || v.length > 6) { return 'Phải là số nguyên không âm.'; }
    var n = parseInt(v, 10);
    if (n < min || n > max) { return 'Phải từ ' + min + ' đến ' + max + (dv ? ' ' + dv : '') + '.'; }
    return '';
  }

  function capNhatHang(o) {
    var hang = o.closest('.ch-hang');
    var ghi = hang.querySelector('.ch-ghi');
    var goc = o.getAttribute('data-goc');
    var moi = o.value.trim();
    var loi = kiemTra(o);
    var doi = moi !== goc;
    hang.classList.toggle('co-loi', loi !== '');
    hang.classList.toggle('da-doi', doi && loi === '');
    hang.setAttribute('data-doi', doi ? '1' : '0');
    hang.setAttribute('data-loi', loi !== '' ? '1' : '0');
    o.setAttribute('aria-invalid', loi !== '' ? 'true' : 'false');
    if (loi !== '') {
      ghi.textContent = loi;
      ghi.className = 'ch-ghi loi-ghi';
    } else if (doi) {
      ghi.textContent = 'Cũ: ' + goc + ' → Mới: ' + moi;
      ghi.className = 'ch-ghi doi-ghi';
    } else {
      ghi.textContent = '';
      ghi.className = 'ch-ghi';
    }
  }

  function dem() {
    var soDoi = 0, soLoi = 0;
    form.querySelectorAll('.ch-hang').forEach(function (h) {
      if (h.getAttribute('data-doi') === '1') { soDoi++; }
      if (h.getAttribute('data-loi') === '1') { soLoi++; }
    });
    return { doi: soDoi, loi: soLoi };
  }

  function capNhatTongHop() {
    var c = dem();
    var chu = 'Chưa có thay đổi';
    if (c.doi > 0 || c.loi > 0) {
      var phan = [];
      if (c.doi > 0) { phan.push('Có ' + c.doi + ' tham số đã sửa chưa lưu'); }
      if (c.loi > 0) { phan.push((c.doi > 0 ? '' : 'Có ') + c.loi + ' giá trị không hợp lệ'); }
      chu = phan.join(', ');
    }
    nhanTT.forEach(function (el) {
      el.textContent = chu;
      el.classList.toggle('co-doi', c.doi > 0);
      el.classList.toggle('co-loi', c.loi > 0);
    });
    // Chấm báo ở menu nhóm: vàng nếu nhóm có chỗ đã sửa, đỏ nếu có chỗ không hợp lệ.
    mucMenu.forEach(function (m) {
      var the = document.getElementById('nhom-' + m.getAttribute('data-nhom'));
      if (!the) { return; }
      m.classList.toggle('co-doi', the.querySelector('.ch-hang[data-doi="1"]') !== null);
      m.classList.toggle('co-loi', the.querySelector('.ch-hang[data-loi="1"]') !== null);
    });
  }

  oNhap.forEach(function (o) {
    o.addEventListener('input', function () { capNhatHang(o); capNhatTongHop(); });
    capNhatHang(o);   // trạng thái ban đầu (kể cả khi trình duyệt khôi phục giá trị cũ hoặc máy chủ trả lại giá trị sai)
  });
  capNhatTongHop();

  if (nutHoanTac) {
    nutHoanTac.addEventListener('click', function () {
      oNhap.forEach(function (o) {
        o.value = o.getAttribute('data-goc');
        capNhatHang(o);
      });
      capNhatTongHop();
    });
  }

  // Cảnh báo khi rời trang mà còn thay đổi chưa lưu (không cảnh báo khi đang bấm Lưu).
  form.addEventListener('submit', function () { daGui = true; });
  window.addEventListener('beforeunload', function (ev) {
    if (!daGui && dem().doi > 0) {
      ev.preventDefault();
      ev.returnValue = '';
    }
  });

  // Khung ý nghĩa của biểu tượng "i": bấm để mở/đóng (cho màn hình cảm ứng), bấm ra ngoài hoặc Esc để đóng.
  var nutI = Array.prototype.slice.call(form.querySelectorAll('.ch-i'));
  function dongHet(except) {
    nutI.forEach(function (b) { if (b !== except) { b.classList.remove('mo'); } });
  }
  nutI.forEach(function (b) {
    b.addEventListener('click', function (ev) {
      ev.stopPropagation();
      dongHet(b);
      b.classList.toggle('mo');
    });
  });
  document.addEventListener('click', function () { dongHet(null); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { dongHet(null); } });

  // Menu nhóm: tô nhóm đang xem khi cuộn, và tô ngay khi bấm.
  function chonMuc(ma) {
    mucMenu.forEach(function (m) { m.classList.toggle('dang-chon', m.getAttribute('data-nhom') === ma); });
  }
  mucMenu.forEach(function (m) {
    m.addEventListener('click', function () { chonMuc(m.getAttribute('data-nhom')); });
  });
  var cacThe = Array.prototype.slice.call(form.querySelectorAll('.ch-the'));
  if ('IntersectionObserver' in window && cacThe.length) {
    var quanSat = new IntersectionObserver(function (ds) {
      ds.forEach(function (d) { if (d.isIntersecting) { chonMuc(d.target.getAttribute('data-nhom')); } });
    }, { rootMargin: '-20% 0px -60% 0px' });
    cacThe.forEach(function (t) { quanSat.observe(t); });
  }
  if (cacThe.length) { chonMuc(cacThe[0].getAttribute('data-nhom')); }
})();