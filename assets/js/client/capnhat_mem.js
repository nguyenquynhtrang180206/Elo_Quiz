// Cập nhật vùng [data-mem-vung] qua fetch không tải lại trang và phát sự kiện "mem:capnhat".
(function () {
  'use strict';

  var KHONG_MEM = ['cd-like-form', 'cd-like-bl-form'];   // form thả lửa đã có xử lý AJAX riêng
  var lanTai = 0;   // đánh số mỗi lần tải để bỏ qua phản hồi cũ khi người dùng bấm liên tục

  function dungDuoc(form) {
    if (!form || form.tagName !== 'FORM') { return false; }
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return false; }
    if (form.hasAttribute('data-khong-mem') || form.target === '_blank') { return false; }
    for (var i = 0; i < KHONG_MEM.length; i++) {
      if (form.classList.contains(KHONG_MEM[i])) { return false; }
    }
    return !!form.closest('[data-mem-vung]');
  }

  // Giữ vị trí cuộn tương đối theo phần tử có id gần nhất để tránh xô lệch trang
  function layNeo(el) {
    var n = el && el.closest ? el.closest('[id]') : null;
    return n ? { id: n.id, top: n.getBoundingClientRect().top, y: window.scrollY } : { id: '', top: 0, y: window.scrollY };
  }

  function veNeo(neo) {
    var n = neo.id ? document.getElementById(neo.id) : null;
    if (n) { window.scrollBy(0, n.getBoundingClientRect().top - neo.top); }
    else { window.scrollTo(0, neo.y); }   // phần tử đã biến mất (vd. bài vừa xóa): giữ nguyên vị trí cuộn cũ
  }

  function thayVung(res, html, neo, lichSu) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var cu = document.querySelectorAll('[data-mem-vung]');
    var cap = [];
    for (var i = 0; i < cu.length; i++) {
      var moi = doc.querySelector('[data-mem-vung="' + cu[i].getAttribute('data-mem-vung') + '"]');
      if (!moi) { window.location.href = res.url; return; }   // trang trả về khác hẳn (vd. sang đăng nhập)
      cap.push([cu[i], moi]);
    }
    if (!cap.length) { window.location.href = res.url; return; }
    cap.forEach(function (c) { c[0].innerHTML = c[1].innerHTML; });
    if (res.url.indexOf(location.origin) === 0) {
      try {
        if (lichSu === 'push') { history.pushState(null, '', res.url); }
        else if (lichSu !== 'giu' && res.redirected) { history.replaceState(null, '', res.url); }
      } catch (err) {}
    }
    veNeo(neo);
    document.dispatchEvent(new CustomEvent('mem:capnhat'));
  }

  // Đọc xong nội dung phản hồi (JSON hoặc HTML) để dùng cho cả tải thường lẫn tải trước
  function docPhanHoi(res) {
    if ((res.headers.get('content-type') || '').indexOf('json') !== -1) {
      return res.json().then(function (d) { return { res: res, json: d }; });
    }
    return res.text().then(function (html) { return { res: res, html: html }; });
  }

  function xuLyPhanHoi(res, neo, lichSu) {
    return docPhanHoi(res).then(function (kq) { dungPhanHoi(kq, neo, lichSu); });
  }

  function dungPhanHoi(kq, neo, lichSu) {
    if (kq.json !== undefined) {
      if (kq.json && kq.json.redirect) { window.location.href = kq.json.redirect; } else { window.location.reload(); }
      return;
    }
    thayVung(kq.res, kq.html, neo, lichSu);
  }

  // Thanh tiến trình tải trên đầu trang
  var thanh = null, hen = null;
  function thanhChay() {
    if (!thanh) {
      thanh = document.createElement('div');
      thanh.setAttribute('aria-hidden', 'true');
      thanh.style.cssText = 'position:fixed;top:0;left:0;height:3px;width:0;z-index:99999;pointer-events:none;' +
        'background:linear-gradient(90deg,#00D9FF,#FDE047);box-shadow:0 0 8px rgba(0,217,255,.7);opacity:0;';
      document.body.appendChild(thanh);
    }
    clearTimeout(hen);
    thanh.style.transition = 'none'; thanh.style.width = '0'; thanh.style.opacity = '1';
    void thanh.offsetWidth;
    thanh.style.transition = 'width 1.6s cubic-bezier(.1,.7,.2,1)'; thanh.style.width = '80%';
  }
  function thanhXong() {
    if (!thanh) { return; }
    thanh.style.transition = 'width .15s ease-out, opacity .3s ease .15s';
    thanh.style.width = '100%'; thanh.style.opacity = '0';
    hen = setTimeout(function () { thanh.style.width = '0'; }, 500);
  }

  // Tải trước link khi nhấn chuột và cache ngắn hạn
  var boNho = {};
  var HAN_NHO = 60000;   // nhớ trang đã tải 60 giây: bấm lại/đã tải trước thì hiện ngay, không đợi máy chủ
  function lay(url) {
    var m = boNho[url];
    if (m && Date.now() - m.t < HAN_NHO) { return m.p; }
    var p = fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(docPhanHoi);
    boNho[url] = { t: Date.now(), p: p };
    p.catch(function () { delete boNho[url]; });
    return p;
  }

  // Báo ngay chip/tab vừa bấm là đang chọn, không đợi máy chủ trả lời (nội dung đúng sẽ thay vào sau)
  function danhDauNgay(a) {
    var cha = a.parentElement;
    if (!cha || a.classList.contains('dang-chon')) { return; }
    var cu = cha.querySelectorAll(':scope > a.dang-chon');
    if (!cu.length) { return; }
    cu.forEach(function (x) { x.classList.remove('dang-chon'); });
    a.classList.add('dang-chon');
  }

  function guiMem(form, nutBam) {
    if (form.dataset.dangGui === '1') { return; }
    form.dataset.dangGui = '1';
    boNho = {};   // dữ liệu có thể vừa đổi: bỏ các trang đã nhớ
    var nuts = form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])');
    nuts.forEach(function (n) { n.disabled = true; });
    var neo = layNeo(form);
    var fd;
    try { fd = nutBam ? new FormData(form, nutBam) : new FormData(form); } catch (err) { fd = new FormData(form); }

    fetch(form.getAttribute('action') ? form.action : window.location.href, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (res) { return xuLyPhanHoi(res, neo); })
    .catch(function () {
      form.dataset.dangGui = '';
      nuts.forEach(function (n) { n.disabled = false; });
      HTMLFormElement.prototype.submit.call(form);   // lỗi mạng: gửi kiểu thường
    });
  }

  // Mở link GET không tải lại trang (lichSu: 'push' | 'giu' | '')
  function moMem(url, neo, lichSu) {
    var lan = ++lanTai;
    thanhChay();
    lay(url)
      .then(function (kq) {
        if (lan !== lanTai) { return; }   // đã có lần bấm mới hơn
        dungPhanHoi(kq, neo, lichSu);
        thanhXong();
      })
      .catch(function () { window.location.href = url; });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || !dungDuoc(form)) { return; }

    // Form hỏi xác nhận bằng modal (ui.js): để ui.js hỏi, sau khi đồng ý nó gọi form.submit() -> gửi mềm
    if (form.hasAttribute('data-xacnhan')) {
      form.submit = function () { guiMem(form); };
      return;
    }
    // Form hỏi xác nhận bằng confirm() của trang Bài đăng
    var cauHoi = form.getAttribute('data-xac-nhan');
    if (cauHoi && !window.confirm(cauHoi)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      return;
    }
    e.preventDefault();
    e.stopImmediatePropagation();
    guiMem(form, e.submitter || null);
  });

  // Link nào được mở mềm: trả về kiểu ghi lịch sử ('' hoặc 'push'), hoặc null nếu để trình duyệt xử lý
  function kieuLink(e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) { return null; }
    if (a.target === '_blank' || a.hasAttribute('download') || !a.closest('[data-mem-vung]')) { return null; }
    if (a.hasAttribute('data-mem-lien')) { return { a: a, lichSu: '' }; }
    if ((a.getAttribute('href') || '').charAt(0) === '?' && a.closest('[data-mem-links]')) { return { a: a, lichSu: 'push' }; }
    return null;
  }

  // Rê chuột vào link khoảng 0,08 giây là tải trước luôn, tới lúc bấm thì thường đã có sẵn
  var henRe = null;
  document.addEventListener('mouseover', function (e) {
    var k = kieuLink({ target: e.target, defaultPrevented: false, button: 0 });
    clearTimeout(henRe);
    if (k) { henRe = setTimeout(function () { lay(k.a.href).catch(function () {}); }, 80); }
  });

  // Rảnh thì tải trước dần các link đánh dấu data-mem-truoc (tab, mốc thời gian...), cách nhau để không dồn máy chủ
  var daiTruoc = null;
  function taiTruocDan() {
    var tietKiem = navigator.connection && navigator.connection.saveData;
    if (tietKiem) { return; }
    clearTimeout(daiTruoc);
    var ds = Array.prototype.slice.call(document.querySelectorAll('a[data-mem-truoc][href]'));
    (function tiep() {
      var a = ds.shift();
      if (!a) { return; }
      var m = boNho[a.href];
      if (!m || Date.now() - m.t >= HAN_NHO) { lay(a.href).catch(function () {}); }
      daiTruoc = setTimeout(tiep, 300);
    })();
  }
  window.addEventListener('load', function () { setTimeout(taiTruocDan, 600); });
  document.addEventListener('mem:capnhat', function () { setTimeout(taiTruocDan, 600); });

  document.addEventListener('pointerdown', function (e) {   // bắt đầu tải trước khi nhả chuột
    var k = kieuLink(e);
    if (k) { lay(k.a.href).catch(function () {}); }
  });

  document.addEventListener('click', function (e) {
    var k = kieuLink(e);
    if (!k) { return; }
    e.preventDefault();
    if (k.lichSu === 'push') { danhDauNgay(k.a); }
    moMem(k.a.href, layNeo(k.a), k.lichSu);
  });

  // Quay lại / tiến tới trong lịch sử ở trang có link mở mềm: nạp lại đúng nội dung của địa chỉ đó
  window.addEventListener('popstate', function () {
    if (!document.querySelector('[data-mem-links]')) { return; }
    moMem(window.location.href, layNeo(null), 'giu');
  });
})();