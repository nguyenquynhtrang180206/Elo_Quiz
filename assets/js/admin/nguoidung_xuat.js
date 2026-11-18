// Hộp thoại "Xuất" của trang Người dùng (admin/nguoidung.php), cùng kiểu với hộp thoại Xuất của Tổng quan / Nhật ký.
// Cột trái: phạm vi, trường, định dạng. Cột phải: xem trước. Số liệu xem trước lấy từ máy chủ (buoc=xemtruoc) một lần cho mỗi
// phạm vi; đổi trường hay định dạng chỉ vẽ lại, không gọi lại. Định dạng "Cả hai": tải liên tiếp hai file (Excel rồi PDF), không nén zip.
(function () {
  'use strict';
  var nutMo = document.getElementById('ndMoXuat');
  var hop = document.getElementById('ndXuatHop');
  var form = document.getElementById('ndXuatForm');
  if (!nutMo || !hop || !form) { return; }
  var xem = document.getElementById('ndXem');
  var xemGhiChu = document.getElementById('ndXemGhiChu');
  var loi = document.getElementById('ndXuatLoi');
  var nutOk = document.getElementById('ndXuatOk');

  // Khớp với $COT trong nguoidung_xuat.php
  var COT = [['ma', 'Mã'], ['ten', 'Họ tên'], ['email', 'Email'], ['tt', 'Trạng thái'], ['sotran', 'Số trận'], ['ngay', 'Ngày tạo']];
  var DONG_XEM = 10;

  var truongBox = document.getElementById('ndTruongBox');
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
  function radio(ten) { var r = form.querySelector('input[name="' + ten + '"]:checked'); return r ? r.value : ''; }
  function truongChon() {
    return Array.prototype.map.call(form.querySelectorAll('input[name="truong[]"]:checked'), function (i) { return i.value; });
  }
  function datTatCa(v) {
    form.querySelectorAll('input[name="truong[]"]').forEach(function (i) { i.checked = v; });
    capNhatXem();
  }
  function chonList(ten) {
    return Array.prototype.map.call(form.querySelectorAll('input[name="' + ten + '"]:checked'), function (i) { return i.value; });
  }
  function kiemTra() {
    var nd = chonList('noidung[]');
    if (!nd.length) { return 'Hãy chọn ít nhất một nội dung cần xuất.'; }
    if (nd.indexOf('ds') >= 0 && !truongChon().length) { return 'Hãy chọn ít nhất một trường cho danh sách người dùng.'; }
    return '';
  }
  function capNhatTruongBox() {
    var coDs = chonList('noidung[]').indexOf('ds') >= 0;
    truongBox.style.opacity = coDs ? '' : '.5';
    form.querySelectorAll('input[name="truong[]"], #ndChonHet, #ndBoChon').forEach(function (i) { i.disabled = !coDs; });
  }

  // ---------- Sơ đồ (dùng cho cả khung xem trước và ảnh chụp đưa vào file) ----------
  // tinh = true: ảnh chụp (kích thước cố định, không hiệu ứng, nét gấp đôi); false: xem trước co giãn theo khung
  function cauHinh(loai, d, tinh) {
    var chung = tinh ? { responsive: false, animation: false, devicePixelRatio: 2 }
                     : { responsive: true, maintainAspectRatio: false, animation: false };
    if (loai === 'ngay') {
      return {
        type: 'bar',
        data: {
          labels: d.theoNgay.map(function (m) { return m.nhan; }),
          datasets: [{ label: 'Tài khoản mới', data: d.theoNgay.map(function (m) { return m.so; }), backgroundColor: '#4f8bff', maxBarThickness: 28, borderRadius: 3 }]
        },
        options: Object.assign({}, chung, {
          plugins: { legend: { display: false }, title: { display: true, text: 'Tài khoản mới 30 ngày gần nhất · ' + d.phamVi } },
          scales: {
            x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 10, maxRotation: 0 } },
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        })
      };
    }
    return {
      type: 'doughnut',
      data: { labels: ['Hoạt động', 'Bị khóa'], datasets: [{ data: [d.hoatDong, d.biKhoa], backgroundColor: ['#19b26b', '#e5484d'], borderWidth: 0 }] },
      options: Object.assign({}, chung, {
        cutout: '62%',
        plugins: { legend: { display: true, position: 'bottom' }, title: { display: true, text: 'Trạng thái tài khoản' } }
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
  // Vẽ lại các sơ đồ đã tích ở kích thước cố định ngoài màn hình rồi chụp thành JPEG: { anh_tk: dataURL, anh_ngay: dataURL }
  function anhBieuDo(d) {
    var kq = {};
    var nd = chonList('noidung[]');
    if (typeof Chart === 'undefined') { return kq; }
    [['tk', 440, 300], ['ngay', 900, 300]].forEach(function (b) {
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
  function layDuLieu() {
    var khoa = radio('pham_vi');
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
    xemGhiChu.textContent = '· ' + so(d.tong) + ' người dùng trong phạm vi';
    if (!d.tong) { xem.appendChild(el('p', 'xuat-ghichu', 'Không có người dùng nào trong phạm vi này.')); return; }
    var loiNhap = kiemTra();
    if (loiNhap) { xem.appendChild(el('p', 'xuat-ghichu', loiNhap)); return; }

    var tr = truongChon(), dd = radio('dinhdang');
    var nd = chonList('noidung[]');
    var coDs = nd.indexOf('ds') >= 0, coTk = nd.indexOf('tk') >= 0, coNgay = nd.indexOf('ngay') >= 0;
    var coElo = tr.indexOf('elo') >= 0;
    if (dd === 'ca') { xem.appendChild(el('p', 'xuat-ghichu', 'Định dạng "Cả hai": tải về hai file, Excel rồi PDF.')); }

    var the = el('section', 'xuat-the');
    var dau = el('div', 'xuat-the-dau');
    var t = el('div', 'xuat-the-tieude');
    t.appendChild(el('b', '', coDs ? 'Danh sách người dùng' : 'Thống kê người dùng'));
    t.appendChild(el('small', '', 'Phạm vi: ' + d.phamVi));
    dau.appendChild(t);
    dau.appendChild(el('span', 'xuat-nhan', dd === 'excel' ? 'Excel · 1 sheet' : (dd === 'pdf' ? 'PDF' : 'Excel + PDF')));
    the.appendChild(dau);

    var soLieu = el('div', 'xuat-so');
    soLieu.appendChild(oSo('Tổng người dùng', so(d.tong)));
    soLieu.appendChild(oSo('Hoạt động', so(d.hoatDong)));
    soLieu.appendChild(oSo('Bị khóa', so(d.biKhoa)));
    the.appendChild(soLieu);

    var canvasCho = [];   // canvas sơ đồ chờ gắn vào trang rồi mới vẽ (Chart.js cần đo kích thước khung)
    if (typeof Chart !== 'undefined' && (coTk || coNgay)) {
      var luoi = el('div', 'nd-xb-bieudo' + (coTk && coNgay ? '' : ' nd-xb-mot'));
      [['ngay', coNgay], ['tk', coTk]].forEach(function (b) {
        if (!b[1]) { return; }
        var o = el('div', 'nd-xb-bd');
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

    var cuon = el('div', 'nd-xb-cuon');
    var bang = el('table', 'nd-xb');
    var tHead = el('thead'), hTr = el('tr');
    var cotHien = COT.filter(function (c) { return tr.indexOf(c[0]) >= 0; });
    cotHien.forEach(function (c) { hTr.appendChild(el('th', '', c[1])); });
    if (coElo) { d.mon.forEach(function (m) { hTr.appendChild(el('th', '', 'Elo - ' + m.ten)); }); }
    tHead.appendChild(hTr); bang.appendChild(tHead);
    var tBody = el('tbody');
    d.dong.slice(0, DONG_XEM).forEach(function (r) {
      var row = el('tr');
      cotHien.forEach(function (c) {
        var td = el('td');
        var v = r[c[0]];
        if (c[0] === 'tt') {
          td.appendChild(el('span', 'nd-xb-tt ' + (v === 'Bị khóa' ? 'nd-xb-loi' : 'nd-xb-ok'), v));
        } else {
          td.textContent = v === '' ? '—' : v;
          if (c[0] === 'ngay') { td.className = 'nd-xb-nowrap'; }
        }
        row.appendChild(td);
      });
      if (coElo) {
        d.mon.forEach(function (m) {
          var v = r.elo[m.ma];
          row.appendChild(el('td', '', v === null || v === undefined ? '—' : String(v)));
        });
      }
      tBody.appendChild(row);
    });
    bang.appendChild(tBody);
    cuon.appendChild(bang);
    the.appendChild(cuon);

    var them = d.tong - Math.min(d.dong.length, DONG_XEM);
    the.appendChild(el('p', 'xuat-ghichu', (them > 0
      ? 'Đang xem ' + so(Math.min(d.dong.length, DONG_XEM)) + ' người dùng đầu tiên, còn ' + so(them) + ' người nữa trong file. '
      : 'Hiển thị đủ ' + so(d.dong.length) + ' người dùng. ') + 'Thứ tự cột trong file giống bảng trên.'));
    var gh = !coDs ? 0 : dd !== 'excel' && d.tong > d.toiDaPdf ? d.toiDaPdf : (dd !== 'pdf' && d.tong > d.toiDaXlsx ? d.toiDaXlsx : 0);
    if (gh) {
      the.appendChild(el('p', 'xuat-canhbao', 'Có ' + so(d.tong) + ' người dùng, vượt giới hạn ' + so(gh) + ' dòng của file '
        + (dd !== 'excel' && d.tong > d.toiDaPdf ? 'PDF' : 'Excel') + '. Hãy lọc bớt rồi xuất lại.'));
    }
    xem.appendChild(the);
    canvasCho.forEach(function (b) { bieuDoXem.push(new Chart(b[1], cauHinh(b[0], d, false))); });
  }

  function capNhatXem() {
    var mine = ++luot;
    bao('');
    capNhatTruongBox();
    if (!cache.du || cache.khoa !== radio('pham_vi')) {
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
  document.getElementById('ndChonHet').addEventListener('click', function () { datTatCa(true); });
  document.getElementById('ndBoChon').addEventListener('click', function () { datTatCa(false); });

  // ---------- Mở / đóng ----------
  function mo() { cache = { khoa: '', du: null }; bao(''); hop.hidden = false; capNhatXem(); }
  function dong() { hop.hidden = true; huyBieuDoXem(); xem.textContent = ''; }
  nutMo.addEventListener('click', mo);
  document.getElementById('ndXuatHuy').addEventListener('click', dong);
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
      return r.blob().then(function (b) { return { blob: b, ten: m ? m[1] : 'nguoidung' }; });
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
      if (!d.tong) { throw new Error('Không có người dùng nào trong phạm vi đã chọn.'); }
      var coDsGui = chonList('noidung[]').indexOf('ds') >= 0;
      if (coDsGui && dds.indexOf('pdf') >= 0 && d.tong > d.toiDaPdf) { throw new Error('Có ' + so(d.tong) + ' người dùng, vượt giới hạn ' + so(d.toiDaPdf) + ' dòng của file PDF.'); }
      if (coDsGui && dds.indexOf('excel') >= 0 && d.tong > d.toiDaXlsx) { throw new Error('Có ' + so(d.tong) + ' người dùng, vượt giới hạn ' + so(d.toiDaXlsx) + ' dòng của file Excel.'); }
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