// Trang Nhập câu hỏi từ Word/Excel (admin/cauhoi_nhap.php). Đặt tại: assets/js/admin/cauhoi_nhap.js
// Chỉ làm đẹp và chặn lỗi sớm ở trình duyệt; mọi kiểm tra thật vẫn do máy chủ (NhapCauHoi.php) thực hiện.
(function () {
  'use strict';

  var giamChuyenDong = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function dinhDangDungLuong(byte) {
    if (byte < 1024) { return byte + ' B'; }
    if (byte < 1024 * 1024) { return Math.round(byte / 1024) + ' KB'; }
    return (byte / 1048576).toFixed(1).replace('.', ',') + ' MB';
  }

  // ---------- Từng vùng thả file ----------
  var vung = document.querySelectorAll('.ci-tha');
  Array.prototype.forEach.call(vung, function (el) {
    var input = el.querySelector('input[type=file]');
    var ten = el.querySelector('.ci-file-ten');
    var kt = el.querySelector('.ci-file-kt');
    var boChon = el.querySelector('.ci-bo-chon');
    var nhac = el.querySelector('.ci-nhac-loi');
    var duoi = el.getAttribute('data-duoi') || '';
    var toiDa = parseInt(el.getAttribute('data-max') || '0', 10);

    function baoLoi(chu) {
      if (!nhac) { return; }
      nhac.textContent = chu;
      nhac.hidden = chu === '';
    }

    function capNhat() {
      var f = input.files && input.files[0];
      el.classList.remove('keo');
      if (!f) {
        el.classList.remove('co-file');
        input.dispatchEvent(new CustomEvent('ci-doi', { bubbles: true }));
        return;
      }
      var phan = f.name.split('.');
      var d = phan.length > 1 ? phan[phan.length - 1].toLowerCase() : '';
      if (d !== duoi) {
        input.value = '';
        el.classList.remove('co-file');
        baoLoi('Chỉ nhận file .' + duoi + '.');
      } else if (toiDa > 0 && f.size > toiDa) {
        input.value = '';
        el.classList.remove('co-file');
        baoLoi('File vượt quá ' + dinhDangDungLuong(toiDa) + '.');
      } else {
        baoLoi('');
        ten.textContent = f.name;
        kt.textContent = dinhDangDungLuong(f.size);
        el.classList.add('co-file');
      }
      input.dispatchEvent(new CustomEvent('ci-doi', { bubbles: true }));
    }

    input.addEventListener('change', capNhat);
    ['dragenter', 'dragover'].forEach(function (s) {
      el.addEventListener(s, function () { el.classList.add('keo'); });
    });
    ['dragleave', 'drop'].forEach(function (s) {
      el.addEventListener(s, function () { el.classList.remove('keo'); });
    });
    if (boChon) {
      boChon.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        input.value = '';
        baoLoi('');
        capNhat();
      });
    }
  });

  // ---------- Từng form: khóa nút khi thiếu dữ liệu, trạng thái đang xử lý ----------
  var forms = document.querySelectorAll('form[data-ci-form]');
  Array.prototype.forEach.call(forms, function (form) {
    var nut = form.querySelector('button[type=submit]');
    var file = form.querySelector('input[type=file]');
    var mon = form.elements['mon'] || null;   // select Môn học nằm ngoài thẻ nhưng gắn vào form bằng thuộc tính form
    var htmlGoc = nut ? nut.innerHTML : '';   // giữ cả biểu tượng trong nút
    var hen = null;

    function kiemTra() {
      var duDuLieu = !!(file && file.files && file.files.length);
      if (mon && parseInt(mon.value, 10) <= 0) { duDuLieu = false; }
      if (nut && !nut.classList.contains('dang-xu-ly')) { nut.disabled = !duDuLieu; }
    }

    function khoiPhuc() {
      if (hen) { clearTimeout(hen); hen = null; }
      if (nut) {
        nut.classList.remove('dang-xu-ly');
        nut.innerHTML = htmlGoc;
      }
      kiemTra();
    }

    form.addEventListener('ci-doi', kiemTra);
    if (mon) { mon.addEventListener('change', kiemTra); }

    // Bắt đầu trạng thái đang xử lý (gọi sau khi người dùng đã xác nhận)
    function batDau() {
      nut.classList.add('dang-xu-ly');
      nut.textContent = 'Đang xử lý...';
      // Bước Word trả về file tải xuống nên trang không tải lại: tự trả nút về trạng thái ban đầu
      if (form.getAttribute('data-ci-form') === 'word') { hen = setTimeout(khoiPhuc, 4000); }
    }

    // Hỏi xác nhận một lần bằng modal chung của dự án (CosmicUI.confirm trong ui.js); không có thì dùng hộp thoại của trình duyệt
    function hoiXacNhan(noiDung, nhanOk, dongY) {
      if (window.CosmicUI && typeof window.CosmicUI.confirm === 'function') {
        window.CosmicUI.confirm(noiDung, dongY, { nhanOk: nhanOk });
      } else if (window.confirm(noiDung)) {
        dongY();
      }
    }

    form.addEventListener('submit', function (ev) {
      if (!nut) { return; }
      ev.preventDefault();   // gửi thật bằng form.submit() sau khi đồng ý (lúc đó trình duyệt đã kiểm tra xong các ô bắt buộc)
      if (nut.classList.contains('dang-xu-ly')) { return; }   // chống bấm hai lần
      var tenFile = file && file.files && file.files[0] ? file.files[0].name : '';
      var noiDung, nhanOk;
      if (form.getAttribute('data-ci-form') === 'word') {
        var tenMon = mon && mon.selectedIndex >= 0 ? mon.options[mon.selectedIndex].text : '';
        noiDung = 'Tách câu hỏi môn "' + tenMon + '" từ file "' + tenFile + '" và tải về file Excel để soát? Bước này chưa lưu gì vào hệ thống.';
        nhanOk = 'Tách và tải Excel';
      } else {
        noiDung = 'Kiểm tra và import file "' + tenFile + '"? Nếu mọi dòng đều hợp lệ, hệ thống sẽ lưu tất cả câu hỏi vào ngân hàng ngay.';
        nhanOk = 'Import';
      }
      hoiXacNhan(noiDung, nhanOk, function () {
        batDau();
        form.submit();
      });
    });

    // Quay lại bằng nút Back của trình duyệt (bfcache) thì bỏ trạng thái đang xử lý
    window.addEventListener('pageshow', function (ev) { if (ev.persisted) { khoiPhuc(); } });

    kiemTra();
  });

  // ---------- Lưới ô soát câu: rê chuột hoặc focus để xem lỗi ----------
  var luoi = document.getElementById('luoi');
  var tin = document.getElementById('thongtin');
  if (luoi && tin) {
    var macDinh = luoi.getAttribute('data-mac-dinh') || '';
    var hien = function (ev) {
      var o = ev.target.closest ? ev.target.closest('.ci-o') : null;
      tin.textContent = o ? (o.getAttribute('data-tip') || '') : macDinh;
    };
    luoi.addEventListener('mouseover', hien);
    luoi.addEventListener('focusin', hien);
    luoi.addEventListener('mouseleave', function () { tin.textContent = macDinh; });
    luoi.addEventListener('focusout', function () { tin.textContent = macDinh; });
  }

  // ---------- Có kết quả kiểm tra thì cuộn tới ----------
  var kq = document.getElementById('ketqua');
  if (kq && kq.scrollIntoView) {
    kq.scrollIntoView({ behavior: giamChuyenDong ? 'auto' : 'smooth', block: 'start' });
  }
})();