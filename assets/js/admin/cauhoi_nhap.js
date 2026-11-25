// Trang Nhập câu hỏi từ Word/Excel kèm Trợ lý Sửa lỗi trực tiếp trên Web (admin/cauhoi_nhap.php).
// Đặt tại: assets/js/admin/cauhoi_nhap.js
(function () {
  'use strict';

  var giamChuyenDong = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function dinhDangDungLuong(byte) {
    if (byte < 1024) { return byte + ' B'; }
    if (byte < 1024 * 1024) { return Math.round(byte / 1024) + ' KB'; }
    return (byte / 1048576).toFixed(1).replace('.', ',') + ' MB';
  }

  // ---------- 1. Từng vùng thả file ----------
  var vung = document.querySelectorAll('.ci-tha');
  Array.prototype.forEach.call(vung, function (el) {
    var input = el.querySelector('input[type=file]');
    var ten = el.querySelector('.ci-file-ten');
    var kt = el.querySelector('.ci-file-kt');
    var boChon = el.querySelector('.ci-bo-chon');
    var nhac = el.querySelector('.ci-nhac-loi');
    var duoi = el.getAttribute('data-duoi') || '';
    var toiDa = parseInt(el.getAttribute('data-max') || '0', 10);

    function baoLoi(chu) {
      if (!nhac) { return; }
      nhac.textContent = chu;
      nhac.hidden = chu === '';
    }

    function capNhat() {
      var f = input.files && input.files[0];
      el.classList.remove('keo');
      if (!f) {
        el.classList.remove('co-file');
        input.dispatchEvent(new CustomEvent('ci-doi', { bubbles: true }));
        return;
      }
      var phan = f.name.split('.');
      var d = phan.length > 1 ? phan[phan.length - 1].toLowerCase() : '';
      if (d !== duoi) {
        input.value = '';
        el.classList.remove('co-file');
        if (duoi === 'docx' && d === 'doc') {
          baoLoi('File .doc là Word 97-2003 cũ. Hãy mở file trong Word -> Bấm F12 (Save As) -> Chọn "Word Document (*.docx)" rồi thả lại vào đây.');
        } else if (duoi === 'xlsx' && (d === 'xls' || d === 'csv')) {
          baoLoi('Hãy mở file trong Excel -> Bấm F12 (Save As) -> Chọn "Excel Workbook (*.xlsx)" rồi thả lại vào đây.');
        } else {
          baoLoi('Chỉ nhận file định dạng .' + duoi + '.');
        }
      } else if (toiDa > 0 && f.size > toiDa) {
        input.value = '';
        el.classList.remove('co-file');
        baoLoi('File vượt quá ' + dinhDangDungLuong(toiDa) + '.');
      } else {
        baoLoi('');
        ten.textContent = f.name;
        kt.textContent = dinhDangDungLuong(f.size);
        el.classList.add('co-file');
      }
      input.dispatchEvent(new CustomEvent('ci-doi', { bubbles: true }));
    }

    input.addEventListener('change', capNhat);
    ['dragenter', 'dragover'].forEach(function (s) {
      el.addEventListener(s, function () { el.classList.add('keo'); });
    });
    ['dragleave', 'drop'].forEach(function (s) {
      el.addEventListener(s, function () { el.classList.remove('keo'); });
    });
    if (boChon) {
      boChon.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        input.value = '';
        baoLoi('');
        capNhat();
      });
    }
  });

  // ---------- 2. Từng form upload Word / Excel ----------
  var forms = document.querySelectorAll('form[data-ci-form]');
  Array.prototype.forEach.call(forms, function (form) {
    var nuts = form.querySelectorAll('button[type=submit]');
    var file = form.querySelector('input[type=file]');
    var mon = form.elements['mon'] || null;
    var nhac = form.querySelector('.ci-nhac-loi');
    var hen = null;

    function kiemTra() {
      var coFile = !!(file && file.files && file.files.length);
      // Mở nút ngay khi đã chọn file để Admin không bị bối rối; nếu chưa chọn môn mà bấm nút thì nhắc và focus vào ô Môn học
      Array.prototype.forEach.call(nuts, function (n) {
        if (!n.classList.contains('dang-xu-ly')) { n.disabled = !coFile; }
      });
      if (mon && parseInt(mon.value, 10) > 0) {
        mon.classList.remove('ci-thieu-mon');
        if (nhac && nhac.textContent.indexOf('Hãy chọn Môn học') === 0) {
          nhac.textContent = '';
          nhac.hidden = true;
        }
      }
    }

    function khoiPhuc() {
      if (hen) { clearTimeout(hen); hen = null; }
      Array.prototype.forEach.call(nuts, function (n) {
        n.classList.remove('dang-xu-ly');
      });
      kiemTra();
    }

    form.addEventListener('ci-doi', kiemTra);
    if (mon) { mon.addEventListener('change', kiemTra); }

    form.addEventListener('submit', function (ev) {
      if (mon && parseInt(mon.value, 10) <= 0) {
        ev.preventDefault();
        mon.classList.add('ci-thieu-mon');
        mon.focus();
        if (nhac) {
          nhac.textContent = 'Hãy chọn Môn học ở ô phía trên trước khi phân tích file Word.';
          nhac.hidden = false;
        }
        return;
      }
      var cheDoWord = document.getElementById('cheDoWord');
      if (form.getAttribute('data-ci-form') === 'word' && cheDoWord && cheDoWord.value === 'xuat') {
        hen = setTimeout(khoiPhuc, 4000);
      }
    });

    window.addEventListener('pageshow', function (ev) { if (ev.persisted) { khoiPhuc(); } });
    kiemTra();
  });

  // ---------- 3. BẢNG TRỢ LÝ SỬA LỖI TRỰC TIẾP TRÊN WEB ----------
  var bangSua = document.getElementById('bangSuaTrucTiep');
  var luoi = document.getElementById('luoi');
  var tin = document.getElementById('thongtin');

  if (luoi && tin) {
    var macDinh = luoi.getAttribute('data-mac-dinh') || '';
    var hien = function (ev) {
      var o = ev.target.closest ? ev.target.closest('.ci-o') : null;
      tin.textContent = o ? (o.getAttribute('data-tip') || '') : macDinh;
    };
    luoi.addEventListener('mouseover', hien);
    luoi.addEventListener('focusin', hien);
    luoi.addEventListener('mouseleave', function () { tin.textContent = macDinh; });
    luoi.addEventListener('focusout', function () { tin.textContent = macDinh; });
  }

  if (bangSua) {
    var demHopLeNut = document.getElementById('demHopLeNut');
    var btnLuuHopLe = document.getElementById('btnLuuHopLe');
    var countOkEl = document.getElementById('countOk');
    var countLoiEl = document.getElementById('countLoi');
    var countTrungEl = document.getElementById('countTrung');

    function danhGiaLaiDong(tr) {
      if (!tr) { return; }
      var idx = tr.getAttribute('data-idx');
      var boQuaInput = tr.querySelector('.input-bo-qua');
      var daBoQua = boQuaInput && boQuaInput.value === '1';
      var oLuoi = luoi ? luoi.querySelector('.ci-o[data-idx="' + idx + '"]') : null;

      if (daBoQua) {
        tr.classList.add('da-bo-qua');
        if (oLuoi) { oLuoi.classList.add('da-bo-qua'); }
        capNhatBoDem();
        return;
      }
      tr.classList.remove('da-bo-qua');
      if (oLuoi) { oLuoi.classList.remove('da-bo-qua'); }

      var nd = (tr.querySelector('.ci-input-nd').value || '').trim();
      var paInputs = tr.querySelectorAll('.ci-input-pa');
      var dsPA = [];
      var mapPA = {};
      Array.prototype.forEach.call(paInputs, function (inp) {
        var v = (inp.value || '').trim();
        var k = inp.getAttribute('data-pa');
        mapPA[k] = v;
        if (v !== '') { dsPA.push(v.toLowerCase()); }
      });
      var daDung = (tr.querySelector('.input-dap-an-dung').value || '').trim().toUpperCase();
      var loi = [];

      if (nd === '') { loi.push('Hãy nhập nội dung câu hỏi.'); }
      if (dsPA.length < 2) { loi.push('Cần ít nhất 2 phương án trả lời.'); }
      if (daDung === '' || ['A', 'B', 'C', 'D'].indexOf(daDung) === -1) {
        loi.push('Hãy chọn đúng một đáp án đúng (A, B, C hoặc D).');
      } else if (!mapPA[daDung] || mapPA[daDung] === '') {
        loi.push('Phương án ' + daDung + ' được chọn làm đáp án đúng đang để trống.');
      }

      var daThayPA = {};
      for (var i = 0; i < dsPA.length; i++) {
        if (daThayPA[dsPA[i]]) {
          loi.push('Có hai phương án trả lời giống nhau.');
          break;
        }
        daThayPA[dsPA[i]] = true;
      }

      var pill = tr.querySelector('[data-pill-idx]');
      var loiBox = tr.querySelector('[data-loi-idx]');
      var nhacDa = tr.querySelector('.ci-nhac-da');
      if (nhacDa) { nhacDa.hidden = daDung !== ''; }

      var trangThaiCu = tr.getAttribute('data-trang-thai');
      if (loi.length > 0) {
        tr.setAttribute('data-trang-thai', 'loi');
        tr.className = 'ci-dong-sua dong-loi';
        if (pill) { pill.className = 'ci-pill ci-pill-loi'; pill.textContent = 'Lỗi'; }
        if (loiBox) { loiBox.textContent = loi.join('; '); loiBox.hidden = false; }
        if (oLuoi) {
          oLuoi.className = 'ci-o ci-o-loi';
          oLuoi.setAttribute('data-tip', 'Câu ' + (parseInt(idx, 10) + 1) + ': ' + loi.join('; '));
        }
      } else if (trangThaiCu !== 'trung') {
        tr.setAttribute('data-trang-thai', 'ok');
        tr.className = 'ci-dong-sua dong-ok';
        if (pill) { pill.className = 'ci-pill ci-pill-ok'; pill.textContent = 'Hợp lệ'; }
        if (loiBox) { loiBox.textContent = ''; loiBox.hidden = true; }
        if (oLuoi) {
          oLuoi.className = 'ci-o ci-o-ok';
          oLuoi.setAttribute('data-tip', 'Câu ' + (parseInt(idx, 10) + 1) + ': Hợp lệ - sẵn sàng lưu.');
        }
      }
      capNhatBoDem();
    }

    function capNhatBoDem() {
      var dongs = bangSua.querySelectorAll('tbody .ci-dong-sua');
      var ok = 0, loi = 0, trung = 0;
      Array.prototype.forEach.call(dongs, function (tr) {
        var boQua = tr.querySelector('.input-bo-qua');
        if (boQua && boQua.value === '1') { return; }
        var tt = tr.getAttribute('data-trang-thai');
        if (tt === 'ok') { ok++; }
        else if (tt === 'trung') { trung++; }
        else { loi++; }
      });
      if (countOkEl) { countOkEl.textContent = ok; }
      if (countLoiEl) { countLoiEl.textContent = loi; }
      if (countTrungEl) { countTrungEl.textContent = trung; }
      if (demHopLeNut) { demHopLeNut.textContent = ok; }
      if (btnLuuHopLe) { btnLuuHopLe.disabled = ok === 0; }
    }

    bangSua.addEventListener('click', function (ev) {
      var nutDa = ev.target.closest ? ev.target.closest('.ci-nut-da') : null;
      if (nutDa) {
        var tr = nutDa.closest('.ci-dong-sua');
        var da = nutDa.getAttribute('data-da');
        var inputDung = tr.querySelector('.input-dap-an-dung');
        inputDung.value = da;
        Array.prototype.forEach.call(tr.querySelectorAll('.ci-nut-da'), function (b) {
          b.classList.toggle('active', b === nutDa);
        });
        danhGiaLaiDong(tr);
        return;
      }

      var nutBoQua = ev.target.closest ? ev.target.closest('.ci-nut-boqua') : null;
      if (nutBoQua) {
        var tr2 = nutBoQua.closest('.ci-dong-sua');
        var inp = tr2.querySelector('.input-bo-qua');
        var moi = inp.value === '1' ? '0' : '1';
        inp.value = moi;
        nutBoQua.textContent = moi === '1' ? 'Khôi phục' : 'Bỏ qua';
        danhGiaLaiDong(tr2);
      }
    });

    bangSua.addEventListener('input', function (ev) {
      var tr = ev.target.closest ? ev.target.closest('.ci-dong-sua') : null;
      if (!tr) { return; }
      if (ev.target.classList.contains('ci-input-nd') && tr.getAttribute('data-trang-thai') === 'trung') {
        tr.setAttribute('data-trang-thai', 'ok');
      }
      danhGiaLaiDong(tr);
    });

    var tabs = document.querySelectorAll('.ci-loc-tab');
    Array.prototype.forEach.call(tabs, function (tab) {
      tab.addEventListener('click', function () {
        var loc = tab.getAttribute('data-loc');
        Array.prototype.forEach.call(tabs, function (t) { t.classList.toggle('dang-chon', t === tab); });
        var dongs = bangSua.querySelectorAll('tbody .ci-dong-sua');
        Array.prototype.forEach.call(dongs, function (tr) {
          var tt = tr.getAttribute('data-trang-thai');
          tr.hidden = !(loc === 'all' || tt === loc);
        });
      });
    });

    var btnChuoiDA = document.getElementById('btnApDungChuoiDA');
    var inpChuoiDA = document.getElementById('inputChuoiDapAn');
    if (btnChuoiDA && inpChuoiDA) {
      btnChuoiDA.addEventListener('click', function () {
        var raw = (inpChuoiDA.value || '').trim().toUpperCase();
        if (!raw) { return; }
        var dongs = bangSua.querySelectorAll('tbody .ci-dong-sua');
        var capMatches = raw.match(/\d+\s*[\.\-:\)]?\s*[A-D]/g);
        if (capMatches && capMatches.length > 0) {
          capMatches.forEach(function (cap) {
            var m = cap.match(/(\d+)\s*[\.\-:\)]?\s*([A-D])/);
            if (m) {
              var viTri = parseInt(m[1], 10) - 1;
              var chu = m[2];
              if (dongs[viTri]) {
                var nut = dongs[viTri].querySelector('.ci-nut-da[data-da="' + chu + '"]');
                if (nut) { nut.click(); }
              }
            }
          });
        } else {
          var chuCai = raw.replace(/[^A-D]/g, '').split('');
          chuCai.forEach(function (chu, idx) {
            if (dongs[idx]) {
              var nut = dongs[idx].querySelector('.ci-nut-da[data-da="' + chu + '"]');
              if (nut) { nut.click(); }
            }
          });
        }
      });
    }

    var btnBoQuaLoi = document.getElementById('btnBoQuaTatCaLoi');
    if (btnBoQuaLoi) {
      btnBoQuaLoi.addEventListener('click', function () {
        var dongs = bangSua.querySelectorAll('tbody .ci-dong-sua');
        Array.prototype.forEach.call(dongs, function (tr) {
          var tt = tr.getAttribute('data-trang-thai');
          var inp = tr.querySelector('.input-bo-qua');
          var nut = tr.querySelector('.ci-nut-boqua');
          if (tt !== 'ok' && inp && inp.value === '0') {
            inp.value = '1';
            if (nut) { nut.textContent = 'Khôi phục'; }
            danhGiaLaiDong(tr);
          }
        });
      });
    }
  }

  // ---------- 4. Có kết quả kiểm tra thì cuộn mượt tới ----------
  var kq = document.getElementById('ketqua');
  if (kq && kq.scrollIntoView) {
    kq.scrollIntoView({ behavior: giamChuyenDong ? 'auto' : 'smooth', block: 'start' });
  }
})();
