/* Trang Tìm trận (client/timtran.php) - LoL Cosmic Arena Engine */
(function () {
  // --- Web Audio SFX Synthesizer (Zero External Dependencies) ---
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

  function playPickSound() {
    var ctx = getAudioCtx();
    if (!ctx) return;
    var now = ctx.currentTime;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'triangle';
    osc.frequency.setValueAtTime(320, now);
    osc.frequency.exponentialRampToValueAtTime(740, now + 0.18);
    gain.gain.setValueAtTime(0.2, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(now);
    osc.stop(now + 0.35);
  }

  function playLaunchQueueSound() {
    var ctx = getAudioCtx();
    if (!ctx) return;
    var now = ctx.currentTime;
    [180, 240, 360, 480].forEach(function(f, i) {
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(f, now + i * 0.05);
      gain.gain.setValueAtTime(0.18, now + i * 0.05);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.6);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(now + i * 0.05);
      osc.stop(now + 0.6);
    });
  }

  function playPetClickSound() {
    var ctx = getAudioCtx();
    if (!ctx) return;
    var now = ctx.currentTime;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(580, now);
    osc.frequency.exponentialRampToValueAtTime(1100, now + 0.12);
    gain.gain.setValueAtTime(0.2, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.18);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(now);
    osc.stop(now + 0.18);
  }

  function playReadyCheckHorn() {
    var ctx = getAudioCtx();
    if (!ctx) return;
    var now = ctx.currentTime;
    // Epic brass fanfare: C4, E4, G4, C5 chord progression
    [261.63, 329.63, 392.00, 523.25].forEach(function (f, i) {
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(f, now + i * 0.08);
      gain.gain.setValueAtTime(0.22, now + i * 0.08);
      gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.08 + 0.7);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(now + i * 0.08);
      osc.stop(now + i * 0.08 + 0.7);
    });
  }

  function playLockInSound() {
    var ctx = getAudioCtx();
    if (!ctx) return;
    var now = ctx.currentTime;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'triangle';
    osc.frequency.setValueAtTime(440, now);
    osc.frequency.exponentialRampToValueAtTime(880, now + 0.08);
    gain.gain.setValueAtTime(0.35, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.4);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(now);
    osc.stop(now + 0.4);
  }

  function playOpponentAcceptedSound() {
    var ctx = getAudioCtx();
    if (!ctx) return;
    var now = ctx.currentTime;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, now);
    osc.frequency.setValueAtTime(1174.66, now + 0.08);
    gain.gain.setValueAtTime(0.18, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.3);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(now);
    osc.stop(now + 0.3);
  }

  function playVsThunderSlashSound() {
    var ctx = getAudioCtx();
    if (!ctx) return;
    var now = ctx.currentTime;
    // Deep thunder rumble & energy slice
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = 'triangle';
    osc.frequency.setValueAtTime(160, now);
    osc.frequency.exponentialRampToValueAtTime(45, now + 0.6);
    gain.gain.setValueAtTime(0.4, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.8);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(now);
    osc.stop(now + 0.8);
  }

  // --- Champion Selection Handling ---
  var champCards = document.querySelectorAll('.champ-card');
  champCards.forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (card.classList.contains('is-selected')) return;
      var form = card.querySelector('form.form-chon-tuong');
      if (form) {
        playPickSound();
        form.submit();
      }
    });
  });

  // --- Exam Quiz Multiple-Choice Cards (Giữa kì, Cuối kì, Cả 2) ---
  var examQuizCards = document.querySelectorAll('.exam-quiz-card');
  examQuizCards.forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (card.classList.contains('is-disabled')) return;
      examQuizCards.forEach(function (c) { c.classList.remove('is-selected'); });
      card.classList.add('is-selected');
      var radio = card.querySelector('input[type="radio"]');
      if (radio) {
        radio.checked = true;
      }
      playPickSound();
    });
  });

  // --- Cyber Propose Subject Modal Logic ---
  function openProposeModal() {
    var modal = document.getElementById('cyberProposeModal');
    if (!modal) return;
    modal.classList.add('is-open');
    modal.style.setProperty('display', 'flex', 'important');
    document.body.classList.add('modal-open');
    try { playPickSound(); } catch (err) {}
    setTimeout(function () {
      var input = document.getElementById('modal_ten_mon');
      if (input) {
        input.focus();
        input.select();
      }
    }, 120);
  }

  function closeProposeModal() {
    var modal = document.getElementById('cyberProposeModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.style.setProperty('display', 'none', 'important');
    document.body.classList.remove('modal-open');
  }

  window.openProposeModal = openProposeModal;
  window.closeProposeModal = closeProposeModal;

  var btnOpenPropose = document.getElementById('btnOpenProposeModal');
  var pillOpenPropose = document.getElementById('proposeQuickPill');
  var btnClosePropose = document.getElementById('btnCloseProposeModal');
  var btnCancelPropose = document.getElementById('btnCancelProposeModal');
  var proposeBackdrop = document.getElementById('proposeModalBackdrop');

  if (btnOpenPropose) {
    btnOpenPropose.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      openProposeModal();
    });
  }
  if (pillOpenPropose) {
    pillOpenPropose.addEventListener('click', function (e) {
      if (e.target.closest('a')) return;
      openProposeModal();
    });
  }
  if (btnClosePropose) {
    btnClosePropose.addEventListener('click', function (e) {
      e.preventDefault();
      closeProposeModal();
    });
  }
  if (btnCancelPropose) {
    btnCancelPropose.addEventListener('click', function (e) {
      e.preventDefault();
      closeProposeModal();
    });
  }
  if (proposeBackdrop) {
    proposeBackdrop.addEventListener('click', function (e) {
      e.preventDefault();
      closeProposeModal();
    });
  }
  window.addEventListener('keydown', function (e) {
    var modal = document.getElementById('cyberProposeModal');
    if (e.key === 'Escape' && modal && (modal.classList.contains('is-open') || modal.style.display !== 'none')) {
      closeProposeModal();
    }
  });

  // --- Auto-submit when selecting subject (with intercept for __propose__) ---
  var chonMon = document.getElementById('mon');
  if (chonMon) {
    var previousMonVal = chonMon.value;
    chonMon.addEventListener('change', function () {
      if (chonMon.value === '__propose__') {
        chonMon.value = previousMonVal;
        openProposeModal();
        return;
      }
      if (chonMon.value) {
        previousMonVal = chonMon.value;
        chonMon.form.submit();
      }
    });
  }

  // --- Quick-Pick Subject Pills ---
  var quickTags = document.querySelectorAll('.quick-mon-tag');
  quickTags.forEach(function (tag) {
    tag.addEventListener('click', function () {
      var monId = tag.getAttribute('data-mon-id');
      if (chonMon && monId) {
        chonMon.value = monId;
        playPickSound();
        chonMon.form.submit();
      }
    });
  });

  function playMatchFoundSound() {
    var ctx = getAudioCtx();
    if (!ctx) return;
    var now = ctx.currentTime;
    [261.63, 329.63, 392.00, 523.25, 659.25].forEach(function(f, i) {
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(f, now + i * 0.08);
      gain.gain.setValueAtTime(0.28, now + i * 0.08);
      gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.08 + 0.45);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(now + i * 0.08);
      osc.stop(now + i * 0.08 + 0.45);
    });
  }

  // --- Smooth Scroll to Champion Select ---
  var btnChangeChamp = document.getElementById('btnChangeChamp');
  if (btnChangeChamp) {
    btnChangeChamp.addEventListener('click', function (e) {
      e.preventDefault();
      var target = document.getElementById('champSelectSection');
      if (target) {
        target.scrollIntoView({ behavior: 'smooth' });
      }
    });
  }

  // --- Submit Quick Match Button ---
  var btnSubmitQuickMatch = document.getElementById('btnSubmitQuickMatch');
  if (btnSubmitQuickMatch) {
    btnSubmitQuickMatch.addEventListener('click', function () {
      try {
        playLaunchQueueSound();
      } catch (err) {}
    });
  }

  // --- Pre-Match Matchmaking Mode Options (Auto Bot vs PvP Only) ---
  var optModeAutoBot = document.getElementById('optModeAutoBot');
  var optModePvpOnly = document.getElementById('optModePvpOnly');
  if (optModeAutoBot && optModePvpOnly) {
    optModeAutoBot.addEventListener('click', function () {
      optModeAutoBot.classList.add('active');
      optModeAutoBot.classList.add('is-selected');
      optModePvpOnly.classList.remove('active');
      optModePvpOnly.classList.remove('is-selected');
      var r = optModeAutoBot.querySelector('input[type="radio"]');
      if (r) r.checked = true;
      playPickSound();
    });
    optModePvpOnly.addEventListener('click', function () {
      optModePvpOnly.classList.add('active');
      optModePvpOnly.classList.add('is-selected');
      optModeAutoBot.classList.remove('active');
      optModeAutoBot.classList.remove('is-selected');
      var r = optModePvpOnly.querySelector('input[type="radio"]');
      if (r) r.checked = true;
      playPickSound();
    });
  }

  // --- Modern LoL Esports Radar Poller & Smooth Digital Clock ---
  var radarCard = document.getElementById('radarCard');
  var dachoClock = document.getElementById('dachoClock');
  var dachoRawInput = document.getElementById('dachoRaw');
  var logLine2 = document.getElementById('logLine2');
  var matchFoundOverlay = document.getElementById('matchFoundOverlay');
  var pvpTimeoutOverlay = document.getElementById('pvpTimeoutOverlay');
  var radarTimerFill = document.getElementById('radarTimerFill');
  var btnRadarModeToggle = document.getElementById('btnRadarModeToggle');
  var modeLiveIcon = document.getElementById('modeLiveIcon');
  var modeLiveName = document.getElementById('modeLiveName');
  var logModeText = document.getElementById('logModeText');
  var btnPvpRetry = document.getElementById('btnPvpRetry');

  if (radarCard && dachoClock) {
    var ajaxUrl = radarCard.getAttribute('data-ajax-url');
    var toggleBotUrl = radarCard.getAttribute('data-toggle-bot-url');
    var resetTimerUrl = radarCard.getAttribute('data-reset-timer-url');
    var phongUrl = radarCard.getAttribute('data-phong-url');
    var choToiDaSec = parseInt(radarCard.getAttribute('data-cho-toi-da') || 60, 10) || 60;
    var nguongEloVal = parseInt(radarCard.getAttribute('data-nguong-elo') || 100, 10) || 100;
    var choPhepBot = (radarCard.getAttribute('data-cho-phep-bot') === '1');
    var daChoSec = parseInt(dachoRawInput ? dachoRawInput.value : 0, 10) || 0;
    var matchFound = false;
    var isPvpTimeoutPaused = false;

    function formatTime(s) {
      var m = Math.floor(s / 60);
      var sec = s % 60;
      return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    // Hàm đồng bộ hiển thị toàn bộ giao diện theo cấu hình Admin (cập nhật tức thời khi Admin đổi)
    function syncConfigUI() {
      var radarMaxLimit = document.getElementById('radarMaxLimit');
      if (radarMaxLimit) {
        radarMaxLimit.textContent = '/ ' + formatTime(choToiDaSec);
      }
      var radarMaxBadge = document.getElementById('radarMaxBadge');
      if (radarMaxBadge) {
        radarMaxBadge.textContent = 'GIỚI HẠN: ' + choToiDaSec + ' GIÂY';
      }
      var pvpTimeoutTitle = document.querySelector('.pvp-timeout-title');
      if (pvpTimeoutTitle) {
        pvpTimeoutTitle.textContent = 'HẾT ' + choToiDaSec + ' GIÂY TÌM KIẾM NGƯỜI THẬT';
      }
      var btnPvpRetryText = document.querySelector('#btnPvpRetry strong');
      if (btnPvpRetryText) {
        btnPvpRetryText.textContent = 'TÌM TIẾP ' + choToiDaSec + ' GIÂY NỮA';
      }
      if (choPhepBot) {
        if (modeLiveName) modeLiveName.textContent = 'Tự ghép Máy sau ' + choToiDaSec + 's';
        if (logModeText) logModeText.textContent = 'Tự động ghép Đấu Thần Máy nếu sau ' + choToiDaSec + 's không có người thật';
      }
      updateProgressBar();
    }

    // Đồng bộ hiển thị ban đầu
    syncConfigUI();

    function updateProgressBar() {
      if (radarTimerFill) {
        var pct = Math.min(100, Math.round((daChoSec / choToiDaSec) * 100));
        radarTimerFill.style.width = pct + '%';
        if (pct >= 80) {
          radarTimerFill.classList.add('urgent');
        } else {
          radarTimerFill.classList.remove('urgent');
        }
      }
    }

    // 1. Live second-by-second ticking clock (Zero page flicker)
    dachoClock.textContent = formatTime(daChoSec);
    updateProgressBar();

    var timerInterval = setInterval(function () {
      if (matchFound || isPvpTimeoutPaused) return;
      daChoSec++;
      dachoClock.textContent = formatTime(daChoSec);
      updateProgressBar();

      // Dynamic Esports Radar Logs: Bán kính nới rộng theo NGUONG_ELO_GHEP_DOI của Admin
      if (logLine2) {
        var radius = nguongEloVal * (1 + Math.floor(daChoSec / 10));
        if (daChoSec < choToiDaSec - 4) {
          if (daChoSec <= 6) {
            logLine2.innerHTML = '<span class="log-bullet pulse"></span> Đang quét đối thủ trong bán kính Elo ±' + radius + '...';
          } else if (daChoSec <= 15) {
            logLine2.innerHTML = '<span class="log-bullet pulse"></span> Mở rộng bán kính tìm kiếm Elo ±' + radius + '...';
          } else {
            logLine2.innerHTML = '<span class="log-bullet pulse"></span> Tăng tốc rà quét toàn máy chủ (Elo ±' + radius + ')...';
          }
        } else {
          logLine2.innerHTML = '<span class="log-bullet pulse" style="background:#F59E0B;"></span> Sắp chạm mốc ' + choToiDaSec + ' giây...';
        }
      }

      // Check client-side timeout for PvP mode
      if (!choPhepBot && daChoSec >= choToiDaSec) {
        triggerPvpTimeout();
      }
    }, 1000);

    function triggerPvpTimeout() {
      if (isPvpTimeoutPaused || matchFound) return;
      isPvpTimeoutPaused = true;
      if (pvpTimeoutOverlay) {
        pvpTimeoutOverlay.style.display = 'flex';
      }
      if (logLine2) {
        logLine2.innerHTML = '<span class="log-bullet" style="background:#F59E0B;box-shadow:0 0 10px #F59E0B;"></span> <strong>Đã hết ' + choToiDaSec + 's tìm người. Vui lòng chọn hướng xử lý bên trên!</strong>';
      }
    }

    // 2. Background Polling without page reloads (Every 1.2s)
    var isPolling = false;
    function pollQueue() {
      if (matchFound || isPolling || isPvpTimeoutPaused) return;
      isPolling = true;

      fetch(ajaxUrl, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          isPolling = false;
          if (data.choToiDa) {
            var newChoToiDa = parseInt(data.choToiDa, 10);
            if (newChoToiDa && newChoToiDa !== choToiDaSec) {
              choToiDaSec = newChoToiDa;
              syncConfigUI();
            }
          }
          if (data.nguongElo) {
            nguongEloVal = parseInt(data.nguongElo, 10) || nguongEloVal;
          }
          if (data.coTran && data.maTran) {
            matchFound = true;
            clearInterval(timerInterval);
            clearInterval(pollInterval);

            if (logLine2) {
              logLine2.innerHTML = '<span class="log-bullet" style="background:#10B981;box-shadow:0 0 10px #10B981;"></span> <strong>ĐÃ TÌM THẤY ĐỐI THỦ! Vui lòng xác nhận thi đấu...</strong>';
            }

            if (window.triggerEsportsMatchFound) {
              window.triggerEsportsMatchFound(data);
            } else {
              setTimeout(function () {
                window.location.href = data.phongUrl || (phongUrl + data.maTran);
              }, 1200);
            }
          } else if (data.pvpTimeout) {
            // PvP timeout expired without bot
            triggerPvpTimeout();
          } else if (!data.dangCho) {
            // Cancelled or removed
            window.location.reload();
          }
        })
        .catch(function () {
          isPolling = false;
        });
    }

    var pollInterval = setInterval(pollQueue, 1200);

    // 3. Live Mode Toggle directly on Radar Screen (Zero reload)
    if (btnRadarModeToggle && toggleBotUrl) {
      btnRadarModeToggle.addEventListener('click', function () {
        playPickSound();
        fetch(toggleBotUrl, { credentials: 'same-origin' })
          .then(function (res) { return res.json(); })
          .then(function (resData) {
            if (resData.ok) {
              choPhepBot = resData.choPhepBot;
              radarCard.setAttribute('data-cho-phep-bot', choPhepBot ? '1' : '0');
              if (choPhepBot) {
                btnRadarModeToggle.classList.remove('is-pvp');
                btnRadarModeToggle.classList.add('is-bot');
                if (modeLiveIcon) modeLiveIcon.textContent = '🤖';
                if (modeLiveName) modeLiveName.textContent = 'Tự ghép Máy sau ' + choToiDaSec + 's';
                if (logModeText) logModeText.textContent = 'Tự động ghép Đấu Thần Máy nếu sau ' + choToiDaSec + 's không có người thật';
              } else {
                btnRadarModeToggle.classList.remove('is-bot');
                btnRadarModeToggle.classList.add('is-pvp');
                if (modeLiveIcon) modeLiveIcon.textContent = '⚔️';
                if (modeLiveName) modeLiveName.textContent = 'Chỉ đấu Người thật (PvP)';
                if (logModeText) logModeText.textContent = 'Chế độ PvP: Chỉ tìm người chơi thật, không ghép Máy';
              }
            }
          })
          .catch(function () {});
      });
    }

    // 4. Retry PvP Search for another period
    if (btnPvpRetry && resetTimerUrl) {
      btnPvpRetry.addEventListener('click', function () {
        playPickSound();
        btnPvpRetry.disabled = true;
        fetch(resetTimerUrl, { credentials: 'same-origin' })
          .then(function (res) { return res.json(); })
          .then(function (resData) {
            btnPvpRetry.disabled = false;
            if (resData.ok) {
              daChoSec = 0;
              dachoClock.textContent = '00:00';
              updateProgressBar();
              if (pvpTimeoutOverlay) {
                pvpTimeoutOverlay.style.display = 'none';
              }
              isPvpTimeoutPaused = false;
              if (logLine2) {
                logLine2.innerHTML = '<span class="log-bullet pulse"></span> Đã gia hạn hàng chờ ' + choToiDaSec + ' giây! Đang tiếp tục quét...';
              }
            }
          })
          .catch(function () {
            btnPvpRetry.disabled = false;
          });
      });
    }
  }

  // --- Faction Filter Tabs (Plants vs Zombies) ---
  var factionBtns = document.querySelectorAll('.btn-faction-filter');
  var champCardsList = document.querySelectorAll('.champ-card');
  if (factionBtns.length > 0) {
    factionBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        playPickSound();
        factionBtns.forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        var filter = btn.getAttribute('data-filter');
        champCardsList.forEach(function (card) {
          var cardFaction = card.getAttribute('data-faction');
          if (filter === 'all' || cardFaction === filter) {
            card.style.display = '';
          } else {
            card.style.display = 'none';
          }
        });
      });
    });
  }

  // --- Plants vs Zombies: Diverse Rotating Zombies Engine ---
  var pvzZombieHeadgears = [
    // 1. Conehead Zombie (Nón chóp giao thông cam)
    '<polygon points="50,-8 34,16 66,16" fill="#F97316" stroke="#C2410C" stroke-width="2"/><ellipse cx="50" cy="16" rx="18" ry="4" fill="#EA580C"/><rect x="42" y="4" width="16" height="3" fill="#FFF" opacity="0.85"/>',
    // 2. Buckethead Zombie (Xô sắt xám bóng)
    '<path d="M34,-4 L66,-4 L63,18 L37,18 Z" fill="#94A3B8" stroke="#475569" stroke-width="2"/><ellipse cx="50" cy="-4" rx="16" ry="4" fill="#CBD5E1"/><path d="M32,8 Q50,22 68,8" stroke="#64748B" stroke-width="2" fill="none"/>',
    // 3. Flag Zombie (Cờ não đỏ)
    '<g transform="translate(68, -25)"><line x1="0" y1="0" x2="0" y2="80" stroke="#78350F" stroke-width="3"/><polygon points="0,0 28,10 0,20" fill="#EF4444"/><circle cx="12" cy="10" r="5" fill="#F472B6"/><path d="M10,8 Q12,12 14,8" stroke="#DB2777" stroke-width="1.5" fill="none"/></g>',
    // 4. Classic Walker (Zombie thường)
    '<path d="M46,14 Q44,6 42,10 M54,12 Q56,4 58,8" stroke="#000" stroke-width="2"/>'
  ];

  var pvzHeadgearSlots = document.querySelectorAll('.pvz-headgear-slot');
  if (pvzHeadgearSlots.length > 0) {
    var currentZIdx = 0;
    setInterval(function () {
      currentZIdx = (currentZIdx + 1) % pvzZombieHeadgears.length;
      var newGear = pvzZombieHeadgears[currentZIdx];
      pvzHeadgearSlots.forEach(function (slot) {
        slot.innerHTML = newGear;
      });
    }, 4000);
  }

  // Unlock Audio on First User Interaction
  document.addEventListener('click', function unlock() {
    getAudioCtx();
    document.removeEventListener('click', unlock);
  }, { once: true });

  // =========================================================================
  // CẨM NANG LUẬT ĐẤU & CÁCH TÍNH ĐIỂM (CYBER ESPORTS RULEBOOK MODAL & SIMULATOR)
  // =========================================================================
  var rulebookModal = document.getElementById('cyberRulebookModal');
  var btnOpenRulebook = document.getElementById('btnOpenRulebook');
  var btnRibbonRulebook = document.getElementById('btnRibbonRulebook');
  var btnOpenRulebookSub = document.getElementById('btnOpenRulebookSub');
  var btnCloseRulebookModal = document.getElementById('btnCloseRulebookModal');
  var rulebookModalBackdrop = document.getElementById('rulebookModalBackdrop');
  var btnRulebookUnderstood = document.getElementById('btnRulebookUnderstood');

  window.openRulebookModal = function () {
    if (!rulebookModal) return;
    rulebookModal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  };

  window.closeRulebookModal = function () {
    if (!rulebookModal) return;
    rulebookModal.style.display = 'none';
    document.body.style.overflow = '';
  };

  if (btnOpenRulebook) {
    btnOpenRulebook.addEventListener('click', function (e) {
      e.preventDefault();
      window.openRulebookModal();
    });
  }

  if (btnRibbonRulebook) {
    btnRibbonRulebook.addEventListener('click', function (e) {
      e.preventDefault();
      window.openRulebookModal();
    });
  }

  if (btnOpenRulebookSub) {
    btnOpenRulebookSub.addEventListener('click', function (e) {
      e.preventDefault();
      window.openRulebookModal();
    });
  }

  if (btnCloseRulebookModal) {
    btnCloseRulebookModal.addEventListener('click', window.closeRulebookModal);
  }

  if (rulebookModalBackdrop) {
    rulebookModalBackdrop.addEventListener('click', window.closeRulebookModal);
  }

  if (btnRulebookUnderstood) {
    btnRulebookUnderstood.addEventListener('click', function () {
      window.closeRulebookModal();
      var ctaBtn = document.getElementById('btnSubmitQuickMatch');
      if (ctaBtn && !ctaBtn.disabled) {
        ctaBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }

  // Phím ESC đóng modal Cẩm nang
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && rulebookModal && rulebookModal.style.display !== 'none') {
      window.closeRulebookModal();
    }
  });

  // Chuyển 3 Tab trong Cẩm nang
  var ruleTabBtns = document.querySelectorAll('.rulebook-tab-btn');
  var ruleTabPanes = document.querySelectorAll('.rulebook-tab-pane');
  ruleTabBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-tab');
      ruleTabBtns.forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });
      ruleTabPanes.forEach(function (p) {
        p.classList.remove('active');
      });

      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');
      var targetPane = document.getElementById(targetId);
      if (targetPane) {
        targetPane.classList.add('active');
      }
    });
  });

  // Interactive Live Speed Calculator Slider (Mô phỏng điểm tốc độ thời gian thực)
  var speedSlider = document.getElementById('rulebookSpeedSlider');
  if (speedSlider) {
    var sliderSecDisplay = document.getElementById('sliderSecDisplay');
    var calcRemainSec = document.getElementById('calcRemainSec');
    var calcBonusPts = document.getElementById('calcBonusPts');
    var calcTotalPts = document.getElementById('calcTotalPts');
    var calcSpeedRating = document.getElementById('calcSpeedRating');
    var calcLiveEquation = document.getElementById('calcLiveEquation');

    function updateSpeedSimulator() {
      var sec = parseFloat(speedSlider.value) || 0;
      var base = parseInt(speedSlider.dataset.base, 10) || 10;
      var bonus = parseInt(speedSlider.dataset.bonus, 10) || 10;
      var maxSec = parseInt(speedSlider.dataset.maxsec, 10) || 15;

      var remain = Math.max(0, maxSec - sec);
      var bonusPts = Math.floor(bonus * remain / maxSec);
      var totalPts = base + bonusPts;

      if (sliderSecDisplay) sliderSecDisplay.textContent = sec.toFixed(1) + ' giây';
      if (calcRemainSec) calcRemainSec.textContent = remain.toFixed(1) + 's';
      if (calcBonusPts) calcBonusPts.textContent = '+' + bonusPts + ' đ';
      if (calcTotalPts) calcTotalPts.textContent = totalPts + ' ĐIỂM';

      if (calcSpeedRating) {
        calcSpeedRating.className = 'calc-rate-badge';
        if (sec <= 2.0) {
          calcSpeedRating.classList.add('badge-godlike');
          calcSpeedRating.textContent = '⚡ ' + sec.toFixed(1) + 's: THẦN TỐC (MAX ĐIỂM)';
        } else if (sec <= 6.0) {
          calcSpeedRating.classList.add('badge-fast');
          calcSpeedRating.textContent = '🚀 ' + sec.toFixed(1) + 's: BỨT PHÁ (THƯỞNG LỚN)';
        } else if (sec <= 11.0) {
          calcSpeedRating.classList.add('badge-normal');
          calcSpeedRating.textContent = '⏱️ ' + sec.toFixed(1) + 's: ỔN ĐỊNH (AN TOÀN)';
        } else {
          calcSpeedRating.classList.add('badge-slow');
          calcSpeedRating.textContent = '🐢 ' + sec.toFixed(1) + 's: SÁT NÚT (VỪA KỊP)';
        }
      }

      if (calcLiveEquation) {
        calcLiveEquation.textContent = base + ' + ⌊ ' + bonus + ' × (' + maxSec + ' - ' + sec.toFixed(1) + ') / ' + maxSec + ' ⌋ = ' + base + ' + ' + bonusPts + ' = ' + totalPts + ' điểm';
      }
    }

    speedSlider.addEventListener('input', updateSpeedSimulator);
  }

  // Lọc phe phái Đấu Thần PvZ trong Tab 3 Cẩm nang
  var pvzFilterBtns = document.querySelectorAll('.pvz-modal-filter-btn');
  var pvzBuffCards = document.querySelectorAll('.pvz-buff-card');
  if (pvzFilterBtns.length > 0) {
    pvzFilterBtns.forEach(function (fBtn) {
      fBtn.addEventListener('click', function () {
        var faction = fBtn.getAttribute('data-faction') || 'all';
        pvzFilterBtns.forEach(function (b) { b.classList.remove('active'); });
        fBtn.classList.add('active');

        pvzBuffCards.forEach(function (card) {
          var cardFaction = card.getAttribute('data-champ-faction');
          if (faction === 'all' || cardFaction === faction) {
            card.style.display = 'flex';
          } else {
            card.style.display = 'none';
          }
        });
      });
    });
  }

  // ==========================================================================
  // 1. INTERACTIVE CHAMPION & DYNAMIC TIP WARM-UP (KHOANG KHỞI ĐỘNG PHẢN XẠ)
  // ==========================================================================
  (function initWarmupInteractions() {
    var champAvatar = document.getElementById('champAvatarGlow');
    var speechBubble = document.getElementById('champSpeechBubble');
    var speechText = document.getElementById('champSpeechText');
    var tipContent = document.getElementById('radarTipContent');

    var cheerQuotes = [
      "Khí thế dâng cao! Hãy giữ vững tâm lý trước mọi câu hỏi khó!",
      "Trả lời nhanh trong 3s đầu sẽ giật trọn +10 điểm tốc độ!",
      "Một cái đầu lạnh và đôi tay dứt khoát sẽ làm chủ đấu trường!",
      "Đọc kỹ 4 phương án, né bẫy đề thi và giành chiến thắng!",
      "Chuỗi thắng đang đợi bạn, tự tin bấm đáp án chính xác!",
      "Tinh thần thép! Không để đối thủ dẫn trước dù chỉ 1 câu!"
    ];

    var rankTips = [
      "⚡ Mẹo Tốc Độ: Trả lời đúng trong 3 giây đầu để giật trọn vẹn điểm thưởng tốc độ tối đa!",
      "🛡️ Luật Chống AFK: Sau khi vào phòng, bạn có thời gian đếm ngược để sẵn sàng bảo toàn Elo.",
      "🔥 Cân Trình Elo: Hệ thống ưu tiên ghép đối thủ có trình độ tương đương ±100 điểm.",
      "🎯 Thưởng Chuỗi Thắng: Đạt chuỗi 3 trận thắng liên tiếp sẽ được cộng thêm Elo danh dự.",
      "⏱️ Giờ Vàng Đấu Trường: Thi đấu buổi tối có tỉ lệ gặp người thật nhanh gấp 3 lần!",
      "🧠 Đọc Kỹ Đề Bài: Trả lời sai bị 0 điểm, độ chính xác luôn là ưu tiên hàng đầu."
    ];

    if (champAvatar) {
      champAvatar.addEventListener('click', function (e) {
        try { playPetClickSound(); } catch (err) {}

        // Bounce click animation
        champAvatar.classList.remove('bounce-click');
        void champAvatar.offsetWidth;
        champAvatar.classList.add('bounce-click');

        // Dynamic cheerful speech
        if (speechText) {
          var q = cheerQuotes[Math.floor(Math.random() * cheerQuotes.length)];
          speechText.textContent = q;
        }
        if (speechBubble) {
          speechBubble.classList.remove('pop-active');
          void speechBubble.offsetWidth;
          speechBubble.classList.add('pop-active');
        }

        // Spawn spark particles around click
        spawnSparks(e.clientX, e.clientY);
      });
    }

    function spawnSparks(x, y) {
      for (var i = 0; i < 6; i++) {
        var p = document.createElement('div');
        p.className = 'spark-particle';
        var angle = (Math.PI * 2 / 6) * i + (Math.random() * 0.4);
        var dist = 35 + Math.random() * 25;
        p.style.setProperty('--dx', Math.cos(angle) * dist + 'px');
        p.style.setProperty('--dy', Math.sin(angle) * dist + 'px');
        p.style.left = (x || window.innerWidth / 2) + 'px';
        p.style.top = (y || window.innerHeight / 2) + 'px';
        document.body.appendChild(p);
        setTimeout(function (elem) {
          if (elem && elem.parentNode) elem.parentNode.removeChild(elem);
        }, 700, p);
      }
    }

    // Cyclic tip capsule
    if (tipContent) {
      var tipIndex = 0;
      setInterval(function () {
        tipIndex = (tipIndex + 1) % rankTips.length;
        tipContent.style.opacity = '0';
        tipContent.style.transform = 'translateY(4px)';
        setTimeout(function () {
          tipContent.textContent = rankTips[tipIndex];
          tipContent.style.opacity = '1';
          tipContent.style.transform = 'translateY(0)';
        }, 250);
      }, 4200);
    }
  })();

  // ==========================================================================
  // 2. ESPORTS READY CHECK & 3. CINEMATIC VS SHOWDOWN FLOW
  // ==========================================================================
  var rcModal = document.getElementById('esportsReadyCheckModal');
  var vsModal = document.getElementById('esportsVsShowdownModal');

  window.triggerEsportsMatchFound = function (data) {
    if (!rcModal) {
      window.location.href = data.phongUrl || (phongUrl + data.maTran);
      return;
    }

    // 1. Play Fanfare Horn & Screen Shake
    try { playReadyCheckHorn(); } catch (err) {}
    var radarCard = document.getElementById('radarCard');
    if (radarCard) {
      radarCard.classList.add('screen-shake-anim');
    }

    // 2. Populate Ready Check Data
    var rcSubjectName = document.getElementById('rcSubjectName');
    var rcExamName = document.getElementById('rcExamName');
    var rcNameYou = document.getElementById('rcNameYou');
    var rcAvatarYou = document.getElementById('rcAvatarYou');
    var rcNameOpp = document.getElementById('rcNameOpp');
    var rcAvatarOpp = document.getElementById('rcAvatarOpp');
    var rcStatusChipYou = document.getElementById('rcStatusChipYou');
    var rcCheckYou = document.getElementById('rcCheckYou');
    var rcStatusChipOpp = document.getElementById('rcStatusChipOpp');
    var rcCheckOpp = document.getElementById('rcCheckOpp');
    var btnRcAccept = document.getElementById('btnRcAccept');
    var btnRcText = document.getElementById('btnRcText');
    var rcTimerDigits = document.getElementById('rcTimerDigits');
    var rcTimerBar = document.getElementById('rcTimerBar');

    if (rcSubjectName) rcSubjectName.textContent = data.tenMon || 'Môn Thi Đấu';
    if (rcExamName) rcExamName.textContent = data.tenLoaiKT || 'Chính thức';
    if (rcNameYou && data.toi) rcNameYou.textContent = data.toi.ten;
    if (rcAvatarYou && data.toi) rcAvatarYou.src = data.toi.tuongAvatar;
    if (rcNameOpp && data.doiThu) rcNameOpp.textContent = data.doiThu.ten;
    if (rcAvatarOpp && data.doiThu) rcAvatarOpp.src = data.doiThu.tuongAvatar;

    if (rcStatusChipYou) {
      rcStatusChipYou.textContent = 'Chờ bạn xác nhận...';
      rcStatusChipYou.className = 'rc-status-chip';
    }
    if (rcCheckYou) rcCheckYou.classList.remove('is-active');

    if (rcStatusChipOpp) {
      rcStatusChipOpp.textContent = 'Đang kết nối...';
      rcStatusChipOpp.className = 'rc-status-chip';
    }
    if (rcCheckOpp) rcCheckOpp.classList.remove('is-active');

    if (btnRcAccept) {
      btnRcAccept.disabled = false;
      btnRcAccept.className = 'btn-rc-accept pulse-anim';
    }
    if (btnRcText) btnRcText.textContent = 'CHẤP NHẬN TRANH HÙNG (10s)';

    // Show Ready Check Modal
    rcModal.style.display = 'flex';
    document.body.classList.add('modal-open');

    // 10s Countdown Ring
    var remainSec = 10;
    var userAccepted = false;
    var oppAccepted = false;
    var fullDash = 326.7; // 2 * PI * 52
    if (rcTimerBar) {
      rcTimerBar.style.strokeDasharray = fullDash;
      rcTimerBar.style.strokeDashoffset = '0';
    }

    // Opponent accepts automatically after 1.2s - 2.0s
    var oppAcceptTimer = setTimeout(function () {
      oppAccepted = true;
      if (rcStatusChipOpp) {
        rcStatusChipOpp.textContent = 'ĐÃ CHẤP NHẬN ✓';
        rcStatusChipOpp.classList.add('chip-ready');
      }
      if (rcCheckOpp) rcCheckOpp.classList.add('is-active');
      try { playOpponentAcceptedSound(); } catch (err) {}
      checkBothAccepted();
    }, 1400);

    function checkBothAccepted() {
      if (userAccepted && oppAccepted) {
        clearInterval(rcInterval);
        setTimeout(function () {
          startVsShowdown(data);
        }, 500);
      }
    }

    function doUserAccept() {
      if (userAccepted) return;
      userAccepted = true;
      try { playLockInSound(); } catch (err) {}

      if (btnRcAccept) {
        btnRcAccept.disabled = true;
        btnRcAccept.classList.remove('pulse-anim');
        btnRcAccept.classList.add('is-accepted');
      }
      if (btnRcText) btnRcText.textContent = '✓ BẠN ĐÃ SẴN SÀNG';
      if (rcStatusChipYou) {
        rcStatusChipYou.textContent = 'ĐÃ SẴN SÀNG ✓';
        rcStatusChipYou.classList.add('chip-ready');
      }
      if (rcCheckYou) rcCheckYou.classList.add('is-active');

      checkBothAccepted();
    }

    if (btnRcAccept) {
      btnRcAccept.onclick = function (e) {
        e.preventDefault();
        doUserAccept();
      };
    }

    var rcInterval = setInterval(function () {
      remainSec--;
      if (rcTimerDigits) rcTimerDigits.textContent = Math.max(0, remainSec);
      if (rcTimerBar) {
        var offset = fullDash * (1 - (remainSec / 10));
        rcTimerBar.style.strokeDashoffset = offset;
      }
      if (btnRcText && !userAccepted) {
        btnRcText.textContent = 'CHẤP NHẬN TRANH HÙNG (' + remainSec + 's)';
      }

      // Auto accept fallback at 1s if user didn't click
      if (remainSec === 1 && !userAccepted) {
        doUserAccept();
      }

      if (remainSec <= 0) {
        clearInterval(rcInterval);
        clearTimeout(oppAcceptTimer);
        startVsShowdown(data);
      }
    }, 1000);
  };

  function startVsShowdown(data) {
    if (rcModal) rcModal.style.display = 'none';

    if (!vsModal) {
      window.location.href = data.phongUrl || (phongUrl + data.maTran);
      return;
    }

    // Play Thunder / Slash sound
    try { playVsThunderSlashSound(); } catch (err) {}

    // Populate Showdown Elements
    var vsSubjectTitle = document.getElementById('vsSubjectTitle');
    var vsAvatarYou = document.getElementById('vsAvatarYou');
    var vsNameYou = document.getElementById('vsNameYou');
    var vsChampYou = document.getElementById('vsChampYou');
    var vsEloYou = document.getElementById('vsEloYou');
    var vsQuoteYou = document.getElementById('vsQuoteYou');

    var vsAvatarOpp = document.getElementById('vsAvatarOpp');
    var vsNameOpp = document.getElementById('vsNameOpp');
    var vsChampOpp = document.getElementById('vsChampOpp');
    var vsEloOpp = document.getElementById('vsEloOpp');
    var vsQuoteOpp = document.getElementById('vsQuoteOpp');

    if (vsSubjectTitle) {
      vsSubjectTitle.textContent = (data.tenMon || 'ĐẤU TRƯỜNG TRANH TÀI') + ' · ' + (data.tenLoaiKT || 'CHÍNH THỨC');
    }

    if (data.toi) {
      if (vsAvatarYou) vsAvatarYou.src = data.toi.tuongAvatar;
      if (vsNameYou) vsNameYou.textContent = data.toi.ten;
      if (vsChampYou) vsChampYou.textContent = data.toi.tuongTen;
      if (vsEloYou) vsEloYou.textContent = (data.toi.elo || 1000) + ' ELO';
      if (vsQuoteYou) vsQuoteYou.textContent = '“' + (data.toi.cauNoi || 'Sẵn sàng tranh tài!') + '”';
      var vsMasteryYou = document.getElementById('vsMasteryYou');
      if (vsMasteryYou && data.toi.capDo) {
        vsMasteryYou.className = 'fighter-mastery-tag ' + (data.toi.cssClass || 'mastery-cap-1');
        vsMasteryYou.innerHTML = (data.toi.iconCap || '🌱') + ' Cấp ' + data.toi.capDo + ' · ' + (data.toi.danhHieuCap || '');
      }
    }

    if (data.doiThu) {
      if (vsAvatarOpp) vsAvatarOpp.src = data.doiThu.tuongAvatar;
      if (vsNameOpp) vsNameOpp.textContent = data.doiThu.ten;
      if (vsChampOpp) vsChampOpp.textContent = data.doiThu.tuongTen;
      if (vsEloOpp) vsEloOpp.textContent = (data.doiThu.elo || 1000) + ' ELO';
      if (vsQuoteOpp) vsQuoteOpp.textContent = '“' + (data.doiThu.cauNoi || 'Nãooo... chuẩn bị tinh thần bại trận!') + '”';
      var vsMasteryOpp = document.getElementById('vsMasteryOpp');
      if (vsMasteryOpp && data.doiThu.capDo) {
        vsMasteryOpp.className = 'fighter-mastery-tag ' + (data.doiThu.cssClass || 'mastery-cap-1');
        vsMasteryOpp.innerHTML = (data.doiThu.iconCap || '⚔️') + ' Cấp ' + data.doiThu.capDo + ' · ' + (data.doiThu.danhHieuCap || '');
      }
    }

    // Hiển thị Đột Biến Không Gian nếu trận đấu có luật đột biến
    var vsMutatorRuleCapsule = document.getElementById('vsMutatorRuleCapsule');
    var vsMutatorIcon = document.getElementById('vsMutatorIcon');
    var vsMutatorTitle = document.getElementById('vsMutatorTitle');
    var vsMutatorDesc = document.getElementById('vsMutatorDesc');

    if (data.dotBien && data.dotBien.id !== 'chuan') {
      if (vsMutatorRuleCapsule) vsMutatorRuleCapsule.style.display = 'inline-flex';
      if (vsMutatorIcon) vsMutatorIcon.textContent = data.dotBien.icon || '🎰';
      if (vsMutatorTitle) vsMutatorTitle.textContent = 'ĐỘT BIẾN LUẬT: ' + (data.dotBien.ten || '');
      if (vsMutatorDesc) vsMutatorDesc.textContent = data.dotBien.moTa || '';
    } else if (vsMutatorRuleCapsule) {
      vsMutatorRuleCapsule.style.display = 'none';
    }

    // Show VS Showdown Screen
    vsModal.style.display = 'flex';

    // Trigger visual slide-in animations
    var vsFighterYou = document.getElementById('vsFighterYou');
    var vsFighterOpp = document.getElementById('vsFighterOpp');
    var vsCenterBurst = document.getElementById('vsCenterBurst');
    var vsLoadingProgress = document.getElementById('vsLoadingProgress');

    if (vsFighterYou) vsFighterYou.classList.add('slide-in-left');
    if (vsFighterOpp) vsFighterOpp.classList.add('slide-in-right');
    if (vsCenterBurst) vsCenterBurst.classList.add('burst-appear');

    if (vsLoadingProgress) {
      vsLoadingProgress.style.transition = 'width 2.4s cubic-bezier(0.16, 1, 0.3, 1)';
      setTimeout(function () {
        vsLoadingProgress.style.width = '100%';
      }, 50);
    }

    // Redirect to match room after 2.5s cinematic showdown
    setTimeout(function () {
      vsModal.classList.add('fade-out-exit');
      setTimeout(function () {
        window.location.href = data.phongUrl || (phongUrl + data.maTran);
      }, 350);
    }, 2500);
  }
})();
