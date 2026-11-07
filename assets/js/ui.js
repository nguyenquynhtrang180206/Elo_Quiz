/* Tiện ích giao diện dùng chung (không phụ thuộc hiệu ứng nền, không đụng nghiệp vụ).
   - CosmicUI.toast(thongBao, loai)   loai: 'thanhcong' | 'loi'
   - Thêm data-xacnhan="Nội dung hỏi" vào <form> hoặc <a> để hiện modal xác nhận trước khi gửi/đi tiếp.
   - Muốn xác nhận 2 lớp: thêm data-xacnhan2="Câu hỏi lớp 2" (tùy chọn data-xacnhan2-nut="Chữ trên nút lớp 2").
   - [data-dropdown] > nút [data-dropdown-nut] + .dropdown-menu: đóng/mở menu thả xuống. */
(function () {
  'use strict';

  let khungToast = null;
  function toast(msg, loai) {
    if (!khungToast) {
      khungToast = document.createElement('div');
      khungToast.className = 'toast-khung';
      khungToast.setAttribute('role', 'status');
      document.body.appendChild(khungToast);
    }
    const t = document.createElement('div');
    t.className = 'toast ' + (loai === 'loi' ? 'loi' : 'thanhcong');
    t.textContent = msg;
    khungToast.appendChild(t);
    setTimeout(function () { t.remove(); }, 4000);
  }

  function hoiXacNhan(noiDung, dongY, tuyChon) {
    tuyChon = tuyChon || {};
    const nen = document.createElement('div');
    nen.className = 'modal-nen';
    nen.innerHTML = '<div class="modal" role="dialog" aria-modal="true"><p></p>' +
      '<div class="hanh-dong"><button type="button" class="btn btn-phu" data-huy>Hủy</button>' +
      '<button type="button" class="btn" data-ok>Đồng ý</button></div></div>';
    nen.querySelector('p').textContent = noiDung;
    if (tuyChon.nhanOk) nen.querySelector('[data-ok]').textContent = tuyChon.nhanOk;
    const dong = function () { nen.remove(); document.removeEventListener('keydown', phim); };
    const phim = function (e) { if (e.key === 'Escape') dong(); };
    nen.addEventListener('click', function (e) {
      if (e.target === nen || e.target.hasAttribute('data-huy')) dong();
      else if (e.target.hasAttribute('data-ok')) { dong(); dongY(); }
    });
    document.addEventListener('keydown', phim);
    document.body.appendChild(nen);
    // Lớp xác nhận thứ 2 trở đi: mặc định focus vào Hủy để tránh bấm Enter liên tiếp lỡ đồng ý cả hai lớp
    nen.querySelector(tuyChon.focusHuy ? '[data-huy]' : '[data-ok]').focus();
  }

  // Hỏi lần lượt nhiều lớp; chỉ khi đồng ý hết mọi lớp mới gọi dongY. Hủy/Esc ở lớp nào thì dừng ngay.
  function hoiXacNhanNhieuLop(phanTu, dongY) {
    const lop = [{ noiDung: phanTu.getAttribute('data-xacnhan'), nhanOk: null }];
    const lop2 = phanTu.getAttribute('data-xacnhan2');
    if (lop2) lop.push({ noiDung: lop2, nhanOk: phanTu.getAttribute('data-xacnhan2-nut') });
    let i = 0;
    (function buoc() {
      hoiXacNhan(lop[i].noiDung, function () {
        i++;
        if (i < lop.length) buoc(); else dongY();
      }, { nhanOk: lop[i].nhanOk, focusHuy: i > 0 });
    })();
  }

  document.addEventListener('submit', function (e) {
    const f = e.target;
    if (!f.hasAttribute || !f.hasAttribute('data-xacnhan') || f.dataset.daXacNhan) return;
    e.preventDefault();
    hoiXacNhanNhieuLop(f, function () { f.dataset.daXacNhan = '1'; f.submit(); });
  });

  document.addEventListener('click', function (e) {
    const a = e.target.closest ? e.target.closest('a[data-xacnhan]') : null;
    if (a) {
      e.preventDefault();
      hoiXacNhanNhieuLop(a, function () { location.href = a.href; });
      return;
    }
    const nut = e.target.closest ? e.target.closest('[data-dropdown-nut]') : null;
    document.querySelectorAll('[data-dropdown] .dropdown-menu').forEach(function (m) {
      const cha = m.closest('[data-dropdown]');
      if (nut && cha.contains(nut)) m.hidden = !m.hidden; else m.hidden = true;
    });
  });

  window.CosmicUI = { toast: toast, confirm: hoiXacNhan };
})();