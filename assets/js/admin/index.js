// Trang Tổng quan (admin/index.php): vẽ 2 biểu đồ bằng Chart.js (assets/vendor/chart.umd.js)
// Hai hàm dựng biểu đồ được dùng chung với hộp thoại xem trước khi xuất báo cáo (thongke_xuat.js).
(function () {
  if (typeof Chart === 'undefined') { return; }

  Chart.defaults.font.family = "'Be Vietnam Pro','Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif";
  Chart.defaults.color = '#5b6b8c';

  // Cột = người dùng mới, đường = số trận. d = {nhan, dangky, tran}; tuyChon ghi đè options (vd: tắt animation khi xuất)
  function veTongQuan(canvas, d, tuyChon) {
    return new Chart(canvas, {
      type: 'bar',
      data: {
        labels: d.nhan,
        datasets: [
          { type: 'bar', label: 'Người dùng mới', data: d.dangky, order: 2,
            backgroundColor: 'rgba(47,107,255,.35)', borderColor: '#2f6bff', borderWidth: 1, borderRadius: 6 },
          { type: 'line', label: 'Trận đấu', data: d.tran, order: 1,
            borderColor: '#f5a524', backgroundColor: 'rgba(245,165,36,.12)', fill: true, tension: 0.35,
            pointBackgroundColor: '#f5a524', pointRadius: 4 }
        ]
      },
      options: Object.assign({
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top', align: 'start', labels: { usePointStyle: true, boxWidth: 8 } } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(20,33,61,.08)' } },
          x: { grid: { display: false } }
        }
      }, tuyChon || {})
    });
  }

  // Tròn = số trận theo môn học. mon = [{ten, so, mau}]
  function veMon(canvas, mon, tuyChon) {
    return new Chart(canvas, {
      type: 'doughnut',
      data: {
        labels: mon.map(function (m) { return m.ten; }),
        datasets: [{
          data: mon.map(function (m) { return m.so; }),
          backgroundColor: mon.map(function (m) { return m.mau; }),
          borderColor: '#ffffff', borderWidth: 2
        }]
      },
      options: Object.assign({
        responsive: true, maintainAspectRatio: false, cutout: '68%',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function (c) { return ' ' + c.label + ': ' + c.parsed + ' trận'; } } }
        }
      }, tuyChon || {})
    });
  }

  window.DashCharts = { tongQuan: veTongQuan, mon: veMon };

  var nguon = document.getElementById('du-lieu-dash');
  if (!nguon) { return; }
  var d = JSON.parse(nguon.textContent);
  var bd = document.getElementById('bd-tong-quan');
  if (bd) { veTongQuan(bd, d); }
  var mon = document.getElementById('bd-mon');
  if (mon && d.mon.length) { veMon(mon, d.mon); }
})();