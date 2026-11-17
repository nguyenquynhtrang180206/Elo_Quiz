// Hộp thoại "Xuất báo cáo" của trang Trận đấu (admin/trandau.php): gọi admin/trandau_xuat.php, tải file về.
// Biểu đồ nhịp trận được chụp từ chính SVG đang hiển thị (đúng khoảng thời gian và số liệu đang xem), gửi kèm dạng JPEG nền trắng.
(function () {
  'use strict';
  var nut = document.getElementById('nutXuatTran');
  var hop = document.getElementById('hopXuatTran');
  var form = document.getElementById('formXuatTran');
  if (!nut || !hop || !form) { return; }
  var loi = document.getElementById('txLoi');
  var ok = document.getElementById('txOk');
  var nhanOk = ok.textContent;

  function bao(m) { loi.textContent = m; loi.hidden = !m; }
  function radio(ten) { var r = form.querySelector('input[name="' + ten + '"]:checked'); return r ? r.value : ''; }
  var xem = document.getElementById('txXem');
  var hen = null, soLanTai = 0;
  function mo() { bao(''); hop.hidden = false; xemTruoc(); }
  function dong() { hop.hidden = true; }

  nut.addEventListener('click', mo);
  document.getElementById('txHuy').addEventListener('click', dong);
  hop.addEventListener('click', function (e) { if (e.target === hop) { dong(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !hop.hidden) { dong(); } });

  // ---------- Xem trước: số liệu + vài dòng đầu của danh sách, biểu đồ lấy từ SVG đang hiển thị ----------
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function so(n) { return Number(n).toLocaleString('vi-VN'); }
  function bangXem(cot, dong) {
    var cuon = el('div', 'tx-bang-cuon'), b = el('table', 'tx-bang'), tr = el('tr');
    cot.forEach(function (c) { tr.appendChild(el('th', '', c)); });
    var th = el('thead'); th.appendChild(tr); b.appendChild(th);
    var tb = el('tbody');
    dong.forEach(function (r) {
      var t = el('tr');
      r.forEach(function (o) { var td = el('td', '', o); td.title = o; t.appendChild(td); });
      tb.appendChild(t);
    });
    b.appendChild(tb); cuon.appendChild(b);
    return cuon;
  }
  function veXem(j) {
    xem.textContent = '';
    var coNd = form.querySelectorAll('input[name="noidung[]"]:checked').length;
    if (!coNd) { xem.appendChild(el('p', 'tx-ghichu', 'Chưa chọn nội dung nào để xuất.')); return; }
    var dau = el('div', 'tx-the');
    dau.appendChild(el('h4', '', j.mota));
    dau.appendChild(el('p', 'tx-ghichu', so(j.tong) + ' trận trong phạm vi này.'));
    xem.appendChild(dau);

    if (j.tq) {
      var t = el('div', 'tx-the');
      t.appendChild(el('h4', '', 'Tổng quan'));
      var g = el('div', 'tx-so');
      [['Tổng số trận', so(j.tq.tong)], ['Trận hôm nay', so(j.tq.hom_nay)], ['Thời lượng TB', j.tq.tb], ['Trận bất thường', so(j.tq.bat_thuong)]].forEach(function (c) {
        var d = el('div'); d.appendChild(el('b', '', c[1])); d.appendChild(el('span', '', c[0])); g.appendChild(d);
      });
      t.appendChild(g);
      var tt = el('div', 'tx-tt');
      Object.keys(j.tq.tt).forEach(function (k) {
        var s = el('span', '', k + ': '); s.appendChild(el('b', '', so(j.tq.tt[k]))); tt.appendChild(s);
      });
      t.appendChild(tt);
      xem.appendChild(t);
    }
    if (form.querySelector('input[name="noidung[]"][value="bieudo"]:checked')) {
      var svg = document.querySelector('#tdBdVe svg');
      var b = el('div', 'tx-the');
      b.appendChild(el('h4', '', 'Biểu đồ nhịp trận')).appendChild(el('small', '', nhanBieuDo()));
      if (svg) {
        var ban = svg.cloneNode(true);
        ban.removeAttribute('width'); ban.removeAttribute('height');
        var kb = el('div', 'tx-bd'); kb.appendChild(ban); b.appendChild(kb);
      } else { b.appendChild(el('p', 'tx-ghichu', 'Biểu đồ chưa tải xong.')); }
      xem.appendChild(b);
    }
    [['ds', 'Danh sách trận', j.ds, j.tong], ['bt', 'Trận bất thường', j.bt, j.tongBt]].forEach(function (m) {
      if (!m[2]) { return; }
      var t = el('div', 'tx-the');
      t.appendChild(el('h4', '', m[1])).appendChild(el('small', '', so(m[3]) + ' trận'));
      if (!j.cot.length) { t.appendChild(el('p', 'tx-ghichu', 'Chưa chọn cột nào.')); }
      else if (!m[2].length) { t.appendChild(el('p', 'tx-ghichu', 'Không có trận nào.')); }
      else {
        t.appendChild(bangXem(j.cot, m[2]));
        if (m[3] > m[2].length) { t.appendChild(el('p', 'tx-them', 'Hiển thị ' + m[2].length + ' dòng đầu, file xuất sẽ có đủ ' + so(m[3]) + ' trận.')); }
      }
      xem.appendChild(t);
    });
  }
  function xemTruoc() {
    var lan = ++soLanTai;
    var fd = new FormData(form);
    fd.set('buoc', 'xemtruoc');
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { return r.text().then(function (t) { throw new Error(t || 'Không tải được xem trước.'); }); } return r.json(); })
      .then(function (j) { if (lan === soLanTai && !hop.hidden) { veXem(j); } })
      .catch(function (err) { if (lan === soLanTai) { xem.textContent = ''; xem.appendChild(el('p', 'tx-ghichu', err.message)); } });
  }
  form.addEventListener('change', function () { clearTimeout(hen); hen = setTimeout(xemTruoc, 250); });

  // Chụp SVG biểu đồ thành JPEG nền trắng (gấp đôi độ phân giải cho nét)
  function chupBieuDo() {
    return new Promise(function (xong) {
      var svg = document.querySelector('#tdBdVe svg');
      if (!svg) { xong(''); return; }
      var w = svg.clientWidth || svg.viewBox.baseVal.width, h = svg.clientHeight || svg.viewBox.baseVal.height;
      var goc = svg.cloneNode(true);
      goc.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
      goc.setAttribute('width', w); goc.setAttribute('height', h);
      goc.setAttribute('font-family', 'Arial, Helvetica, sans-serif');
      var url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(goc)], { type: 'image/svg+xml;charset=utf-8' }));
      var img = new Image();
      img.onload = function () {
        var c = document.createElement('canvas');
        c.width = w * 2; c.height = h * 2;
        var g = c.getContext('2d');
        g.fillStyle = '#ffffff'; g.fillRect(0, 0, c.width, c.height);
        g.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        xong(c.toDataURL('image/jpeg', 0.95));
      };
      img.onerror = function () { URL.revokeObjectURL(url); xong(''); };
      img.src = url;
    });
  }
  function nhanBieuDo() {
    var a = document.getElementById('tdBdNhan');
    var k = document.querySelector('#tdBd [data-khoang].on');
    return (a ? a.textContent : '') + (k ? ' · ' + k.textContent : '');
  }

  function taoFile(fd, dinhDang) {
    fd.set('dinhdang', dinhDang);
    return fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) { return r.text().then(function (t) { throw new Error(t || 'Không xuất được file.'); }); }
      var m = /filename="?([^";]+)"?/.exec(r.headers.get('Content-Disposition') || '');
      return r.blob().then(function (b) { return { blob: b, ten: m ? m[1] : 'trandau' }; });
    });
  }
  function luuFile(kq) {
    var url = URL.createObjectURL(kq.blob);
    var a = document.createElement('a');
    a.href = url; a.download = kq.ten;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    bao('');
    if (!form.querySelector('input[name="noidung[]"]:checked')) { bao('Hãy chọn ít nhất một nội dung cần xuất.'); return; }
    var dungDs = form.querySelector('input[name="noidung[]"][value="ds"]:checked') || form.querySelector('input[name="noidung[]"][value="batthuong"]:checked');
    if (dungDs && !form.querySelector('input[name="truong[]"]:checked')) { bao('Hãy chọn ít nhất một cột cho danh sách trận.'); return; }

    ok.disabled = true; ok.textContent = 'Đang chuẩn bị...';
    var layAnh = form.querySelector('input[name="noidung[]"][value="bieudo"]:checked') ? chupBieuDo() : Promise.resolve('');
    layAnh.then(function (anh) {
      var fd = new FormData(form);
      if (anh) { fd.append('anh_bieudo', anh); fd.append('nhan_bieudo', nhanBieuDo()); }
      // "Cả hai" = tải liên tiếp hai file riêng (Excel rồi PDF), không nén zip
      var dds = radio('dinhdang') === 'ca' ? ['excel', 'pdf'] : [radio('dinhdang')];
      var daTai = 0, chuoi = Promise.resolve();
      dds.forEach(function (dd) {
        chuoi = chuoi.then(function () {
          ok.textContent = dds.length > 1 ? 'Đang xuất ' + dd.toUpperCase() + '...' : 'Đang xuất...';
          return taoFile(fd, dd).then(function (kq) {
            luuFile(kq); daTai++;
            return dds.length > 1 && daTai < dds.length ? new Promise(function (x) { setTimeout(x, 700); }) : null;
          }).catch(function (err) {
            throw new Error(daTai ? 'Đã tải ' + daTai + ' file nhưng file ' + dd.toUpperCase() + ' bị lỗi: ' + err.message : err.message);
          });
        });
      });
      return chuoi.then(function () {
        dong();
        if (window.CosmicUI) { window.CosmicUI.toast(dds.length > 1 ? 'Đã xuất báo cáo (Excel và PDF).' : 'Đã xuất báo cáo.', 'thanhcong'); }
      });
    }).catch(function (err) { bao(err.message); })
      .then(function () { ok.disabled = false; ok.textContent = nhanOk; });
  });
})();