/* Hồ sơ cá nhân - C-15: biểu đồ biến động Elo theo môn (Chart.js, dữ liệu từ LICHSUELO).
   Dữ liệu do client/hoso.php in sẵn trong <script id="bdDuLieu">; đổi môn hoặc khoảng trận không tải lại trang. */
(function () {
  'use strict';

  var nguon = document.getElementById('bdDuLieu');
  var canvas = document.getElementById('bdCanvas');
  var chonMon = document.getElementById('bdMon');
  if (!nguon || !canvas || !chonMon || typeof Chart === 'undefined') return;

  var duLieu;
  try { duLieu = JSON.parse(nguon.textContent); } catch (err) { return; }
  if (!duLieu.length) return;

  var MAC_DINH = 30;                       // khoảng trận hiện sẵn
  var khoang = MAC_DINH;
  var bieuDo = null;
  var nutKhoang = document.querySelectorAll('.bd-khoang [data-khoang]');

  var XANH = '#34d399', DO = '#f87171', VANG = '#fbbf24', XAM = '#9fb4d9', CYAN = '#00D9FF';

  function chuoiDau(n) { return (n > 0 ? '+' : '') + n; }

  function layMon() {
    var ma = parseInt(chonMon.value, 10);
    for (var i = 0; i < duLieu.length; i++) { if (duLieu[i].ma === ma) return duLieu[i]; }
    return duLieu[0];
  }

  // Dựng chuỗi điểm: điểm đầu là Elo trước trận đầu tiên của khoảng đang xem, sau đó là Elo sau mỗi trận.
  function dungDiem(mon) {
    var tran = mon.tran;
    if (khoang > 0 && tran.length > khoang) tran = tran.slice(tran.length - khoang);
    var diem = [{ elo: tran[0].truoc, nhan: 'Bắt đầu', dau: true }];
    tran.forEach(function (t, i) {
      diem.push({ elo: t.sau, nhan: String(i + 1), t: t });
    });
    return diem;
  }

  function capNhatTomTat(diem) {
    var elo = diem.map(function (d) { return d.elo; });
    var dau = elo[0], cuoi = elo[elo.length - 1];
    var doi = cuoi - dau;
    document.getElementById('bdHienTai').textContent = cuoi;
    document.getElementById('bdCao').textContent = Math.max.apply(null, elo);
    document.getElementById('bdThap').textContent = Math.min.apply(null, elo);
    var o = document.getElementById('bdDoi');
    o.textContent = chuoiDau(doi);
    o.className = doi > 0 ? 'bd-len' : (doi < 0 ? 'bd-xuong' : '');
    document.getElementById('bdSoTran').textContent = diem.length - 1;
  }

  function mauDiem(d) {
    if (d.dau) return XAM;
    return d.t.dd > 0 ? XANH : (d.t.dd < 0 ? DO : VANG);
  }

  function ve() {
    var mon = layMon();
    var diem = dungDiem(mon);
    capNhatTomTat(diem);

    var mau = diem.map(mauDiem);
    var nhan = diem.map(function (d) { return d.nhan; });
    var gt = diem.map(function (d) { return d.elo; });

    nutKhoang.forEach(function (n) {
      var v = parseInt(n.getAttribute('data-khoang'), 10);
      n.classList.toggle('bd-chon', v === khoang);
      n.setAttribute('aria-pressed', v === khoang ? 'true' : 'false');
    });

    if (bieuDo) bieuDo.destroy();

    var ctx = canvas.getContext('2d');
    var nen = ctx.createLinearGradient(0, 0, 0, canvas.parentNode.clientHeight || 320);
    nen.addColorStop(0, 'rgba(0,217,255,.28)');
    nen.addColorStop(1, 'rgba(0,217,255,0)');

    bieuDo = new Chart(canvas, {
      type: 'line',
      data: {
        labels: nhan,
        datasets: [{
          label: 'Elo',
          data: gt,
          borderColor: CYAN,
          backgroundColor: nen,
          fill: true,
          tension: 0.25,
          borderWidth: 2,
          pointRadius: diem.length > 60 ? 2 : 4,
          pointHoverRadius: 6,
          pointBackgroundColor: mau,
          pointBorderColor: mau
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'nearest', intersect: false, axis: 'x' },
        scales: {
          x: {
            title: { display: true, text: 'Trận (theo thứ tự thời gian)', color: XAM },
            ticks: { color: XAM, maxTicksLimit: 12, autoSkip: true, maxRotation: 0 },
            grid: { color: 'rgba(138,245,255,.08)' }
          },
          y: {
            title: { display: true, text: 'Elo', color: XAM },
            ticks: { color: XAM, precision: 0 },
            grid: { color: 'rgba(138,245,255,.12)' },
            grace: '10%'
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(6,12,36,.95)',
            borderColor: 'rgba(138,245,255,.35)',
            borderWidth: 1,
            titleColor: '#F5F8FF',
            bodyColor: '#c9d8f5',
            displayColors: false,
            callbacks: {
              title: function (items) {
                var d = diem[items[0].dataIndex];
                return d.dau ? 'Elo trước trận đầu tiên' : 'Trận #' + d.t.id + ' - ' + d.t.luc;
              },
              label: function (item) {
                var d = diem[item.dataIndex];
                if (d.dau) return 'Elo: ' + d.elo;
                return ['Elo: ' + d.t.truoc + ' → ' + d.t.sau, 'Thay đổi: ' + chuoiDau(d.t.dd)];
              }
            }
          }
        }
      }
    });
  }

  chonMon.addEventListener('change', ve);
  nutKhoang.forEach(function (n) {
    n.addEventListener('click', function () {
      khoang = parseInt(n.getAttribute('data-khoang'), 10);
      ve();
    });
  });

  ve();
})();