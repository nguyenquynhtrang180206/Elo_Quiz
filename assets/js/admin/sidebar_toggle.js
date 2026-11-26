// Điều khiển ẩn/hiện Sidebar Admin (localStorage trên desktop, menu trượt trên mobile).
(function () {
  'use strict';

  const STORAGE_KEY = 'quizora_sidebar_hidden';
  const MOBILE_MQ = window.matchMedia('(max-width: 768px)');

  function isHidden() {
    return localStorage.getItem(STORAGE_KEY) === '1';
  }

  // Chỉ bật menu trượt khi trang có nút #adminHamburger
  function coNutBaGach() {
    return !!document.getElementById('adminHamburger');
  }
  function laDienThoai() {
    return coNutBaGach() && MOBILE_MQ.matches;
  }

  // Menu trượt trên điện thoại
  function isMobileOpen() {
    return document.body.classList.contains('sidebar-open');
  }

  function setMobileOpen(open) {
    document.body.classList.toggle('sidebar-open', open);
    const burger = document.getElementById('adminHamburger');
    if (burger) {
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  }

  // Ẩn/hiện Sidebar trên máy tính
  function setSidebarState(hidden) {
    if (hidden) {
      document.documentElement.classList.add('sidebar-hidden');
      document.body && document.body.classList.add('sidebar-hidden');
      localStorage.setItem(STORAGE_KEY, '1');
    } else {
      document.documentElement.classList.remove('sidebar-hidden');
      document.body && document.body.classList.remove('sidebar-hidden');
      localStorage.setItem(STORAGE_KEY, '0');
    }

    const collapseBtn = document.getElementById('sidebarCollapseBtn');
    const expandBtn = document.getElementById('sidebarExpandBtn');
    if (collapseBtn) collapseBtn.setAttribute('aria-expanded', !hidden);
    if (expandBtn) expandBtn.setAttribute('aria-expanded', !hidden);

    // Kích hoạt resize để biểu đồ và bảng tự điều chỉnh kích thước
    setTimeout(function () {
      window.dispatchEvent(new Event('resize'));
    }, 300);
  }

  function initSidebar() {
    if (isHidden() && !laDienThoai()) {
      setSidebarState(true);
    }

    const burger = document.getElementById('adminHamburger');
    if (burger) {
      burger.addEventListener('click', function (e) {
        e.preventDefault();
        setMobileOpen(true);
      });
    }

    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
      overlay.addEventListener('click', function () {
        setMobileOpen(false);
      });
    }

    const sidebar = document.getElementById('adminSidebar');
    if (sidebar) {
      sidebar.addEventListener('click', function (e) {
        if (laDienThoai() && e.target.closest && e.target.closest('a')) {
          setMobileOpen(false);
        }
      });
    }

    document.querySelectorAll('.sidebar-toggle-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (laDienThoai()) {
          setMobileOpen(false);
        } else {
          setSidebarState(true);
        }
      });
    });

    document.querySelectorAll('.sidebar-expand-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (laDienThoai()) {
          setMobileOpen(true);
        } else {
          setSidebarState(false);
        }
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isMobileOpen()) {
        setMobileOpen(false);
      }
    });

    // Phím tắt Ctrl+B / Cmd+B
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'B')) {
        // Tránh can thiệp khi đang gõ text trong input/textarea
        const tag = (e.target && e.target.tagName) || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) {
          return;
        }
        e.preventDefault();
        if (laDienThoai()) {
          setMobileOpen(!isMobileOpen());
        } else {
          setSidebarState(!isHidden());
        }
      }
    });

    // Đóng menu trượt khi đổi kích thước qua mốc 768px
    const onMqChange = function () {
      setMobileOpen(false);
      if (!laDienThoai() && isHidden()) {
        setSidebarState(true);
      }
    };
    if (MOBILE_MQ.addEventListener) {
      MOBILE_MQ.addEventListener('change', onMqChange);
    } else if (MOBILE_MQ.addListener) {
      MOBILE_MQ.addListener(onMqChange);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebar);
  } else {
    initSidebar();
  }
})();