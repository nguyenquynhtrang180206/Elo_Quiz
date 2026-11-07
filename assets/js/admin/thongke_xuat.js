// Nút "Xuất" ở trang Tổng quan: mở hộp thoại chọn trang, mốc thời gian (nhiều mốc), định dạng; có phần XEM TRƯỚC
// đúng nội dung sẽ xuất. Chỉ khi bấm "Xuất" trong hộp thoại thì file mới tải về.
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
  var nhanOk = ok.textContent;
  var TRANG = [['tongquan', 'Thống kê tổng quan'], ['mon', 'Thống kê theo môn học']];

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
  function chon(ten) {
    return Array.prototype.map.call(form.querySelectorAll('input[name="' + ten + '"]:checked'), function (i) { return i.value; });
  }
  function dinhDang() { return form.querySelector('input[name="dinhdang"]:checked').value; }

  function xoaXemTruoc() {
    bieuDo.forEach(function (c) { c.destroy(); });
    bieuDo = []; anh = {};
    vung.textContent = '';
  }

  function ve(du, trang, khoang) {
    xoaXemTruoc();
    var excel = dinhDang() === 'excel';
    var tuyChon = { responsive: false, animation: false, devicePixelRatio: 2, events: [] };

    trang.forEach(function (t) {
      khoang.forEach(function (n) {
        var d = du[n];
        var tieuDe = t[1] + ' - ' + d.nhan;
        var the = el('section', 'xuat-the');
        var dau = el('div', 'xuat-the-dau');
        dau.appendChild(el('b', '', tieuDe));
        dau.appendChild(el('span', 'xuat-nhan', excel ? 'Sheet riêng' : 'PDF dọc'));
        the.appendChild(dau);

        if (t[0] === 'tongquan') {
          var so = el('div', 'xuat-so');
          d.tq.the.forEach(function (r) {
            var o = el('div', 'xuat-so-o');
            o.appendChild(el('strong', '', r[1]));
            o.appendChild(el('span', '', r[0] + ' (' + r[2] + ')'));
            so.appendChild(o);
          });
          the.appendChild(so);
          var cv = document.createElement('canvas');
          cv.width = 900; cv.height = 340; cv.className = 'xuat-cv';
          the.appendChild(cv);
          vung.appendChild(the);
          bieuDo.push(window.DashCharts.tongQuan(cv, d.tq, tuyChon));
          anh['tongquan_' + n] = cv;
          the.appendChild(el('p', 'xuat-ghichu', 'Gom theo ' + d.tq.kieu + '. Kèm bảng số liệu và bảng trận theo trạng thái trong file.'));
        } else {
          if (!d.mon.ds.length) {
            the.appendChild(el('p', 'xuat-ghichu', 'Chưa có trận nào trong ' + d.nhan + ' gần nhất.'));
            vung.appendChild(the);
            return;
          }
          var ngang = el('div', 'xuat-mon');
          var cm = document.createElement('canvas');
          cm.width = 360; cm.height = 360; cm.className = 'xuat-cv xuat-cv-mon';
          ngang.appendChild(cm);
          var ct = el('ul', 'xuat-chuthich');
          d.mon.ds.forEach(function (m) {
            var li = el('li');
            var i = el('i'); i.style.background = m.mau;
            li.appendChild(i);
            li.appendChild(el('span', '', m.ten));
            li.appendChild(el('b', '', m.so + ' trận · ' + m.phantram + '%'));
            ct.appendChild(li);
          });
          ngang.appendChild(ct);
          the.appendChild(ngang);
          vung.appendChild(the);
          bieuDo.push(window.DashCharts.mon(cm, d.mon.ds, tuyChon));
          anh['mon_' + n] = cm;
        }
      });
    });
  }

  function capNhat() {
    var trang = TRANG.filter(function (t) { return chon('trang[]').indexOf(t[0]) >= 0; });
    var khoang = chon('ngay[]').map(Number).sort(function (a, b) { return a - b; });
    var mine = ++luot;
    bao('');

    if (!trang.length || !khoang.length) {
      xoaXemTruoc();
      vung.appendChild(el('p', 'xuat-ghichu', 'Hãy chọn ít nhất một trang và một mốc thời gian để xem trước.'));
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
      ve(cache, trang, khoang);
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

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    bao('');
    if (!chon('trang[]').length) { bao('Hãy chọn ít nhất một trang để xuất.'); return; }
    if (!chon('ngay[]').length) { bao('Hãy chọn ít nhất một mốc thời gian.'); return; }

    var fd = new FormData(form);
    Object.keys(anh).forEach(function (k) {
      var d = chup(anh[k]);
      if (d) { fd.append('anh_' + k, d); }
    });

    ok.disabled = true; ok.textContent = 'Đang xuất...';
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) { return r.text().then(function (t) { throw new Error(t || 'Không xuất được file.'); }); }
        var m = /filename="?([^";]+)"?/.exec(r.headers.get('Content-Disposition') || '');
        return r.blob().then(function (b) { return { blob: b, ten: m ? m[1] : 'thongke' }; });
      })
      .then(function (kq) {
        var url = URL.createObjectURL(kq.blob);
        var a = document.createElement('a');
        a.href = url; a.download = kq.ten;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
        dong();
        if (window.CosmicUI) { window.CosmicUI.toast('Đã xuất báo cáo.', 'thanhcong'); }
      })
      .catch(function (err) { bao(err.message); })
      .then(function () { ok.disabled = false; ok.textContent = nhanOk; });
  });
})();