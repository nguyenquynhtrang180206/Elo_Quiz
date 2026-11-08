/* Trang Ngân hàng câu hỏi (admin/cauhoi.php). Đặt tại: assets/js/admin/cauhoi.js
   1) Hỏi xác nhận trước nút có thuộc tính data-xac-nhan (giữ từ bản cũ)
   2) Chọn nhiều câu + thanh nổi "Đã chọn N câu hỏi"
   3) Ngăn "Xem nhanh": bấm dòng -> tải chi tiết từ cauhoi_api.php, đổ vào panel bên phải
   Dữ liệu từ máy chủ luôn đổ bằng textContent (không innerHTML) nên không có nguy cơ XSS. */
(function () {
  'use strict';

  // ---------- 1) Xác nhận ----------
  document.addEventListener('click', function (e) {
    var nut = e.target.closest('[data-xac-nhan]');
    if (nut && !confirm(nut.getAttribute('data-xac-nhan'))) { e.preventDefault(); }
  });

  // ---------- Bộ lọc: đổi môn / loại kỳ thi thì lọc ngay ----------
  var formLoc = document.getElementById('chLoc');
  if (formLoc) {
    [].forEach.call(formLoc.querySelectorAll('select'), function (s) {
      s.addEventListener('change', function () { formLoc.submit(); });
    });
  }

  // ---------- 2) Chọn nhiều ----------
  var bang       = document.getElementById('chBang');
  var thanh      = document.getElementById('chThanhChon');
  var soChon     = document.getElementById('chSoChon');
  var chonTatCa  = document.getElementById('chChonTatCa');
  var boChon     = document.getElementById('chBoChon');

  function dsChon() {
    return bang ? [].slice.call(bang.querySelectorAll('.ch-chon:checked')) : [];
  }
  function capNhatChon() {
    if (!bang || !thanh) { return; }
    var n = dsChon().length;
    var tong = bang.querySelectorAll('.ch-chon').length;
    soChon.textContent = n;
    thanh.hidden = n === 0;
    if (chonTatCa) {
      chonTatCa.checked = n > 0 && n === tong;
      chonTatCa.indeterminate = n > 0 && n < tong;
    }
    [].forEach.call(bang.querySelectorAll('.ch-chon'), function (o) {
      o.closest('tr').classList.toggle('dang-chon', o.checked);
    });
  }

  if (bang && thanh) {
    bang.addEventListener('change', function (e) {
      if (e.target.classList.contains('ch-chon')) { capNhatChon(); }
    });
    if (chonTatCa) {
      chonTatCa.addEventListener('change', function () {
        [].forEach.call(bang.querySelectorAll('.ch-chon'), function (o) { o.checked = chonTatCa.checked; });
        capNhatChon();
      });
    }
    boChon.addEventListener('click', function () {
      [].forEach.call(bang.querySelectorAll('.ch-chon'), function (o) { o.checked = false; });
      capNhatChon();
    });
    thanh.addEventListener('submit', function (e) {
      var ds = dsChon();
      if (!ds.length) { e.preventDefault(); return; }
      var hd = e.submitter ? e.submitter.value : '';
      if (hd === 'an_nhieu' && !confirm('Ẩn ' + ds.length + ' câu hỏi đã chọn? Các câu sẽ không được chọn vào trận mới.')) {
        e.preventDefault();
        return;
      }
      if (hd === 'xoa_nhieu' && !confirm('Xóa vĩnh viễn ' + ds.length + ' câu hỏi đã chọn? Câu đã dùng trong trận sẽ bị xóa cả bài làm liên quan (điểm, Elo đã lưu không đổi); câu đang nằm trong trận chưa kết thúc được giữ lại. Không thể hoàn tác.')) {
        e.preventDefault();
        return;
      }
      [].forEach.call(thanh.querySelectorAll('input[name="ids[]"]'), function (x) { x.remove(); });
      ds.forEach(function (o) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = 'ids[]'; h.value = o.value;
        thanh.appendChild(h);
      });
    });
    window.addEventListener('pageshow', capNhatChon);   // trình duyệt có thể khôi phục ô đã tích khi bấm Back
    capNhatChon();
  }

  // ---------- Bộ chọn môn trong bộ lọc: tìm kiếm, hiện 10 môn đầu, "Xem thêm" ----------
  var chonMon = document.getElementById('chChonMon');
  if (chonMon && formLoc) {
    var nutMo   = chonMon.querySelector('.ch-chon-mon-nut');
    var hopMon  = chonMon.querySelector('.ch-chon-mon-hop');
    var oTim    = chonMon.querySelector('.ch-chon-mon-tim');
    var nutThemMon = chonMon.querySelector('.ch-chon-mon-them');
    var rongMon = chonMon.querySelector('.ch-chon-mon-rong');
    var giaTriMon = chonMon.querySelector('input[name="mon"]');
    var dsLi = [].slice.call(hopMon.querySelectorAll('li'));

    // Bỏ dấu để gõ "toan" vẫn ra "Toán", "dai so" ra "Đại số"
    var boDau = function (t) {
      return String(t).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
    };
    var locMon = function () {
      var t = boDau(oTim.value.trim());
      var dem = 0;
      dsLi.forEach(function (li) {
        var ten = li.querySelector('button').getAttribute('data-ten');
        var khop = t === '' || boDau(ten).indexOf(t) !== -1;
        li.classList.toggle('an', !khop);
        if (khop) { dem++; }
      });
      hopMon.classList.toggle('dang-tim', t !== '');
      rongMon.hidden = dem > 0;
      if (nutThemMon) { nutThemMon.hidden = t !== '' || hopMon.classList.contains('mo-het'); }
    };
    var moHop = function (mo) {
      hopMon.hidden = !mo;
      nutMo.setAttribute('aria-expanded', mo ? 'true' : 'false');
      if (mo) {
        hopMon.classList.remove('mo-het');
        oTim.value = '';
        locMon();
        oTim.focus();
      }
    };

    nutMo.addEventListener('click', function () { moHop(hopMon.hidden); });
    oTim.addEventListener('input', locMon);
    oTim.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); }   // Enter trong ô tìm môn không gửi cả form
    });
    if (nutThemMon) {
      nutThemMon.addEventListener('click', function () {
        hopMon.classList.add('mo-het');
        nutThemMon.hidden = true;
      });
    }
    hopMon.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-id]');
      if (!b) { return; }
      giaTriMon.value = b.getAttribute('data-id');
      chonMon.querySelector('.ch-chon-mon-ten').textContent = b.getAttribute('data-ten');
      formLoc.submit();
    });
    document.addEventListener('click', function (e) {
      if (!hopMon.hidden && !chonMon.contains(e.target)) { moHop(false); }
    });
    chonMon.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !hopMon.hidden) { moHop(false); nutMo.focus(); }
    });
  }

  // ---------- 3) Ngăn "Xem nhanh" ----------
  var ngan = document.getElementById('chNgan');
  if (!ngan || !bang) { return; }

  var nen      = document.getElementById('chNen');
  var nutDong  = document.getElementById('chNganDong');
  var tieuDe   = document.getElementById('chNganTieuDe');
  var tai      = document.getElementById('chNganTai');
  var than     = document.getElementById('chNganThan');
  var meta     = document.getElementById('chMeta');
  var noiDung  = document.getElementById('chNoiDung');
  var dapAn    = document.getElementById('chDapAn');
  var gtBox    = document.getElementById('chGiaiThichBox');
  var gt       = document.getElementById('chGiaiThich');
  var ghiChu   = document.getElementById('chGhiChu');
  var xoaNut   = document.getElementById('chXoaNut');
  var nutSua   = document.getElementById('chSua');
  var anId     = document.getElementById('chAnId');
  var anNut    = document.getElementById('chAnNut');

  var dongDangXem = null;
  var tokenTai = 0;              // bỏ qua kết quả cũ nếu admin bấm sang dòng khác khi đang tải
  var LOP_DO_KHO = { 'Dễ': 'pill-de', 'Trung bình': 'pill-tb', 'Khó': 'pill-kho' };
  var CHU_CAI = 'ABCD';

  function tao(tag, lop, chu) {
    var el = document.createElement(tag);
    if (lop) { el.className = lop; }
    if (chu !== undefined && chu !== null) { el.textContent = chu; }
    return el;
  }
  function xoaCon(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }

  function moNgan() {
    nen.classList.add('mo');
    ngan.classList.add('mo');
    ngan.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ch-khoa-cuon');
    nutDong.focus();
  }
  function dongNgan() {
    tokenTai++;
    nen.classList.remove('mo');
    ngan.classList.remove('mo');
    ngan.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ch-khoa-cuon');
    if (dongDangXem) {
      dongDangXem.classList.remove('dang-xem');
      dongDangXem.focus({ preventScroll: true });
      dongDangXem = null;
    }
  }

  function baoLoi(chu) {
    tai.hidden = false;
    tai.className = 'ch-ngan-tai loi-tai';
    tai.textContent = chu;
    than.hidden = true;
  }

  function veChiTiet(d) {
    tieuDe.textContent = 'Câu hỏi #' + d.id;

    // Hàng thông tin: môn, độ khó, trạng thái, loại kỳ thi
    xoaCon(meta);
    var mon = tao('span', 'ch-mon-o');
    mon.appendChild(tao('span', 'ch-ico-mon m' + (d.mamon % 6), (d.tenmon || '?').trim().charAt(0).toUpperCase()));
    mon.appendChild(tao('b', null, d.tenmon));
    meta.appendChild(mon);
    meta.appendChild(tao('span', 'ch-pill ' + (LOP_DO_KHO[d.dokho] || ''), d.dokho));
    meta.appendChild(tao('span', 'ch-pill ' + (d.trangthai === 'Đã ẩn' ? 'pill-an' : 'pill-hien'), d.trangthai));
    meta.appendChild(tao('span', 'nhan-phu', d.loaikt));

    noiDung.textContent = d.noidung;

    // Đáp án A đến D, dòng đúng tô xanh
    xoaCon(dapAn);
    d.dapan.forEach(function (x, i) {
      var li = tao('li', x.dung ? 'dung' : '');
      li.appendChild(tao('span', 'ch-chu-cai', CHU_CAI.charAt(i) || String(i + 1)));
      li.appendChild(tao('span', 'ch-da-nd', x.noidung));
      if (x.dung) { li.appendChild(tao('span', 'ch-nhan-dung', '✓ Đáp án đúng')); }
      dapAn.appendChild(li);
    });

    // Giải thích (nếu có)
    var coGT = d.giaithich && d.giaithich.trim() !== '';
    gtBox.hidden = !coGT;
    gt.textContent = coGT ? d.giaithich : '';

    // Câu đã dùng trong trận: vẫn sửa được (có ghi nhật ký) nhưng không xóa được, chỉ ẩn
    var daDung = d.so_tran > 0;
    ghiChu.hidden = !daDung;
    ghiChu.textContent = daDung
      ? 'Câu hỏi đã dùng trong ' + d.so_tran + ' trận. Vẫn sửa được để chữa lỗi (điểm và Elo của các trận cũ không tính lại), muốn ngừng dùng mà vẫn giữ lịch sử thì bấm Ẩn thay vì Xóa.'
      : '';
    xoaNut.hidden = false;
    xoaNut.setAttribute('data-xac-nhan', daDung
      ? 'Xóa vĩnh viễn câu hỏi này? Câu đã dùng trong ' + d.so_tran + ' trận: bài làm và dòng câu trong các trận đó cũng bị xóa (điểm, Elo đã lưu không đổi). Không thể hoàn tác.'
      : 'Xóa vĩnh viễn câu hỏi này? Không thể hoàn tác.');
    nutSua.textContent = 'Sửa câu hỏi';
    nutSua.href = ngan.getAttribute('data-form') + '?id=' + encodeURIComponent(d.id)
      + '&ve=' + encodeURIComponent(window.location.search.replace(/^\?/, ''));

    // Nút Ẩn / Hiện lại: POST về chính trang này (giữ bộ lọc), đã có CSRF trong form
    anId.value = d.id;
    if (d.trangthai === 'Đã ẩn') {
      anNut.value = 'hien';
      anNut.textContent = 'Hiện lại';
      anNut.removeAttribute('data-xac-nhan');
    } else {
      anNut.value = 'an';
      anNut.textContent = 'Ẩn';
      anNut.setAttribute('data-xac-nhan', 'Ẩn câu hỏi này? Câu sẽ không được chọn vào trận mới.');
    }

    tai.hidden = true;
    than.hidden = false;
    ngan.scrollTop = 0;
  }

  function xemNhanh(id, dong) {
    if (dongDangXem) { dongDangXem.classList.remove('dang-xem'); }
    dongDangXem = dong;
    dong.classList.add('dang-xem');

    tieuDe.textContent = 'Câu hỏi #' + id;
    than.hidden = true;
    tai.hidden = false;
    tai.className = 'ch-ngan-tai';
    tai.textContent = 'Đang tải…';
    moNgan();

    var token = ++tokenTai;
    fetch(ngan.getAttribute('data-api') + '?id=' + encodeURIComponent(id), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, msg: 'Phản hồi không hợp lệ.' }; });
      })
      .then(function (d) {
        if (token !== tokenTai) { return; }
        if (!d.ok) { baoLoi(d.msg || 'Không tải được câu hỏi.'); return; }
        veChiTiet(d);
      })
      .catch(function () {
        if (token === tokenTai) { baoLoi('Không tải được câu hỏi. Hãy kiểm tra kết nối rồi thử lại.'); }
      });
  }

  // Bấm dòng (trừ ô chọn, nút, liên kết) hoặc Enter/Space khi dòng đang được focus
  bang.addEventListener('click', function (e) {
    if (e.target.closest('a, button, input, label, select, form')) { return; }
    var dong = e.target.closest('tr.ch-dong');
    if (dong) { xemNhanh(dong.getAttribute('data-id'), dong); }
  });
  bang.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('ch-dong')) {
      e.preventDefault();
      xemNhanh(e.target.getAttribute('data-id'), e.target);
    }
  });

  nutDong.addEventListener('click', dongNgan);
  nen.addEventListener('click', dongNgan);

  // Esc để đóng; Tab quay vòng trong panel
  document.addEventListener('keydown', function (e) {
    if (!ngan.classList.contains('mo')) { return; }
    if (e.key === 'Escape') { dongNgan(); return; }
    if (e.key !== 'Tab') { return; }
    var ds = [].filter.call(ngan.querySelectorAll('a[href], button:not([disabled])'), function (el) {
      return el.offsetParent !== null;
    });
    if (!ds.length) { return; }
    var dau = ds[0], cuoi = ds[ds.length - 1];
    if (e.shiftKey && document.activeElement === dau) { e.preventDefault(); cuoi.focus(); }
    else if (!e.shiftKey && document.activeElement === cuoi) { e.preventDefault(); dau.focus(); }
  });
})();

// ---------- Biểu đồ phân bố tỉ lệ đúng (Chart.js, assets/vendor/chart.umd.js) ----------
(function () {
  'use strict';
  var cv = document.getElementById('chBieuDo');
  if (!cv || typeof Chart === 'undefined') { return; }
  var nhan, so, mau;
  try {
    nhan = JSON.parse(cv.getAttribute('data-nhan'));
    so   = JSON.parse(cv.getAttribute('data-so'));
    mau  = JSON.parse(cv.getAttribute('data-mau'));
  } catch (err) { return; }
  new Chart(cv, {
    type: 'bar',
    data: { labels: nhan, datasets: [{ data: so, backgroundColor: mau, borderRadius: 5, maxBarThickness: 28 }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: function (c) { return c.parsed.y + ' câu'; } } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#6b7694', font: { size: 10 }, maxRotation: 0, autoSkip: false,
             callback: function (v) { return String(this.getLabelForValue(v)).replace('%', '').split('–')[0]; } } },
        y: { beginAtZero: true, ticks: { precision: 0, color: '#6b7694', font: { size: 11 } }, grid: { color: '#e6eaf7' } }
      }
    }
  });
})();


// ---------- Ghi chú cách đọc biểu đồ: bấm nút i để mở / đóng (cho màn hình cảm ứng, không có rê chuột) ----------
(function () {
  'use strict';
  var gc = document.querySelector('.ch-ghichu');
  if (!gc) { return; }
  var nut = gc.querySelector('.ch-ghichu-nut');
  function dat(mo) { gc.classList.toggle('mo', mo); nut.setAttribute('aria-expanded', mo ? 'true' : 'false'); }
  nut.addEventListener('click', function (e) { e.stopPropagation(); dat(!gc.classList.contains('mo')); });
  document.addEventListener('click', function (e) { if (!gc.contains(e.target)) { dat(false); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { dat(false); } });
})();


// ---------- Hộp thoại Xuất (Excel / PDF) có khung XEM TRƯỚC, gửi tới cauhoi_xuat.php rồi tải file về ----------
// Xem trước: hỏi máy chủ (buoc=xemtruoc) số liệu của phạm vi đã chọn, rồi dựng từng thẻ đúng theo nội dung + định dạng đang tích
// (mỗi thẻ có nhãn cho biết thành sheet / trang nào trong file). Biểu đồ vẽ bằng ĐÚNG cấu hình Chart.js của biểu đồ trên trang;
// khi xuất, chính biểu đồ đó được vẽ lại thành ảnh JPEG rồi gửi kèm để chèn vào Excel / PDF.
(function () {
  'use strict';
  var nutMo = document.getElementById('cxMo');
  var hop = document.getElementById('cxHop');
  var form = document.getElementById('cxForm');
  if (!nutMo || !hop || !form) { return; }
  var loi = document.getElementById('cxLoi');
  var nutXuat = document.getElementById('cxXuat');
  var radioChon = document.getElementById('cxChon');
  var soChon = document.getElementById('cxSoChon');
  var hieuChinh = document.getElementById('cxHieuChinh');
  var bando = document.getElementById('cxBando');
  var xem = document.getElementById('cxXem');
  var xemGhiChu = document.getElementById('cxXemGhiChu');

  var cache = { khoa: '', du: null };   // số liệu xem trước theo phạm vi
  var luot = 0;                          // chống kết quả cũ ghi đè kết quả mới khi bấm nhanh
  var bieuDoXem = [];                    // biểu đồ Chart.js đang có trong khung xem trước

  function bao(msg) { loi.textContent = msg; loi.hidden = !msg; }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function cacCauDaChon() {
    return Array.prototype.map.call(document.querySelectorAll('.ch-chon:checked'), function (c) { return c.value; });
  }
  function radio(ten) { var r = form.querySelector('input[name="' + ten + '"]:checked'); return r ? r.value : ''; }
  function daTich(giaTri) { var i = form.querySelector('input[name="noidung[]"][value="' + giaTri + '"]'); return !!(i && i.checked); }

  // ---------- Vẽ biểu đồ giống hệt trên trang ----------
  function cauHinhBieuDo(d, co, tt) {
    return {
      type: 'bar',
      data: { labels: d.nhan, datasets: [{ data: d.so, backgroundColor: d.mau, borderRadius: 5, maxBarThickness: 28 }] },
      options: {
        responsive: false, animation: false, devicePixelRatio: 2, events: [],
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#6b7694', font: { size: co }, maxRotation: 0, autoSkip: false,
               callback: function (v) { return String(this.getLabelForValue(v)).replace('%', '').split('–')[0]; } } },
          y: { beginAtZero: true, ticks: { precision: 0, color: '#6b7694', font: { size: co + 1 } }, grid: { color: '#e6eaf7' } }
        }
      }
    };
  }
  function hinhBo(g, x, y, w, h, r) {
    g.beginPath();
    g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r);
    g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath();
  }
  // Ảnh hoàn chỉnh để chèn vào file: thẻ + tiêu đề + biểu đồ + chú thích màu (giống khối biểu đồ trên trang)
  function veAnhBieuDo(d) {
    var W = 900, H = 372, S = 2;
    var cb = document.createElement('canvas');
    cb.width = 860; cb.height = 270;
    var bd = new Chart(cb, cauHinhBieuDo(d, 11));
    var cv = document.createElement('canvas');
    cv.width = W * S; cv.height = H * S;
    var g = cv.getContext('2d');
    g.scale(S, S);
    var fam = '"Segoe UI", Roboto, Helvetica, Arial, sans-serif';
    g.fillStyle = '#ffffff'; g.fillRect(0, 0, W, H);
    hinhBo(g, 1, 1, W - 2, H - 2, 16);
    g.fillStyle = '#fafbff'; g.fill();
    g.lineWidth = 1; g.strokeStyle = '#e2e7f6'; g.stroke();
    g.textBaseline = 'alphabetic';
    g.fillStyle = '#15204a'; g.font = '700 17px ' + fam; g.textAlign = 'left';
    g.fillText('Phân bố tỉ lệ đúng', 20, 34);
    g.fillStyle = '#6b7694'; g.font = '400 14px ' + fam; g.textAlign = 'right';
    g.fillText(d.phu, W - 20, 34);
    g.textAlign = 'left';
    g.drawImage(cb, 20, 50, 860, 270);
    var muc = [['#fddde2', 'Khó (< ' + d.nguongKho + '%)'], ['#fdecc4', 'Trung bình'], ['#a9e5c8', 'Dễ (≥ ' + d.nguongDe + '%)']];
    var x = 22, y = H - 24;
    g.font = '400 13px ' + fam;
    muc.forEach(function (m) {
      hinhBo(g, x, y - 11, 13, 13, 4); g.fillStyle = m[0]; g.fill();
      g.fillStyle = '#6b7694'; g.fillText(m[1], x + 20, y);
      x += 20 + g.measureText(m[1]).width + 22;
    });
    bd.destroy();
    return cv.toDataURL('image/jpeg', 0.95);
  }

  // ---------- Gọi máy chủ ----------
  function goi(fd) { return fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' }); }
  function docLoi(r) { return r.text().then(function (t) { throw new Error(t || 'Không xuất được file.'); }); }
  function khoaPhamVi() { var pv = radio('pham_vi'); return pv === 'chon' ? 'chon:' + cacCauDaChon().join(',') : pv; }

  // Lấy số liệu xem trước của phạm vi đang chọn (dùng lại bản đã tải nếu phạm vi không đổi)
  function layDuLieu() {
    var k = khoaPhamVi();
    if (cache.du && cache.khoa === k) { return Promise.resolve(cache.du); }
    var f2 = new FormData(form);
    f2.delete('noidung[]'); f2.append('noidung[]', 'danhsach');
    if (radio('pham_vi') === 'chon') { cacCauDaChon().forEach(function (id) { f2.append('ids[]', id); }); }
    f2.set('buoc', 'xemtruoc');
    f2.set('dinhdang', 'excel');   // xem trước không phụ thuộc định dạng ("Cả hai" không phải giá trị hợp lệ khi gửi lên máy chủ)
    return goi(f2).then(function (r) { return r.ok ? r.json() : docLoi(r); }).then(function (d) {
      cache = { khoa: k, du: d };
      return d;
    });
  }

  // ---------- Dựng khung xem trước ----------
  function xoaXem() {
    bieuDoXem.forEach(function (c) { c.destroy(); });
    bieuDoXem = [];
    xem.textContent = '';
  }
  function nhanThe(excelTen, pdfTen) {
    var dd = radio('dinhdang');
    if (dd === 'excel') { return 'Sheet "' + excelTen + '"'; }
    if (dd === 'pdf') { return pdfTen; }
    return 'Sheet + PDF';
  }
  function the(tieuDe, phu, nhan) {
    var t = el('section', 'xuat-the');
    var dau = el('div', 'xuat-the-dau');
    var tt = el('div', 'xuat-the-tieude');
    tt.appendChild(el('b', '', tieuDe));
    if (phu) { tt.appendChild(el('small', '', phu)); }
    dau.appendChild(tt);
    dau.appendChild(el('span', 'xuat-nhan', nhan));
    t.appendChild(dau);
    return t;
  }
  function oSo(cac) {
    var h = el('div', 'xuat-so');
    cac.forEach(function (c) {
      var o = el('div', 'xuat-so-o');
      o.appendChild(el('strong', '', String(c[1])));
      o.appendChild(el('span', '', c[0]));
      h.appendChild(o);
    });
    return h;
  }
  function bang(tieuDe, dong, canGiua) {
    var cuon = el('div', 'cx-bang-cuon');
    var tb = el('table', 'cx-bang');
    var tr = el('tr');
    tieuDe.forEach(function (t, i) { var th = el('th', canGiua.indexOf(i) >= 0 ? 'giua' : '', t); tr.appendChild(th); });
    var th0 = el('thead'); th0.appendChild(tr); tb.appendChild(th0);
    var tbody = el('tbody');
    dong.forEach(function (d) {
      var r = el('tr');
      d.forEach(function (o, i) {
        var cls = canGiua.indexOf(i) >= 0 ? 'giua' : '';
        var td;
        if (o && typeof o === 'object') { td = el('td', (cls + ' ' + (o.cls || '')).trim(), o.t); }
        else { td = el('td', cls, o === null || o === undefined ? '' : String(o)); }
        r.appendChild(td);
      });
      tbody.appendChild(r);
    });
    tb.appendChild(tbody);
    cuon.appendChild(tb);
    return cuon;
  }
  function tyChu(c) {
    if (c.luot === 0) { return 'Chưa có lượt'; }
    return c.ty + '% (' + c.dung + '/' + c.luot + ')' + (c.du ? '' : ' · chưa đủ dữ liệu');
  }
  function nhanDoKho(c) { return { t: c.doKho, cls: c.doKho === 'Dễ' ? 'dk-de' : (c.doKho === 'Khó' ? 'dk-kho' : 'dk-tb') }; }

  function theDanhSach(d) {
    var t = the('Danh sách câu hỏi', d.tong + ' câu, mỗi câu một dòng', nhanThe('Câu hỏi', 'PDF · danh sách'));
    if (!d.cau.length) { t.appendChild(el('p', 'xuat-ghichu', 'Không có câu hỏi nào trong phạm vi này.')); return t; }
    t.appendChild(bang(['Mã', 'Môn', 'Độ khó', 'Nội dung', 'Đáp án đúng', 'Tỉ lệ đúng'],
      d.cau.map(function (c) {
        return ['#' + c.ma, c.mon, nhanDoKho(c), c.nd, c.dapAn, tyChu(c) + (c.lech ? ' → ' + c.goiY : '')];
      }), []));
    var them = d.tong - d.cau.length;
    t.appendChild(el('p', 'xuat-ghichu', (them > 0 ? 'Còn ' + them + ' câu nữa. ' : '') + 'Trong file còn có phương án A–D, giải thích, số trận đã dùng, trạng thái.'));
    return t;
  }
  function theHieuChinh(d) {
    var coBD = daTich('bando');
    var t = the('Báo cáo hiệu chỉnh độ khó', 'Tính trên ' + d.tong + ' câu hỏi', nhanThe('Hiệu chỉnh độ khó', 'PDF · trang báo cáo'));
    var so = [['Tổng số câu', d.tong], ['Đủ dữ liệu (≥ ' + d.minLuot + ' lượt)', d.duDL], ['Câu lệch độ khó', d.soLech]];
    if (coBD) { so.push(['Ô thiếu trên bản đồ', d.oThieu]); }
    t.appendChild(oSo(so));
    if (d.duDL > 0 && typeof Chart !== 'undefined') {
      var khung = el('div', 'cx-bd');
      var dau = el('div', 'cx-bd-dau');
      dau.appendChild(el('b', '', 'Phân bố tỉ lệ đúng'));
      dau.appendChild(el('span', '', d.phu));
      khung.appendChild(dau);
      var cv = document.createElement('canvas');
      cv.width = 640; cv.height = 220; cv.className = 'xuat-cv';
      khung.appendChild(cv);
      var ct = el('p', 'cx-chuthich');
      [['o-do', 'Khó (< ' + d.nguongKho + '%)'], ['o-vang', 'Trung bình'], ['o-xanh', 'Dễ (≥ ' + d.nguongDe + '%)']].forEach(function (m) {
        ct.appendChild(el('i', m[0])); ct.appendChild(document.createTextNode(' ' + m[1] + ' '));
      });
      khung.appendChild(ct);
      t.appendChild(khung);
      var bd = new Chart(cv, cauHinhBieuDo(d, 10));
      cv.style.width = '100%'; cv.style.height = 'auto';
      bieuDoXem.push(bd);
    } else {
      t.appendChild(el('p', 'xuat-ghichu', 'Chưa có câu nào đủ ' + d.minLuot + ' lượt trả lời nên chưa vẽ được biểu đồ.'));
    }
    t.appendChild(el('p', 'xuat-ghichu', 'Kèm bảng số câu theo 10 khoảng tỉ lệ đúng.'));
    return t;
  }
  function theCauLech(d) {
    var t = the('Câu lệch độ khó', d.soLech + ' câu, xếp từ lệch nhiều đến ít', nhanThe('Câu lệch độ khó', 'PDF · trang báo cáo'));
    if (!d.lech.length) { t.appendChild(el('p', 'xuat-ghichu', 'Không có câu nào lệch độ khó trong phạm vi này.')); return t; }
    t.appendChild(bang(['Mã', 'Nội dung', 'Đang gắn', 'Tỉ lệ đúng', 'Nên đổi thành'],
      d.lech.map(function (c) { return ['#' + c.ma, c.nd, nhanDoKho(c), tyChu(c), c.goiY]; }), []));
    var them = d.soLech - d.lech.length;
    if (them > 0) { t.appendChild(el('p', 'xuat-ghichu', 'Còn ' + them + ' câu nữa trong file.')); }
    return t;
  }
  function theBanDo(d) {
    var t = the('Bản đồ phủ câu hỏi', d.banDoLoc ? 'Chỉ môn đang lọc: ' + d.tenMon : 'Tất cả môn đang mở · ' + d.soMon + ' môn',
      nhanThe('Bản đồ phủ', 'PDF · ' + (daTich('hieuchinh') ? 'trang báo cáo' : 'bản đồ phủ')));
    if (!d.bando.length) { t.appendChild(el('p', 'xuat-ghichu', 'Chưa có môn học nào đang mở.')); return t; }
    var dong = d.bando.slice(0, 8).map(function (m) {
      return [m.ten, { t: String(m.de), cls: 'o' + m.b[0] }, { t: String(m.tb), cls: 'o' + m.b[1] }, { t: String(m.kho), cls: 'o' + m.b[2] }, String(m.thieu)];
    });
    t.appendChild(bang(['Môn học', 'Dễ', 'Trung bình', 'Khó', 'Còn thiếu'], dong, [1, 2, 3, 4]));
    var ct = el('p', 'cx-chuthich');
    [['o-do', 'dưới ' + d.nguongDo], ['o-vang', d.nguongDo + '–' + (d.phuDu - 1)], ['o-xanh-nhat', d.phuDu + '–' + (d.phuTot - 1)], ['o-xanh', '≥ ' + d.phuTot]].forEach(function (m) {
      ct.appendChild(el('i', m[0])); ct.appendChild(document.createTextNode(' ' + m[1] + ' câu '));
    });
    t.appendChild(ct);
    var them = d.soMon - dong.length;
    t.appendChild(el('p', 'xuat-ghichu', (them > 0 ? 'Còn ' + them + ' môn nữa trong file, môn thiếu nhiều nhất xếp trước. ' : '') + 'Mỗi môn cần tối thiểu ' + d.phuDu + ' câu cho mỗi độ khó.'));
    return t;
  }

  function veXem(d) {
    xoaXem();
    var coDS = daTich('danhsach'), coHC = daTich('hieuchinh'), coBD = daTich('bando');
    xemGhiChu.textContent = '· ' + d.tong + ' câu trong phạm vi';
    if (!coDS && !coHC && !coBD) {
      xem.appendChild(el('p', 'xuat-ghichu', 'Hãy chọn ít nhất một nội dung để xem trước.'));
      return;
    }
    var dd = radio('dinhdang');
    if (dd === 'ca') { xem.appendChild(el('p', 'xuat-ghichu', 'Định dạng "Cả hai": tải về hai file, Excel rồi PDF. Mỗi thẻ cho biết thành sheet nào và nằm ở PDF.')); }
    if (coDS) { xem.appendChild(theDanhSach(d)); }
    if (coHC) { xem.appendChild(theHieuChinh(d)); xem.appendChild(theCauLech(d)); }
    if (coBD) { xem.appendChild(theBanDo(d)); }
  }

  function capNhatXem() {
    var mine = ++luot;
    bao('');
    if (!cache.du || cache.khoa !== khoaPhamVi()) {
      xoaXem(); xem.appendChild(el('p', 'xuat-ghichu', 'Đang tải xem trước…'));
    }
    layDuLieu().then(function (d) {
      if (mine !== luot) { return; }
      veXem(d);
    }).catch(function (err) {
      if (mine !== luot) { return; }
      xoaXem();
      xem.appendChild(el('p', 'xuat-ghichu', err.message === 'Failed to fetch' || err instanceof SyntaxError
        ? 'Không tải được xem trước (có thể phiên đăng nhập đã hết).' : err.message));
    });
  }
  form.addEventListener('change', capNhatXem);

  function mo() {
    var ids = cacCauDaChon();
    radioChon.disabled = ids.length === 0;
    soChon.textContent = ids.length ? '(' + ids.length + ' câu đang được tích)' : '(chưa tích câu nào)';
    if (ids.length) { radioChon.checked = true; }
    else if (radio('pham_vi') === 'chon') { form.querySelector('input[name="pham_vi"][value="loc"]').checked = true; }
    cache = { khoa: '', du: null };   // luôn lấy số liệu mới mỗi lần mở
    bao('');
    hop.hidden = false;
    capNhatXem();
  }
  function dong() { hop.hidden = true; xoaXem(); }
  nutMo.addEventListener('click', mo);
  document.getElementById('cxHuy').addEventListener('click', dong);
  hop.addEventListener('click', function (e) { if (e.target === hop) { dong(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !hop.hidden) { dong(); } });

  // ---------- Xuất file ----------
  function taoFile(fd, dinhDang) {
    fd.set('dinhdang', dinhDang);
    return goi(fd).then(function (r) {
      if (!r.ok) { return docLoi(r); }
      var m = /filename="?([^";]+)"?/.exec(r.headers.get('Content-Disposition') || '');
      return r.blob().then(function (b) { return { blob: b, ten: m ? m[1] : 'ngan_hang_cau_hoi' }; });
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
    if (!daTich('danhsach') && !daTich('hieuchinh') && !daTich('bando')) { bao('Hãy chọn ít nhất một nội dung cần xuất.'); return; }
    var fd = new FormData(form);
    if (radio('pham_vi') === 'chon') {
      var ids = cacCauDaChon();
      if (!ids.length) { bao('Chưa tích chọn câu hỏi nào trong bảng.'); return; }
      ids.forEach(function (id) { fd.append('ids[]', id); });
    }
    var dds = radio('dinhdang') === 'ca' ? ['excel', 'pdf'] : [radio('dinhdang')];
    nutXuat.disabled = true; nutXuat.textContent = 'Đang xuất…';
    var anhP = (daTich('hieuchinh') && typeof Chart !== 'undefined') ? layDuLieu() : Promise.resolve(null);
    anhP.then(function (d) {
      if (d && d.duDL > 0) { fd.set('anh_phanbo', veAnhBieuDo(d)); }
      // "Cả hai" = tải liên tiếp hai file riêng (Excel rồi PDF), không nén zip
      return dds.reduce(function (truoc, dd) {
        return truoc.then(function () { return taoFile(fd, dd).then(luuFile); });
      }, Promise.resolve());
    }).then(function () {
      dong();
    }).catch(function (err) {
      bao(err.message === 'Failed to fetch' ? 'Không kết nối được máy chủ (có thể phiên đăng nhập đã hết).' : err.message);
    }).then(function () {
      nutXuat.disabled = false; nutXuat.textContent = 'Xuất file';
    });
  });
})();