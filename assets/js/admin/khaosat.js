// Trang Thống kê khảo sát (admin/khaosat.php): vẽ 3 biểu đồ bằng Chart.js (assets/vendor/chart.umd.js).
// Dữ liệu đọc từ <script id="du-lieu-khaosat" type="application/json"> do PHP in ra.
(function () {
  'use strict';
  var nguon = document.getElementById('du-lieu-khaosat');
  if (!nguon || typeof Chart === 'undefined') { return; }
  var d;
  try { d = JSON.parse(nguon.textContent); } catch (e) { return; }

  Chart.defaults.font.family = "'Be Vietnam Pro','Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif";
  Chart.defaults.color = '#9fb4d9';

  // Dải màu Cosmic sáng, nổi trên nền tối (đổi tại đây nếu muốn đổi tông màu)
  var MAU = ['#087BFF', '#00D9FF', '#8B35FF', '#E044FF', '#2ee6a8', '#ffd08a'];
  function mau(n) { return MAU.slice(0, n); }
  function pt(v) { return String(v).replace('.', ',') + '%'; }
  function so(v) { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

  // Không có dữ liệu: hiện dòng thông báo thay cho biểu đồ
  function bao_trong(canvas) {
    var o = document.createElement('div');
    o.className = 'ks-trong';
    o.textContent = 'Chưa có dữ liệu trong khoảng ngày này.';
    canvas.parentNode.appendChild(o);
    canvas.style.visibility = 'hidden';
  }
  function tong(a) { return a.reduce(function (s, v) { return s + v; }, 0); }

  // Plugin nhỏ: ghi % ở đầu mỗi cột (không cần thêm thư viện datalabels)
  var nhanPT = {
    id: 'nhanPhanTram',
    afterDatasetsDraw: function (c, _a, tuyChon) {
      var ctx = c.ctx, ngang = c.options.indexAxis === 'y';
      var meta = c.getDatasetMeta(0), gt = c.data.datasets[0].data;
      ctx.save();
      ctx.fillStyle = '#F5F8FF'; ctx.font = '600 12px ' + Chart.defaults.font.family;
      meta.data.forEach(function (bar, i) {
        var t = pt(gt[i]);
        if (ngang) { ctx.textAlign = 'left'; ctx.textBaseline = 'middle'; ctx.fillText(t, bar.x + 6, bar.y); }
        else { ctx.textAlign = 'center'; ctx.textBaseline = 'bottom'; ctx.fillText(t, bar.x, bar.y - 4); }
      });
      ctx.restore();
    }
  };

  // 1) Donut: mục tiêu tham gia (tỷ lệ trên tổng lượt chọn, cộng lại = 100%)
  var cv1 = document.getElementById('bdMucTieu');
  var m = d.mucTieu, tongChon = tong(m.luot);
  document.getElementById('bdMucTieuTong').textContent = so(tongChon);
  var ds = document.getElementById('bdMucTieuChuThich');
  m.nhan.forEach(function (ten, i) {
    var li = document.createElement('li');
    var chamMau = document.createElement('i'); chamMau.style.background = MAU[i % MAU.length];
    var khoi = document.createElement('div');
    var b = document.createElement('b'); b.textContent = ten;
    khoi.appendChild(b); khoi.appendChild(document.createTextNode(pt(m.tyLe[i])));
    li.appendChild(chamMau); li.appendChild(khoi); ds.appendChild(li);
  });
  if (tongChon === 0) { bao_trong(cv1); } else {
    new Chart(cv1, {
      type: 'doughnut',
      data: { labels: m.nhan, datasets: [{ data: m.luot, backgroundColor: mau(m.nhan.length), borderWidth: 2, borderColor: '#0a1430' }] },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '68%',
        plugins: { legend: { display: false },
          tooltip: { callbacks: { label: function (c) { return ' ' + c.label + ': ' + c.raw + ' lượt (' + pt(m.tyLe[c.dataIndex]) + ')'; } } } }
      }
    });
  }

  // 2) Cột ngang: lĩnh vực yêu thích
  var cv2 = document.getElementById('bdLinhVuc'), lv = d.linhVuc;
  if (tong(lv.luot) === 0) { bao_trong(cv2); } else {
    var tran2 = Math.min(100, Math.ceil((Math.max.apply(null, lv.tyLe) + 12) / 10) * 10);
    new Chart(cv2, {
      type: 'bar',
      data: { labels: lv.nhan, datasets: [{ data: lv.tyLe, backgroundColor: mau(lv.nhan.length), borderRadius: 6, barThickness: 22 }] },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false },
          tooltip: { callbacks: { label: function (c) { return ' ' + lv.luot[c.dataIndex] + ' lượt (' + pt(c.raw) + ')'; } } } },
        scales: { x: { min: 0, max: tran2, ticks: { callback: function (v) { return v + '%'; } }, grid: { color: 'rgba(138,245,255,.12)' } },
                  y: { grid: { display: false } } }
      },
      plugins: [nhanPT]
    });
  }

  // 3) Cột dọc: trình độ tự đánh giá
  var cv3 = document.getElementById('bdTrinhDo'), td = d.trinhDo;
  if (tong(td.luot) === 0) { bao_trong(cv3); } else {
    var tran3 = Math.min(100, Math.ceil((Math.max.apply(null, td.tyLe) + 8) / 10) * 10);
    new Chart(cv3, {
      type: 'bar',
      data: { labels: td.nhan, datasets: [{ data: td.tyLe, backgroundColor: ['#00D9FF', '#087BFF', '#8B35FF', '#E044FF'], borderRadius: 8, maxBarThickness: 46 }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false },
          tooltip: { callbacks: { label: function (c) { return ' ' + td.luot[c.dataIndex] + ' sinh viên (' + pt(c.raw) + ')'; } } } },
        scales: { y: { min: 0, max: tran3, ticks: { callback: function (v) { return v + '%'; } }, grid: { color: 'rgba(138,245,255,.12)' } },
                  x: { grid: { display: false } } }
      },
      plugins: [nhanPT]
    });
  }
})();