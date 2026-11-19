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
  // Đảm bảo dù người dùng bấm vào icon ngọn lửa, số đếm hay padding viền nút đều phản hồi lập tức, không bị bôi đen
  document.addEventListener('selectstart', function (e) {
    if (e.target.closest && e.target.closest('.cd-tha-lua, .cd-chi-so')) {
      e.preventDefault();
    }
  });

  document.addEventListener('mousedown', function (e) {
    if (e.target.closest && e.target.closest('.cd-tha-lua, .cd-chi-so')) {
      if (e.detail > 1) {
        e.preventDefault();
      }
      if (window.getSelection) {
        try { window.getSelection().removeAllRanges(); } catch (err) {}
      }
    }
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.cd-tha-lua') : null;
    if (!btn) return;

    var form = btn.closest('form.cd-like-form, form.cd-like-bl-form');
    if (!form) return;

    e.preventDefault();
    e.stopPropagation();
    if (window.getSelection) {
      try { window.getSelection().removeAllRanges(); } catch (err) {}
    }
    thucHienThaLua(form, btn);
  });

  // 3. Trả lời bình luận: tra cứu phần tử lúc dùng vì vùng bình luận có thể được thay mới sau khi gửi form
  function $(id) { return document.getElementById(id); }

  document.addEventListener('click', function (e) {
    var huy = e.target.closest ? e.target.closest('#btnCancelRep') : null;
    if (huy) {
      if ($('rep_mand')) $('rep_mand').value = '';
      if ($('rep_bl_id')) $('rep_bl_id').value = '';
      if ($('repIndicator')) $('repIndicator').style.display = 'none';
      var tx = $('noi_dung');
      if (tx && tx.value.startsWith('@')) {
        tx.value = tx.value.replace(/^@[^\s]+\s*/, '');
      }
      return;
    }

    var btn = e.target.closest ? e.target.closest('.btn-rep-bl') : null;
    if (!btn) return;
    e.preventDefault();
    var ten = btn.getAttribute('data-ten') || '';
    if ($('rep_mand')) $('rep_mand').value = btn.getAttribute('data-mand') || '';
    if ($('rep_bl_id')) $('rep_bl_id').value = btn.getAttribute('data-bl-id') || '';
    if ($('repTenDisplay')) $('repTenDisplay').textContent = '@' + ten;
    if ($('repIndicator')) $('repIndicator').style.display = 'inline-flex';

    var textarea = $('noi_dung');
    if (textarea) {
      var prefix = '@' + ten + ' ';
      if (!textarea.value.startsWith(prefix)) {
        textarea.value = prefix + textarea.value;
      }
      if ($('form-bl')) {
        $('form-bl').scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      textarea.focus();
    }
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
  // 3. AJAX Kết bạn trực tiếp cạnh tên tác giả bài đăng & từng bình luận (đồng bộ tất cả bình luận của cùng user)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-friend-action]') : null;
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    if (btn.dataset.loading === '1') return;
    var action = btn.getAttribute('data-friend-action');
    var uid = btn.getAttribute('data-friend-uid');
    if (!action || !uid) return;

    var metaCsrf = document.querySelector('meta[name="csrf-token"]');
    var inputCsrf = document.querySelector('input[name="csrf"]');
    var csrfVal = (metaCsrf && metaCsrf.getAttribute('content')) || (inputCsrf && inputCsrf.value) || '';
    if (!csrfVal) return;

    btn.dataset.loading = '1';
    btn.disabled = true;

    var fd = new FormData();
    fd.append('csrf', csrfVal);
    fd.append('hanh_dong', 'ajax_ket_ban');
    fd.append('kieu', action);
    fd.append('nguoi', uid);

    fetch(window.location.href, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.yeuCauLogin && data.redirect) {
          window.location.href = data.redirect;
          return;
        }
        if (data.html) {
          document.querySelectorAll('[data-friend-widget="' + uid + '"]').forEach(function (el) {
            el.outerHTML = data.html;
          });
        } else {
          btn.disabled = false;
        }
        if (data.msg && window.CosmicUI && window.CosmicUI.toast) {
          window.CosmicUI.toast(data.msg, data.ok ? 'thanhcong' : 'loi');
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.dataset.loading = '0';
        if (window.CosmicUI && window.CosmicUI.toast) {
          window.CosmicUI.toast('Lỗi kết nối khi thao tác bạn bè.', 'loi');
        }
      });
  });
})();
