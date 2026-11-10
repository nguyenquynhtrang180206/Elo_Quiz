// Nút "Xuất" ở trang Thống kê khảo sát: hộp thoại 2 cột. Cột trái chọn các phần/trường cần xuất, các cột của danh sách sinh viên,
// định dạng (Excel / PDF / Cả hai); cột phải XEM TRƯỚC toàn bộ nội dung sẽ xuất (đúng theo khoảng ngày đang lọc ở ngoài).
// Biểu đồ trong xem trước được chụp thành JPEG nền trắng và gửi kèm khi xuất. Chỉ khi bấm "Xuất" trong hộp thoại thì file mới tải về.
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
  var cotSV = document.getElementById('xuatCotSV');
  var MAU = ['#5b4bdb', '#7a6be6', '#9a8ef0', '#b9b0f5', '#d3cdf9', '#e8e5fc'];
  var CHART_FONT = "'Be Vietnam Pro','Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif";

  var du = null;      // số liệu từ máy chủ
  var bieuDo = [];    // biểu đồ Chart.js đang hiển thị
  var anh = {};       // 'mucTieu' / 'linhVuc' / 'trinhDo' => canvas
  var luot = 0;

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
  function radio(ten) { var r = form.querySelector('input[name="' + ten + '"]:checked'); return r ? r.value : ''; }
  function pt(v) { return String(v).replace('.', ',') + '%'; }
  function tong(a) { return a.reduce(function (s, v) { return s + v; }, 0); }

  function xoaXemTruoc() {
    bieuDo.forEach(function (c) { c.destroy(); });
    bieuDo = []; anh = {};
    vung.textContent = '';
  }
  function the(tieuDe, phu) {
    var t = el('section', 'xuat-the');
    var dau = el('div', 'xuat-the-dau');
    var tt = el('div', 'xuat-the-tieude');
    tt.appendChild(el('b', '', tieuDe));
    if (phu) { tt.appendChild(el('small', '', phu)); }
    dau.appendChild(tt);
    t.appendChild(dau);
    return t;
  }
  function bang(tieuDe, dong) {
    var khung = el('div', 'xuat-bang');
    var tb = el('table');
    var tr = el('tr');
    tieuDe.forEach(function (h) { tr.appendChild(el('th', '', h)); });
    tb.appendChild(tr);
    dong.forEach(function (r) {
      var tr2 = el('tr');
      r.forEach(function (v) { tr2.appendChild(el('td', '', String(v))); });
      tb.appendChild(tr2);
    });
    khung.appendChild(tb);
    return khung;
  }
  function canvas(rong, cao) {
    var cv = document.createElement('canvas');
    cv.width = rong; cv.height = cao; cv.className = 'xuat-cv';
    return cv;
  }

  // Plugin ghi % ở đầu mỗi cột / thanh
  var nhanPT = {
    id: 'nhanPhanTram',
    afterDatasetsDraw: function (c) {
      var ctx = c.ctx, ngang = c.options.indexAxis === 'y';
      var meta = c.getDatasetMeta(0), gt = c.data.datasets[0].data;
      ctx.save();
      ctx.fillStyle = '#3a3f63'; ctx.font = '600 12px ' + CHART_FONT;
      meta.data.forEach(function (b, i) {
        var t = pt(gt[i]);
        if (ngang) { ctx.textAlign = 'left'; ctx.textBaseline = 'middle'; ctx.fillText(t, b.x + 6, b.y); }
        else { ctx.textAlign = 'center'; ctx.textBaseline = 'bottom'; ctx.fillText(t, b.x, b.y - 4); }
      });
      ctx.restore();
    }
  };
  var CHUNG = { responsive: false, animation: false, devicePixelRatio: 2, events: [] };

  function veBieuDo(k, cv, b) {
    Chart.defaults.font.family = CHART_FONT;
    Chart.defaults.color = '#5b6080';
    var cfg;
    if (k === 'mucTieu') {
      cfg = { type: 'doughnut',
        data: { labels: b.nhan, datasets: [{ data: b.luot, backgroundColor: MAU.slice(0, b.nhan.length), borderWidth: 2, borderColor: '#fff' }] },
        options: Object.assign({ cutout: '62%', plugins: { legend: { display: true, position: 'right', labels: { boxWidth: 10, font: { size: 11 } } } } }, CHUNG) };
    } else {
      var ngang = k === 'linhVuc';
      var tran = Math.min(100, Math.ceil((Math.max.apply(null, b.tyLe) + 12) / 10) * 10);
      var truc = { min: 0, max: tran, ticks: { callback: function (v) { return v + '%'; } }, grid: { color: 'rgba(60,50,150,.08)' } };
      cfg = { type: 'bar',
        data: { labels: b.nhan, datasets: [{ data: b.tyLe, backgroundColor: k === 'trinhDo' ? ['#9a8ef0', '#5b4bdb', '#b9b0f5', '#d3cdf9'] : MAU.slice(0, b.nhan.length), borderRadius: 6, barThickness: ngang ? 22 : undefined, maxBarThickness: 46 }] },
        options: Object.assign({ indexAxis: ngang ? 'y' : 'x', plugins: { legend: { display: false } },
          scales: ngang ? { x: truc, y: { grid: { display: false } } } : { y: truc, x: { grid: { display: false } } } }, CHUNG),
        plugins: [nhanPT] };
    }
    bieuDo.push(new Chart(cv, cfg));
    anh[k] = cv;
  }

  var TIEU_DE = { mucTieu: 'Mục tiêu tham gia Elo_Quiz', linhVuc: 'Lĩnh vực yêu thích', trinhDo: 'Trình độ tự đánh giá' };

  function ve() {
    xoaXemTruoc();
    var truong = chon('truong[]');
    var kem = form.querySelector('input[name="kemBieuDo"]').checked;
    if (!truong.length) { vung.appendChild(el('p', 'xuat-ghichu', 'Hãy chọn ít nhất một phần để xem trước.')); return; }
    var luu = el('p', 'xuat-ghichu', 'Khoảng ngày: ' + du.khoang);
    vung.appendChild(luu);

    if (truong.indexOf('the') >= 0) {
      var t = the('Số liệu tổng quan');
      var so = el('div', 'xuat-so');
      du.the.forEach(function (r) {
        var o = el('div', 'xuat-so-o');
        o.appendChild(el('strong', '', r[1]));
        o.appendChild(el('span', '', r[0] + (r[2] ? ' · ' + r[2] : '')));
        so.appendChild(o);
      });
      t.appendChild(so);
      vung.appendChild(t);
    }
    ['mucTieu', 'linhVuc', 'trinhDo'].forEach(function (k) {
      if (truong.indexOf(k) < 0) { return; }
      var b = du[k];
      var t = the(TIEU_DE[k], tong(b.luot) + ' lượt chọn');
      if (kem && tong(b.luot) > 0) {
        var cv = canvas(k === 'mucTieu' ? 760 : 700, k === 'mucTieu' ? 300 : 320);
        t.appendChild(cv);
        vung.appendChild(t);
        veBieuDo(k, cv, b);
      } else { vung.appendChild(t); }
      t.appendChild(bang(['Lựa chọn', 'Lượt chọn', 'Tỷ lệ'], b.nhan.map(function (n, i) { return [n, b.luot[i], pt(b.tyLe[i])]; })));
    });
    if (truong.indexOf('chiTiet') >= 0) {
      var t2 = the('Chi tiết câu hỏi khảo sát', 'Tỷ lệ tính trên ' + du.hoanTat + ' sinh viên hoàn tất');
      t2.appendChild(bang(['Câu hỏi', 'Đáp án nhiều nhất', 'Số lượng', 'Tỷ lệ'], du.chiTiet.map(function (c) { return [c.cau, c.dapAn, c.so, pt(c.tyLe)]; })));
      vung.appendChild(t2);
    }
    if (truong.indexOf('sinhVien') >= 0) {
      var cot = chon('cot[]');
      var t3 = the('Danh sách sinh viên', du.sinhVien.length + ' sinh viên (tối đa 500, mới nhất trước)');
      if (!cot.length) { t3.appendChild(el('p', 'xuat-ghichu', 'Hãy chọn ít nhất một cột.')); }
      else if (!du.sinhVien.length) { t3.appendChild(el('p', 'xuat-ghichu', 'Chưa có sinh viên nào làm khảo sát trong khoảng ngày này.')); }
      else {
        var tenCot = {};
        Array.prototype.forEach.call(cotSV.querySelectorAll('input'), function (i) { tenCot[i.value] = i.getAttribute('data-ten'); });
        t3.appendChild(bang(cot.map(function (k) { return tenCot[k]; }), du.sinhVien.map(function (r) { return cot.map(function (k) { return r[k]; }); })));
      }
      vung.appendChild(t3);
    }
  }

  function capNhat() {
    var mine = ++luot;
    bao('');
    // ô cột sinh viên chỉ có tác dụng khi chọn phần "Danh sách từng sinh viên"
    var coSV = !!form.querySelector('input[name="truong[]"][value="sinhVien"]:checked');
    cotSV.hidden = !coSV;
    Array.prototype.forEach.call(cotSV.querySelectorAll('input'), function (i) { i.disabled = !coSV; });
    if (typeof Chart === 'undefined') { bao('Chưa tải được thư viện biểu đồ, hãy tải lại trang.'); ok.disabled = true; return; }
    ok.disabled = !chon('truong[]').length || (coSV && !chon('cot[]').length);
    if (du) { ve(); return; }
    vung.appendChild(el('p', 'xuat-ghichu', 'Đang tải...'));
    ok.disabled = true;
    fetch(form.getAttribute('data-api') + '?tu=' + encodeURIComponent(form.tu.value) + '&den=' + encodeURIComponent(form.den.value), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('Không tải được số liệu xem trước.'); } return r.json(); })
      .then(function (j) { if (mine !== luot) { return; } du = j; ve(); ok.disabled = !chon('truong[]').length || (coSV && !chon('cot[]').length); })
      .catch(function (err) {
        if (mine !== luot) { return; }
        xoaXemTruoc(); ok.disabled = true;
        bao(err.message === 'Failed to fetch' || err instanceof SyntaxError ? 'Không tải được số liệu xem trước (có thể phiên đăng nhập đã hết).' : err.message);
      });
  }

  function mo() { du = null; hop.hidden = false; xoaXemTruoc(); capNhat(); }
  function dong() { hop.hidden = true; xoaXemTruoc(); }
  nut.addEventListener('click', mo);
  document.getElementById('xuatHuy').addEventListener('click', dong);
  hop.addEventListener('click', function (e) { if (e.target === hop) { dong(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !hop.hidden) { dong(); } });
  function chonHetCot() {
    Array.prototype.forEach.call(cotSV.querySelectorAll('input'), function (i) { i.checked = true; });
  }
  // Vừa tick "Danh sách từng sinh viên" thì các cột hiện ra và mặc định được chọn hết
  form.addEventListener('change', function (e) {
    if (e.target.name === 'truong[]' && e.target.value === 'sinhVien' && e.target.checked) { chonHetCot(); }
  });
  form.addEventListener('change', capNhat);
  document.getElementById('xuatChonHet').addEventListener('click', function () {
    var het = form.querySelectorAll('input[name="truong[]"]');
    var tatCa = chon('truong[]').length === het.length;
    Array.prototype.forEach.call(het, function (i) { i.checked = !tatCa; });
    if (!tatCa) { chonHetCot(); }
    capNhat();
  });

  // Chụp canvas lên nền trắng (canvas Chart.js trong suốt nên lưu thẳng sang JPEG sẽ bị nền đen)
  function chup(c) {
    if (!c || !c.width || !c.height) { return ''; }
    var t = document.createElement('canvas');
    t.width = c.width; t.height = c.height;
    var g = t.getContext('2d');
    g.fillStyle = '#ffffff'; g.fillRect(0, 0, t.width, t.height);
    g.drawImage(c, 0, 0);
    return t.toDataURL('image/jpeg', 0.95);
  }
  function taoFile(fd, dinhDang) {
    fd.set('dinhdang', dinhDang);
    return fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) { return r.text().then(function (t) { throw new Error(t || 'Không xuất được file.'); }); }
        var m = /filename="?([^";]+)"?/.exec(r.headers.get('Content-Disposition') || '');
        return r.blob().then(function (b) { return { blob: b, ten: m ? m[1] : 'thongke_khaosat' }; });
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
    if (!chon('truong[]').length) { bao('Hãy chọn ít nhất một phần cần xuất.'); return; }
    var fd = new FormData(form);
    Object.keys(anh).forEach(function (k) { var d = chup(anh[k]); if (d) { fd.append('anh_' + k, d); } });

    var dds = radio('dinhdang') === 'ca' ? ['excel', 'pdf'] : [radio('dinhdang')];
    var daTai = 0, chuoi = Promise.resolve();
    dds.forEach(function (dd) {
      chuoi = chuoi.then(function () {
        ok.textContent = dds.length > 1 ? 'Đang xuất ' + dd.toUpperCase() + '...' : 'Đang xuất...';
        return taoFile(fd, dd).then(function (kq) {
          luuFile(kq); daTai++;
          return dds.length > 1 && daTai < dds.length ? new Promise(function (xong) { setTimeout(xong, 700); }) : null;
        }).catch(function (err) {
          throw new Error(daTai ? 'Đã tải ' + daTai + ' file nhưng file ' + dd.toUpperCase() + ' bị lỗi: ' + err.message : err.message);
        });
      });
    });
    ok.disabled = true;
    chuoi.then(function () {
      dong();
      if (window.CosmicUI) { window.CosmicUI.toast(dds.length > 1 ? 'Đã xuất báo cáo (Excel và PDF).' : 'Đã xuất báo cáo.', 'thanhcong'); }
    }).catch(function (err) { bao(err.message); })
      .then(function () { ok.disabled = false; ok.textContent = nhanOk; });
  });
})();