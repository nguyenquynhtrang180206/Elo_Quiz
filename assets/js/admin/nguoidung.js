// Trang Người dùng (admin/nguoidung.php): biểu đồ Chart.js, popup khóa/mở khóa và lọc môn BXH.
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

  // Hộp xác nhận khóa/mở khóa
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
  // Ô chọn môn BXH có tìm kiếm (mặc định hiện 5 môn)
  var chonMon = document.getElementById('ndChonMon');
  var formBxh = document.getElementById('ndBxhLoc');
  if (chonMon && formBxh) {
    var nutMo = chonMon.querySelector('.nd-chon-mon-nut');
    var hop = chonMon.querySelector('.nd-chon-mon-hop');
    var oTim = chonMon.querySelector('.nd-chon-mon-tim');
    var nutThem = chonMon.querySelector('.nd-chon-mon-them');
    var rong = chonMon.querySelector('.nd-chon-mon-rong');
    var giaTri = chonMon.querySelector('input[name="bxh_mon"]');
    var dsLi = [].slice.call(hop.querySelectorAll('li'));

    // Bỏ dấu để gõ "toan" vẫn ra "Toán"
    var boDau = function (t) {
      return String(t).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
    };
    var locMon = function () {
      var t = boDau(oTim.value.trim()), dem = 0;
      dsLi.forEach(function (li) {
        var khop = t === '' || boDau(li.querySelector('button').getAttribute('data-ten')).indexOf(t) !== -1;
        li.classList.toggle('an', !khop);
        if (khop) { dem++; }
      });
      hop.classList.toggle('dang-tim', t !== '');
      rong.hidden = dem > 0;
      if (nutThem) { nutThem.hidden = t !== '' || hop.classList.contains('mo-het'); }
    };
    var moHop = function (mo) {
      hop.hidden = !mo;
      nutMo.setAttribute('aria-expanded', mo ? 'true' : 'false');
      if (mo) { hop.classList.remove('mo-het'); oTim.value = ''; locMon(); oTim.focus(); }
    };

    nutMo.addEventListener('click', function () { moHop(hop.hidden); });
    oTim.addEventListener('input', locMon);
    oTim.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    if (nutThem) {
      nutThem.addEventListener('click', function () { hop.classList.add('mo-het'); nutThem.hidden = true; });
    }
    hop.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-id]');
      if (!b) { return; }
      giaTri.value = b.getAttribute('data-id');
      formBxh.submit();
    });
    document.addEventListener('click', function (e) {
      if (!hop.hidden && !chonMon.contains(e.target)) { moHop(false); }
    });
    chonMon.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !hop.hidden) { moHop(false); nutMo.focus(); }
    });
  }
})();