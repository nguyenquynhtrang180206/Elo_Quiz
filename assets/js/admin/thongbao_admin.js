// Chuông thông báo admin (trang Tổng quan). Dữ liệu từ admin/thongbao_api.php
(function () {
  'use strict';
  var goc = document.getElementById('admTb');
  var nut = document.getElementById('admTbNut');
  var hop = document.getElementById('admTbHop');
  if (!goc || !nut || !hop) { return; }

  var api   = goc.getAttribute('data-api');
  var csrf  = goc.getAttribute('data-csrf');
  var badge = document.getElementById('admTbBadge');
  var tag   = document.getElementById('admTbTag');
  var ds    = document.getElementById('admTbDs');
  var nutTatCa = document.getElementById('admTbTatCa');
  var nutDocHet = document.getElementById('admTbDocHet');
  var dangMo = false;

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function capNhatSo(n) {
    n = parseInt(n, 10) || 0;
    badge.hidden = n <= 0;
    badge.textContent = n > 99 ? '99+' : n;
    tag.textContent = n + ' mới';
  }

  function post(duLieu) {
    var f = new FormData();
    f.append('csrf', csrf);
    Object.keys(duLieu).forEach(function (k) { f.append(k, duLieu[k]); });
    return fetch(api, { method: 'POST', body: f }).then(function (r) { return r.json(); });
  }

  function tai() {
    fetch(api + '?action=lay_danh_sach')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { loi(); return; }
        capNhatSo(d.soChuaDoc);
        var list = d.danhSach || [];
        if (!list.length) {
          ds.innerHTML = '<p class="adm-tb-trong">Chưa có thông báo nào.</p>';
        } else {
          ds.innerHTML = list.map(function (t) {
            return '<a class="adm-tb-muc ' + (t.da_doc ? 'da-doc' : 'chua-doc') + '" href="' + esc(t.lien_ket) + '"'
              + ' data-loai="' + t.loai + '" data-ma="' + t.ma + '">'
              + '<span class="adm-tb-ava">' + esc(t.chu_cai) + '</span>'
              + '<span class="adm-tb-nd"><span class="adm-tb-ten">' + esc(t.ten) + '</span> ' + esc(t.tieu_de)
              + '<span class="adm-tb-trich">' + esc(t.noi_dung) + '</span>'
              + '<span class="adm-tb-tg">' + esc(t.thoi_gian) + '</span></span>'
              + (t.da_doc ? '<span class="adm-tb-nhan">✓ Đã đọc</span>' : '<span class="adm-tb-cham" title="Chưa đọc"></span>')
              + '</a>';
          }).join('');
        }
        // Hộp chỉ hiện 5 thông báo gần nhất; nút dưới hộp mở trang xem toàn bộ
        nutTatCa.textContent = 'Tất cả thông báo (' + (d.tong || 0) + ')';
      })
      .catch(loi);
  }

  function loi() {
    ds.innerHTML = '<p class="adm-tb-trong">Không tải được thông báo (kiểm tra file admin/thongbao_api.php).</p>';
  }

  function dong() {
    dangMo = false;
    hop.hidden = true;
    nut.setAttribute('aria-expanded', 'false');
  }

  nut.addEventListener('click', function (e) {
    e.stopPropagation();
    if (dangMo) { dong(); return; }
    dangMo = true;
    hop.hidden = false;
    nut.setAttribute('aria-expanded', 'true');
    tai();
  });
  document.addEventListener('click', function (e) { if (dangMo && !goc.contains(e.target)) { dong(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { dong(); } });

  nutDocHet.addEventListener('click', function () {
    post({ action: 'doc_het' }).then(function (d) { if (d && d.ok) { tai(); } });
  });

  // Bấm một thông báo: gửi yêu cầu "đã đọc" (keepalive để không bị hủy khi chuyển trang), link vẫn chuyển trang bình thường
  ds.addEventListener('click', function (e) {
    var muc = e.target.closest('.adm-tb-muc');
    if (!muc || !muc.classList.contains('chua-doc')) { return; }
    var f = new FormData();
    f.append('csrf', csrf);
    f.append('action', 'doc_mot');
    f.append('loai', muc.getAttribute('data-loai'));
    f.append('ma', muc.getAttribute('data-ma'));
    try { fetch(api, { method: 'POST', body: f, keepalive: true }); } catch (err) { /* vẫn cho chuyển trang */ }
  });

  // Cập nhật số chưa đọc mỗi 30 giây
  setInterval(function () {
    fetch(api + '?action=dem').then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) { capNhatSo(d.soChuaDoc); if (dangMo) { tai(); } }
    }).catch(function () {});
  }, 30000);
})();