// Trang Nhật ký quản trị: bấm nút "…" ở cuối dòng để xem chi tiết bản ghi (chỉ xem, không sửa/xóa: A-42).
// Dữ liệu nằm trong thuộc tính data-nk (JSON do PHP tạo); gán vào hộp bằng textContent nên không chèn HTML.
(function () {
  'use strict';
  var hop = document.getElementById('nkChiTiet');
  if (!hop || typeof hop.showModal !== 'function') { return; }

  document.addEventListener('click', function (ev) {
    var nut = ev.target.closest ? ev.target.closest('.nk-more') : null;
    if (nut) {
      var d;
      try { d = JSON.parse(nut.getAttribute('data-nk') || '{}'); } catch (e) { return; }
      hop.querySelectorAll('[data-f]').forEach(function (el) {
        var v = d[el.getAttribute('data-f')];
        el.textContent = (v === undefined || v === null || v === '') ? '—' : String(v);
      });
      var tt = hop.querySelector('.nk-tt[data-f="tt"]');
      if (tt) { tt.className = 'nk-tt ' + (d.tt === 'Thất bại' ? 'nk-tt-loi' : 'nk-tt-ok'); }
      hop.showModal();
      return;
    }
    // Đóng: bấm nút ×, hoặc bấm ra vùng nền tối (target chính là thẻ dialog)
    if (ev.target === hop || (ev.target.closest && ev.target.closest('[data-dong]'))) {
      hop.close();
    }
  });
})();

// ---------- Hộp thoại Xuất (Excel / PDF) có khung XEM TRƯỚC, gửi tới nhatky_xuat.php rồi tải file về ----------
// Cột trái: chọn phạm vi, trường (cột) và định dạng. Cột phải: xem trước đúng theo các trường đang tích.
// Số liệu xem trước lấy từ máy chủ (buoc=xemtruoc) một lần cho mỗi phạm vi; đổi trường hoặc định dạng chỉ vẽ lại, không gọi lại.
// Định dạng "Cả hai": gọi máy chủ lần lượt hai lần (Excel rồi PDF) và tải về liên tiếp hai file (không nén zip).
(function () {
  'use strict';
  var nutMo = document.getElementById('nkMoXuat');
  var hop = document.getElementById('nkXuatHop');
  var form = document.getElementById('nkXuatForm');
  if (!nutMo || !hop || !form) { return; }
  var xem = document.getElementById('nkXem');
  var xemGhiChu = document.getElementById('nkXemGhiChu');
  var loi = document.getElementById('nkXuatLoi');
  var nutOk = document.getElementById('nkXuatOk');

  // Khớp với $COT trong nhatky_xuat.php (khóa, tiêu đề cột)
  var COT = [
    ['tg', 'Thời gian'], ['hd', 'Hành động'], ['dt', 'Đối tượng'], ['mota', 'Mô tả'], ['tt', 'Trạng thái']
  ];

  var cache = { khoa: '', du: null };   // số liệu xem trước theo phạm vi
  var luot = 0;                          // chống kết quả cũ ghi đè kết quả mới khi bấm nhanh

  function bao(msg) { loi.textContent = msg; loi.hidden = !msg; }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function radio(ten) { var r = form.querySelector('input[name="' + ten + '"]:checked'); return r ? r.value : ''; }
  function truongChon() {
    return Array.prototype.map.call(form.querySelectorAll('input[name="truong[]"]:checked'), function (i) { return i.value; });
  }
  function so(n) { return Number(n).toLocaleString('vi-VN'); }
  function datTatCa(giaTri) {
    form.querySelectorAll('input[name="truong[]"]').forEach(function (i) { i.checked = giaTri; });
    capNhatXem();
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
  function nhanThe() {
    var dd = radio('dinhdang');
    if (dd === 'excel') { return 'Sheet "Nhật ký quản trị"'; }
    if (dd === 'pdf') { return 'PDF ngang'; }
    return 'Sheet + PDF ngang';
  }
  function oSo(nhan, giaTri) {
    var o = el('div', 'xuat-so-o');
    o.appendChild(el('strong', '', giaTri));
    o.appendChild(el('span', '', nhan));
    return o;
  }
  function veXem(d) {
    xem.textContent = '';
    xemGhiChu.textContent = '· ' + so(d.tong) + ' bản ghi trong phạm vi';
    var tr = truongChon();
    if (!d.tong) { xem.appendChild(el('p', 'xuat-ghichu', 'Không có bản ghi nhật ký nào trong phạm vi này.')); return; }
    if (!tr.length) { xem.appendChild(el('p', 'xuat-ghichu', 'Hãy chọn ít nhất một trường để xem trước.')); return; }
    if (radio('dinhdang') === 'ca') {
      xem.appendChild(el('p', 'xuat-ghichu', 'Định dạng "Cả hai": tải về hai file, Excel rồi PDF.'));
    }

    var the = el('section', 'xuat-the');
    var dau = el('div', 'xuat-the-dau');
    var t = el('div', 'xuat-the-tieude');
    t.appendChild(el('b', '', 'Nhật ký quản trị'));
    t.appendChild(el('small', '', d.phamVi + ' · xếp ' + d.sapXep));
    dau.appendChild(t);
    dau.appendChild(el('span', 'xuat-nhan', nhanThe()));
    the.appendChild(dau);

    var soLieu = el('div', 'xuat-so');
    soLieu.appendChild(oSo('Tổng số bản ghi', so(d.tong)));
    soLieu.appendChild(oSo('Thành công', so(d.thanhCong)));
    soLieu.appendChild(oSo('Thất bại', so(d.thatBai)));
    the.appendChild(soLieu);

    var cuon = el('div', 'nk-xb-cuon');
    var bang = el('table', 'nk-xb');
    var tHead = el('thead'), hTr = el('tr');
    var cotHien = COT.filter(function (c) { return tr.indexOf(c[0]) >= 0; });
    cotHien.forEach(function (c) { hTr.appendChild(el('th', '', c[1])); });
    tHead.appendChild(hTr); bang.appendChild(tHead);
    var tBody = el('tbody');
    d.dong.forEach(function (r) {
      var row = el('tr');
      cotHien.forEach(function (c) {
        var td = el('td');
        var v = r[c[0]];
        if (c[0] === 'tt') {
          td.appendChild(el('span', 'nk-xb-tt ' + (v === 'Thất bại' ? 'nk-xb-loi' : 'nk-xb-ok'), v));
        } else {
          td.textContent = v === '' ? '—' : v;
          if (c[0] === 'tg') { td.className = 'nk-xb-nowrap'; }
        }
        row.appendChild(td);
      });
      tBody.appendChild(row);
    });
    bang.appendChild(tBody);
    cuon.appendChild(bang);
    the.appendChild(cuon);

    var them = d.tong - d.dong.length;
    the.appendChild(el('p', 'xuat-ghichu', (them > 0
      ? 'Đang xem ' + so(d.dong.length) + ' bản ghi đầu tiên, còn ' + so(them) + ' bản ghi nữa trong file. '
      : 'Hiển thị đủ ' + so(d.dong.length) + ' bản ghi. ')
      + 'Thứ tự cột trong file giống bảng trên.'));

    var dd = radio('dinhdang');
    if ((dd !== 'excel' && d.tong > d.toiDaPdf) || (dd !== 'pdf' && d.tong > d.toiDaXlsx)) {
      var gh = dd !== 'excel' && d.tong > d.toiDaPdf ? d.toiDaPdf : d.toiDaXlsx;
      the.appendChild(el('p', 'xuat-canhbao', 'Phạm vi này vượt giới hạn ' + so(gh) + ' bản ghi của file '
        + (dd !== 'excel' && d.tong > d.toiDaPdf ? 'PDF' : 'Excel') + '. Hãy lọc bớt rồi xuất lại.'));
    }
    xem.appendChild(the);
  }

  function capNhatXem() {
    var mine = ++luot;
    bao('');
    if (!cache.du || cache.khoa !== radio('pham_vi')) {
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
  document.getElementById('nkChonHet').addEventListener('click', function () { datTatCa(true); });
  document.getElementById('nkBoChon').addEventListener('click', function () { datTatCa(false); });

  // ---------- Mở / đóng ----------
  function mo() {
    cache = { khoa: '', du: null };   // luôn lấy số liệu mới mỗi lần mở
    bao('');
    hop.hidden = false;
    capNhatXem();
  }
  function dong() { hop.hidden = true; xem.textContent = ''; }
  nutMo.addEventListener('click', mo);
  document.getElementById('nkXuatHuy').addEventListener('click', dong);
  hop.addEventListener('click', function (e) { if (e.target === hop) { dong(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !hop.hidden) { dong(); } });

  // ---------- Xuất file ----------
  function taoFile(dinhDang) {
    var fd = new FormData(form);
    fd.set('dinhdang', dinhDang);
    return goi(fd).then(function (r) {
      if (!r.ok) { return docLoi(r); }
      var m = /filename="?([^";]+)"?/.exec(r.headers.get('Content-Disposition') || '');
      return r.blob().then(function (b) { return { blob: b, ten: m ? m[1] : 'nhatky_quantri' }; });
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
    if (!truongChon().length) { bao('Hãy chọn ít nhất một trường cần xuất.'); return; }
    var dd = radio('dinhdang');
    var dds = dd === 'ca' ? ['excel', 'pdf'] : [dd];
    nutOk.disabled = true; nutOk.textContent = 'Đang xuất…';
    layDuLieu().then(function (d) {
      // Kiểm tra trước để không tải file Excel rồi mới báo lỗi PDF khi chọn "Cả hai"
      if (!d.tong) { throw new Error('Không có bản ghi nhật ký nào trong phạm vi đã chọn.'); }
      if (dds.indexOf('pdf') >= 0 && d.tong > d.toiDaPdf) { throw new Error('Phạm vi có ' + so(d.tong) + ' bản ghi, vượt giới hạn ' + so(d.toiDaPdf) + ' của file PDF. Hãy lọc bớt rồi xuất lại.'); }
      if (dds.indexOf('excel') >= 0 && d.tong > d.toiDaXlsx) { throw new Error('Phạm vi có ' + so(d.tong) + ' bản ghi, vượt giới hạn ' + so(d.toiDaXlsx) + ' của file Excel. Hãy lọc bớt rồi xuất lại.'); }
      // "Cả hai" = tải liên tiếp hai file riêng (Excel rồi PDF), không nén zip
      return dds.reduce(function (truoc, f) {
        return truoc.then(function () { return taoFile(f).then(luuFile); });
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