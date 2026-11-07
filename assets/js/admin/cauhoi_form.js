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

  // ---------- Thẻ "Độ khó theo môn": Xem thêm / Thu gọn ----------
  var nutMonThem = document.getElementById('chMonThem');
  var luoiMon = document.getElementById('chLuoiMon');
  if (nutMonThem && luoiMon) {
    nutMonThem.addEventListener('click', function () {
      var mo = luoiMon.classList.toggle('mo-het');
      nutMonThem.setAttribute('aria-expanded', mo ? 'true' : 'false');
      nutMonThem.textContent = mo ? 'Thu gọn' : nutMonThem.getAttribute('data-nhan');
    });
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
      ? 'Câu hỏi đã dùng trong ' + d.so_tran + ' trận. Vẫn sửa được để chữa lỗi (điểm và Elo của các trận cũ không tính lại), nhưng không xóa được; muốn ngừng dùng thì bấm Ẩn.'
      : '';
    xoaNut.hidden = daDung;
    nutSua.textContent = 'Sửa câu hỏi';
    nutSua.href = ngan.getAttribute('data-form') + '?id=' + encodeURIComponent(d.id);

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