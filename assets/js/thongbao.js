/* =====================================================================
   HỆ THỐNG THÔNG BÁO REALTIME QUIZUP ARENA (assets/js/thongbao.js)
   Tự động cập nhật chuông thông báo khi có người rep bài, bình luận
   ===================================================================== */
(function () {
  'use strict';

  var navNotifWrap = document.getElementById('navNotifWrap');
  var btnNotifToggle = document.getElementById('btnNotifToggle');
  var notifDropdown = document.getElementById('notifDropdown');
  var notifBadge = document.getElementById('notifBadge');
  var notifCountTag = document.getElementById('notifCountTag');
  var notifList = document.getElementById('notifList');
  var notifLoading = document.getElementById('notifLoading');
  var notifEmpty = document.getElementById('notifEmpty');
  var btnMarkAllRead = document.getElementById('btnMarkAllRead');

  if (!btnNotifToggle || !notifDropdown) {
    return; // Người dùng chưa đăng nhập hoặc không có chuông thông báo
  }

  var apiUrl = (window.BASE_URL || '') + '/client/thongbao_api.php';
  var isLoaded = false;
  var isOpen = false;

  // Thoát HTML: tên người gửi do người dùng tự đặt nên không được chèn thẳng vào innerHTML
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var ICON_LOAI = { rep_bl: '💬', loi_moi_kb: '👋', chap_nhan_kb: '🤝' };

  // Cập nhật số đếm trên badge
  function capNhatBadge(count) {
    count = parseInt(count, 10) || 0;
    if (count > 0) {
      notifBadge.textContent = count > 99 ? '99+' : count;
      notifBadge.classList.add('has-unread');
      if (notifCountTag) { notifCountTag.textContent = count + ' mới'; }
    } else {
      notifBadge.textContent = '';
      notifBadge.classList.remove('has-unread');
      if (notifCountTag) { notifCountTag.textContent = '0 mới'; }
    }
  }

  // Tải danh sách thông báo qua API
  function taiDanhSachThongBao(force) {
    if (isLoaded && !force) { return; }
    if (notifLoading) notifLoading.style.display = 'block';
    if (notifList) notifList.innerHTML = '';
    if (notifEmpty) notifEmpty.style.display = 'none';

    fetch(apiUrl + '?action=lay_danh_sach&limit=8')
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (notifLoading) notifLoading.style.display = 'none';
        if (!data || !data.ok) { return; }
        
        capNhatBadge(data.soChuaDoc);
        isLoaded = true;

        var list = data.danhSach || [];
        if (list.length === 0) {
          if (notifEmpty) notifEmpty.style.display = 'flex';
          return;
        }

        var html = '';
        list.forEach(function (item) {
          var unreadClass = item.da_doc ? 'is-read' : 'is-unread';
          var miniIcon = ICON_LOAI[item.loai] || '📝';
          var avatarHtml = item.anh_nguoi_gui
            ? '<img src="' + esc(item.anh_nguoi_gui) + '" alt="' + esc(item.ten_nguoi_gui) + '" class="drop-avatar-img">'
            : '<span class="drop-avatar-txt">' + esc(item.chu_cai) + '</span>';

          html += '<a href="' + esc(item.lien_ket) + '" class="notif-drop-item ' + unreadClass + '" data-matb="' + esc(item.ma_tb) + '">'
                +   '<div class="notif-drop-avatar">' + avatarHtml + '<span class="notif-drop-mini">' + miniIcon + '</span></div>'
                +   '<div class="notif-drop-content">'
                +     '<div class="notif-drop-text"><strong>' + esc(item.ten_nguoi_gui) + '</strong> ' + esc(item.tieu_de) + '</div>'
                +     (item.noi_dung ? '<div class="notif-drop-snippet">&ldquo;' + esc(item.noi_dung) + '&rdquo;</div>' : '')
                +     '<div class="notif-drop-time">' + esc(item.thoi_gian) + '</div>'
                +   '</div>'
                +   (!item.da_doc ? '<span class="notif-drop-dot" title="Chưa đọc"></span>' : '')
                + '</a>';
        });

        if (notifList) {
          notifList.innerHTML = html;
        }
      })
      .catch(function (err) {
        if (notifLoading) notifLoading.style.display = 'none';
        console.error('Lỗi tải thông báo:', err);
      });
  }

  function dongDropdown() {
    if (!isOpen) { return; }
    isOpen = false;
    notifDropdown.hidden = true;
    btnNotifToggle.setAttribute('aria-expanded', 'false');
    btnNotifToggle.classList.remove('is-active');
  }

  // Đóng / mở dropdown khi bấm chuông
  function toggleDropdown() {
    if (isOpen) { dongDropdown(); return; }
    isOpen = true;
    notifDropdown.hidden = false;
    btnNotifToggle.setAttribute('aria-expanded', 'true');
    btnNotifToggle.classList.add('is-active');
    taiDanhSachThongBao();
  }

  btnNotifToggle.addEventListener('click', function (e) {
    e.stopPropagation();
    toggleDropdown();
  });

  // Đóng dropdown khi click ra ngoài
  document.addEventListener('click', function (e) {
    if (isOpen && navNotifWrap && !navNotifWrap.contains(e.target)) {
      dongDropdown();
    }
  });

  // Chỉ mở một khung một lúc: rê chuột hoặc focus vào menu Hồ sơ thì đóng khung thông báo
  var menuHoSo = document.querySelector('.nav-dropdown');
  if (menuHoSo) {
    menuHoSo.addEventListener('mouseenter', dongDropdown);
    menuHoSo.addEventListener('focusin', dongDropdown);
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { dongDropdown(); }
  });

  // Đánh dấu tất cả là đã đọc
  if (btnMarkAllRead) {
    btnMarkAllRead.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      fetch(apiUrl + '?action=doc_het', { method: 'POST' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data && data.ok) {
            capNhatBadge(0);
            if (notifList) {
              notifList.querySelectorAll('.notif-drop-item').forEach(function (el) {
                el.classList.remove('is-unread');
                el.classList.add('is-read');
                var dot = el.querySelector('.notif-drop-dot');
                if (dot) dot.remove();
              });
            }
          }
        });
    });
  }

  // Bấm vào từng thông báo -> đánh dấu đã đọc trước khi chuyển trang
  if (notifList) {
    notifList.addEventListener('click', function (e) {
      var item = e.target.closest('.notif-drop-item');
      if (!item) return;
      var matb = item.getAttribute('data-matb');
      if (matb && item.classList.contains('is-unread')) {
        var formData = new FormData();
        formData.append('ma_tb', matb);
        fetch(apiUrl + '?action=doc_mot', { method: 'POST', body: formData });
      }
    });
  }

  // Polling định kỳ mỗi 20 giây để cập nhật số thông báo chưa đọc trong thời gian thực
  setInterval(function () {
    fetch(apiUrl + '?action=dem')
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data && data.ok) {
          capNhatBadge(data.soChuaDoc);
          if (isOpen) {
            taiDanhSachThongBao(true);
          }
        }
      })
      .catch(function () {});
  }, 20000);

})();