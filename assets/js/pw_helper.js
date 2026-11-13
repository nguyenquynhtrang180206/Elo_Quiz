// Hỗ trợ kiểm tra độ mạnh mật khẩu và hiển thị mật khẩu theo thời gian thực
(function () {
  'use strict';

  // 1. Xử lý nút con mắt ẩn/hiện mật khẩu
  var toggleBtns = document.querySelectorAll('.btn-toggle-pw');
  toggleBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrap = btn.closest('.pw-field-wrap');
      if (!wrap) return;
      var input = wrap.querySelector('input');
      if (!input) return;
      var isPw = input.type === 'password';
      input.type = isPw ? 'text' : 'password';
      btn.classList.toggle('active', isPw);
      input.focus();
    });
  });

  // 2. Xử lý kiểm tra thời gian thực
  var pwInput = document.getElementById('mat_khau') || document.getElementById('mat_khau_moi');
  var confirmInput = document.getElementById('xac_nhan');
  var checklist = document.getElementById('pwChecklist');
  var meterBox = document.getElementById('pwMeterBox');
  var meterFill = document.getElementById('pwFill');
  var meterLabel = document.getElementById('pwLabel');

  if (!pwInput) return;

  function updateRule(ruleName, isValid) {
    if (!checklist) return;
    var item = checklist.querySelector('[data-rule="' + ruleName + '"]');
    if (item) {
      item.classList.toggle('valid', isValid);
    }
  }

  function calculateStrength(pw) {
    if (!pw) return 0;
    var score = 0;
    if (pw.length >= 8) score += 25;
    if (pw.length >= 12) score += 10;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score += 25;
    if (/[0-9]/.test(pw)) score += 20;
    if (/[!@#$%^&*()_\-+=\[\]{};:,.<>?/~`|\\]/.test(pw)) score += 20;
    return Math.min(100, score);
  }

  function checkPassword() {
    var pw = pwInput.value;
    var conf = confirmInput ? confirmInput.value : '';

    if (meterBox) {
      meterBox.style.display = pw.length > 0 ? 'block' : 'none';
      var strength = calculateStrength(pw);
      if (meterFill) {
        meterFill.style.width = strength + '%';
        meterFill.className = 'pw-strength-fill';
        if (strength < 40) {
          meterFill.classList.add('weak');
          if (meterLabel) meterLabel.innerHTML = 'Độ mạnh: <span style="color:#ef4444;font-weight:600">Yếu</span>';
        } else if (strength < 75) {
          meterFill.classList.add('medium');
          if (meterLabel) meterLabel.innerHTML = 'Độ mạnh: <span style="color:#f59e0b;font-weight:600">Trung bình</span>';
        } else if (strength < 90) {
          meterFill.classList.add('strong');
          if (meterLabel) meterLabel.innerHTML = 'Độ mạnh: <span style="color:#10b981;font-weight:600">Mạnh</span>';
        } else {
          meterFill.classList.add('secure');
          if (meterLabel) meterLabel.innerHTML = 'Độ mạnh: <span style="color:#06b6d4;font-weight:700">Siêu bảo mật 🛡️</span>';
        }
      }
    }

    // Rules
    updateRule('len', pw.length >= 8 && pw.length <= 72);
    updateRule('case', /[a-z]/.test(pw) && /[A-Z]/.test(pw));
    updateRule('num_sym', /[0-9]/.test(pw) && /[!@#$%^&*()_\-+=\[\]{};:,.<>?/~`|\\]/.test(pw));

    var oldPwInput = document.getElementById('mat_khau_cu');
    if (oldPwInput) {
      updateRule('diff', pw.length > 0 && pw !== oldPwInput.value);
    }

    if (confirmInput) {
      updateRule('match', conf.length > 0 && conf === pw);
    }
  }

  if (pwInput) {
    pwInput.addEventListener('input', checkPassword);
    if (confirmInput) confirmInput.addEventListener('input', checkPassword);
    var oldPwInput = document.getElementById('mat_khau_cu');
    if (oldPwInput) oldPwInput.addEventListener('input', checkPassword);
    checkPassword();
  }

  // 3. Khóa Copy / Paste trên các ô Xác nhận mật khẩu & Mật khẩu chính
  function showNoPasteToast(targetInput, text) {
    var wrap = targetInput.closest('.pw-field-wrap') || targetInput.closest('.auth-field-wrapper') || targetInput.closest('.dmk-o') || targetInput.parentElement;
    if (!wrap) return;

    var existingToast = wrap.querySelector('.no-paste-toast');
    if (existingToast) existingToast.remove();

    var toast = document.createElement('div');
    toast.className = 'no-paste-toast';
    toast.textContent = text;
    wrap.style.position = wrap.style.position || 'relative';
    wrap.appendChild(toast);

    setTimeout(function () {
      if (toast && toast.parentElement) toast.remove();
    }, 2600);
  }

  function blockCopyPasteOnConfirm() {
    // Chặn copy từ ô mật khẩu gốc
    var primaryPws = document.querySelectorAll('input[type="password"]:not([name="xac_nhan"]):not(#xac_nhan):not(#modalRegConfirm):not(#modalConfirmNewPassword)');
    primaryPws.forEach(function (inp) {
      inp.addEventListener('copy', function (e) {
        e.preventDefault();
        showNoPasteToast(inp, '🔒 Không cho phép sao chép mật khẩu!');
      });
      inp.addEventListener('cut', function (e) {
        e.preventDefault();
      });
    });

    // Chặn paste, drop, copy vào ô xác nhận mật khẩu (bắt buộc nhập tay)
    var confirmInputs = document.querySelectorAll('input[name="xac_nhan"], #xac_nhan, #modalRegConfirm, #modalConfirmNewPassword, .input-no-paste');
    confirmInputs.forEach(function (inp) {
      inp.setAttribute('autocomplete', 'new-password');

      ['paste', 'drop'].forEach(function (evtName) {
        inp.addEventListener(evtName, function (e) {
          e.preventDefault();
          showNoPasteToast(inp, '✍️ Vui lòng nhập tay mật khẩu xác nhận, không sao chép/dán!');
          inp.classList.add('shake-warning');
          setTimeout(function () {
            inp.classList.remove('shake-warning');
          }, 450);
        });
      });

      inp.addEventListener('copy', function (e) {
        e.preventDefault();
      });
      inp.addEventListener('cut', function (e) {
        e.preventDefault();
      });
    });
  }

  blockCopyPasteOnConfirm();
  window.blockCopyPasteOnConfirm = blockCopyPasteOnConfirm;
  window.showNoPasteToast = showNoPasteToast;
})();

