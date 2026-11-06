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

  // --- Auto-submit when selecting subject ---
  var chonMon = document.getElementById('mon');
  if (chonMon) {
    chonMon.addEventListener('change', function () {
      if (chonMon.value) {
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
    var choToiDaSec = parseInt(radarCard.getAttribute('data-cho-toi-da') || 30, 10) || 30;
    var choPhepBot = (radarCard.getAttribute('data-cho-phep-bot') === '1');
    var daChoSec = parseInt(dachoRawInput ? dachoRawInput.value : 0, 10) || 0;
    var matchFound = false;
    var isPvpTimeoutPaused = false;

    function formatTime(s) {
      var m = Math.floor(s / 60);
      var sec = s % 60;
      return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }

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

      // Dynamic Esports Radar Logs
      if (logLine2) {
        if (daChoSec <= 6) {
          logLine2.innerHTML = '<span class="log-bullet pulse"></span> Đang quét đối thủ trong bán kính Elo ±100...';
        } else if (daChoSec <= 18) {
          logLine2.innerHTML = '<span class="log-bullet pulse"></span> Mở rộng bán kính tìm kiếm Elo ±200...';
        } else if (daChoSec <= 26) {
          logLine2.innerHTML = '<span class="log-bullet pulse"></span> Đang kết nối tín hiệu Đấu Thần đối thủ...';
        } else {
          logLine2.innerHTML = '<span class="log-bullet pulse" style="background:#F59E0B;"></span> Sắp chạm mốc 30 giây...';
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
        logLine2.innerHTML = '<span class="log-bullet" style="background:#F59E0B;box-shadow:0 0 10px #F59E0B;"></span> <strong>Đã hết 30s tìm người. Vui lòng chọn hướng xử lý bên trên!</strong>';
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
          if (data.coTran && data.maTran) {
            matchFound = true;
            clearInterval(timerInterval);

            // Play Match Found Sound
            try { playMatchFoundSound(); } catch (e) {}

            // Show Match Found Banner
            if (matchFoundOverlay) {
              matchFoundOverlay.style.display = 'flex';
            }
            if (logLine2) {
              logLine2.innerHTML = '<span class="log-bullet" style="background:#10B981;box-shadow:0 0 10px #10B981;"></span> <strong>ĐÃ TÌM THẤY ĐỐI THỦ! Đang tải đấu trường...</strong>';
            }

            setTimeout(function () {
              window.location.href = phongUrl + data.maTran;
            }, 1200);
          } else if (data.pvpTimeout) {
            // PvP 30s expired without bot
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
                if (modeLiveName) modeLiveName.textContent = 'Tự ghép Máy sau 30s';
                if (logModeText) logModeText.textContent = 'Tự động ghép Đấu Thần Máy nếu sau 30s không có người thật';
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

    // 4. Retry PvP Search for another 30 seconds
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
                logLine2.innerHTML = '<span class="log-bullet pulse"></span> Đã gia hạn hàng chờ 30 giây! Đang tiếp tục quét...';
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
})();
