/* Trang Cộng đồng (client/congdong.php) - Theme Cosmic Esports Arena */
(function () {
  // 1. Lọc môn / loại bài / từ khóa: mở mềm bằng capnhat_mem.js (chỉ thay nội dung, không tải lại trang, không nhảy lên banner)
  //    Dùng ủy quyền sự kiện vì vùng bộ lọc được thay mới sau mỗi lần bấm
  function locMem(form) {
    var qs = new URLSearchParams();
    new FormData(form).forEach(function (v, k) {
      v = String(v).trim();
      if (v !== '' && v !== '0') { qs.append(k, v); }
    });
    var a = document.createElement('a');
    a.href = '?' + qs.toString();
    a.hidden = true;
    form.appendChild(a);
    a.click();
    if (a.parentNode) { a.parentNode.removeChild(a); }
  }
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && (t.id === 'loc_mon' || t.id === 'loc_loai') && t.form) { locMem(t.form); }
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.id === 'formLocCD') { e.preventDefault(); locMem(f); }
  });

  // 2. Thu gọn / Mở rộng form Viết bài mới mượt mà
  // Tra cứu phần tử lúc dùng (không giữ tham chiếu) vì vùng nội dung có thể được thay mới sau khi gửi form
  function layCard() { return document.getElementById('khung-dang-bai'); }
  function layToggle() { return document.getElementById('cd-write-toggle'); }

  function moFormDangBai(focusInput) {
    var writeCard = layCard(), writeToggle = layToggle();
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
    var writeCard = layCard(), writeToggle = layToggle();
    if (!writeCard) { return; }
    writeCard.classList.remove('is-open');
    if (writeToggle) {
      writeToggle.setAttribute('aria-expanded', 'false');
      var txt = writeToggle.querySelector('.btn-toggle-write .toggle-text');
      if (txt) { txt.textContent = 'Viết bài'; }
    }
  }

  function toggleFormDangBai() {
    var writeCard = layCard();
    if (!writeCard) { return; }
    if (writeCard.classList.contains('is-open')) {
      dongFormDangBai();
    } else {
      moFormDangBai(true);
    }
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#cd-write-toggle')) { return; }
    // Bỏ qua nếu click phát sinh từ bên trong form bài đăng
    if (e.target.closest('#cd-write-body')) { return; }
    toggleFormDangBai();
  });

  document.addEventListener('keydown', function (e) {
    if (e.target !== layToggle()) { return; }
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      toggleFormDangBai();
    }
  });

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
  // (ủy quyền ở pha bắt sự kiện để chạy trước bộ mở mềm, vì nút trong sidebar bị thay mới sau mỗi lần bấm)
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.cd-tab-btn.active[href*="tab=moi"]') : null;
    if (!b) { return; }
    if (location.search.indexOf('tab=moi') !== -1 || location.search === '' || location.search === '?') {
      e.preventDefault();
      e.stopPropagation();
      moFormDangBai(true);
    }
  }, true);

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

  // 2b. Bộ chọn môn học tương tác: hiện 5 môn + tìm kiếm nhanh + xem thêm (tối ưu 20+ môn)
  // Quy tắc để chọn môn, tìm kiếm và thu gọn không xung đột nhau:
  //  - "Không gắn môn" luôn hiện (để bỏ chọn bất cứ lúc nào), không tính vào kết quả tìm kiếm.
  //  - Đang tìm: hiện môn khớp từ khóa, cộng với môn đang chọn (giữ viền sáng) để người dùng luôn thấy mình đang chọn gì.
  //    Bấm chọn một môn thì xóa từ khóa và quay lại danh sách thường.
  //  - Không tìm: hiện 5 môn đầu; môn đang chọn luôn hiện dù nằm ngoài 5 môn đầu, kể cả khi đang thu gọn.
  //  - Nhấn Enter trong ô tìm kiếm không được gửi form đăng bài: chỉ chọn môn nếu còn đúng 1 kết quả.
  function khoiTaoMonPicker() {
    var monHiddenInput = document.getElementById('mon');
    var monSelectedDisplay = document.getElementById('monSelectedDisplay');
    var monSearchInput = document.getElementById('monSearchInput');
    var monClearSearch = document.getElementById('monClearSearch');
    var btnXemThemMon = document.getElementById('btnXemThemMon');
    var cdMonGrid = document.getElementById('cdMonGrid');
    var monNoMatch = document.getElementById('monNoMatch');
    if (!cdMonGrid) { return; }

    var SO_MON_HIEN_SAN = 5;
    var allPills = Array.prototype.slice.call(cdMonGrid.querySelectorAll('.cd-mon-pill'));
    var pillKhong = allPills.filter(function (p) { return (p.getAttribute('data-mon-id') || '') === ''; })[0] || null;
    var pillMon = allPills.filter(function (p) { return (p.getAttribute('data-mon-id') || '') !== ''; });
    var isExpanded = false;
    var dangKhop = [];   // các môn khớp từ khóa tìm kiếm hiện tại (không tính môn đang chọn nếu nó không khớp)

    // Đánh dấu môn "xem thêm" theo thứ tự, không phụ thuộc môn nào đang được chọn lúc tải trang
    pillMon.forEach(function (p, i) { p.classList.toggle('is-extra', i >= SO_MON_HIEN_SAN); });

    // Tìm kiếm môn học không dấu & có dấu
    function boDauTiengViet(str) {
      return (str || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'd');
    }

    // Tính lại môn nào hiện, môn nào ẩn từ trạng thái hiện tại (từ khóa, môn đang chọn, đang mở rộng hay không)
    function hienThi() {
      var q = boDauTiengViet(monSearchInput ? monSearchInput.value.trim() : '');
      var dangTim = q !== '';
      var chon = cdMonGrid.querySelector('.cd-mon-pill.is-selected');
      var khop = 0;
      var anDi = 0;
      dangKhop = [];

      allPills.forEach(function (p) {
        var hien;
        if (p === pillKhong) {
          hien = true;
        } else if (dangTim) {
          var trungTuKhoa = boDauTiengViet(p.getAttribute('data-mon-ten') || '').indexOf(q) !== -1;
          if (trungTuKhoa) { khop++; dangKhop.push(p); }
          hien = trungTuKhoa || p === chon;   // môn đang chọn luôn hiện để không bị tưởng là đã bỏ chọn
        } else {
          hien = !p.classList.contains('is-extra') || isExpanded || p === chon;
          if (!hien) { anDi++; }
        }
        p.style.display = hien ? 'inline-flex' : 'none';
      });

      if (monClearSearch) { monClearSearch.style.display = dangTim ? 'block' : 'none'; }
      if (monNoMatch) { monNoMatch.style.display = dangTim && khop === 0 ? 'block' : 'none'; }

      if (btnXemThemMon) {
        // Ẩn nút khi đang tìm kiếm, hoặc khi đang thu gọn mà không còn môn nào để xem thêm
        btnXemThemMon.style.display = (dangTim || (!isExpanded && anDi === 0)) ? 'none' : '';
        btnXemThemMon.classList.toggle('is-open', isExpanded);
        var txt = btnXemThemMon.querySelector('.xem-them-text');
        if (txt) { txt.textContent = isExpanded ? 'Thu gọn môn học' : 'Xem thêm +' + anDi + ' môn khác'; }
      }
    }

    // Chọn môn học khi click vào pill
    cdMonGrid.addEventListener('click', function (e) {
      var pill = e.target.closest('.cd-mon-pill');
      if (!pill) { return; }
      var monId = pill.getAttribute('data-mon-id') || '';
      var monTen = pill.getAttribute('data-mon-ten') || 'Không gắn môn';

      allPills.forEach(function (p) { p.classList.remove('is-selected'); });
      pill.classList.add('is-selected');

      if (monHiddenInput) { monHiddenInput.value = monId; }
      if (monSelectedDisplay) {
        monSelectedDisplay.textContent = monTen;
        var badge = monSelectedDisplay.parentNode;
        badge.classList.remove('is-pulse');
        void badge.offsetWidth; // restart animation
        badge.classList.add('is-pulse');
      }

      // Chọn xong thì thoát chế độ tìm kiếm để thấy môn vừa chọn trong danh sách thường
      if (monSearchInput) { monSearchInput.value = ''; }
      hienThi();
    });

    // Toggle xem thêm môn
    if (btnXemThemMon) {
      btnXemThemMon.addEventListener('click', function () {
        isExpanded = !isExpanded;
        hienThi();
      });
    }

    if (monSearchInput) {
      monSearchInput.addEventListener('input', hienThi);

      monSearchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          // Không để Enter gửi form đăng bài; còn đúng 1 kết quả thì chọn luôn môn đó
          e.preventDefault();
          if (dangKhop.length === 1) { dangKhop[0].click(); }
        } else if (e.key === 'Escape') {
          monSearchInput.value = '';
          hienThi();
        }
      });

      if (monClearSearch) {
        monClearSearch.addEventListener('click', function () {
          monSearchInput.value = '';
          hienThi();
          monSearchInput.focus();
        });
      }
    }

    hienThi();
  }
  khoiTaoMonPicker();
  document.addEventListener('mem:capnhat', khoiTaoMonPicker);   // vùng bài đăng vừa được thay mới sau khi gửi form

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

  // 3b. Bấm vào bất kỳ chỗ nào trên khung bài đăng thì mở bài và nhảy tới phần bình luận
  //     (bỏ qua khi bấm link, nút, form, ô nhập, widget bạn bè, hoặc đang bôi đen chữ)
  document.addEventListener('click', function (e) {
    var the = e.target.closest ? e.target.closest('.cd-the[data-href]') : null;
    if (!the) { return; }
    if (e.target.closest('a, button, form, input, textarea, select, label, summary, details, .cd-friend-widget')) { return; }
    var chon = window.getSelection ? window.getSelection().toString() : '';
    if (chon) { return; }
    if (e.ctrlKey || e.metaKey) {
      window.open(the.getAttribute('data-href'), '_blank');
      return;
    }
    window.location.href = the.getAttribute('data-href');
  });

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
    var form = btn.closest('form.cd-like-form');
    if (!form) return;
    e.preventDefault();
    e.stopPropagation();
    if (window.getSelection) {
      try { window.getSelection().removeAllRanges(); } catch (err) {}
    }
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

  // 6. AJAX Kết bạn trực tiếp & Đồng bộ toàn trang (MVP, Thành viên, Bảng tin)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-friend-action]') : null;
    if (!btn) { return; }
    e.preventDefault();
    e.stopPropagation();

    if (btn.dataset.loading === '1') { return; }
    var action = btn.getAttribute('data-friend-action');
    var uid = btn.getAttribute('data-friend-uid');
    if (!action || !uid) { return; }

    var metaCsrf = document.querySelector('meta[name="csrf-token"]');
    var inputCsrf = document.querySelector('input[name="csrf"]');
    var csrfVal = (metaCsrf && metaCsrf.getAttribute('content')) || (inputCsrf && inputCsrf.value) || '';
    if (!csrfVal) { return; }

    btn.dataset.loading = '1';
    var oldHtml = btn.innerHTML;
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
          btn.innerHTML = oldHtml;
        }
        if (data.msg) {
          var ico = data.trangThai === 'ban_be' ? '🤝' : (data.ok ? '👋' : '⚠️');
          hienThiToast(data.msg, ico);
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.dataset.loading = '0';
        btn.innerHTML = oldHtml;
        hienThiToast('Lỗi kết nối khi thao tác bạn bè.', '⚠️');
      });
  });
})();