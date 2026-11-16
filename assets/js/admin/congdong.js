// Trang Kiểm duyệt cộng đồng (admin/congdong.php): 4 biểu đồ Chart.js + chọn hàng loạt + khung xử lý.
// Dữ liệu biểu đồ đọc từ <script id="du-lieu-congdong" type="application/json"> do PHP in ra.
(function () {
  'use strict';

  /* ================= 1) Biểu đồ ================= */
  var nguon = document.getElementById('du-lieu-congdong');
  var d = null;
  if (nguon && typeof Chart !== 'undefined') {
    try { d = JSON.parse(nguon.textContent); } catch (e) { d = null; }
  }
  if (d) {
    Chart.defaults.font.family = "'Be Vietnam Pro','Inter',-apple-system,'Segoe UI',Roboto,Arial,sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#6b7694';

    var XANH = '#1e5cf0', VANG = '#f5b82e', DO = '#e5484d', LUC = '#16a34a', XAM = '#b7c0d6', TIM = '#7c3aed';
    var tong = function (a) { return a.reduce(function (s, v) { return s + v; }, 0); };
    // Không có dữ liệu: phủ dòng thông báo lên biểu đồ
    var baoTrong = function (canvas, chu) {
      var o = document.createElement('div');
      o.className = 'cdm-cv-trong';
      o.textContent = chu || 'Chưa có dữ liệu.';
      canvas.parentNode.appendChild(o);
    };
    var truc = {
      x: { grid: { display: false } },
      y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#edf2fb' } }
    };
    var chuThich = { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 10 } };
    var cv;

    // 1) Đường: hoạt động 7 ngày
    cv = document.getElementById('bdHoatDong');
    if (cv) {
      if (tong(d.bai) + tong(d.bl) === 0) { baoTrong(cv, 'Chưa có hoạt động trong 7 ngày qua.'); }
      new Chart(cv, {
        type: 'line',
        data: {
          labels: d.ngay,
          datasets: [
            { label: 'Bình luận', data: d.bl, borderColor: XANH, backgroundColor: XANH, tension: .35, pointRadius: 3, borderWidth: 2.5 },
            { label: 'Bài đăng', data: d.bai, borderColor: VANG, backgroundColor: VANG, tension: .35, pointRadius: 3, borderWidth: 2.5 }
          ]
        },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, scales: truc, plugins: { legend: chuThich } }
      });
    }

    // 2) Donut: trạng thái nội dung, số tổng ở giữa
    cv = document.getElementById('bdTrangThai');
    if (cv) {
      if (d.tongMuc === 0) { baoTrong(cv); }
      var soGiua = {
        id: 'soGiua',
        afterDraw: function (c) {
          var a = c.chartArea, x = (a.left + a.right) / 2, y = (a.top + a.bottom) / 2, ctx = c.ctx;
          ctx.save(); ctx.textAlign = 'center'; ctx.fillStyle = '#12235e';
          ctx.font = '800 20px ' + Chart.defaults.font.family; ctx.fillText(String(d.tongMuc), x, y + 2);
          ctx.fillStyle = '#8a94b3'; ctx.font = '500 10px ' + Chart.defaults.font.family; ctx.fillText('tổng mục', x, y + 16);
          ctx.restore();
        }
      };
      new Chart(cv, {
        type: 'doughnut',
        data: { labels: ['Hiển thị', 'Bị ẩn', 'Đã xóa'], datasets: [{ data: d.trangThai, backgroundColor: [LUC, DO, XAM], borderWidth: 2, borderColor: '#fff' }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '66%', plugins: { legend: { position: 'right', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 8 } } } },
        plugins: [soGiua]
      });
    }

    // 3) Cột ngang: bài đăng theo loại (hoặc theo môn nếu chưa có cột LoaiBai), ghi số ở đầu cột
    cv = document.getElementById('bdPhanLoai');
    if (cv) {
      var pl = d.phanLoai;
      if (!pl.so.length || tong(pl.so) === 0) { baoTrong(cv); }
      var mau = [XANH, VANG, LUC, TIM, XAM, DO];
      var soDau = {
        id: 'soDau',
        afterDatasetsDraw: function (c) {
          var ctx = c.ctx, meta = c.getDatasetMeta(0), gt = c.data.datasets[0].data;
          ctx.save(); ctx.fillStyle = '#12235e'; ctx.font = '700 11px ' + Chart.defaults.font.family;
          ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
          meta.data.forEach(function (bar, i) { ctx.fillText(String(gt[i]), bar.x + 5, bar.y); });
          ctx.restore();
        }
      };
      new Chart(cv, {
        type: 'bar',
        data: { labels: pl.nhan, datasets: [{ data: pl.so, backgroundColor: pl.so.map(function (_, i) { return mau[i % mau.length]; }), borderRadius: 5, barThickness: 14 }] },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false, layout: { padding: { right: 24 } },
          scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#edf2fb' } }, y: { grid: { display: false } } },
          plugins: { legend: { display: false } }
        },
        plugins: [soDau]
      });
    }

    // 4) Cột đôi: thao tác kiểm duyệt (ẩn / hiện lại) 7 ngày
    cv = document.getElementById('bdThaoTac');
    if (cv) {
      if (tong(d.an) + tong(d.hien) === 0) { baoTrong(cv, 'Chưa có thao tác nào trong 7 ngày qua.'); }
      new Chart(cv, {
        type: 'bar',
        data: {
          labels: d.ngay,
          datasets: [
            { label: 'Ẩn (' + tong(d.an) + ')', data: d.an, backgroundColor: DO, borderRadius: 4 },
            { label: 'Hiện lại (' + tong(d.hien) + ')', data: d.hien, backgroundColor: XANH, borderRadius: 4 }
          ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: truc, plugins: { legend: chuThich } }
      });
    }
  }

  /* ================= 2) Lọc: đổi môn / sắp xếp thì gửi luôn ================= */
  document.querySelectorAll('select[data-tu-gui]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  /* ================= 3) Chọn hàng loạt ================= */
  var tatCa = document.getElementById('cdmChonTatCa');
  var hl = document.getElementById('formHangLoat');
  var soChon = document.getElementById('cdmSoChon');
  var cacHang = Array.prototype.slice.call(document.querySelectorAll('.cdm-chon-hang'));
  function capNhatHL() {
    var n = cacHang.filter(function (c) { return c.checked; }).length;
    if (soChon) { soChon.textContent = String(n); }
    if (hl) { hl.hidden = n === 0; }
    if (tatCa) {
      var kha = cacHang.filter(function (c) { return !c.disabled; }).length;
      tatCa.checked = kha > 0 && n === kha;
      tatCa.indeterminate = n > 0 && n < kha;
    }
  }
  cacHang.forEach(function (c) { c.addEventListener('change', capNhatHL); });
  if (tatCa) {
    tatCa.addEventListener('change', function () {
      cacHang.forEach(function (c) { if (!c.disabled) { c.checked = tatCa.checked; } });
      capNhatHL();
    });
  }
  if (hl) {
    hl.addEventListener('submit', function (ev) {
      var nut = ev.submitter, hanh = nut ? nut.textContent.trim() : 'xử lý';
      var n = soChon ? soChon.textContent : '';
      if (!window.confirm(hanh + ' ' + n + ' mục với cùng một lý do?')) { ev.preventDefault(); }
    });
  }

  /* ================= 4) Khung xử lý bên phải ================= */
  var form = document.getElementById('cdmFormXuLy');
  if (form) {
    var inHd = document.getElementById('cdmHd'), inMa = document.getElementById('cdmMa');
    var tieuDe = document.getElementById('cdmXlTieuDe'), nutGui = document.getElementById('cdmNutGui');
    var nutVe = document.getElementById('cdmNutVeMuc'), lyDo = document.getElementById('cdmLyDo');
    var nhomAn = form.querySelector('[data-nhom="an"]'), nhomHien = form.querySelector('[data-nhom="hien"]');

    // Đặt mục tiêu của khung xử lý: muc = { hd, ma, tieuDe, nut, do }
    function datMucTieu(m, laMacDinh) {
      var laAn = m.hd.indexOf('an_') === 0;
      inHd.value = m.hd; inMa.value = m.ma;
      tieuDe.textContent = m.tieuDe; nutGui.textContent = m.nut;
      nutGui.className = 'cdm-nut ' + (laAn ? 'cdm-nut-do' : 'cdm-nut-chinh');
      form.classList.toggle('la-hien', !laAn);
      if (nhomAn) { nhomAn.hidden = !laAn; }
      if (nhomHien) { nhomHien.hidden = laAn; }
      if (nutVe) { nutVe.hidden = !!laMacDinh; }
      if (!laMacDinh) { form.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); lyDo.focus(); }
    }
    var macDinh = { hd: form.dataset.hd0, ma: form.dataset.ma0, tieuDe: form.dataset.tieude0, nut: form.dataset.nut0 };

    // Bấm "Ẩn"/"Hiện lại" ở một bình luận: chuyển mục tiêu sang bình luận đó
    document.querySelectorAll('[data-doi-muc-tieu]').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('.cdm-bl').forEach(function (r) { r.classList.remove('dang-chon-bl'); });
        b.closest('.cdm-bl').classList.add('dang-chon-bl');
        datMucTieu({ hd: b.dataset.hd, ma: b.dataset.ma, tieuDe: b.dataset.tieude, nut: b.dataset.nut }, false);
      });
    });
    if (nutVe) {
      nutVe.addEventListener('click', function () {
        document.querySelectorAll('.cdm-bl').forEach(function (r) { r.classList.remove('dang-chon-bl'); });
        datMucTieu(macDinh, true);
      });
    }

    // Chip lý do: điền sẵn vào ô lý do (chip "Khác…" thì xóa để tự gõ)
    form.querySelectorAll('.cdm-chip[data-ly]').forEach(function (c) {
      c.addEventListener('click', function () {
        lyDo.value = c.dataset.ly;
        form.querySelectorAll('.cdm-chip').forEach(function (o) { o.classList.remove('on'); });
        c.classList.add('on');
        lyDo.focus();
      });
    });

    // Ctrl+Enter trong ô lý do: gửi
    lyDo.addEventListener('keydown', function (ev) {
      if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') { ev.preventDefault(); if (form.reportValidity()) { form.requestSubmit(); } }
    });
  }

  // Phím tắt (không kích hoạt khi đang gõ): H = nhập lý do, S = bỏ qua, xem mục tiếp
  document.addEventListener('keydown', function (ev) {
    if (ev.ctrlKey || ev.metaKey || ev.altKey) { return; }
    var t = ev.target, ten = t && t.tagName;
    if (ten === 'INPUT' || ten === 'TEXTAREA' || ten === 'SELECT' || (t && t.isContentEditable)) { return; }
    var k = (ev.key || '').toLowerCase();
    if (k === 'h') {
      var o = document.getElementById('cdmLyDo');
      if (o) { ev.preventDefault(); o.focus(); }
    } else if (k === 's') {
      var bq = document.getElementById('cdmBoQua');
      if (bq) { ev.preventDefault(); window.location.href = bq.href; }
    }
  });
})();