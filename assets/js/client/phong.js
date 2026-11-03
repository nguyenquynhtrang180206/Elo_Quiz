/* Phòng thi đấu (client/phong.php) */
(function () {
  // Phòng chờ (trạng thái "Đã ghép"): tải lại định kỳ cho tới khi trận bắt đầu.
  var cho = document.querySelector('[data-tai-lai]');
  if (cho) {
    setTimeout(function () { location.reload(); }, parseInt(cho.getAttribute('data-tai-lai'), 10) || 1500);
    return;
  }

  // Đang thi đấu (trạng thái "Đang diễn ra"): chỉ chạy khi trang có khung #thi.
  var thi = document.getElementById('thi');
  if (!thi) { return; }
  var API  = thi.dataset.api;     // địa chỉ api_thi.php của trận này (do phong.php truyền qua data-api)
  var CSRF = thi.dataset.csrf;    // mã CSRF (do phong.php truyền qua data-csrf)
  var khung = document.getElementById('khung');
  var tiendo = document.getElementById('tiendo');
  var cauHienTai = null, hanChot = 0, daGui = false, dangPhanHoi = false, dangLay = false;
  var nutDapAn = [], oDongHo = null;

  function xoa(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }
  function the(tag, text, cls) {
    var e = document.createElement(tag);
    if (text !== undefined) { e.textContent = text; }   // textContent: không chèn HTML, chống XSS
    if (cls) { e.className = cls; }
    return e;
  }

  // Chịu thua: người bấm thua, đối thủ thắng. Máy chủ kiểm tra lại mọi điều kiện.
  var nutChiuThua = document.getElementById('nutChiuThua');
  nutChiuThua.onclick = function () {
    if (!confirm('Bạn chắc chắn muốn chịu thua? Đối thủ sẽ thắng trận này.')) { return; }
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

  function veTienDo(d) {
    var gn = { dung: 'trả lời đúng', sai: 'trả lời sai', hetgio: 'hết giờ' };
    var t = d.toi, k = d.doiThu, n = d.tran.tongCau;
    tiendo.textContent = 'Bạn: ' + t.diem + ' điểm (' + t.daXong + '/' + n + ' câu)   |   ' +
      k.ten + ': ' + k.diem + ' điểm (' + k.daXong + '/' + n + ' câu' + (k.ganNhat ? ', vừa ' + gn[k.ganNhat] : '') + ')';
  }

  function veCau(c, tong) {
    xoa(khung);
    nutDapAn = [];
    khung.appendChild(the('h3', 'Câu ' + c.thuTu + '/' + tong));
    oDongHo = the('p', '', 'loi');
    khung.appendChild(oDongHo);
    khung.appendChild(the('p', c.noiDung));
    c.dapAn.forEach(function (d) {
      var b = the('button', d.noiDung, 'btn btn-phu nut-dap-an');
      b.type = 'button';
      b.onclick = function () { gui(d.id); };
      nutDapAn.push(b);
      khung.appendChild(b);
    });
    tick();
  }

  function khoaNut() { nutDapAn.forEach(function (b) { b.disabled = true; }); }

  // Đồng hồ chỉ để hiển thị; máy chủ mới quyết định hạn trả lời
  function tick() {
    if (!cauHienTai || !oDongHo) { return; }
    var con = Math.max(0, (hanChot - Date.now()) / 1000);
    if (con <= 0 && !daGui) {
      oDongHo.textContent = 'Hết giờ...';
      khoaNut();
    } else if (!daGui) {
      oDongHo.textContent = 'Còn ' + Math.ceil(con) + ' giây';
    }
  }
  setInterval(tick, 200);

  function gui(maDapAn) {
    if (daGui || !cauHienTai) { return; }
    daGui = true;
    khoaNut();
    var fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('macau', cauHienTai.maCau);
    fd.append('dapan', maDapAn);
    fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (r.loi) { location.reload(); return; }
        dangPhanHoi = true;   // tạm dừng polling để người chơi kịp thấy đúng/sai; câu kế chỉ gửi sau đó
        xoa(khung);
        khung.appendChild(the('h2', r.hetGio ? 'Hết giờ! +0 điểm' : (r.dung ? 'Đúng! +' + r.diem + ' điểm' : 'Sai. +0 điểm')));
        setTimeout(function () { dangPhanHoi = false; cauHienTai = null; lay(); }, 1000);
      })
      .catch(function () { location.reload(); });
  }

  function xuLy(d) {
    if (d.tran.trangThai !== 'Đang diễn ra') { location.reload(); return; }   // kết thúc hoặc hủy: tải lại để xem kết quả
    veTienDo(d);
    nutChiuThua.classList.toggle('an', !!d.toi.xong);   // đã làm xong mọi câu thì không còn chịu thua
    if (d.demNguoc > 0) {
      cauHienTai = null;
      xoa(khung);
      khung.appendChild(the('h2', 'Bắt đầu sau ' + d.demNguoc + ' giây...'));
    } else if (d.cau) {
      if (!cauHienTai || cauHienTai.maCau !== d.cau.maCau) {
        cauHienTai = d.cau;
        daGui = false;
        hanChot = Date.now() + d.cau.conLai * 1000;
        veCau(d.cau, d.tran.tongCau);
      }
    } else if (d.toi.xong) {
      cauHienTai = null;
      xoa(khung);
      khung.appendChild(the('p', 'Bạn đã hoàn thành mọi câu. Đang chờ đối thủ...'));
    }
  }

  function lay() {
    if (dangLay || dangPhanHoi) { return; }
    dangLay = true;
    fetch(API, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(xuLy)
      .catch(function () {})
      .then(function () { dangLay = false; });
  }
  lay();
  setInterval(lay, 1200);
})();
