/* Trang Bài đăng (client/baidang.php) */
document.addEventListener('submit', function (e) {
  var form = e.target;
  if (!form) { return; }

  // 1. Hỏi xác nhận trước khi gửi form có data-xac-nhan
  var cauHoi = form.getAttribute('data-xac-nhan');
  if (cauHoi && !confirm(cauHoi)) {
    e.preventDefault();
    return;
  }

  // 2. AJAX Thả Lửa 🔥 Bài Viết & Bình Luận
  if (form.classList.contains('cd-like-form') || form.classList.contains('cd-like-bl-form')) {
    e.preventDefault();
    var btn = form.querySelector('.cd-tha-lua') || form.querySelector('button[type="submit"]');
    if (!btn) { return; }

    var formData = new FormData(form);
    var actionUrl = form.getAttribute('action') || window.location.href;

    btn.style.pointerEvents = 'none';
    fetch(actionUrl, {
      method: 'POST',
      body: formData,
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      btn.style.pointerEvents = '';
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
            setTimeout(function () { icon.style.transform = ''; }, 300);
          }
        } else {
          btn.classList.remove('da-tha-lua');
          btn.title = 'Thả lửa';
        }
      }
    })
    .catch(function () {
      btn.style.pointerEvents = '';
      form.submit();
    });
  }
});
