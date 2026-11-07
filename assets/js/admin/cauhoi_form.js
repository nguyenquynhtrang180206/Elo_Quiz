/* Trang Thêm/Sửa câu hỏi (admin/cauhoi_form.php). Đặt tại: assets/js/admin/cauhoi_form.js
   - Thẻ xem trước cập nhật ngay khi gõ (môn, kỳ thi, hạng theo độ khó, nội dung)
   - Gõ thẳng vào thẻ phương án, bấm vòng tròn bên phải để chọn đáp án đúng
   - Bộ đếm ký tự, danh sách 5 điều kiện + vòng tiến độ
   - "Chạy thử như người chơi" (hộp thoại, không ghi vào CSDL), "Làm mới"
   Dữ liệu người dùng luôn đổ bằng textContent (không innerHTML) nên không có nguy cơ XSS.
   Việc kiểm tra thật vẫn nằm ở máy chủ (CauHoiHelper::kiemTra); phần này chỉ để báo sớm. */
(function () {
  'use strict';

  var form = document.getElementById('cfForm');
  if (!form) { return; }

  var HANG = {
    'Dễ':         { ten: 'Hạng Đồng', lop: 'h-de' },
    'Trung bình': { ten: 'Hạng Bạc',  lop: 'h-tb' },
    'Khó':        { ten: 'Hạng Vàng', lop: 'h-kho' }
  };
  var MAC_DINH_THONGBAO = 'Cần ít nhất 2 phương án và một đáp án đúng để chạy thử.';

  var laMoi   = form.getAttribute('data-moi') === '1';
  var daDung  = form.getAttribute('data-dadung') === '1';
  var soO     = parseInt(form.getAttribute('data-so-o'), 10) || 4;
  var toiDaND = parseInt(form.getAttribute('data-toida-nd'), 10) || 2000;
  var toiDaGT = parseInt(form.getAttribute('data-toida-gt'), 10) || 2000;

  function $(id) { return document.getElementById(id); }
  var selMon = $('mon'), selLoai = $('loai'), taND = $('noidung'), taGT = $('giaithich');
  var dapIn  = [].slice.call(form.querySelectorAll('.cf-dap input[type=text]'));
  var dungIn = [].slice.call(form.querySelectorAll('input[name=dung]'));
  var theDap = [].slice.call(form.querySelectorAll('.cf-dap'));
  var thongBao = $('cfThongBao');
  var daSua = false;   // đã có thay đổi so với lúc mở trang (để hỏi trước khi "Làm mới")

  function txt(s) { return String(s || '').replace(/\r\n?/g, '\n').trim(); }
  function giaTriKho() {
    var r = form.querySelector('input[name=dokho]:checked');
    return r ? r.value : 'Trung bình';
  }
  function tenMon() {
    if (!selMon || selMon.value === '0' || selMon.selectedIndex < 0) { return ''; }
    return selMon.options[selMon.selectedIndex].text;
  }
  function tenKyThi() {
    if (!selLoai || selLoai.value === '' || selLoai.selectedIndex < 0) { return 'Cả hai kỳ'; }
    return selLoai.options[selLoai.selectedIndex].text;
  }
  function chiSoDung() {
    for (var i = 0; i < dungIn.length; i++) { if (dungIn[i].checked) { return i; } }
    return -1;
  }
  // Các phương án có chữ, giữ thứ tự (giống cách máy chủ bỏ ô trống)
  function dapAnCoChu() {
    var kq = [];
    dapIn.forEach(function (o, i) {
      var t = txt(o.value);
      if (t !== '') { kq.push({ goc: i, chu: t }); }
    });
    return kq;
  }

  function tinhDieuKien() {
    var ds = dapAnCoChu();
    var thuong = ds.map(function (d) { return d.chu.toLowerCase(); });
    var trung = thuong.some(function (t, i) { return thuong.indexOf(t) !== i; });
    var iDung = chiSoDung();
    return {
      mon:  daDung || (selMon && selMon.value !== '0'),
      nd:   txt(taND.value) !== '',
      pa:   daDung ? ds.length === soO : ds.length >= 2,
      khac: ds.length >= 2 && !trung,
      dung: iDung >= 0 && txt(dapIn[iDung].value) !== ''
    };
  }

  function capNhat() {
    // ----- Thẻ xem trước -----
    $('cfXemMon').textContent = (tenMon() || 'Chưa chọn môn') + ' · ' + tenKyThi();
    var h = HANG[giaTriKho()] || HANG['Trung bình'];
    var nhanHang = $('cfXemHang');
    nhanHang.className = 'cf-hang ' + h.lop;
    $('cfXemHangTen').textContent = h.ten;
    var nd = txt(taND.value);
    var cau = $('cfXemCau');
    cau.textContent = nd !== '' ? nd : 'Nội dung câu hỏi sẽ hiện ở đây';
    cau.classList.toggle('trong', nd === '');

    var iDung = chiSoDung();
    theDap.forEach(function (the, i) { the.classList.toggle('dung', i === iDung); });

    // ----- Bộ đếm ký tự -----
    var dND = $('cfDemND'), dGT = $('cfDemGT');
    dND.textContent = taND.value.length + '/' + toiDaND;
    dGT.textContent = taGT.value.length + '/' + toiDaGT;
    dND.classList.toggle('gan-het', taND.value.length > toiDaND * 0.9);
    dGT.classList.toggle('gan-het', taGT.value.length > toiDaGT * 0.9);

    // ----- Điều kiện + vòng tiến độ -----
    var dk = tinhDieuKien(), dat = 0;
    [].forEach.call($('cfKt').querySelectorAll('li'), function (li) {
      var ok = !!dk[li.getAttribute('data-k')];
      li.classList.toggle('ok', ok);
      if (ok) { dat++; }
    });
    var vong = $('cfVong');
    vong.style.setProperty('--p', String(dat * 20));
    vong.classList.toggle('xong', dat === 5);
    $('cfVongSo').textContent = dat + '/5';

    // ----- Dòng báo dưới nút -----
    var duDeThu = dk.pa && dk.dung;
    thongBao.textContent = duDeThu ? '' : MAC_DINH_THONGBAO;
    thongBao.classList.remove('loi-luu');
  }

  form.addEventListener('input', function () { daSua = true; capNhat(); });
  form.addEventListener('change', function () { daSua = true; capNhat(); });

  // ---------- Lưu: báo sớm những gì còn thiếu (máy chủ vẫn kiểm tra lại) ----------
  form.addEventListener('submit', function (e) {
    var dk = tinhDieuKien();
    var thieu = [];
    if (!dk.mon)  { thieu.push('chưa chọn môn học'); }
    if (!dk.nd)   { thieu.push('chưa nhập nội dung câu hỏi'); }
    if (!dk.pa)   { thieu.push(daDung ? 'không được để trống phương án nào' : 'cần ít nhất 2 phương án'); }
    else if (!dk.khac) { thieu.push('có hai phương án giống nhau'); }
    if (!dk.dung) { thieu.push('chưa chọn đáp án đúng (hoặc đáp án đúng đang để trống)'); }
    if (thieu.length) {
      e.preventDefault();
      thongBao.textContent = 'Chưa lưu được: ' + thieu.join('; ') + '.';
      thongBao.classList.add('loi-luu');
      thongBao.scrollIntoView({ block: 'center', behavior: 'smooth' });
      return;
    }
    var nut = $('cfLuu');
    if (nut) { nut.disabled = true; }   // tránh bấm hai lần
  });

  // ---------- Làm mới ----------
  $('cfLamMoi').addEventListener('click', function () {
    if (daSua && !confirm(laMoi ? 'Xóa hết những gì đã nhập?' : 'Khôi phục lại nội dung câu hỏi như lúc mở trang?')) { return; }
    if (laMoi) {
      if (selMon && !daDung) { selMon.value = '0'; }
      if (selLoai && !daDung) { selLoai.value = ''; }
      var tb = form.querySelector('input[name=dokho][value="Trung bình"]');
      if (tb) { tb.checked = true; }
      taND.value = '';
      taGT.value = '';
      dapIn.forEach(function (o) { o.value = ''; });
      dungIn.forEach(function (o) { o.checked = false; });
    } else {
      form.reset();
    }
    daSua = false;
    capNhat();
    taND.focus();
  });

  // ---------- Chạy thử như người chơi ----------
  var dlg = $('cfDlg');
  var dlgDap = $('cfDlgDap'), dlgKq = $('cfDlgKq'), dlgGt = $('cfDlgGt');
  var dsThu = [], dungThu = -1;

  function tao(tag, lop, chu) {
    var el = document.createElement(tag);
    if (lop) { el.className = lop; }
    if (chu !== undefined) { el.textContent = chu; }
    return el;
  }

  function dungLaiHop() {
    dlgDap.textContent = '';
    dlgKq.textContent = '';
    dlgKq.className = 'cf-dlg-kq';
    dlgGt.hidden = true;
    dsThu.forEach(function (d, i) {
      var nut = tao('button', 'cf-dlg-pa');
      nut.type = 'button';
      nut.appendChild(tao('span', 'cf-chu', String.fromCharCode(65 + i)));
      nut.appendChild(tao('span', 'cf-dlg-chu', d.chu));
      nut.addEventListener('click', function () { traLoi(i, nut); });
      dlgDap.appendChild(nut);
    });
  }

  function traLoi(i, nut) {
    var cacNut = [].slice.call(dlgDap.querySelectorAll('.cf-dlg-pa'));
    cacNut.forEach(function (n) { n.disabled = true; });
    cacNut[dungThu].classList.add('dung');
    if (i === dungThu) {
      dlgKq.textContent = 'Chính xác!';
      dlgKq.className = 'cf-dlg-kq ok';
    } else {
      nut.classList.add('sai');
      dlgKq.textContent = 'Chưa đúng. Đáp án đúng là ' + String.fromCharCode(65 + dungThu) + '.';
      dlgKq.className = 'cf-dlg-kq sai';
    }
    var gt = txt(taGT.value);
    if (gt !== '') {
      dlgGt.textContent = 'Giải thích: ' + gt;
      dlgGt.hidden = false;
    }
  }

  $('cfChayThu').addEventListener('click', function () {
    var dk = tinhDieuKien();
    if (!(dk.pa && dk.dung)) {
      thongBao.textContent = MAC_DINH_THONGBAO;
      thongBao.classList.add('loi-luu');
      thongBao.scrollIntoView({ block: 'center', behavior: 'smooth' });
      return;
    }
    dsThu = dapAnCoChu();
    var iDung = chiSoDung();
    dungThu = 0;
    dsThu.forEach(function (d, k) { if (d.goc === iDung) { dungThu = k; } });

    var h = HANG[giaTriKho()] || HANG['Trung bình'];
    $('cfDlgTieuDe').textContent = (tenMon() || 'Chưa chọn môn') + ' · ' + tenKyThi();
    $('cfDlgHang').textContent = h.ten;
    $('cfDlgHang').className = 'cf-hang ' + h.lop;
    var nd = txt(taND.value);
    $('cfDlgCau').textContent = nd !== '' ? nd : '(Chưa nhập nội dung câu hỏi)';
    dungLaiHop();
    if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
  });

  $('cfDlgLai').addEventListener('click', dungLaiHop);
  function dong() { if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.removeAttribute('open'); } }
  $('cfDlgDong').addEventListener('click', dong);
  dlg.addEventListener('click', function (e) { if (e.target === dlg) { dong(); } });   // bấm nền tối để đóng

  // Cảnh báo khi rời trang mà chưa lưu
  window.addEventListener('beforeunload', function (e) {
    if (daSua && !form.classList.contains('dang-luu')) { e.preventDefault(); e.returnValue = ''; }
  });
  form.addEventListener('submit', function (e) { if (!e.defaultPrevented) { form.classList.add('dang-luu'); } });

  capNhat();
})();