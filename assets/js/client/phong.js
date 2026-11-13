/* ==========================================================================
   QUIZUP ARENA - PHONG.JS
   League of Legends 1 vs 1 Esports Combat & Web Audio Engine
   ========================================================================== */
(function () {
  'use strict';

  // 1. LOBBY WAITING SCREEN (Trạng thái "Đã ghép")
  var cho = document.querySelector('[data-tai-lai]');
  if (cho) {
    setTimeout(function () { location.reload(); }, parseInt(cho.getAttribute('data-tai-lai'), 10) || 1200);
    return;
  }

  // 2. ACTIVE COMBAT MATCH (Trạng thái "Đang diễn ra")
  var thi = document.getElementById('thi');
  if (!thi) { return; }

  var API = thi.dataset.api;
  var CSRF = thi.dataset.csrf;
  var myChamp = thi.dataset.myChamp || 'sunflower';
  var oppChamp = thi.dataset.oppChamp || 'buckethead';
  var myChampBuff = thi.dataset.myChampBuff || '';
  var oppChampBuff = thi.dataset.oppChampBuff || '';

  // DOM Elements
  var khung = document.getElementById('khung');
  var tiendo = document.getElementById('tiendo');
  var arenaCombatStage = document.getElementById('arenaCombatStage');
  var fxLayer = document.getElementById('combatFxLayer');
  var blueFighter = document.getElementById('blueFighter');
  var redFighter = document.getElementById('redFighter');
  var myAvatarBox = document.getElementById('myAvatarBox');
  var oppAvatarBox = document.getElementById('oppAvatarBox');
  var myStunStars = document.getElementById('myStunStars');
  var oppStunStars = document.getElementById('oppStunStars');
  var myHpBar = document.getElementById('myHpBar');
  var oppHpBar = document.getElementById('oppHpBar');
  var myHpGhost = document.getElementById('myHpGhost');
  var oppHpGhost = document.getElementById('oppHpGhost');
  var myHpVal = document.getElementById('myHpVal');
  var oppHpVal = document.getElementById('oppHpVal');
  var myManaBar = document.getElementById('myManaBar');
  var oppManaBar = document.getElementById('oppManaBar');
  var myManaVal = document.getElementById('myManaVal');
  var oppManaVal = document.getElementById('oppManaVal');
  var soCauHienTai = document.getElementById('soCauHienTai');
  var tongSoCau = document.getElementById('tongSoCau');
  var demNguocGiay = document.getElementById('demNguocGiay');
  var timerCircle = document.getElementById('timerCircle');
  var announcerBanner = document.getElementById('lolAnnouncerBanner');
  var announcerTitle = document.getElementById('announcerTitle');
  var announcerSub = document.getElementById('announcerSub');
  var baronBanner = document.getElementById('baronAlertBanner');
  var killstreakBadge = document.getElementById('killstreakBadge');
  var killstreakText = document.getElementById('killstreakText');
  var nutChiuThua = document.getElementById('nutChiuThua');

  // Mutator & Sabotage Mind Games Elements
  var mutatorMatchBanner = document.getElementById('mutatorMatchBanner');
  var rageBarInner = document.getElementById('rageBarInner');
  var rageValNum = document.getElementById('rageValNum');
  var btnDebuffLocXoay = document.getElementById('btnDebuffLocXoay');
  var btnDebuffSuongMu = document.getElementById('btnDebuffSuongMu');
  var btnDebuffDongBang = document.getElementById('btnDebuffDongBang');

  // Combat State
  var MAX_HP = 1000;
  var myCurrentHp = 1000;
  var oppCurrentHp = 1000;
  var myCurrentMana = 0;
  var oppCurrentMana = 0;
  var prevOppScore = 0;
  var killstreakCount = 0;
  var isBaronRound = false;

  var currentDotBien = {
    id: thi.dataset.dotbienId || 'chuan',
    ten: thi.dataset.dotbienTen || 'Đấu Trường Chuẩn',
    moTa: thi.dataset.dotbienMota || '',
    icon: thi.dataset.dotbienIcon || '⚔️'
  };
  var currentRage = 0;
  var lastDebuffTriggered = 0;

  var cauHienTai = null;
  var hanChot = 0;
  var thoiGianQuyDinh = 15;
  var daGui = false;
  var dangPhanHoi = false;
  var dangLay = false;
  var nutDapAn = [];

  // ==========================================================================
  // PROCEDURAL WEB AUDIO SFX ENGINE (0 External Audio Files Needed)
  // ==========================================================================
  var audioCtx = null;
  function getAudioCtx() {
    if (!audioCtx) {
      var AudioContext = window.AudioContext || window.webkitAudioContext;
      if (AudioContext) { audioCtx = new AudioContext(); }
    }
    if (audioCtx && audioCtx.state === 'suspended') {
      audioCtx.resume();
    }
    return audioCtx;
  }

  function playHitSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'triangle';
    osc.frequency.setValueAtTime(140, now);
    osc.frequency.exponentialRampToValueAtTime(35, now + 0.16);
    gain.gain.setValueAtTime(0.3, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.18);
    osc.connect(gain); gain.connect(ctx.destination);
    osc.start(now); osc.stop(now + 0.18);
  }

  function playSlashSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    // White noise swoosh filter
    var bufferSize = ctx.sampleRate * 0.15;
    var buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
    var data = buffer.getChannelData(0);
    for (var i = 0; i < bufferSize; i++) { data[i] = Math.random() * 2 - 1; }
    var noise = ctx.createBufferSource();
    noise.buffer = buffer;
    var filter = ctx.createBiquadFilter();
    filter.type = 'bandpass';
    filter.frequency.setValueAtTime(800, now);
    filter.frequency.exponentialRampToValueAtTime(2800, now + 0.12);
    var gain = ctx.createGain();
    gain.gain.setValueAtTime(0.25, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.15);
    noise.connect(filter); filter.connect(gain); gain.connect(ctx.destination);
    noise.start(now);
  }

  function playCritSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    [520, 780, 1040, 1560].forEach(function (f, i) {
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(f, now + i * 0.03);
      gain.gain.setValueAtTime(0.18, now + i * 0.03);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start(now + i * 0.03); osc.stop(now + 0.35);
    });
  }

  function playFirstBloodSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    // LoL First Blood Brass Chime
    [330, 440, 554, 660].forEach(function (f, i) {
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(f, now + i * 0.08);
      gain.gain.setValueAtTime(0.25, now + i * 0.08);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 1.2);
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start(now + i * 0.08); osc.stop(now + 1.2);
    });
  }

  function playPentakillSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    // Epic ascending fanfare
    [261.6, 329.6, 392.0, 523.2, 659.2, 783.9, 1046.5].forEach(function (f, i) {
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(f, now + i * 0.07);
      gain.gain.setValueAtTime(0.2, now + i * 0.07);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 1.5);
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start(now + i * 0.07); osc.stop(now + 1.5);
    });
  }

  function playWrongSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(150, now);
    osc.frequency.linearRampToValueAtTime(90, now + 0.25);
    gain.gain.setValueAtTime(0.25, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.3);
    osc.connect(gain); gain.connect(ctx.destination);
    osc.start(now); osc.stop(now + 0.3);
  }

  function playBaronAlarmSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(220, now);
    osc.frequency.linearRampToValueAtTime(440, now + 0.2);
    osc.frequency.linearRampToValueAtTime(220, now + 0.4);
    gain.gain.setValueAtTime(0.25, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.8);
    osc.connect(gain); gain.connect(ctx.destination);
    osc.start(now); osc.stop(now + 0.8);
  }

  function playIceFreezeSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    [1200, 1500, 1800, 2400].forEach(function (f, i) {
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(f, now + i * 0.04);
      gain.gain.setValueAtTime(0.18, now + i * 0.04);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.45);
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start(now + i * 0.04); osc.stop(now + 0.45);
    });
  }

  function playTornadoWindSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    var bufferSize = ctx.sampleRate * 0.4;
    var buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
    var data = buffer.getChannelData(0);
    for (var i = 0; i < bufferSize; i++) { data[i] = Math.random() * 2 - 1; }
    var noise = ctx.createBufferSource();
    noise.buffer = buffer;
    var filter = ctx.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.setValueAtTime(300, now);
    filter.frequency.linearRampToValueAtTime(1300, now + 0.2);
    filter.frequency.linearRampToValueAtTime(220, now + 0.4);
    var gain = ctx.createGain();
    gain.gain.setValueAtTime(0.3, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.4);
    noise.connect(filter); filter.connect(gain); gain.connect(ctx.destination);
    noise.start(now);
  }

  function playFogHissSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    var bufferSize = ctx.sampleRate * 0.5;
    var buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
    var data = buffer.getChannelData(0);
    for (var i = 0; i < bufferSize; i++) { data[i] = Math.random() * 2 - 1; }
    var noise = ctx.createBufferSource();
    noise.buffer = buffer;
    var filter = ctx.createBiquadFilter();
    filter.type = 'bandpass';
    filter.frequency.setValueAtTime(1600, now);
    var gain = ctx.createGain();
    gain.gain.setValueAtTime(0.2, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
    noise.connect(filter); filter.connect(gain); gain.connect(ctx.destination);
    noise.start(now);
  }

  function playSabotageCastSound() {
    var ctx = getAudioCtx(); if (!ctx) return;
    var now = ctx.currentTime;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(320, now);
    osc.frequency.exponentialRampToValueAtTime(950, now + 0.22);
    gain.gain.setValueAtTime(0.3, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.26);
    osc.connect(gain); gain.connect(ctx.destination);
    osc.start(now); osc.stop(now + 0.26);
  }

  // ==========================================================================
  // COMBAT VISUAL FX (PROJECTILES, DAMAGE POPUPS, HEALTH BARS)
  // ==========================================================================
  function spawnDamagePopup(isAtOpponent, text, isCrit) {
    if (!fxLayer) return;
    var targetEl = isAtOpponent ? redFighter : blueFighter;
    var rect = targetEl.getBoundingClientRect();
    var stageRect = arenaCombatStage.getBoundingClientRect();

    var popup = document.createElement('div');
    popup.className = 'damage-number-popup' + (isCrit ? ' crit-strike' : '');
    popup.textContent = text;
    popup.style.left = (rect.left - stageRect.left + rect.width / 2) + 'px';
    popup.style.top = (rect.top - stageRect.top + 30) + 'px';
    fxLayer.appendChild(popup);

    setTimeout(function () {
      if (popup.parentNode) popup.parentNode.removeChild(popup);
    }, 1200);
  }

  function launchProjectile(fromBlueToRed, callback) {
    if (!fxLayer) { if (callback) callback(); return; }
    var proj = document.createElement('div');
    proj.className = 'combat-projectile ' + (fromBlueToRed ? 'shoot-right' : 'shoot-left');
    fxLayer.appendChild(proj);

    playSlashSound();

    setTimeout(function () {
      if (proj.parentNode) proj.parentNode.removeChild(proj);
      if (callback) callback();
    }, 450);
  }

  function triggerHitShake(isOpponent) {
    var targetBox = isOpponent ? oppAvatarBox : myAvatarBox;
    var targetFighter = isOpponent ? redFighter : blueFighter;
    if (targetBox) {
      targetBox.classList.add('flash-hit');
      setTimeout(function () { targetBox.classList.remove('flash-hit'); }, 300);
    }
    if (targetFighter) {
      targetFighter.classList.add('shake-anim');
      setTimeout(function () { targetFighter.classList.remove('shake-anim'); }, 400);
    }
  }

  function updateHpDisplay() {
    var myPct = Math.max(0, Math.min(100, (myCurrentHp / MAX_HP) * 100));
    var oppPct = Math.max(0, Math.min(100, (oppCurrentHp / MAX_HP) * 100));

    if (myHpBar) myHpBar.style.width = myPct + '%';
    if (oppHpBar) oppHpBar.style.width = oppPct + '%';
    if (myHpVal) myHpVal.textContent = Math.round(myCurrentHp) + ' / ' + MAX_HP;
    if (oppHpVal) oppHpVal.textContent = Math.round(oppCurrentHp) + ' / ' + MAX_HP;

    // Smooth ghost delay
    setTimeout(function () {
      if (myHpGhost) myHpGhost.style.width = myPct + '%';
      if (oppHpGhost) oppHpGhost.style.width = oppPct + '%';
    }, 300);
  }

  function updateManaDisplay() {
    var myPct = Math.min(100, myCurrentMana);
    var oppPct = Math.min(100, oppCurrentMana);

    if (myManaBar) myManaBar.style.width = myPct + '%';
    if (oppManaBar) oppManaBar.style.width = oppPct + '%';
    if (myManaVal) myManaVal.textContent = myPct + '%';
    if (oppManaVal) oppManaVal.textContent = oppPct + '%';

    if (myAvatarBox) {
      if (myPct >= 100) myAvatarBox.classList.add('ult-ready-aura');
      else myAvatarBox.classList.remove('ult-ready-aura');
    }
  }

  // ==========================================================================
  // LOL ANNOUNCER KILLSTREAK SYSTEM
  // ==========================================================================
  function showAnnouncer(title, sub, soundFn) {
    if (!announcerBanner || !announcerTitle || !announcerSub) return;
    announcerTitle.textContent = title;
    announcerSub.textContent = sub;
    announcerBanner.style.display = 'flex';
    announcerBanner.classList.remove('fade-out');
    announcerBanner.classList.add('pop-in');

    if (soundFn) soundFn();

    setTimeout(function () {
      announcerBanner.classList.add('fade-out');
      setTimeout(function () { announcerBanner.style.display = 'none'; }, 400);
    }, 2200);
  }

  function handleKillstreak(isCorrect) {
    if (isCorrect) {
      killstreakCount++;
      if (killstreakBadge && killstreakText) {
        killstreakBadge.style.display = 'inline-flex';
        killstreakText.textContent = 'COMBO x' + killstreakCount;
      }
      if (killstreakCount === 1) {
        showAnnouncer('FIRST BLOOD', 'CHIẾN CÔNG ĐẦU! KHỞI HOÀN THUẬN LỢI', playFirstBloodSound);
      } else if (killstreakCount === 2) {
        showAnnouncer('DOUBLE KILL', 'HẠ GỤC KÉP! CHIẾN Ý DÂNG TRÀO', playCritSound);
      } else if (killstreakCount === 3) {
        showAnnouncer('KILLING SPREE', 'ĐANG BẮT ĐẦU HOÀNG KIM! KHÔNG THỂ NGĂN CẢN', playCritSound);
      } else if (killstreakCount === 4) {
        showAnnouncer('DOMINATING', 'THỐNG TRỊ VŨ ĐÀI! ÁP ĐẢO HOÀN TOÀN', playCritSound);
      } else if (killstreakCount >= 5) {
        showAnnouncer('PENTAKILL / ACE', 'QUÉT SẠCH! HUYỀN THOẠI ĐẤU THẦN BẤT TỬ', playPentakillSound);
      }
    } else {
      if (killstreakCount >= 2) {
        showAnnouncer('SHUT DOWN', 'CHẤM DỨT CHUỖI THẮNG! BÌNH TĨNH PHẢN CÔNG', playWrongSound);
      }
      killstreakCount = 0;
      if (killstreakBadge) killstreakBadge.style.display = 'none';
    }
  }

  // ==========================================================================
  // SURRENDER ACTION
  // ==========================================================================
  if (nutChiuThua) {
    nutChiuThua.onclick = function () {
      if (!confirm('Bạn chắc chắn muốn đầu hàng? Đối thủ sẽ được tính là chiến thắng.')) return;
      nutChiuThua.disabled = true;
      var fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('hanhdong', 'chiuthua');
      fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (r.loi) { alert(r.loi); nutChiuThua.disabled = false; return; }
          location.reload();
        })
        .catch(function () { location.reload(); });
    };
  }

  // ==========================================================================
  // CHIÊU TRÒ PHÁ RỐI & HỆ THỐNG TÍCH NỘ (SABOTAGE SYSTEM)
  // ==========================================================================
  function addRage(amt) {
    currentRage = Math.min(100, Math.max(0, currentRage + amt));
    if (rageBarInner) rageBarInner.style.width = currentRage + '%';
    if (rageValNum) rageValNum.textContent = currentRage + '%';

    var isReady = (currentRage >= 100);
    [btnDebuffLocXoay, btnDebuffSuongMu, btnDebuffDongBang].forEach(function (btn) {
      if (btn) {
        btn.disabled = !isReady;
        if (isReady) {
          btn.classList.add('ready-to-fire');
        } else {
          btn.classList.remove('ready-to-fire');
        }
      }
    });
  }

  function banPhaRoi(loai) {
    if (currentRage < 100) return;
    playSabotageCastSound();

    launchProjectile(true, function () {
      spawnDamagePopup(true, '💥 ĐÃ PHÁ RỐI ĐỐI THỦ!', true);
    });

    addRage(-100);

    var fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('hanhdong', 'pharoi');
    fd.append('loai', loai);

    fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.loi) console.warn('Lỗi phá rối:', res.loi);
      })
      .catch(function (e) { console.warn('Lỗi gửi phá rối:', e); });
  }

  if (btnDebuffLocXoay) {
    btnDebuffLocXoay.onclick = function () { banPhaRoi('loc_xoay'); };
  }
  if (btnDebuffSuongMu) {
    btnDebuffSuongMu.onclick = function () { banPhaRoi('suong_mu'); };
  }
  if (btnDebuffDongBang) {
    btnDebuffDongBang.onclick = function () { banPhaRoi('dong_bang'); };
  }

  // ==========================================================================
  // XỬ LÝ TRỰC QUAN KHI BỊ ĐỐI THỦ TẤN CÔNG BẰNG CHIÊU TRÒ (DEBUFF FX)
  // ==========================================================================
  function triggerDebuffDongBang() {
    playIceFreezeSound();
    var freezeOverlay = document.getElementById('debuffFreezeOverlay');
    if (freezeOverlay) {
      freezeOverlay.style.display = 'block';
      freezeOverlay.classList.remove('fade-out');
    }

    nutDapAn.forEach(function (btn) {
      btn.classList.add('ice-frozen');
      btn.dataset.wasDisabled = btn.disabled ? '1' : '0';
      btn.disabled = true;
    });

    setTimeout(function () {
      if (freezeOverlay) {
        freezeOverlay.classList.add('fade-out');
        setTimeout(function () { freezeOverlay.style.display = 'none'; }, 400);
      }
      nutDapAn.forEach(function (btn) {
        btn.classList.remove('ice-frozen');
        if (btn.dataset.wasDisabled !== '1' && !daGui) {
          btn.disabled = false;
        }
      });
    }, 1500);
  }

  function triggerDebuffLocXoay() {
    playTornadoWindSound();
    var tornadoAlert = document.getElementById('debuffTornadoAlert');
    if (tornadoAlert) {
      tornadoAlert.style.display = 'flex';
      tornadoAlert.classList.remove('fade-out');
      setTimeout(function () {
        tornadoAlert.classList.add('fade-out');
        setTimeout(function () { tornadoAlert.style.display = 'none'; }, 400);
      }, 2500);
    }

    var grid = khung ? khung.querySelector('.quiz-answers-grid') : null;
    if (grid && grid.children.length > 1) {
      grid.classList.add('tornado-shuffle-spin');
      var btns = Array.prototype.slice.call(grid.children);
      btns.sort(function () { return Math.random() - 0.5; });
      btns.forEach(function (b) { grid.appendChild(b); });
      setTimeout(function () {
        grid.classList.remove('tornado-shuffle-spin');
      }, 700);
    }
  }

  function triggerDebuffSuongMu() {
    playFogHissSound();
    var fogOverlay = document.getElementById('debuffFogOverlay');
    var canvas = document.getElementById('fogCanvas');
    if (!fogOverlay || !canvas) return;

    fogOverlay.style.display = 'block';
    fogOverlay.classList.remove('fade-out');

    var consoleCard = document.getElementById('consoleCard');
    var rect = consoleCard ? consoleCard.getBoundingClientRect() : { width: 640, height: 380 };
    canvas.width = rect.width || 640;
    canvas.height = rect.height || 380;

    var ctx = canvas.getContext('2d');
    if (ctx) {
      ctx.fillStyle = 'rgba(15, 23, 42, 0.95)';
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      ctx.fillStyle = 'rgba(30, 41, 59, 0.8)';
      for (var i = 0; i < 20; i++) {
        ctx.beginPath();
        ctx.arc(Math.random() * canvas.width, Math.random() * canvas.height, 40 + Math.random() * 60, 0, Math.PI * 2);
        ctx.fill();
      }
    }

    function scrub(e) {
      if (!ctx) return;
      var cRect = canvas.getBoundingClientRect();
      var cx = (e.clientX !== undefined) ? e.clientX : (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
      var cy = (e.clientY !== undefined) ? e.clientY : (e.touches && e.touches[0] ? e.touches[0].clientY : 0);
      var x = cx - cRect.left;
      var y = cy - cRect.top;
      ctx.globalCompositeOperation = 'destination-out';
      ctx.beginPath();
      ctx.arc(x, y, 48, 0, Math.PI * 2);
      ctx.fill();
    }

    canvas.onmousemove = scrub;
    canvas.ontouchmove = scrub;

    setTimeout(function () {
      fogOverlay.classList.add('fade-out');
      setTimeout(function () {
        fogOverlay.style.display = 'none';
        canvas.onmousemove = null;
        canvas.ontouchmove = null;
      }, 500);
    }, 3500);
  }

  // ==========================================================================
  // RENDERING QUESTION & SKILLS (Q - W - E - R)
  // ==========================================================================
  var SKILL_KEYS = ['Q', 'W', 'E', 'R'];

  function xoa(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }

  function veCau(c, tong) {
    xoa(khung);
    nutDapAn = [];

    // Header with Question number
    if (soCauHienTai) soCauHienTai.textContent = c.thuTu;
    if (tongSoCau) tongSoCau.textContent = tong;

    // Check Baron Steal Sudden Death Round (Last question)
    if (c.thuTu === tong && !isBaronRound) {
      isBaronRound = true;
      if (arenaCombatStage) arenaCombatStage.classList.add('baron-mode');
      if (baronBanner) baronBanner.style.display = 'flex';
      playBaronAlarmSound();
    }

    var qBox = document.createElement('div');
    qBox.className = 'quiz-question-box';

    var qNumBadge = document.createElement('div');
    qNumBadge.className = 'quiz-q-num-badge';
    qNumBadge.textContent = 'CÂU HỎI THI ĐẤU ' + c.thuTu + ' / ' + tong + (isBaronRound ? ' [VÒNG BARON]' : '');
    qBox.appendChild(qNumBadge);

    // Kỹ năng Snow Pea (+4s suy nghĩ)
    if (myChampBuff === 'extra_time') {
      var iceBadge = document.createElement('span');
      iceBadge.className = 'pvz-ice-bonus-badge';
      iceBadge.textContent = '❄️ +4s Băng Hàn';
      qBox.appendChild(iceBadge);
    }

    var qText = document.createElement('div');
    qText.className = 'quiz-question-text';
    qText.textContent = c.noiDung;
    qBox.appendChild(qText);

    // Cảnh báo Đột biến Ngày Đảo Ngược
    if (currentDotBien && currentDotBien.id === 'dao_nguoc') {
      var revBadge = document.createElement('div');
      revBadge.className = 'reverse-day-warning-badge';
      revBadge.innerHTML = '<span class="rev-icon">🔄</span> <strong>LUẬT ĐẢO NGƯỢC:</strong> Bạn phải CHỌN ĐÁP ÁN SAI mới được tính là ĐÚNG!';
      qBox.appendChild(revBadge);
    }

    khung.appendChild(qBox);

    // 4 Answer choices styled as LoL Skills
    var answersGrid = document.createElement('div');
    answersGrid.className = 'quiz-answers-grid';

    c.dapAn.forEach(function (d, idx) {
      var keyChar = SKILL_KEYS[idx] || (idx + 1);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'quiz-skill-btn' + (keyChar === 'R' ? ' ult-btn' : '');
      btn.dataset.dapAnId = d.id;

      // Kỹ năng Dr. Zomboss (Nuốt 1 đáp án sai trong 3 câu đầu)
      var devouredIdx = (c.thuTu % c.dapAn.length);
      if (myChampBuff === 'eliminate_wrong' && c.thuTu <= 3 && c.dapAn.length > 2 && idx === devouredIdx) {
        btn.classList.add('zomboss-devoured');
        btn.disabled = true;
        btn.title = 'Tiến Sĩ Zomboss đã nuốt chửng đáp án sai này!';
        var skull = document.createElement('span');
        skull.className = 'zomboss-devour-tag';
        skull.textContent = '🧠 ĐÃ NUỐT';
        btn.appendChild(skull);
      }

      var keyBadge = document.createElement('span');
      keyBadge.className = 'skill-hotkey-badge';
      keyBadge.textContent = keyChar;
      btn.appendChild(keyBadge);

      var ansText = document.createElement('span');
      ansText.className = 'skill-answer-text';
      ansText.textContent = d.noiDung;
      btn.appendChild(ansText);

      btn.onclick = function () { gui(d.id, btn); };
      nutDapAn.push(btn);
      answersGrid.appendChild(btn);
    });

    khung.appendChild(answersGrid);
    tick();
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
  }

  function khoaNut() {
    nutDapAn.forEach(function (b) { b.disabled = true; });
  }

  // Keyboard Event Listener for Q, W, E, R Keys
  window.addEventListener('keydown', function (e) {
    if (daGui || !cauHienTai || nutDapAn.length === 0) return;
    var k = e.key.toUpperCase();
    var idx = SKILL_KEYS.indexOf(k);
    if (idx !== -1 && nutDapAn[idx] && !nutDapAn[idx].disabled) {
      nutDapAn[idx].click();
    }
  });

  // Circular Neon Timer Tick
  function tick() {
    if (!cauHienTai || !demNguocGiay) return;
    var con = Math.max(0, (hanChot - Date.now()) / 1000);
    var rounded = Math.ceil(con);

    if (con <= 0 && !daGui) {
      demNguocGiay.textContent = '0';
      if (timerCircle) timerCircle.style.strokeDashoffset = '264';
      khoaNut();
      gui(0, null); // Tự động chốt khi hết giờ để hiện rõ đáp án đúng cho người chơi
    } else if (!daGui) {
      demNguocGiay.textContent = rounded;
      if (timerCircle) {
        var totalSec = thoiGianQuyDinh || 15;
        var progress = Math.max(0, Math.min(1, con / totalSec));
        var offset = 264 * (1 - progress);
        timerCircle.style.strokeDashoffset = offset;
        if (rounded <= 3) timerCircle.style.stroke = '#EF4444';
        else if (rounded <= 6) timerCircle.style.stroke = '#F59E0B';
        else timerCircle.style.stroke = '#00D9FF';
      }
    }
  }
  setInterval(tick, 100);

  // ==========================================================================
  // SEND ANSWER & COMBAT RESOLUTION
  // ==========================================================================
  function gui(maDapAn, clickedBtn) {
    if (daGui || !cauHienTai) return;
    daGui = true;
    khoaNut();

    if (clickedBtn) clickedBtn.classList.add('selected');

    var fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('macau', cauHienTai.maCau);
    fd.append('dapan', maDapAn);

    fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (r.loi) { location.reload(); return; }
        dangPhanHoi = true;

        // 1. Phản hồi trực quan trạng thái nút đã chọn
        nutDapAn.forEach(function (btn) {
          if (btn === clickedBtn) {
            if (r.dung) {
              btn.classList.add('choice-correct');
              var tag = document.createElement('span');
              tag.className = 'choice-result-badge badge-correct';
              tag.innerHTML = '✔ CHÍNH XÁC';
              btn.appendChild(tag);
            } else {
              btn.classList.add('choice-wrong');
              var tag = document.createElement('span');
              tag.className = 'choice-result-badge badge-wrong';
              tag.innerHTML = '✖ ĐÃ CHỌN (SAI)';
              btn.appendChild(tag);
            }
          } else {
            btn.classList.add('choice-dimmed');
          }
        });

        // 2. Combat Resolution FX (Sát thương / Chiêu thức)
        if (r.dung) {
          var dmg = (r.diem || 10) * 10;
          if (isBaronRound) dmg *= 2;
          var isCrit = (killstreakCount >= 2 || isBaronRound);

          oppCurrentHp = Math.max(0, oppCurrentHp - dmg);
          myCurrentMana = Math.min(100, myCurrentMana + 25);
          updateHpDisplay();
          updateManaDisplay();

          // Nạp năng lượng Nộ khi xuất chiêu đúng & phản xạ nhanh
          var conLaiSec = Math.max(0, (hanChot - Date.now()) / 1000);
          var isFast = (conLaiSec / (thoiGianQuyDinh || 15)) >= 0.5;
          addRage(isFast ? 50 : 35);

          launchProjectile(true, function () {
            playHitSound();
            triggerHitShake(true);
            spawnDamagePopup(true, '-' + dmg + ' HP' + (isCrit ? ' [BẠO KÍCH]' : ''), isCrit);
          });

          handleKillstreak(true);
        } else {
          // Trả lời sai hoặc hết giờ
          myCurrentHp = Math.max(0, myCurrentHp - 50);
          updateHpDisplay();

          if (myStunStars) {
            myStunStars.style.display = 'block';
            setTimeout(function () { myStunStars.style.display = 'none'; }, 1200);
          }
          triggerHitShake(false);
          spawnDamagePopup(false, r.hetGio ? 'HẾT GIỜ! -50 HP' : 'SAI LẦM! -50 HP', false);
          playWrongSound();

          handleKillstreak(false);
        }

        // 3. Hiện Banner kết quả lượt đấu (Bảo mật: Không tiết lộ đáp án đúng để người chơi tự làm lại)
        var oldBanner = document.getElementById('answerRevealBanner');
        if (oldBanner) oldBanner.remove();

        var banner = document.createElement('div');
        banner.id = 'answerRevealBanner';
        banner.className = 'answer-reveal-banner ' + (r.dung ? 'banner-correct' : (r.hetGio ? 'banner-timeout' : 'banner-wrong'));

        if (r.dung) {
          var successTitle = (currentDotBien && currentDotBien.id === 'dao_nguoc')
            ? 'BẪY THÀNH CÔNG (NGÀY ĐẢO NGƯỢC)!'
            : 'XUẤT CHIÊU CHÍNH XÁC!';
          var successDesc = (currentDotBien && currentDotBien.id === 'dao_nguoc')
            ? 'Đã chọn đáp án SAI chuẩn xác! Đối thủ chịu <strong>-' + ((r.diem || 10) * 10 * (isBaronRound ? 2 : 1)) + ' HP</strong> sát thương!'
            : 'Đòn tấn công chuẩn xác! Đối thủ chịu <strong>-' + ((r.diem || 10) * 10 * (isBaronRound ? 2 : 1)) + ' HP</strong> sát thương!';

          banner.innerHTML =
            '<div class="reveal-header">' +
              '<span class="reveal-icon">⚡</span>' +
              '<span class="reveal-title">' + successTitle + '</span>' +
              '<span class="reveal-score">+' + r.diem + ' ĐIỂM</span>' +
            '</div>' +
            '<div class="reveal-body">' + successDesc + '</div>';
        } else {
          var headerTitle = r.hetGio
            ? '⏰ HẾT GIỜ THI ĐẤU! (0 ĐIỂM)'
            : ((currentDotBien && currentDotBien.id === 'dao_nguoc') ? '❌ BẠN ĐÃ CHỌN NHẦM ĐÁP ÁN ĐÚNG!' : '❌ ĐÁP ÁN CHƯA ĐÚNG! (0 ĐIỂM)');
          var descText = r.hetGio
            ? 'Bạn không kịp xuất chiêu! Hãy thao tác nhanh hơn ở lượt tiếp theo.'
            : ((currentDotBien && currentDotBien.id === 'dao_nguoc')
                ? 'Trong Ngày Đảo Ngược, bạn PHẢI chọn câu SAI mới được nhận điểm!'
                : 'Lựa chọn chưa chính xác! Hãy tập trung thi đấu để lật ngược tình thế.');
          banner.innerHTML =
            '<div class="reveal-header">' +
              '<span class="reveal-icon">' + (r.hetGio ? '⏰' : '❌') + '</span>' +
              '<span class="reveal-title">' + headerTitle + '</span>' +
            '</div>' +
            '<div class="reveal-body">' + descText + '</div>';
        }

        var answersGrid = khung.querySelector('.quiz-answers-grid');
        if (answersGrid) {
          khung.insertBefore(banner, answersGrid);
        } else {
          khung.appendChild(banner);
        }

        // 4. Nghỉ 1.4s chuyển mượt sang câu tiếp theo
        setTimeout(function () {
          dangPhanHoi = false;
          cauHienTai = null;
          lay();
        }, 1400);
      })
      .catch(function () { location.reload(); });
  }

  // ==========================================================================
  // POLLING & ARENA SYNCHRONIZATION
  // ==========================================================================
  function xuLy(d) {
    if (!d || d.loi) {
      if (d && d.loi) console.warn("API Error:", d.loi);
      return;
    }

    if (!d.tran || d.tran.trangThai !== 'Đang diễn ra') {
      location.reload();
      return;
    }

    // Đồng bộ Đột Biến Không Gian
    if (d.dotBien) {
      currentDotBien = d.dotBien;
      if (mutatorMatchBanner && d.dotBien.id !== 'chuan') {
        mutatorMatchBanner.style.display = 'flex';
        var nameEl = mutatorMatchBanner.querySelector('.mmb-name');
        var descEl = mutatorMatchBanner.querySelector('.mmb-desc');
        if (nameEl) nameEl.textContent = d.dotBien.ten;
        if (descEl) descEl.textContent = d.dotBien.moTa;
      }
    }

    // Kiểm tra và thực thi Chiêu Phá Rối từ đối thủ
    if (d.phaRoiBiDinh && d.phaRoiBiDinh.loai) {
      var nowTime = Date.now();
      if (nowTime - lastDebuffTriggered > 4500) {
        lastDebuffTriggered = nowTime;
        var debuffType = d.phaRoiBiDinh.loai;
        if (debuffType === 'loc_xoay') {
          triggerDebuffLocXoay();
        } else if (debuffType === 'suong_mu') {
          triggerDebuffSuongMu();
        } else if (debuffType === 'dong_bang') {
          triggerDebuffDongBang();
        }
      }
    }

    // Check if Opponent scored since last poll!
    var currentOppScore = (d.doiThu && d.doiThu.diem) ? d.doiThu.diem : 0;
    if (currentOppScore > prevOppScore) {
      var diffScore = currentOppScore - prevOppScore;
      prevOppScore = currentOppScore;

      // Opponent attacked You!
      var oppDmg = diffScore * 10;
      myCurrentHp = Math.max(0, myCurrentHp - oppDmg);
      oppCurrentMana = Math.min(100, oppCurrentMana + 25);
      updateHpDisplay();
      updateManaDisplay();

      launchProjectile(false, function () {
        playHitSound();
        triggerHitShake(false);
        spawnDamagePopup(false, '-' + oppDmg + ' HP', false);
      });
    }

    if (nutChiuThua) {
      nutChiuThua.classList.toggle('an', !!(d.toi && d.toi.xong));
    }

    if (d.demNguoc > 0) {
      cauHienTai = null;
      if (demNguocGiay) demNguocGiay.textContent = d.demNguoc;
      xoa(khung);
      var cdBox = document.createElement('div');
      cdBox.className = 'arena-intro-countdown';
      cdBox.innerHTML = '<div class="intro-sub">ĐẤU TRƯỜNG CHUẨN BỊ</div><div class="intro-num">' + d.demNguoc + '</div><div class="intro-desc">Chuẩn bị xuất chiêu cùng ' + (thi.dataset.myChampName || 'Đấu Thần') + '!</div>';
      khung.appendChild(cdBox);
    } else if (d.cau) {
      if (!cauHienTai || cauHienTai.maCau !== d.cau.maCau) {
        cauHienTai = d.cau;
        daGui = false;
        var extraSec = (myChampBuff === 'extra_time') ? 4 : 0;
        thoiGianQuyDinh = (d.cau.thoiGian || 15) + extraSec;
        hanChot = Date.now() + (d.cau.conLai + extraSec) * 1000;
        veCau(d.cau, d.tran.tongCau);
      }
    } else if (d.toi && d.toi.xong) {
      cauHienTai = null;
      xoa(khung);
      var waitBox = document.createElement('div');
      waitBox.className = 'waiting-prompt';
      waitBox.innerHTML = '<div class="spinner-ring"></div><h3>BẠN ĐÃ HOÀN THÀNH TOÀN BỘ CÂU HỎI!</h3><p>Đang chờ kết quả từ phía đối thủ để định đoạt thắng bại...</p>';
      khung.appendChild(waitBox);
    }
  }

  function lay() {
    if (dangLay || dangPhanHoi) return;
    dangLay = true;
    fetch(API, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) {
          throw new Error('HTTP ' + r.status);
        }
        return r.json();
      })
      .then(xuLy)
      .catch(function (err) {
        console.warn('Polling error:', err);
      })
      .then(function () { dangLay = false; });
  }

  // Initial call & Polling loop
  lay();
  setInterval(lay, 1200);

  // Initialize UI Displays
  updateHpDisplay();
  updateManaDisplay();

  // Unlock Audio
  document.addEventListener('click', function unlock() {
    getAudioCtx();
    document.removeEventListener('click', unlock);
  }, { once: true });
})();
