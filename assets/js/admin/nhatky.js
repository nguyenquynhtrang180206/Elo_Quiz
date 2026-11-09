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