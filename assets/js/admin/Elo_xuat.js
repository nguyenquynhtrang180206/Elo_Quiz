// Hộp thoại "Xuất" của tab Bảng xếp hạng (admin/elo.php), cùng kiểu với hộp thoại Xuất của trang Người dùng.
// Cột trái: môn, số người, nội dung, cột, định dạng. Cột phải: xem trước. Số liệu xem trước lấy từ máy chủ (buoc=xemtruoc)
// một lần cho mỗi cặp (môn, top); đổi nội dung, cột hay định dạng chỉ vẽ lại, không gọi lại.
// Định dạng "Cả hai": tải liên tiếp hai file (Excel rồi PDF), không nén zip. Xử lý ở admin/elo_xuat.php.
(function () {
  'use strict';
  var nutMo = document.getElementById('exMoXuat');
  var hop = document.getElementById('exXuatHop');
  var form = document.getElementById('exXuatForm');
  if (!nutMo || !hop || !form) { return; }
  var xem = document.getElementById('exXem');
  var xemGhiChu = document.getElementById('exXemGhiChu');
  var loi = document.getElementById('exXuatLoi');
  var nutOk = document.getElementById('exXuatOk');
  var cotBox = document.getElementById('exCotBox');

  // Khớp với $COT trong elo_xuat.php
  var COT = [['hang', 'Hạng'], ['ten', 'Họ tên'], ['email', 'Email'], ['bac', 'Bậc'], ['elo', 'Elo'], ['sotran', 'Số trận'], ['tyle', 'Tỷ lệ thắng'], ['tt', 'Trạng thái']];
  var DONG_XEM = 10;

  var cache = { khoa: '', du: null };
  var luot = 0;          // chống kết quả cũ ghi đè kết quả mới khi bấm nhanh
  var bieuDoXem = [];    // các sơ đồ đang vẽ trong khung xem trước (hủy trước khi vẽ lại để không rò bộ nhớ)

  function bao(msg) { loi.textContent = msg; loi.hidden = !msg; }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function so(n) { return Number(n).toLocaleString('vi-VN'); }
  function gia(ten) { var f = form.elements[ten]; return f ? f.value : ''; }
  function radio(ten) { var r = form.querySelector('input[name="' + ten + '"]:checked'); return r ? r.value : ''; }
  function chonList(ten) {
    return Array.prototype.map.call(form.querySelectorAll('input[name="' + ten + '"]:checked'), function (i) { return i.value; });
  }
  function datTatCa(v) {
    form.querySelectorAll('input[name="truong[]"]').forEach(function (i) { i.checked = v; });
    capNhatXem();
  }
  function kiemTra() {
    var nd = chonList('noidung[]');
    if (!nd.length) { return 'Hãy chọn ít nhất một nội dung cần xuất.'; }
    if (nd.indexOf('ds') >= 0 && !chonList('truong[]').length) { return 'Hãy chọn ít nhất một cột cho bảng xếp hạng.'; }
    return '';
  }
  function capNhatCotBox() {
    var coDs = chonList('noidung[]').indexOf('ds') >= 0;
    cotBox.style.opacity = coDs ? '' : '.5';
    form.querySelectorAll('input[name="truong[]"], #exChonHet, #exBoChon').forEach(function (i) { i.disabled = !coDs; });
  }

  // ---------- Sơ đồ (dùng cho cả khung xem trước và ảnh chụp đưa vào file) ----------
  // tinh = true: ảnh chụp (kích thước cố định, không hiệu ứng, nét gấp đôi); false: xem trước co giãn theo khung
  function cauHinh(loai, d, tinh) {
    var chung = tinh ? { responsive: false, animation: false, devicePixelRatio: 2 }
                     : { responsive: true, maintainAspectRatio: false, animation: false };
    if (loai === 'pho') {
      return {
        type: 'bar',
        data: { labels: d.pho.nhan, datasets: [{ label: 'Số người', data: d.pho.so, backgroundColor: d.pho.mau, borderRadius: 4, maxBarThickness: 38 }] },
        options: Object.assign({}, chung, {
          plugins: { legend: { display: false }, title: { display: true, text: 'Phân bố điểm Elo · BXH ' + d.tenBxh } },
          scales: {
            x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 50, minRotation: 0 } },
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        })
      };
    }
    return {
      type: 'doughnut',
      data: {
        labels: d.bac.map(function (b) { return b.ten; }),
        datasets: [{ data: d.bac.map(function (b) { return b.so; }), backgroundColor: d.bac.map(function (b) { return b.mau; }), borderColor: '#ffffff', borderWidth: 2 }]
      },
      options: Object.assign({}, chung, {
        cutout: '62%',
        plugins: { legend: { display: true, position: 'bottom' }, title: { display: true, text: 'Phân bố theo bậc' } }
      })
    };
  }
  function huyBieuDoXem() {
    bieuDoXem.forEach(function (c) { c.destroy(); });
    bieuDoXem = [];
  }
  // Canvas của Chart.js trong suốt nên chụp lên nền trắng (lưu thẳng sang JPEG sẽ bị nền đen)
  function chup(c) {
    var t = document.createElement('canvas');
    t.width = c.width; t.height = c.height;
    var g = t.getContext('2d');
    g.fillStyle = '#ffffff';
    g.fillRect(0, 0, t.width, t.height);
    g.drawImage(c, 0, 0);
    return t.toDataURL('image/jpeg', 0.92);
  }
  // Vẽ lại các sơ đồ đã tích ở kích thước cố định ngoài màn hình rồi chụp thành JPEG: { anh_bac: dataURL, anh_pho: dataURL }
  function anhBieuDo(d) {
    var kq = {};
    var nd = chonList('noidung[]');
    if (typeof Chart === 'undefined') { return kq; }
    [['bac', 440, 300], ['pho', 900, 300]].forEach(function (b) {
      if (nd.indexOf(b[0]) < 0) { return; }
      var khung = el('div');
      khung.style.cssText = 'position:fixed;left:-10000px;top:0;pointer-events:none;';
      var cv = document.createElement('canvas');
      cv.width = b[1]; cv.height = b[2];
      khung.appendChild(cv);
      document.body.appendChild(khung);
      var ch = null;
      try {
        ch = new Chart(cv, cauHinh(b[0], d, true));
        kq['anh_' + b[0]] = chup(cv);
      } catch (e) { /* không chụp được thì file xuất không có sơ đồ này */ }
      if (ch) { ch.destroy(); }
      khung.remove();
    });
    return kq;
  }

  // ---------- Gọi máy chủ ----------
  function goi(fd) { return fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' }); }
  function docLoi(r) { return r.text().then(function (t) { throw new Error(t || 'Không xuất được file.'); }); }
  function khoaDuLieu() { return gia('mon') + '|' + radio('top'); }
  function layDuLieu() {
    var khoa = khoaDuLieu();
    if (cache.du && cache.khoa === khoa) { return Promise.resolve(cache.du); }
    var fd = new FormData(form);
    fd.set('buoc', 'xemtruoc');
    return goi(fd).then(function (r) {
      if (!r.ok) { return docLoi(r); }
      return r.json();
    }).then(function (d) {
      cache = { khoa: khoa, du: d };
      return d;
    });
  }

  // ---------- Khung xem trước ----------
  function oSo(nhan, giaTri) {
    var o = el('div', 'xuat-so-o');
    o.appendChild(el('strong', '', giaTri));
    o.appendChild(el('span', '', nhan));
    return o;
  }
  function veXem(d) {
    huyBieuDoXem();
    xem.textContent = '';
    xemGhiChu.textContent = '· BXH ' + d.tenBxh + ' · ' + so(d.tong) + ' người';
    if (!d.tong) { xem.appendChild(el('p', 'xuat-ghichu', 'Chưa có người chơi nào được xếp hạng trong ' + d.tenBxh + '.')); return; }
    var loiNhap = kiemTra();
    if (loiNhap) { xem.appendChild(el('p', 'xuat-ghichu', loiNhap)); return; }

    var tr = chonList('truong[]'), dd = radio('dinhdang');
    var nd = chonList('noidung[]');
    var coDs = nd.indexOf('ds') >= 0, coBac = nd.indexOf('bac') >= 0, coPho = nd.indexOf('pho') >= 0;
    if (dd === 'ca') { xem.appendChild(el('p', 'xuat-ghichu', 'Định dạng "Cả hai": tải về hai file, Excel rồi PDF.')); }

    var the = el('section', 'xuat-the');
    var dau = el('div', 'xuat-the-dau');
    var t = el('div', 'xuat-the-tieude');
    t.appendChild(el('b', '', coDs ? 'Bảng xếp hạng Elo' : 'Thống kê xếp hạng Elo'));
    t.appendChild(el('small', '', 'BXH ' + d.tenBxh + (d.soXuat < d.tong ? ' · Top ' + so(d.soXuat) : ' · toàn bộ')));
    dau.appendChild(t);
    dau.appendChild(el('span', 'xuat-nhan', dd === 'excel' ? 'Excel' : (dd === 'pdf' ? 'PDF' : 'Excel + PDF')));
    the.appendChild(dau);

    var soLieu = el('div', 'xuat-so');
    soLieu.appendChild(oSo('Người được xếp hạng', so(d.tong)));
    soLieu.appendChild(oSo('Elo trung bình', so(d.tb)));
    soLieu.appendChild(oSo('Elo cao nhất', so(d.max)));
    the.appendChild(soLieu);

    var canvasCho = [];   // canvas sơ đồ chờ gắn vào trang rồi mới vẽ (Chart.js cần đo kích thước khung)
    if (typeof Chart !== 'undefined' && (coBac || coPho)) {
      var luoi = el('div', 'ex-xb-bieudo' + (coBac && coPho ? '' : ' ex-xb-mot'));
      [['pho', coPho], ['bac', coBac]].forEach(function (b) {
        if (!b[1]) { return; }
        var o = el('div', 'ex-xb-bd');
        var cv = document.createElement('canvas');
        o.appendChild(cv);
        luoi.appendChild(o);
        canvasCho.push([b[0], cv]);
      });
      the.appendChild(luoi);
    }
    if (!coDs) {
      xem.appendChild(the);
      canvasCho.forEach(function (b) { bieuDoXem.push(new Chart(b[1], cauHinh(b[0], d, false))); });
      return;
    }

    var cuon = el('div', 'ex-xb-cuon');
    var bang = el('table', 'ex-xb');
    var tHead = el('thead'), hTr = el('tr');
    var cotHien = COT.filter(function (c) { return tr.indexOf(c[0]) >= 0; });
    cotHien.forEach(function (c) { hTr.appendChild(el('th', '', c[1])); });
    tHead.appendChild(hTr); bang.appendChild(tHead);
    var tBody = el('tbody');
    d.dong.slice(0, DONG_XEM).forEach(function (r) {
      var row = el('tr');
      cotHien.forEach(function (c) {
        var td = el('td');
        var v = r[c[0]];
        if (c[0] === 'tt') {
          td.appendChild(el('span', 'ex-xb-tt ' + (v === 'Bị khóa' ? 'ex-xb-loi' : 'ex-xb-ok'), v));
        } else {
          td.textContent = (v === '' || v === null || v === undefined) ? '—' : String(v);
          if (c[0] === 'ten' || c[0] === 'email') { td.className = 'ex-xb-nowrap'; }
        }
        row.appendChild(td);
      });
      tBody.appendChild(row);
    });
    bang.appendChild(tBody);
    cuon.appendChild(bang);
    the.appendChild(cuon);

    var hien = Math.min(d.dong.length, DONG_XEM);
    var them = d.soXuat - hien;
    the.appendChild(el('p', 'xuat-ghichu', (them > 0
      ? 'Đang xem ' + so(hien) + ' người đầu bảng, còn ' + so(them) + ' người nữa trong file. '
      : 'Hiển thị đủ ' + so(hien) + ' người. ') + 'Thứ tự cột trong file giống bảng trên.'));
    var gh = dd !== 'excel' && d.soXuat > d.toiDaPdf ? d.toiDaPdf : (dd !== 'pdf' && d.soXuat > d.toiDaXlsx ? d.toiDaXlsx : 0);
    if (gh) {
      the.appendChild(el('p', 'xuat-canhbao', 'Có ' + so(d.soXuat) + ' người, vượt giới hạn ' + so(gh) + ' dòng của file '
        + (dd !== 'excel' && d.soXuat > d.toiDaPdf ? 'PDF' : 'Excel') + '. Hãy chọn Top nhỏ hơn rồi xuất lại.'));
    }
    xem.appendChild(the);
    canvasCho.forEach(function (b) { bieuDoXem.push(new Chart(b[1], cauHinh(b[0], d, false))); });
  }

  function capNhatXem() {
    var mine = ++luot;
    bao('');
    capNhatCotBox();
    if (!cache.du || cache.khoa !== khoaDuLieu()) {
      huyBieuDoXem();
      xem.textContent = '';
      xem.appendChild(el('p', 'xuat-ghichu', 'Đang tải xem trước…'));
    }
    layDuLieu().then(function (d) {
      if (mine !== luot) { return; }
      veXem(d);
    }).catch(function (err) {
      if (mine !== luot) { return; }
      xem.textContent = '';
      xem.appendChild(el('p', 'xuat-ghichu', err.message === 'Failed to fetch' || err instanceof SyntaxError
        ? 'Không tải được xem trước (có thể phiên đăng nhập đã hết).' : err.message));
    });
  }
  form.addEventListener('change', capNhatXem);
  document.getElementById('exChonHet').addEventListener('click', function () { datTatCa(true); });
  document.getElementById('exBoChon').addEventListener('click', function () { datTatCa(false); });

  // ---------- Mở / đóng ----------
  function mo() { cache = { khoa: '', du: null }; bao(''); hop.hidden = false; capNhatXem(); }
  function dong() { hop.hidden = true; huyBieuDoXem(); xem.textContent = ''; }
  nutMo.addEventListener('click', mo);
  document.getElementById('exXuatHuy').addEventListener('click', dong);
  hop.addEventListener('click', function (e) { if (e.target === hop) { dong(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !hop.hidden) { dong(); } });

  // ---------- Xuất file ----------
  function taoFile(dinhDang, anh) {
    var fd = new FormData(form);
    fd.set('dinhdang', dinhDang);
    Object.keys(anh || {}).forEach(function (k) { fd.set(k, anh[k]); });
    return goi(fd).then(function (r) {
      if (!r.ok) { return docLoi(r); }
      var m = /filename="?([^";]+)"?/.exec(r.headers.get('Content-Disposition') || '');
      return r.blob().then(function (b) { return { blob: b, ten: m ? m[1] : 'bxh' }; });
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
    var loiNhap = kiemTra();
    if (loiNhap) { bao(loiNhap); return; }
    var dd = radio('dinhdang');
    var dds = dd === 'ca' ? ['excel', 'pdf'] : [dd];
    nutOk.disabled = true; nutOk.textContent = 'Đang xuất…';
    layDuLieu().then(function (d) {
      // Kiểm tra trước để không tải file Excel rồi mới báo lỗi PDF khi chọn "Cả hai"
      if (!d.tong) { throw new Error('Chưa có người chơi nào được xếp hạng trong ' + d.tenBxh + '.'); }
      var coDsGui = chonList('noidung[]').indexOf('ds') >= 0;
      if (coDsGui && dds.indexOf('pdf') >= 0 && d.soXuat > d.toiDaPdf) { throw new Error('Có ' + so(d.soXuat) + ' người, vượt giới hạn ' + so(d.toiDaPdf) + ' dòng của file PDF. Hãy chọn Top nhỏ hơn.'); }
      if (coDsGui && dds.indexOf('excel') >= 0 && d.soXuat > d.toiDaXlsx) { throw new Error('Có ' + so(d.soXuat) + ' người, vượt giới hạn ' + so(d.toiDaXlsx) + ' dòng của file Excel. Hãy chọn Top nhỏ hơn.'); }
      var anh = anhBieuDo(d);   // chụp một lần, dùng cho cả Excel và PDF
      return dds.reduce(function (truoc, f) {
        return truoc.then(function () { return taoFile(f, anh).then(luuFile); });
      }, Promise.resolve());
    }).then(function () {
      dong();
    }).catch(function (err) {
      bao(err.message === 'Failed to fetch' ? 'Không kết nối được máy chủ (có thể phiên đăng nhập đã hết).' : err.message);
    }).then(function () {
      nutOk.disabled = false; nutOk.textContent = 'Xuất';
    });
  });
})();