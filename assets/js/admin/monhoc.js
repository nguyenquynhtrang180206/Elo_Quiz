/* Trang Môn học, loại kỳ thi (admin/monhoc.php) */

// Hỏi xác nhận trước khi thực hiện nút có thuộc tính data-xac-nhan
document.addEventListener('click', function (e) {
  var nut = e.target.closest('[data-xac-nhan]');
  if (nut && !confirm(nut.getAttribute('data-xac-nhan'))) { e.preventDefault(); }
});

(function () {
  'use strict';

  // ---------- Tab Môn học / Loại kỳ thi ----------
  var trang  = document.getElementById('mhTrang');
  var nutTab = document.querySelectorAll('.mh-tab-nut');

  function chonTab(ten) {
    if (!trang) { return; }
    trang.setAttribute('data-tab', ten);
    nutTab.forEach(function (n) {
      var dang = n.getAttribute('data-tab') === ten;
      n.classList.toggle('dang-chon', dang);
      n.setAttribute('aria-selected', dang ? 'true' : 'false');
    });
    // Ghi ?tab=kt lên địa chỉ để tải lại trang và chuyển hướng sau khi lưu vẫn ở đúng tab
    if (window.history && history.replaceState) {
      var u = new URL(window.location.href);
      if (ten === 'kt') {
        u.searchParams.set('tab', 'kt');
      } else {
        u.searchParams.delete('tab');
        u.searchParams.delete('pkt');   // ?pkt=... tự bật tab Loại kỳ thi nên bỏ đi khi về tab Môn học
      }
      history.replaceState(null, '', u.pathname + u.search + u.hash);
    }
  }
  nutTab.forEach(function (n) {
    n.addEventListener('click', function () { chonTab(n.getAttribute('data-tab')); });
  });

  // ---------- Popup thêm / sửa ----------
  function $(id) { return document.getElementById(id); }

  function mo(hop, o) {
    if (!hop) { return; }
    if (typeof hop.showModal === 'function') { hop.showModal(); } else { hop.setAttribute('open', ''); }
    if (o) { setTimeout(function () { o.focus(); o.select && o.select(); }, 50); }
  }
  function dong(hop) {
    if (typeof hop.close === 'function') { hop.close(); } else { hop.removeAttribute('open'); }
  }

  // Cấu hình hai popup: id các phần tử, nhãn khi thêm và khi sửa
  var cauHinh = {
    mon: {
      hop: 'hopMon', hd: 'hopMonHd', id: 'hopMonId', tieuDe: 'hopMonTieuDe', luu: 'hopMonLuu',
      ten: 'ten_mon', moTa: 'mo_ta',
      them: { hd: 'them_mon', tieuDe: 'Thêm môn học',  luu: 'Thêm môn' },
      sua:  { hd: 'sua_mon',  tieuDe: 'Sửa môn học',   luu: 'Lưu thay đổi' }
    },
    kt: {
      hop: 'hopKt', hd: 'hopKtHd', id: 'hopKtId', tieuDe: 'hopKtTieuDe', luu: 'hopKtLuu',
      ten: 'ten_kt', moTa: 'mo_ta_kt',
      them: { hd: 'them_kt', tieuDe: 'Thêm loại kỳ thi', luu: 'Thêm loại kỳ thi' },
      sua:  { hd: 'sua_kt',  tieuDe: 'Sửa loại kỳ thi',  luu: 'Lưu thay đổi' }
    }
  };

  // du = null: chế độ thêm; du = {id, ten, mota}: chế độ sửa
  function moPopup(loai, du) {
    var c = cauHinh[loai], cd = du ? c.sua : c.them;
    $(c.hd).value = cd.hd;
    $(c.id).value = du ? du.id : '0';
    $(c.tieuDe).textContent = cd.tieuDe;
    $(c.luu).textContent = cd.luu;
    $(c.ten).value = du ? du.ten : '';
    $(c.moTa).value = du ? du.mota : '';
    mo($(c.hop), $(c.ten));
  }

  var nutThemMon = $('nutThemMon');
  if (nutThemMon) { nutThemMon.addEventListener('click', function () { moPopup('mon', null); }); }
  var nutThemKt = $('nutThemKt');
  if (nutThemKt) { nutThemKt.addEventListener('click', function () { moPopup('kt', null); }); }

  // Nút Sửa trên từng dòng (dùng ủy quyền sự kiện)
  document.addEventListener('click', function (e) {
    var nut = e.target.closest('[data-sua-mon], [data-sua-kt]');
    if (!nut) { return; }
    moPopup(nut.hasAttribute('data-sua-mon') ? 'mon' : 'kt', {
      id: nut.getAttribute('data-id'),
      ten: nut.getAttribute('data-ten') || '',
      mota: nut.getAttribute('data-mota') || ''
    });
  });

  // Nút Hủy và bấm ra ngoài popup thì đóng (Esc đã được trình duyệt xử lý)
  document.querySelectorAll('.mh-hop').forEach(function (hop) {
    hop.addEventListener('click', function (e) {
      if (e.target === hop || e.target.closest('[data-dong-hop]')) { dong(hop); }
    });
  });
})();