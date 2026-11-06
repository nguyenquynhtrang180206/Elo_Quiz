/* Trang Cộng đồng (client/congdong.php) - Theme Cosmic Esports Arena */
(function () {
  // 1. Tự động submit lọc khi thay đổi môn học hoặc loại bài
  var chonMon = document.getElementById('loc_mon');
  if (chonMon) {
    chonMon.addEventListener('change', function () { chonMon.form.submit(); });
  }

  var chonLoai = document.getElementById('loc_loai');
  if (chonLoai) {
    chonLoai.addEventListener('change', function () { chonLoai.form.submit(); });
  }

  // 2. Thu gọn / Mở rộng form Viết bài mới mượt mà
  var writeToggle = document.getElementById('cd-write-toggle');
  var writeCard = document.getElementById('khung-dang-bai');

  function moFormDangBai(focusInput) {
    if (!writeCard) { return; }
    if (!writeCard.classList.contains('is-open')) {
      writeCard.classList.add('is-open');
      if (writeToggle) {
        writeToggle.setAttribute('aria-expanded', 'true');
        var txt = writeToggle.querySelector('.btn-toggle-write .toggle-text');
        if (txt) { txt.textContent = 'Thu gọn'; }
      }
    }
    writeCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    if (focusInput) {
      setTimeout(function () {
        var o = document.getElementById('tieu_de');
        if (o) { o.focus(); }
      }, 250);
    }
  }

  function dongFormDangBai() {
    if (!writeCard) { return; }
    writeCard.classList.remove('is-open');
    if (writeToggle) {
      writeToggle.setAttribute('aria-expanded', 'false');
      var txt = writeToggle.querySelector('.btn-toggle-write .toggle-text');
      if (txt) { txt.textContent = 'Viết bài'; }
    }
  }

  function toggleFormDangBai() {
    if (!writeCard) { return; }
    if (writeCard.classList.contains('is-open')) {
      dongFormDangBai();
    } else {
      moFormDangBai(true);
    }
  }

  if (writeToggle && writeCard) {
    writeToggle.addEventListener('click', function (e) {
      // Bỏ qua nếu click phát sinh từ bên trong form bài đăng
      if (e.target.closest('#cd-write-body')) { return; }
      toggleFormDangBai();
    });

    writeToggle.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        toggleFormDangBai();
      }
    });
  }

  // Bấm các nút "Viết bài" ngoài trang (như banner, CTA) -> bung form và focus
  document.querySelectorAll('[data-mo-form]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      moFormDangBai(true);
    });
  });

  // Nếu truy cập kèm hashtag #khung-dang-bai hoặc #dang-bai -> tự động bung form
  if (location.hash === '#khung-dang-bai' || location.hash === '#dang-bai') {
    moFormDangBai(true);
  }

  // Khi bấm nút "✏️ Bài đăng mới" trên Sidebar nếu đang ở tab 'moi' -> tự động bung form
  var tabMoiBtn = document.querySelector('.cd-tab-btn[href*="tab=moi"].active');
  if (tabMoiBtn) {
    tabMoiBtn.addEventListener('click', function (e) {
      if (location.search.indexOf('tab=moi') !== -1 || location.search === '' || location.search === '?') {
        e.preventDefault();
        moFormDangBai(true);
      }
    });
  }

  // Bấm nút "Viết bài ngay" trên Hero Banner -> bung form và cuộn xuống
  var btnHeroDangBai = document.getElementById('btnHeroDangBai');
  if (btnHeroDangBai) {
    btnHeroDangBai.addEventListener('click', function (e) {
      e.preventDefault();
      moFormDangBai(true);
    });
  }

  // Floating Toast thông báo hiệu ứng Cyber Esports
  function hienThiToast(noiDung, bieuTuong) {
    var oldToast = document.getElementById('cd-floating-toast');
    if (oldToast) { oldToast.remove(); }
    var toast = document.createElement('div');
    toast.id = 'cd-floating-toast';
    toast.className = 'cd-floating-toast';
    toast.innerHTML = '<span class="toast-icon">' + (bieuTuong || '🎁') + '</span><span>' + noiDung + '</span>';
    document.body.appendChild(toast);
    setTimeout(function () { toast.classList.add('is-visible'); }, 15);
    setTimeout(function () {
      toast.classList.remove('is-visible');
      setTimeout(function () { toast.remove(); }, 350);
    }, 4500);
  }

  // 2b. Bộ chọn môn học tương tác: Hiện 5 môn + Tìm kiếm nhanh + Xem thêm (Tối ưu 20+ môn)
  (function initMonPicker() {
    var monHiddenInput = document.getElementById('mon');
    var monSelectedDisplay = document.getElementById('monSelectedDisplay');
    var monSearchInput = document.getElementById('monSearchInput');
    var monClearSearch = document.getElementById('monClearSearch');
    var btnXemThemMon = document.getElementById('btnXemThemMon');
    var cdMonGrid = document.getElementById('cdMonGrid');
    var monNoMatch = document.getElementById('monNoMatch');
    if (!cdMonGrid) { return; }

    var allPills = cdMonGrid.querySelectorAll('.cd-mon-pill');
    var extraPills = cdMonGrid.querySelectorAll('.cd-mon-pill.is-extra');
    var isExpanded = false;

    // Chọn môn học khi click vào pill
    cdMonGrid.addEventListener('click', function (e) {
      var pill = e.target.closest('.cd-mon-pill');
      if (!pill) { return; }
      var monId = pill.getAttribute('data-mon-id') || '';
      var monTen = pill.getAttribute('data-mon-ten') || 'Không gắn môn';

      allPills.forEach(function (p) { p.classList.remove('is-selected'); });
      pill.classList.add('is-selected');

      if (monHiddenInput) { monHiddenInput.value = monId; }
      if (monSelectedDisplay) { monSelectedDisplay.textContent = monTen; }
    });

    // Toggle xem thêm môn
    if (btnXemThemMon) {
      btnXemThemMon.addEventListener('click', function () {
        isExpanded = !isExpanded;
        btnXemThemMon.classList.toggle('is-open', isExpanded);
        var txt = btnXemThemMon.querySelector('.xem-them-text');
        if (txt) {
          txt.textContent = isExpanded ? 'Thu gọn môn học' : 'Xem thêm +' + extraPills.length + ' môn khác';
        }
        extraPills.forEach(function (p) {
          p.style.display = isExpanded ? 'inline-flex' : 'none';
        });
      });
    }

    // Tìm kiếm môn học không dấu & có dấu
    function boDauTiengViet(str) {
      return (str || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'd');
    }

    if (monSearchInput) {
      monSearchInput.addEventListener('input', function () {
        var q = boDauTiengViet(monSearchInput.value.trim());
        if (monClearSearch) {
          monClearSearch.style.display = q !== '' ? 'block' : 'none';
        }

        if (q === '') {
          // Khôi phục trạng thái ban đầu
          allPills.forEach(function (p) {
            if (p.classList.contains('is-extra')) {
              p.style.display = isExpanded ? 'inline-flex' : 'none';
            } else {
              p.style.display = 'inline-flex';
            }
          });
          if (monNoMatch) { monNoMatch.style.display = 'none'; }
          if (btnXemThemMon) { btnXemThemMon.style.display = ''; }
          return;
        }

        // Đang tìm kiếm -> ẩn nút xem thêm và lọc tất cả pills
        if (btnXemThemMon) { btnXemThemMon.style.display = 'none'; }
        var count = 0;
        allPills.forEach(function (p) {
          var name = boDauTiengViet(p.getAttribute('data-mon-ten') || '');
          if (name.indexOf(q) !== -1) {
            p.style.display = 'inline-flex';
            count++;
          } else {
            p.style.display = 'none';
          }
        });
        if (monNoMatch) {
          monNoMatch.style.display = count === 0 ? 'block' : 'none';
        }
      });

      if (monClearSearch) {
        monClearSearch.addEventListener('click', function () {
          monSearchInput.value = '';
          monSearchInput.dispatchEvent(new Event('input'));
          monSearchInput.focus();
        });
      }
    }
  })();

  // 3. Tương tác Thẻ Ngôi Sao Tri Thức Tuần (Weekly MVP)
  var cdMvpCard = document.getElementById('cdMvpCard');
  if (cdMvpCard) {
    cdMvpCard.addEventListener('click', function (e) {
      if (e.target.closest('a') || e.target.closest('button')) { return; }
      var link = cdMvpCard.querySelector('.cd-mvp-link');
      if (link && link.href) {
        window.location.href = link.href;
      }
    });
  }

  // 4. Hiệu ứng nhấp nháy / nảy vui nhộn khi click vào Chibi Faker ở Sidebar
  var fakerBtn = document.getElementById('cd-faker-btn');
  if (fakerBtn) {
    fakerBtn.addEventListener('click', function () {
      fakerBtn.classList.remove('faker-bounce');
      void fakerBtn.offsetWidth; // trigger reflow
      fakerBtn.classList.add('faker-bounce');
    });
  }

  // 5. AJAX Thả Lửa 🔥 Không Giật Lag / Không Tải Lại Trang
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.classList.contains('cd-like-form')) { return; }
    e.preventDefault();
    thucHienThaLuaCD(form);
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.cd-tha-lua') : null;
    if (!btn) return;
    var form = btn.closest('form.cd-like-form');
    if (!form) return;
    e.preventDefault();
    e.stopPropagation();
    thucHienThaLuaCD(form, btn);
  });

  function thucHienThaLuaCD(form, btnTarget) {
    var btn = btnTarget || form.querySelector('.cd-tha-lua') || form.querySelector('button[type="submit"]');
    if (!btn || btn.dataset.loading === '1') { return; }

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
    .then(function (res) { return res.json(); })
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
          btn.title = 'Thả lửa bài viết này';
        }
      }
    })
    .catch(function (err) {
      console.warn('Lỗi AJAX thả lửa cộng đồng:', err);
      form.submit();
    })
    .finally(function () {
      btn.dataset.loading = '0';
      btn.style.pointerEvents = '';
    });
  }
})();
