// Trang Người dùng (admin/nguoidung.php): vẽ sơ đồ bằng Chart.js (assets/vendor/chart.umd.js) và hộp xác nhận khóa / mở khóa.
(function () {
  'use strict';

  function doc(canvas, khoa) {
    try { return JSON.parse(canvas.getAttribute('data-' + khoa) || '[]'); } catch (e) { return []; }
  }

  if (window.Chart) {
    var cot = document.getElementById('ndBieuDoNgay');
    if (cot) {
      new Chart(cot, {
        type: 'bar',
        data: { labels: doc(cot, 'nhan'), datasets: [{ label: 'Tài khoản mới', data: doc(cot, 'so'), backgroundColor: '#4f8bff', hoverBackgroundColor: '#1e5cf0', borderRadius: 4, maxBarThickness: 18 }] },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 10, color: '#8a97b5', font: { size: 11 } } },
            y: { beginAtZero: true, ticks: { precision: 0, color: '#8a97b5', font: { size: 11 } }, grid: { color: '#eef2fb' } }
          }
        }
      });
    }
    var banh = document.getElementById('ndBieuDoTT');
    if (banh) {
      new Chart(banh, {
        type: 'doughnut',
        data: { labels: doc(banh, 'nhan'), datasets: [{ data: doc(banh, 'so'), backgroundColor: ['#19b26b', '#e5484d'], borderWidth: 0 }] },
        options: { responsive: false, cutout: '68%', plugins: { legend: { display: false } } }
      });
    }
  }

  // ---- Hộp xác nhận khóa / mở khóa ----
  var hop = document.getElementById('ndHopKhoa');
  if (!hop || typeof hop.showModal !== 'function') { return; }
  var $ = function (id) { return document.getElementById(id); };
  var nutXacNhan = $('ndHopXacNhan');

  document.querySelectorAll('.nd-khoa-nut').forEach(function (nut) {
    nut.addEventListener('click', function () {
      var khoa = nut.getAttribute('data-hanh-dong') === 'khoa';
      var ten = nut.getAttribute('data-ten') || '';
      $('ndHopId').value = nut.getAttribute('data-id');
      $('ndHopHanhDong').value = khoa ? 'khoa' : 'mokhoa';
      $('ndHopTieuDe').textContent = khoa ? 'Khóa tài khoản' : 'Mở khóa tài khoản';
      $('ndHopMoTa').textContent = khoa
        ? 'Khóa "' + ten + '": người này sẽ không đăng nhập được nữa. Hệ thống gửi thông báo cho người dùng và ghi vào nhật ký quản trị.'
        : 'Mở khóa "' + ten + '": người này đăng nhập lại được. Hệ thống gửi thông báo cho người dùng và ghi vào nhật ký quản trị.';
      $('ndHopNhanLyDo').textContent = khoa ? 'Lý do (người dùng sẽ thấy trong thông báo)' : 'Ghi chú (không bắt buộc)';
      $('ndHopLyDo').value = '';
      nutXacNhan.textContent = khoa ? 'Khóa tài khoản' : 'Mở khóa';
      nutXacNhan.className = 'nd-nut ' + (khoa ? 'nd-nut-do' : 'nd-nut-xanh');
      hop.showModal();
      $('ndHopLyDo').focus();
    });
  });
  $('ndHopHuy').addEventListener('click', function () { hop.close(); });
  hop.addEventListener('click', function (ev) { if (ev.target === hop) { hop.close(); } });
})();