// Trang Elo và xếp hạng (admin/elo.php, tab Bảng xếp hạng): vẽ biểu đồ bằng Chart.js (assets/vendor/chart.umd.js)
// Dữ liệu do máy chủ nhúng trong thẻ <script id="du-lieu-elo" type="application/json">.
(function () {
  if (typeof Chart === 'undefined') { return; }
  var nguon = document.getElementById('du-lieu-elo');
  if (!nguon) { return; }
  var d = JSON.parse(nguon.textContent);

  Chart.defaults.font.family = "'Be Vietnam Pro','Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif";
  Chart.defaults.color = '#64748b';
  var luoi = 'rgba(15,23,42,.07)';

  // 1. Tròn: số người chơi theo bậc rank
  var cBac = document.getElementById('bd-bac');
  if (cBac) {
    new Chart(cBac, {
      type: 'doughnut',
      data: {
        labels: d.bac.map(function (b) { return b.ten; }),
        datasets: [{ data: d.bac.map(function (b) { return b.so; }), backgroundColor: d.bac.map(function (b) { return b.mau; }), borderColor: '#ffffff', borderWidth: 2 }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '68%',
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + c.label + ': ' + c.parsed + ' người'; } } } }
      }
    });
  }

  // 2. Cột: số người theo khoảng điểm Elo (màu theo bậc của khoảng)
  var cPho = document.getElementById('bd-pho');
  if (cPho) {
    new Chart(cPho, {
      type: 'bar',
      data: { labels: d.pho.nhan, datasets: [{ label: 'Số người', data: d.pho.so, backgroundColor: d.pho.mau, borderRadius: 6, maxBarThickness: 38 }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { title: function (it) { return 'Elo ' + it[0].label; }, label: function (c) { return ' ' + c.parsed.y + ' người'; } } } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: luoi } },
          x: { grid: { display: false }, ticks: { maxRotation: 50, minRotation: 0, autoSkip: true } }
        }
      }
    });
  }

  // 3. Thanh ngang: top 5 Elo cao nhất
  var cTop = document.getElementById('bd-top');
  if (cTop && d.top.ten.length) {
    var thap = Math.min.apply(null, d.top.elo);
    new Chart(cTop, {
      type: 'bar',
      data: { labels: d.top.ten, datasets: [{ label: 'Elo', data: d.top.elo, backgroundColor: d.top.mau, borderRadius: 6, maxBarThickness: 26 }] },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' Elo ' + c.parsed.x; } } } },
        scales: {
          x: { min: Math.max(0, Math.floor((thap - 100) / 50) * 50), grid: { color: luoi }, ticks: { precision: 0 } },
          y: { grid: { display: false } }
        }
      }
    });
  }

  // 4. Đường: Elo 30 ngày gần nhất của người đang chọn (panel bên phải)
  var cXh = document.getElementById('bd-xuhuong');
  if (cXh && d.xuhuong && d.xuhuong.length > 1) {
    new Chart(cXh, {
      type: 'line',
      data: {
        labels: d.xuhuong.map(function (_, i) { return 'Trận ' + (i + 1); }),
        datasets: [{ data: d.xuhuong, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.16)', fill: true, tension: 0.35, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2.5 }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' Elo ' + c.parsed.y; } } } },
        scales: { x: { display: false }, y: { display: true, grid: { color: luoi }, ticks: { maxTicksLimit: 3, precision: 0 } } }
      }
    });
  }
})();