// Nút "Xuất" ở trang Tổng quan: mở hộp thoại chọn trang (Tổng quan / Theo môn học / Cả hai), mốc thời gian (nhiều mốc, có nút
// "Chọn tất cả"), định dạng (Excel / PDF / Cả hai) và cách chia (Theo mốc ngày / Theo loại biểu đồ / Cả hai);
// có phần XEM TRƯỚC đúng theo cách chia đang chọn. Chỉ khi bấm "Xuất" trong hộp thoại thì file mới tải về.
// Định dạng "Cả hai": gọi máy chủ lần lượt hai lần (Excel rồi PDF) và tải về liên tiếp hai file (không nén zip).
// Biểu đồ trong phần xem trước được vẽ lại bằng cùng hàm với biểu đồ trên trang (DashCharts, assets/js/admin/index.js);
// khi xuất, chính các canvas đó được chụp thành JPEG nền trắng (giữ nguyên màu) rồi gửi kèm.
(function () {
  'use strict';
  var nut = document.getElementById('nutXuat');
  var hop = document.getElementById('hopXuat');
  var form = document.getElementById('formXuat');
  if (!nut || !hop || !form) { return; }

  var vung = document.getElementById('xuatXem');
  var loi = document.getElementById('xuatLoi');
  var ok = document.getElementById('xuatOk');
  var ghiChuChia = document.getElementById('xuatGhiChuChia');
  var nhanOk = ok.textContent;

  var cache = {};     // mốc => số liệu từ máy chủ
  var bieuDo = [];    // biểu đồ Chart.js đang hiển thị trong xem trước
  var anh = {};       // 'tongquan_7' / 'mon_30' => canvas (để chụp khi xuất)
  var luot = 0;       // chống kết quả cũ ghi đè kết quả mới khi bấm nhanh

  function bao(msg) { loi.textContent = msg; loi.hidden = !msg; }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function chon(ten) {   // các ô tick đang chọn
    return Array.prototype.map.call(form.querySelectorAll('input[name="' + ten + '"]:checked'), function (i) { return i.value; });
  }
  function radio(ten) {  // giá trị ô radio đang chọn
    var r = form.querySelector('input[name="' + ten + '"]:checked');
    return r ? r.value : '';
  }

  function xoaXemTruoc() {
    bieuDo.forEach(function (c) { c.destroy(); });
    bieuDo = []; anh = {};
    vung.textContent = '';
  }

  // Nhãn nhỏ ở góc thẻ: cho biết phần này thành sheet / trang nào trong file
  function nhanThe(nhom, tenSheet) {
    var dd = radio('dinhdang');
    var pdf = nhom === 'ngay' ? 'PDF ngang' : 'PDF dọc';
    if (dd === 'excel') { return 'Sheet "' + tenSheet + '"'; }
    if (dd === 'pdf') { return pdf; }
    return 'Sheet + ' + pdf;
  }
  function khungThe(tieuDe, phu, nhan) {
    var the = el('section', 'xuat-the');
    var dau = el('div', 'xuat-the-dau');
    var t = el('div', 'xuat-the-tieude');
    t.appendChild(el('b', '', tieuDe));
    if (phu) { t.appendChild(el('small', '', phu)); }
    dau.appendChild(t);
    dau.appendChild(el('span', 'xuat-nhan', nhan));
    the.appendChild(dau);
    return the;
  }
  function tieuDeNhom(chu) { vung.appendChild(el('div', 'xuat-nhom', chu)); }

  function oSoLieu(tq) {
    var so = el('div', 'xuat-so');
    tq.the.forEach(function (r) {
      var o = el('div', 'xuat-so-o');
      o.appendChild(el('strong', '', r[1]));
      o.appendChild(el('span', '', r[0] + ' (' + r[2] + ' so với kỳ trước)'));
      so.appendChild(o);
    });
    return so;
  }
  function chuThich(mon) {
    var ct = el('ul', 'xuat-chuthich');
    mon.ds.forEach(function (m) {
      var li = el('li');
      var i = el('i'); i.style.background = m.mau;
      li.appendChild(i);
      li.appendChild(el('span', '', m.ten));
      li.appendChild(el('b', '', m.so + ' trận · ' + m.phantram + '%'));
      ct.appendChild(li);
    });
    return ct;
  }
  function canvas(rong, cao, cls) {
    var cv = document.createElement('canvas');
    cv.width = rong; cv.height = cao; cv.className = 'xuat-cv ' + cls;
    return cv;
  }

  // ---- Nhóm 1: THEO MỐC NGÀY - mỗi mốc một thẻ gộp, hai biểu đồ ngang cạnh nhau ----
  function veTheoNgay(du, coTq, coMon, khoang, tuyChon) {
    khoang.forEach(function (n) {
      var d = du[n];
      var tenSheet = 'Ngày - ' + d.nhan;
      var the = khungThe(tenSheet, d.khoang, nhanThe('ngay', tenSheet));
      if (coTq) { the.appendChild(oSoLieu(d.tq)); }

      var cap = el('div', 'xuat-cap');
      var cvTq = null, cvMon = null;
      if (coTq) { cvTq = canvas(900, 340, 'xuat-cv-tq'); cap.appendChild(cvTq); }
      if (coMon && d.mon.ds.length) { cvMon = canvas(360, 360, 'xuat-cv-mon'); cap.appendChild(cvMon); }
      if (cap.firstChild) { the.appendChild(cap); }
      vung.appendChild(the);

      if (cvTq)  { bieuDo.push(window.DashCharts.tongQuan(cvTq, d.tq, tuyChon)); anh['tongquan_' + n] = cvTq; }
      if (cvMon) { bieuDo.push(window.DashCharts.mon(cvMon, d.mon.ds, tuyChon)); anh['mon_' + n] = cvMon; }
      if (cvMon) { the.appendChild(chuThich(d.mon)); }
      if (coMon && !d.mon.ds.length) {
        the.appendChild(el('p', 'xuat-ghichu', 'Chưa có trận nào trong ' + d.nhan + ' gần nhất.'));
      }
      the.appendChild(el('p', 'xuat-ghichu', 'Kèm bảng chi tiết trong file.'));
    });
  }

  // ---- Nhóm 2: THEO LOẠI BIỂU ĐỒ - xếp theo loại, trong mỗi loại các mốc nối tiếp ----
  function veTheoLoai(du, coTq, coMon, khoang, tuyChon) {
    if (coTq) {
      var tenTq = 'Loại - Tổng quan';
      vung.appendChild(el('div', 'xuat-loai', tenTq));
      khoang.forEach(function (n) {
        var d = du[n];
        var the = khungThe('Mốc ' + d.nhan, d.khoang, nhanThe('loai', tenTq));
        the.appendChild(oSoLieu(d.tq));
        var cv = canvas(900, 340, 'xuat-cv-tq');
        the.appendChild(cv);
        vung.appendChild(the);
        bieuDo.push(window.DashCharts.tongQuan(cv, d.tq, tuyChon));
        anh['tongquan_' + n] = cv;
        the.appendChild(el('p', 'xuat-ghichu', 'Gom theo ' + d.tq.kieu + '. Kèm bảng số liệu và bảng trận theo trạng thái trong file.'));
      });
    }
    if (coMon) {
      var tenMon = 'Loại - Theo môn học';
      vung.appendChild(el('div', 'xuat-loai', tenMon));
      khoang.forEach(function (n) {
        var d = du[n];
        var the = khungThe('Mốc ' + d.nhan, d.khoang, nhanThe('loai', tenMon));
        if (!d.mon.ds.length) {
          the.appendChild(el('p', 'xuat-ghichu', 'Chưa có trận nào trong ' + d.nhan + ' gần nhất.'));
          vung.appendChild(the);
          return;
        }
        var ngang = el('div', 'xuat-mon');
        var cm = canvas(360, 360, 'xuat-cv-mon');
        ngang.appendChild(cm);
        ngang.appendChild(chuThich(d.mon));
        the.appendChild(ngang);
        vung.appendChild(the);
        bieuDo.push(window.DashCharts.mon(cm, d.mon.ds, tuyChon));
        anh['mon_' + n] = cm;
      });
    }
  }

  function ve(du, trang, khoang, chia) {
    xoaXemTruoc();
    var tuyChon = { responsive: false, animation: false, devicePixelRatio: 2, events: [] };
    var coTq = trang === 'tongquan' || trang === 'ca';
    var coMon = trang === 'mon' || trang === 'ca';
    var ca = chia === 'ca';

    if (chia === 'ngay' || ca) {
      if (ca) { tieuDeNhom('Nhóm 1 · Theo mốc ngày'); }
      veTheoNgay(du, coTq, coMon, khoang, tuyChon);
    }
    if (chia === 'loai' || ca) {
      if (ca) { tieuDeNhom('Nhóm 2 · Theo loại biểu đồ'); }
      veTheoLoai(du, coTq, coMon, khoang, tuyChon);
    }
  }

  var MOTA_CHIA = {
    ngay: 'Excel: mỗi mốc một sheet. PDF: A4 ngang, mỗi mốc một trang tóm tắt, bảng chi tiết nối phía sau.',
    loai: 'Excel: mỗi loại biểu đồ một sheet, các mốc xếp nối tiếp từ trên xuống. PDF: A4 dọc, một luồng dọc.',
    ca: 'Cả hai nhóm trong cùng một file: nhóm theo mốc ngày trước, nhóm theo loại biểu đồ sau (PDF: trang ngang rồi đến trang dọc).'
  };

  function capNhat() {
    var trang = radio('trang');
    var chia = radio('chia');
    var khoang = chon('ngay[]').map(Number).sort(function (a, b) { return a - b; });
    var mine = ++luot;
    bao('');
    ghiChuChia.textContent = MOTA_CHIA[chia] || '';

    if (!khoang.length) {
      xoaXemTruoc();
      vung.appendChild(el('p', 'xuat-ghichu', 'Hãy chọn ít nhất một mốc thời gian để xem trước.'));
      ok.disabled = true;
      return;
    }
    if (!window.DashCharts) { bao('Chưa tải được thư viện biểu đồ, hãy tải lại trang.'); ok.disabled = true; return; }

    ok.disabled = true;
    var thieu = khoang.filter(function (n) { return !cache[n]; });
    var tai = Promise.resolve();
    if (thieu.length) {
      if (!vung.firstChild) { vung.appendChild(el('p', 'xuat-ghichu', 'Đang tải...')); }
      var q = thieu.map(function (n) { return 'ngay[]=' + n; }).join('&');
      tai = fetch(form.getAttribute('data-api') + '?' + q, { credentials: 'same-origin' })
        .then(function (r) {
          if (!r.ok) { throw new Error('Không tải được số liệu xem trước.'); }
          return r.json();
        })
        .then(function (j) { Object.keys(j).forEach(function (k) { cache[k] = j[k]; }); });
    }
    tai.then(function () {
      if (mine !== luot) { return; }
      ve(cache, trang, khoang, chia);
      ok.disabled = false;
    }).catch(function (err) {
      if (mine !== luot) { return; }
      xoaXemTruoc();
      bao(err.message === 'Failed to fetch' || err instanceof SyntaxError ? 'Không tải được số liệu xem trước (có thể phiên đăng nhập đã hết).' : err.message);
    });
  }

  function mo() { hop.hidden = false; capNhat(); }
  function dong() { hop.hidden = true; xoaXemTruoc(); }

  nut.addEventListener('click', mo);
  document.getElementById('xuatHuy').addEventListener('click', dong);
  hop.addEventListener('click', function (e) { if (e.target === hop) { dong(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !hop.hidden) { dong(); } });
  form.addEventListener('change', capNhat);

  // Nút "Chọn tất cả" mốc thời gian
  document.getElementById('xuatChonHet').addEventListener('click', function () {
    Array.prototype.forEach.call(form.querySelectorAll('input[name="ngay[]"]'), function (i) { i.checked = true; });
    capNhat();
  });

  // Chụp canvas lên nền trắng (canvas của Chart.js trong suốt nên nếu lưu thẳng sang JPEG sẽ bị nền đen)
  function chup(c) {
    if (!c || !c.width || !c.height) { return ''; }
    var t = document.createElement('canvas');
    t.width = c.width; t.height = c.height;
    var g = t.getContext('2d');
    g.fillStyle = '#ffffff';
    g.fillRect(0, 0, t.width, t.height);
    g.drawImage(c, 0, 0);
    return t.toDataURL('image/jpeg', 0.95);
  }

  // Gọi máy chủ tạo MỘT file (excel hoặc pdf), trả về {blob, ten}
  function taoFile(fd, dinhDang) {
    fd.set('dinhdang', dinhDang);
    return fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) { return r.text().then(function (t) { throw new Error(t || 'Không xuất được file.'); }); }
        var m = /filename="?([^";]+)"?/.exec(r.headers.get('Content-Disposition') || '');
        return r.blob().then(function (b) { return { blob: b, ten: m ? m[1] : 'thongke' }; });
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
    if (!chon('ngay[]').length) { bao('Hãy chọn ít nhất một mốc thời gian.'); return; }

    var fd = new FormData(form);
    Object.keys(anh).forEach(function (k) {
      var d = chup(anh[k]);
      if (d) { fd.append('anh_' + k, d); }
    });

    // "Cả hai" = tải liên tiếp hai file riêng (Excel rồi PDF), không nén zip
    var dds = radio('dinhdang') === 'ca' ? ['excel', 'pdf'] : [radio('dinhdang')];
    var daTai = 0;
    var chuoi = Promise.resolve();
    dds.forEach(function (dd) {
      chuoi = chuoi.then(function () {
        ok.textContent = dds.length > 1 ? 'Đang xuất ' + dd.toUpperCase() + '...' : 'Đang xuất...';
        return taoFile(fd, dd).then(function (kq) {
          luuFile(kq);
          daTai++;
          // chờ một chút giữa hai lần tải để trình duyệt nhận đủ cả hai file
          return dds.length > 1 && daTai < dds.length ? new Promise(function (xong) { setTimeout(xong, 700); }) : null;
        }).catch(function (err) {
          throw new Error(daTai ? 'Đã tải ' + daTai + ' file nhưng file ' + dd.toUpperCase() + ' bị lỗi: ' + err.message : err.message);
        });
      });
    });

    ok.disabled = true;
    chuoi
      .then(function () {
        dong();
        if (window.CosmicUI) { window.CosmicUI.toast(dds.length > 1 ? 'Đã xuất báo cáo (Excel và PDF).' : 'Đã xuất báo cáo.', 'thanhcong'); }
      })
      .catch(function (err) { bao(err.message); })
      .then(function () { ok.disabled = false; ok.textContent = nhanOk; });
  });
})();