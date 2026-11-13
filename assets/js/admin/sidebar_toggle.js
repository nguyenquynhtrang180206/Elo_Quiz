/**
 * Quizora Admin - Sidebar Toggle & Responsive Persistence Controller
 * Quản lý ẩn/hiện thanh Sidebar menu trên toàn bộ hệ thống Admin:
 * - Lưu trạng thái vào localStorage (ghi nhớ xuyên suốt mọi trang)
 * - Tự động co giãn nội dung 100% khi ẩn, khít màn hình hiển thị
 * - Hỗ trợ phím tắt tiện lợi: Ctrl + B (hoặc Cmd + B)
 */
(function () {
  'use strict';

  const STORAGE_KEY = 'quizora_sidebar_hidden';

  function isHidden() {
    return localStorage.getItem(STORAGE_KEY) === '1';
  }

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
    // Áp dụng trạng thái đã lưu
    if (isHidden()) {
      setSidebarState(true);
    }

    // Bắt sự kiện tất cả các nút Thu gọn trên Sidebar
    document.querySelectorAll('.sidebar-toggle-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        setSidebarState(true);
      });
    });

    // Bắt sự kiện tất cả các nút Mở nổi khi Sidebar ẩn
    document.querySelectorAll('.sidebar-expand-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        setSidebarState(false);
      });
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
        setSidebarState(!isHidden());
      }
    });
  }

  // Khởi tạo khi DOM sẵn sàng
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebar);
  } else {
    initSidebar();
  }
})();
