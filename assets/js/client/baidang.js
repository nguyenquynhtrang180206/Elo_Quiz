/* Trang Bài đăng (client/baidang.php) */
(function () {
  'use strict';

  // 1. Hỏi xác nhận trước khi gửi form có data-xac-nhan
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form) return;

    var cauHoi = form.getAttribute('data-xac-nhan');
    if (cauHoi && !confirm(cauHoi)) {
      e.preventDefault();
      return;
    }

    // Nếu form thả lửa gửi qua submit tự nhiên, chuyển sang xử lý AJAX
    if (form.classList.contains('cd-like-form') || form.classList.contains('cd-like-bl-form')) {
      e.preventDefault();
      thucHienThaLua(form);
    }
  });

  // 2. Bắt trực tiếp sự kiện CLICK trên nút Thả Lửa 🔥
  // Đảm bảo dù người dùng bấm vào icon ngọn lửa, số đếm hay padding viền nút đều phản hồi lập tức
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.cd-tha-lua') : null;
    if (!btn) return;

    var form = btn.closest('form.cd-like-form, form.cd-like-bl-form');
    if (!form) return;

    e.preventDefault();
    e.stopPropagation();
    thucHienThaLua(form, btn);
  });

  function thucHienThaLua(form, btnTarget) {
    var btn = btnTarget || form.querySelector('.cd-tha-lua') || form.querySelector('button[type="submit"]');
    if (!btn || btn.dataset.loading === '1') return;

    btn.dataset.loading = '1';
    btn.style.pointerEvents = 'none';

    var formData = new FormData(form);
    formData.append('ajax', '1');

    var actionUrl = form.action || form.getAttribute('action') || window.location.href;

    fetch(actionUrl, {
      method: 'POST',
      body: formData,
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(function (res) {
      return res.json();
    })
    .then(function (data) {
      if (data.yeuCauLogin && data.redirect) {
        window.location.href = data.redirect;
        return;
      }
      if (data.ok) {
        var countSpan = btn.querySelector('.flame-count');
        if (countSpan) {
          countSpan.textContent = data.soThich;
        }
        if (data.daThich) {
          btn.classList.add('da-tha-lua');
          btn.title = 'Dập lửa (Bỏ thả lửa)';
          var icon = btn.querySelector('.flame-icon');
          if (icon) {
            icon.style.transform = 'scale(1.55) rotate(-12deg)';
            setTimeout(function () { icon.style.transform = ''; }, 320);
          }
        } else {
          btn.classList.remove('da-tha-lua');
          btn.title = 'Thả lửa';
        }
      }
    })
    .catch(function (err) {
      console.warn('Lỗi AJAX thả lửa bài đăng/bình luận:', err);
      // Fallback submit form thông thường nếu AJAX gặp sự cố
      form.submit();
    })
    .finally(function () {
      btn.dataset.loading = '0';
      btn.style.pointerEvents = '';
    });
  }
})();
