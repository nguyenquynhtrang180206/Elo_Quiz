// Biểu đồ nhịp số trận đã đấu bằng SVG thuần (admin/trandau.php), tự làm mới mỗi 30 giây.
(function () {
  'use strict';

  var goc = document.getElementById('tdBd');
  var ve = document.getElementById('tdBdVe');
  if (!goc || !ve) { return; }

  var NS = 'http://www.w3.org/2000/svg';
  var XANH = '#2563eb', DO = '#e5484d', LUOI = '#e6ecf8', CHU = '#8a97b5';
  var NHAN_TONG = { '1h': 'Tổng 1 giờ', '3h': 'Tổng 3 giờ', '1d': 'Tổng 1 ngày', '1w': 'Tổng 1 tuần', '1m': 'Tổng 1 tháng', d7: 'Tổng 7 ngày', d30: 'Tổng 30 ngày', d90: 'Tổng 90 ngày', tuychon: 'Tổng khoảng chọn' };
  var NHAN_TANG = { '1h': 'Thêm trong 1 giờ', '3h': 'Thêm trong 3 giờ', '1d': 'Thêm trong 1 ngày', '1w': 'Thêm trong 1 tuần', '1m': 'Thêm trong 1 tháng', d7: 'Thêm trong 7 ngày', d30: 'Thêm trong 30 ngày', d90: 'Thêm trong 90 ngày', tuychon: 'Thêm trong khoảng chọn' };
  var NHAN_TIEUDE = { batdau: 'TRẬN BẮT ĐẦU MỖI ', dadau: 'TRẬN ĐÃ ĐẤU XONG MỖI ' };
  var NHAN_HOP = { batdau: 'Trận bắt đầu: ', dadau: 'Trận đã đấu: ', tichluy: 'Tổng đã đấu: ' };
  var NHAN_KY = { '5 phút': '5 phút', '30 phút': '30 phút', '3 giờ': '3 giờ' };

  var api = goc.getAttribute('data-api');
  var khoang = '3h', cheDo = 'tichluy', kieu = 'nen', du = null, dangTai = false;

  function $(id) { return document.getElementById(id); }
  function so(n, tp) { return Number(n).toLocaleString('vi-VN', { maximumFractionDigits: tp || 0 }); }
  function el(ten, thuocTinh, cha) {
    var e = document.createElementNS(NS, ten);
    for (var k in thuocTinh) { e.setAttribute(k, thuocTinh[k]); }
    if (cha) { cha.appendChild(e); }
    return e;
  }
  function chu(cha, x, y, noiDung, thuocTinh) {
    thuocTinh = thuocTinh || {};
    thuocTinh.x = x; thuocTinh.y = y;
    var t = el('text', thuocTinh, cha);
    t.textContent = noiDung;
    return t;
  }

  function tai() {
    if (dangTai) { return; }
    dangTai = true;
    fetch(api + '?khoang=' + encodeURIComponent(khoang) + '&che_do=' + encodeURIComponent(cheDo) + (khoang === 'tuychon' ? '&tu=' + encodeURIComponent($('tdBdTu').value) + '&den=' + encodeURIComponent($('tdBdDen').value) : ''), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.ok) { return r.json(); }
        return r.json().catch(function () { return {}; }).then(function (j) { var e = new Error(j.loi || 'http'); e.tuServer = !!j.loi; throw e; });
      })
      .then(function (d) { du = d; capNhatSo(); veBieuDo(); })
      .catch(function (err) {
        if (err && err.tuServer) { ve.textContent = err.message; ve.className = 'td-bd-ve td-bd-loi'; return; }
        if (!du) { ve.textContent = 'Không tải được dữ liệu biểu đồ.'; ve.className = 'td-bd-ve td-bd-loi'; }
      })
      .then(function () { dangTai = false; });
  }

  function capNhatSo() {
    var tk = du.tk;
    $('tdBdNhan').textContent = du.che_do === 'tichluy' ? 'TỔNG SỐ TRẬN ĐÃ ĐẤU' : NHAN_TIEUDE[du.che_do] + (NHAN_KY[du.don_vi] || du.don_vi).toUpperCase();
    $('tdBdHienTai').textContent = so(tk.hien_tai);
    var dl = $('tdBdDelta');
    dl.textContent = '';
    if (tk.truoc !== null) {
      var chenh = tk.hien_tai - tk.truoc;
      var mui = chenh > 0 ? '▲ +' : (chenh < 0 ? '▼ −' : '■ ');
      var b = document.createElement('i');
      b.className = chenh > 0 ? 'tang' : (chenh < 0 ? 'giam' : '');
      b.textContent = mui + so(Math.abs(chenh));
      dl.appendChild(b);
      dl.appendChild(document.createTextNode(' so với ' + du.don_vi + ' trước'));
    }
    $('tdTkCao').textContent = so(tk.cao);
    $('tdTkThap').textContent = so(tk.thap);
    $('tdTkTongNhan').textContent = (du.che_do === 'tichluy' ? NHAN_TANG : NHAN_TONG)[du.khoang] || 'Tổng';
    $('tdTkTong').textContent = so(tk.tong) + ' trận';
    $('tdTkBt').textContent = so(tk.bat_thuong);
    $('tdTkTl').textContent = tk.thoi_luong;
  }

  // Chọn bước chia trục dọc sao cho có 3 khoảng (0, b, 2b, 3b) và bao được giá trị lớn nhất
  function buocTruc(max) {
    var ds = [1, 2, 4, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000];
    for (var i = 0; i < ds.length; i++) { if (ds[i] * 3 >= max) { return ds[i]; } }
    return Math.ceil(max / 3);
  }

  function veBieuDo() {
    ve.className = 'td-bd-ve';
    ve.textContent = '';
    var diem = du.diem, n = diem.length;
    if (n < 2) { ve.textContent = 'Chưa đủ dữ liệu để vẽ biểu đồ.'; ve.className = 'td-bd-ve td-bd-loi'; return; }

    var W = Math.max(ve.clientWidth, 320), H = 292;
    var tr = 14, ph = 46, tren = 14;           // lề trái, lề phải (chứa nhãn trục + nhãn giá trị hiện tại), lề trên
    var yDay = 190;                              // đường y = 0
    var xLan = 222, xCot = 252;                  // dải "trận bất thường": nhãn và đáy cột
    var rong = W - tr - ph;

    var max = 0, thap = Infinity, i;
    for (i = 0; i < n; i++) { if (diem[i].n > max) { max = diem[i].n; } if (diem[i].n < thap) { thap = diem[i].n; } }
    var tichLuy = du.che_do === 'tichluy';
    var g0 = tichLuy ? thap : 0;                 // chế độ tích lũy: trục dọc bắt đầu từ giá trị thấp nhất để thấy được độ tăng
    var buoc = buocTruc(Math.max(max - g0, 3));
    var yMax = buoc * 3;
    function X(k) { return tr + rong * k / (n - 1); }
    function Y(v) { return yDay - (yDay - tren) * (v - g0) / yMax; }

    var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Biểu đồ số trận theo thời gian' }, ve);
    var defs = el('defs', {}, svg);
    var gd = el('linearGradient', { id: 'tdGrad', x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
    el('stop', { offset: '0%', 'stop-color': XANH, 'stop-opacity': kieu === 'nen' ? 0.55 : 0.22 }, gd);
    el('stop', { offset: '100%', 'stop-color': XANH, 'stop-opacity': kieu === 'nen' ? 0.12 : 0.02 }, gd);

    // Lưới ngang + nhãn trục dọc (bên phải)
    for (i = 0; i <= 3; i++) {
      var yy = Y(g0 + buoc * i);
      el('line', { x1: tr, x2: W - ph, y1: yy, y2: yy, stroke: LUOI, 'stroke-width': 1 }, svg);
      chu(svg, W - ph + 12, yy + 4, so(g0 + buoc * i), { fill: CHU, 'font-size': 11 });
    }

    // Nhãn trục ngang: thưa dần theo bề rộng (mỗi nhãn cần khoảng 56px)
    var cach = Math.max(1, Math.ceil(56 / (rong / (n - 1))));
    for (i = n - 1; i >= 0; i -= cach) {
      el('line', { x1: X(i), x2: X(i), y1: tren, y2: yDay, stroke: LUOI, 'stroke-width': 1, 'stroke-dasharray': '2 4' }, svg);
      chu(svg, X(i), H - 8, diem[i].nhan, { fill: CHU, 'font-size': 11, 'text-anchor': i === 0 ? 'start' : 'middle' });
    }

    // Vùng tô + đường
    var d = '';
    for (i = 0; i < n; i++) { d += (i ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(diem[i].n).toFixed(1); }
    el('path', { d: d + 'L' + X(n - 1).toFixed(1) + ' ' + yDay + 'L' + X(0).toFixed(1) + ' ' + yDay + 'Z', fill: 'url(#tdGrad)' }, svg);
    el('path', { d: d, fill: 'none', stroke: XANH, 'stroke-width': kieu === 'nen' ? 1.5 : 2.4, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);

    // Đường trung bình (nét đứt) + nhãn
    if (!tichLuy) {
      var yTb = Y(du.tk.tb_moc);
      el('line', { x1: tr, x2: W - ph, y1: yTb, y2: yTb, stroke: '#9aa7c7', 'stroke-width': 1, 'stroke-dasharray': '4 3' }, svg);
      chu(svg, tr + 6, yTb - 5, 'Trung bình ' + so(du.tk.tb_moc, 1) + ' trận / ' + du.don_vi, { fill: '#6b7694', 'font-size': 10.5 });
    }

    // Chấm đỏ rỗng trên đường tại các mốc có trận bất thường
    for (i = 0; i < n; i++) {
      if (diem[i].bt > 0) { el('circle', { cx: X(i), cy: Y(diem[i].n), r: 3.6, fill: '#fff', stroke: DO, 'stroke-width': 2 }, svg); }
    }

    // Nhãn giá trị hiện tại ở mép phải
    var yh = Y(diem[n - 1].n);
    el('rect', { x: W - ph + 2, y: yh - 10, width: 34, height: 20, rx: 6, fill: XANH }, svg);
    chu(svg, W - ph + 19, yh + 4, so(diem[n - 1].n), { fill: '#fff', 'font-size': 12, 'font-weight': 700, 'text-anchor': 'middle' });

    // Dải "TRẬN BẤT THƯỜNG": đường đứt đáy + cột đỏ
    chu(svg, tr, xLan, 'TRẬN BẤT THƯỜNG', { fill: CHU, 'font-size': 10, 'font-weight': 700, 'letter-spacing': '.04em' });
    el('line', { x1: tr, x2: W - ph, y1: xCot + 1, y2: xCot + 1, stroke: '#d6dcf3', 'stroke-width': 3, 'stroke-dasharray': '7 5' }, svg);
    var maxBt = 1;
    for (i = 0; i < n; i++) { if (diem[i].bt > maxBt) { maxBt = diem[i].bt; } }
    var rongCot = Math.max(4, Math.min(12, rong / n * 0.7));
    for (i = 0; i < n; i++) {
      if (diem[i].bt > 0) {
        var cao = 14 + 14 * diem[i].bt / maxBt;
        el('rect', { x: X(i) - rongCot / 2, y: xCot - cao, width: rongCot, height: cao, rx: 2, fill: DO }, svg);
      }
    }

    // Lớp rê chuột: đường dọc + chấm + cờ + hộp thông tin + nhãn giờ
    var lop = el('g', { visibility: 'hidden', 'pointer-events': 'none' }, svg);
    var duongDoc = el('line', { y1: tren, y2: xCot + 2, stroke: '#64748b', 'stroke-width': 1, 'stroke-dasharray': '3 3' }, lop);
    var cham = el('circle', { r: 4.5, fill: '#fff', stroke: XANH, 'stroke-width': 2.4 }, lop);
    var gio = el('g', {}, lop);
    var gioNen = el('rect', { height: 20, rx: 5, fill: '#0f172a' }, gio);
    var gioChu = chu(gio, 0, 0, '', { fill: '#fff', 'font-size': 11, 'font-weight': 700, 'text-anchor': 'middle' });
    var co = el('g', {}, lop);
    var coNen = el('rect', { height: 20, rx: 4, fill: DO }, co);
    var coChu = chu(co, 0, 0, '', { fill: '#fff', 'font-size': 11, 'font-weight': 700 });
    var hop = el('g', {}, lop);
    var hopNen = el('rect', { rx: 8, fill: '#0f2557' }, hop);
    var h1 = chu(hop, 0, 0, '', { fill: '#b7c6ee', 'font-size': 10.5 });
    var h2 = chu(hop, 0, 0, '', { fill: '#fff', 'font-size': 12, 'font-weight': 700 });
    var h3 = chu(hop, 0, 0, '', { fill: '#fff', 'font-size': 12, 'font-weight': 700 });

    var bat = el('rect', { x: tr, y: 0, width: rong, height: H - 24, fill: 'transparent' }, svg);
    function dat(k) {
      var p = diem[k], x = X(k), y = Y(p.n);
      lop.setAttribute('visibility', 'visible');
      duongDoc.setAttribute('x1', x); duongDoc.setAttribute('x2', x);
      cham.setAttribute('cx', x); cham.setAttribute('cy', y);
      cham.setAttribute('stroke', p.bt > 0 ? DO : XANH);

      gioChu.textContent = p.nhan;
      var wg = Math.max(46, p.nhan.length * 7.2 + 14);
      gioNen.setAttribute('width', wg); gioNen.setAttribute('x', Math.min(Math.max(x - wg / 2, 0), W - wg));
      gioNen.setAttribute('y', H - 22);
      gioChu.setAttribute('x', Math.min(Math.max(x, wg / 2), W - wg / 2)); gioChu.setAttribute('y', H - 8);

      h1.textContent = p.day;
      h2.textContent = (NHAN_HOP[du.che_do] || 'Số trận: ') + so(p.n);
      h3.textContent = 'Bất thường: ' + so(p.bt);
      var wh = 128, hh = 62;
      var hx = x + 14 + wh > W - 4 ? x - 14 - wh : x + 14;
      var hy = Math.min(Math.max(y - hh - 10, tren), yDay - hh - 4);
      if (y - hh - 10 < tren) { hy = Math.min(y + 14, yDay - hh - 4); }
      hopNen.setAttribute('x', hx); hopNen.setAttribute('y', hy); hopNen.setAttribute('width', wh); hopNen.setAttribute('height', hh);
      h1.setAttribute('x', hx + 10); h1.setAttribute('y', hy + 16);
      h2.setAttribute('x', hx + 10); h2.setAttribute('y', hy + 34);
      h3.setAttribute('x', hx + 10); h3.setAttribute('y', hy + 51);
      h3.setAttribute('fill', p.bt > 0 ? '#ff9aa0' : '#fff');

      if (p.bt > 0 && p.ma) {
        coChu.textContent = '⚑ #' + p.ma + ' ' + (p.lydo || 'Bất thường');
        var wc = coChu.getComputedTextLength ? coChu.getComputedTextLength() + 16 : 150;
        var cx = Math.min(Math.max(x - wc / 2, 2), W - wc - 2);
        coNen.setAttribute('x', cx); coNen.setAttribute('y', 2); coNen.setAttribute('width', wc);
        coChu.setAttribute('x', cx + 8); coChu.setAttribute('y', 16);
        co.setAttribute('visibility', 'visible');
      } else {
        co.setAttribute('visibility', 'hidden');
      }
    }
    function theoChuot(e) {
      var r = svg.getBoundingClientRect();
      var k = Math.round((e.clientX - r.left - tr) / (rong / (n - 1)));
      dat(Math.min(Math.max(k, 0), n - 1));
    }
    bat.addEventListener('pointermove', theoChuot);
    bat.addEventListener('pointerdown', theoChuot);
    bat.addEventListener('pointerleave', function () { lop.setAttribute('visibility', 'hidden'); });
  }

  goc.addEventListener('click', function (e) {
    var nut = e.target.closest ? e.target.closest('button') : null;
    if (!nut || !goc.contains(nut)) { return; }
    if (nut.hasAttribute('data-khoang')) {
      khoang = nut.getAttribute('data-khoang');
      var tatCa = goc.querySelectorAll('[data-khoang]');
      for (var q = 0; q < tatCa.length; q++) { tatCa[q].classList.toggle('on', tatCa[q] === nut); }
      var hang = $('tdBdNgay');
      if (hang) { hang.hidden = khoang !== 'tuychon'; }
      if (khoang === 'tuychon') { if ($('tdBdTu').value && $('tdBdDen').value) { tai(); } else { ve.className = 'td-bd-ve td-bd-loi'; ve.textContent = 'Chọn "Từ ngày" và "Đến ngày" rồi bấm Xem.'; } }
      else { tai(); }
    } else if (nut.id === 'tdBdXem') {
      if (!$('tdBdTu').value || !$('tdBdDen').value) { ve.className = 'td-bd-ve td-bd-loi'; ve.textContent = 'Hãy chọn đủ "Từ ngày" và "Đến ngày".'; return; }
      tai();
    }
  });

  // Vẽ lại khi đổi kích thước (kể cả khi ẩn/hiện sidebar)
  var hen = null;
  window.addEventListener('resize', function () {
    clearTimeout(hen);
    hen = setTimeout(function () { if (du) { veBieuDo(); } }, 120);
  });
  if (window.ResizeObserver) {
    var cu = ve.clientWidth;
    new ResizeObserver(function () {
      if (du && Math.abs(ve.clientWidth - cu) > 8) { cu = ve.clientWidth; veBieuDo(); }
    }).observe(ve);
  }

  tai();
  setInterval(function () { if (!document.hidden) { tai(); } }, 30000);
})();