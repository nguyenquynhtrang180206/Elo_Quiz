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
    if (j.bd) {
      var b = el('div', 'tx-the');
      b.appendChild(el('h4', '', 'Trận bắt đầu mỗi ngày')).appendChild(el('small', '', so(j.bd.tk.tong) + ' trận · TB ' + so(j.bd.tk.tb) + '/ngày'));
      b.appendChild(el('p', 'tx-ghichu', j.bd.mota));
      var kb = el('div', 'tx-bd'); kb.appendChild(veCot(j.bd.ngay)); b.appendChild(kb);
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
  // Vẽ biểu đồ "trận bắt đầu mỗi ngày" lên canvas, cùng kiểu với biểu đồ trên trang Trận đấu:
  // đường xanh + vùng tô, đường trung bình nét đứt, chấm đỏ rỗng ở ngày có trận bất thường, dải "TRẬN BẤT THƯỜNG" bên dưới.
  // Nền trắng; dùng cho cả xem trước lẫn ảnh JPEG đưa vào file xuất.
  function veCot(ngay) {
    var W = 900, H = 340, tr = 16, ph = 58, tren = 18, yDay = 220, xLan = 258, xCot = 294;
    var XANH = '#2563eb', DO = '#e5484d', LUOI = '#e6ecf8', CHU = '#8a97b5';
    var c = document.createElement('canvas');
    c.width = W; c.height = H;
    var g = c.getContext('2d');
    g.fillStyle = '#fff'; g.fillRect(0, 0, W, H);
    var n = ngay.length, rong = W - tr - ph, i, tong = 0, max = 0, maxBt = 1;
    ngay.forEach(function (d) { tong += d.n; if (d.n > max) { max = d.n; } if (d.bt > maxBt) { maxBt = d.bt; } });
    var ds = [1, 2, 4, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000], buoc = Math.ceil(Math.max(max, 3) / 3);
    for (i = 0; i < ds.length; i++) { if (ds[i] * 3 >= Math.max(max, 3)) { buoc = ds[i]; break; } }
    var yMax = buoc * 3;
    function X(k) { return n > 1 ? tr + rong * k / (n - 1) : tr + rong / 2; }
    function Y(v) { return yDay - (yDay - tren) * v / yMax; }
    function chu(t, x, y, co, mau, can, dam) { g.font = (dam ? '700 ' : '') + co + 'px Arial, sans-serif'; g.fillStyle = mau; g.textAlign = can || 'left'; g.textBaseline = 'alphabetic'; g.fillText(t, x, y); }
    function hop(x, y, w, h, r) { g.beginPath(); g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r); g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath(); }

    // Lưới ngang + nhãn trục dọc (bên phải)
    for (i = 0; i <= 3; i++) {
      var yy = Y(buoc * i);
      g.strokeStyle = LUOI; g.lineWidth = 1; g.beginPath(); g.moveTo(tr, yy); g.lineTo(W - ph, yy); g.stroke();
      chu(String(buoc * i), W - ph + 12, yy + 4, 12, CHU);
    }
    // Nhãn trục ngang thưa dần theo bề rộng (mỗi nhãn cần khoảng 56px), đường dọc nét đứt
    var cach = n > 1 ? Math.max(1, Math.ceil(56 / (rong / (n - 1)))) : 1;
    g.setLineDash([2, 4]);
    for (i = n - 1; i >= 0; i -= cach) {
      g.strokeStyle = LUOI; g.beginPath(); g.moveTo(X(i), tren); g.lineTo(X(i), yDay); g.stroke();
      chu(ngay[i].nhan, X(i), H - 10, 12, CHU, i === 0 ? 'left' : 'center');
    }
    g.setLineDash([]);

    // Vùng tô + đường
    var gr = g.createLinearGradient(0, tren, 0, yDay);
    gr.addColorStop(0, 'rgba(37,99,235,.22)'); gr.addColorStop(1, 'rgba(37,99,235,.02)');
    g.beginPath(); g.moveTo(X(0), yDay);
    for (i = 0; i < n; i++) { g.lineTo(X(i), Y(ngay[i].n)); }
    g.lineTo(X(n - 1), yDay); g.closePath(); g.fillStyle = gr; g.fill();
    g.beginPath();
    for (i = 0; i < n; i++) { if (i) { g.lineTo(X(i), Y(ngay[i].n)); } else { g.moveTo(X(i), Y(ngay[i].n)); } }
    g.strokeStyle = XANH; g.lineWidth = 2.6; g.lineJoin = 'round'; g.lineCap = 'round'; g.stroke();

    // Đường trung bình (nét đứt) + nhãn
    var tb = n ? tong / n : 0, yTb = Y(tb);
    g.setLineDash([4, 3]); g.strokeStyle = '#9aa7c7'; g.lineWidth = 1;
    g.beginPath(); g.moveTo(tr, yTb); g.lineTo(W - ph, yTb); g.stroke(); g.setLineDash([]);
    chu('Trung bình ' + (Math.round(tb * 10) / 10).toLocaleString('vi-VN') + ' trận / ngày', tr + 6, yTb - 6, 11.5, '#6b7694');

    // Chấm đỏ rỗng trên đường tại các ngày có trận bất thường
    for (i = 0; i < n; i++) {
      if (ngay[i].bt > 0) { g.beginPath(); g.arc(X(i), Y(ngay[i].n), 4, 0, Math.PI * 2); g.fillStyle = '#fff'; g.fill(); g.strokeStyle = DO; g.lineWidth = 2.2; g.stroke(); }
    }
    // Nhãn giá trị ngày cuối ở mép phải
    var yh = Y(ngay[n - 1].n);
    hop(W - ph + 2, yh - 11, 38, 22, 6); g.fillStyle = XANH; g.fill();
    chu(String(ngay[n - 1].n), W - ph + 21, yh + 4.5, 13, '#fff', 'center', true);

    // Dải "TRẬN BẤT THƯỜNG": đường đứt đáy + cột đỏ
    chu('TRẬN BẤT THƯỜNG', tr, xLan, 11, CHU, 'left', true);
    g.setLineDash([7, 5]); g.strokeStyle = '#d6dcf3'; g.lineWidth = 3;
    g.beginPath(); g.moveTo(tr, xCot + 1); g.lineTo(W - ph, xCot + 1); g.stroke(); g.setLineDash([]);
    var rc = Math.max(4, Math.min(12, rong / n * 0.7));
    for (i = 0; i < n; i++) {
      if (ngay[i].bt > 0) { var cao = 14 + 14 * ngay[i].bt / maxBt; hop(X(i) - rc / 2, xCot - cao, rc, cao, 2); g.fillStyle = DO; g.fill(); }
    }
    return c;
  }

  function layXem() {
    var fd = new FormData(form);
    fd.set('buoc', 'xemtruoc');
    return fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { return r.text().then(function (t) { throw new Error(t || 'Không tải được xem trước.'); }); } return r.json(); });
  }
  function xemTruoc() {
    var lan = ++soLanTai;
    layXem()
      .then(function (j) { if (lan === soLanTai && !hop.hidden) { veXem(j); } })
      .catch(function (err) { if (lan === soLanTai) { xem.textContent = ''; xem.appendChild(el('p', 'tx-ghichu', err.message)); } });
  }
  form.addEventListener('input', function (e) {
    if (e.target.type === 'date') { var r = form.querySelector('input[name="bd_khoang"][value="tuychon"]'); if (r) { r.checked = true; } }
  });
  form.addEventListener('change', function () { clearTimeout(hen); hen = setTimeout(xemTruoc, 250); });

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
    // Ảnh biểu đồ: lấy số liệu theo ngày từ máy chủ rồi vẽ canvas, gửi kèm dạng JPEG
    var layAnh = form.querySelector('input[name="noidung[]"][value="bieudo"]:checked')
      ? layXem().then(function (j) { return j.bd ? veCot(j.bd.ngay).toDataURL('image/jpeg', 0.95) : ''; })
      : Promise.resolve('');
    layAnh.then(function (anh) {
      var fd = new FormData(form);
      if (anh) { fd.append('anh_bieudo', anh); }
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