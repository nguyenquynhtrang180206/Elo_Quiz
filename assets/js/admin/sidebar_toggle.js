/**
 * Quizora Admin - Sidebar Toggle & Responsive Persistence Controller
 * Quản lý ẩn/hiện thanh Sidebar menu trên toàn bộ hệ thống Admin:
 * - MÁY TÍNH: ẩn/hiện Sidebar, lưu trạng thái vào localStorage (ghi nhớ xuyên suốt mọi trang)
 * - ĐIỆN THOẠI (<= 768px): Sidebar là menu trượt, mở bằng nút 3 gạch (#adminHamburger),
 *   đóng bằng nền mờ / nút thu gọn / chọn một mục / phím Esc. Không dùng localStorage.
 * - Hỗ trợ phím tắt tiện lợi: Ctrl + B (hoặc Cmd + B)
 */
(function () {
  'use strict';

  const STORAGE_KEY = 'quizora_sidebar_hidden';
  const MOBILE_MQ = window.matchMedia('(max-width: 768px)');

  function isHidden() {
    return localStorage.getItem(STORAGE_KEY) === '1';
  }

  // Chỉ bật chế độ menu trượt khi trang có nút 3 gạch (các trang khung giao diện mới không bị ảnh hưởng)
  function coNutBaGach() {
    return !!document.getElementById('adminHamburger');
  }
  function laDienThoai() {
    return coNutBaGach() && MOBILE_MQ.matches;
  }

  /* ---------- Điện thoại: menu trượt ---------- */
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

  /* ---------- Máy tính: ẩn/hiện Sidebar ---------- */
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

    // Cập nhật thuộc tính aria-expanded cho khả năng truy cập
    const collapseBtn = document.getElementById('sidebarCollapseBtn');
    const expandBtn = document.getElementById('sidebarExpandBtn');
    if (collapseBtn) collapseBtn.setAttribute('aria-expanded', !hidden);
    if (expandBtn) expandBtn.setAttribute('aria-expanded', !hidden);

    // Bắn sự kiện window resize nhẹ để các biểu đồ Chart / Bảng biểu tự điều chỉnh layout
    setTimeout(function () {
      window.dispatchEvent(new Event('resize'));
    }, 300);
  }

  function initSidebar() {
    // Áp dụng trạng thái đã lưu (chỉ cho máy tính)
    if (isHidden() && !laDienThoai()) {
      setSidebarState(true);
    }

    // Nút 3 gạch (điện thoại): mở menu
    const burger = document.getElementById('adminHamburger');
    if (burger) {
      burger.addEventListener('click', function (e) {
        e.preventDefault();
        setMobileOpen(true);
      });
    }

    // Nền mờ: đóng menu
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
      overlay.addEventListener('click', function () {
        setMobileOpen(false);
      });
    }

    // Chọn một mục trong menu -> đóng menu (phòng khi trang không tải lại)
    const sidebar = document.getElementById('adminSidebar');
    if (sidebar) {
      sidebar.addEventListener('click', function (e) {
        if (laDienThoai() && e.target.closest && e.target.closest('a')) {
          setMobileOpen(false);
        }
      });
    }

    // Bắt sự kiện tất cả các nút Thu gọn trên Sidebar
    // (trên điện thoại nút này đóng menu trượt)
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

    // Bắt sự kiện tất cả các nút Mở nổi khi Sidebar ẩn (máy tính)
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

    // Phím Esc: đóng menu trượt
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isMobileOpen()) {
        setMobileOpen(false);
      }
    });

    // Phím tắt Ctrl + B (hoặc Cmd + B)
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

    // Xoay ngang màn hình / đổi kích thước qua mốc 768px: đóng menu trượt, trả lại trạng thái máy tính
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

  // Khởi tạo khi DOM sẵn sàng
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebar);
  } else {
    initSidebar();
  }
})();