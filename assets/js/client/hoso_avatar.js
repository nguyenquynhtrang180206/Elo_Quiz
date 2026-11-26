// Cắt và căn chỉnh ảnh đại diện 512x512 trước khi gửi form trên trang Hồ sơ.
(function () {
  'use strict';
  var input = document.getElementById('anh_dai_dien');
  if (!input || !input.form || !window.FileReader || !HTMLCanvasElement.prototype.toBlob) return;

  var form = input.form;
  var MAX_GOC = 10 * 1024 * 1024;   // ảnh gốc tối đa 10 MB (ảnh sau khi cắt chỉ còn khoảng vài trăm KB)
  var OUT = 512;                    // cạnh ảnh xuất ra
  var VIEW = 520;                   // cạnh canvas xem trước (đơn vị nội bộ)
  var ZMAX = 3;                     // phóng to tối đa 3 lần

  var loi = document.createElement('div');
  loi.className = 'loi av-loi';
  loi.hidden = true;

  var panel = document.createElement('div');
  panel.className = 'av-panel';
  panel.hidden = true;
  panel.innerHTML =
    '<div class="av-dau"><span>Xem trước &amp; căn chỉnh</span>' +
    '<button type="button" class="av-x" aria-label="Bỏ ảnh đã chọn" title="Bỏ chọn ảnh">&times;</button></div>' +
    '<div class="av-khung"><canvas class="av-canvas" width="' + VIEW + '" height="' + VIEW + '"></canvas>' +
    '<div class="av-mask"></div></div>' +
    '<div class="av-zoom"><span aria-hidden="true">&minus;</span>' +
    '<input type="range" min="100" max="' + (ZMAX * 100) + '" value="100" step="1" aria-label="Phóng to / thu nhỏ">' +
    '<span aria-hidden="true">+</span></div>' +
    '<button type="button" class="btn btn-phu av-datlai">Đặt lại vị trí</button>' +
    '<div class="ghichu">Kéo ảnh để dời vị trí, kéo thanh trượt hoặc lăn chuột để phóng to. ' +
    'Phần nằm trong vòng tròn sẽ là ảnh đại diện.</div>';

  var anchor = input.nextElementSibling || input;          // dòng ghi chú ngay dưới ô chọn tệp
  anchor.insertAdjacentElement('afterend', loi);
  loi.insertAdjacentElement('afterend', panel);

  var canvas = panel.querySelector('.av-canvas');
  var ctx = canvas.getContext('2d');
  var slider = panel.querySelector('input[type=range]');
  var nutX = panel.querySelector('.av-x');
  var nutDatLai = panel.querySelector('.av-datlai');

  var img = null, url = null, laPng = false, tenGoc = 'avatar';
  var z = 1, nx = 0, ny = 0;       // z: độ phóng; nx, ny: độ lệch tâm ảnh so với tâm khung (tỉ lệ theo cạnh khung)

  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  // Giữ ảnh luôn phủ kín khung, không để lộ viền trống
  function rangBuoc() {
    if (!img) return;
    var s = z * VIEW / Math.min(img.naturalWidth, img.naturalHeight);
    var mx = Math.max(0, (img.naturalWidth * s - VIEW) / 2 / VIEW);
    var my = Math.max(0, (img.naturalHeight * s - VIEW) / 2 / VIEW);
    nx = clamp(nx, -mx, mx);
    ny = clamp(ny, -my, my);
  }

  function ve(c, S, nenTrang) {
    var k = S / VIEW;
    var s = z * VIEW / Math.min(img.naturalWidth, img.naturalHeight) * k;
    var dw = img.naturalWidth * s, dh = img.naturalHeight * s;
    c.clearRect(0, 0, S, S);
    if (nenTrang) { c.fillStyle = '#fff'; c.fillRect(0, 0, S, S); }
    c.drawImage(img, S / 2 + nx * S - dw / 2, S / 2 + ny * S - dh / 2, dw, dh);
  }

  function hienThi() { if (img) { rangBuoc(); ve(ctx, VIEW, false); } }

  function baoLoi(msg) {
    loi.textContent = msg;
    loi.hidden = false;
  }

  function boChon() {
    if (url) { URL.revokeObjectURL(url); url = null; }
    img = null; z = 1; nx = 0; ny = 0;
    slider.value = 100;
    input.value = '';
    panel.hidden = true;
    loi.hidden = true;
  }

  input.addEventListener('change', function () {
    loi.hidden = true;
    var f = input.files && input.files[0];
    if (!f) { boChon(); return; }

    var ok = /^image\/(jpeg|png)$/.test(f.type) || /\.(jpe?g|png)$/i.test(f.name);
    if (!ok) { boChon(); baoLoi('Ảnh đại diện chỉ nhận file JPG hoặc PNG.'); return; }
    if (f.size > MAX_GOC) { boChon(); baoLoi('Ảnh quá lớn, hãy chọn ảnh dưới 10 MB.'); return; }

    if (url) URL.revokeObjectURL(url);
    url = URL.createObjectURL(f);
    laPng = f.type === 'image/png' || /\.png$/i.test(f.name);
    tenGoc = f.name.replace(/\.[^.]+$/, '') || 'avatar';

    var anh = new Image();
    anh.onload = function () {
      img = anh; z = 1; nx = 0; ny = 0; slider.value = 100;
      panel.hidden = false;
      hienThi();
    };
    anh.onerror = function () { boChon(); baoLoi('Không đọc được ảnh này, hãy thử ảnh khác.'); };
    anh.src = url;
  });

  // Kéo để dời vị trí (chuột, cảm ứng, bút đều dùng pointer events)
  var keo = null;
  canvas.addEventListener('pointerdown', function (e) {
    if (!img) return;
    keo = { x: e.clientX, y: e.clientY };
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!keo) return;
    var r = canvas.getBoundingClientRect();
    nx += (e.clientX - keo.x) / r.width;
    ny += (e.clientY - keo.y) / r.height;
    keo.x = e.clientX; keo.y = e.clientY;
    hienThi();
  });
  function thaKeo() { keo = null; }
  canvas.addEventListener('pointerup', thaKeo);
  canvas.addEventListener('pointercancel', thaKeo);

  slider.addEventListener('input', function () { z = slider.value / 100; hienThi(); });
  canvas.addEventListener('wheel', function (e) {
    if (!img) return;
    e.preventDefault();
    z = clamp(z - e.deltaY * 0.002, 1, ZMAX);
    slider.value = Math.round(z * 100);
    hienThi();
  }, { passive: false });

  nutDatLai.addEventListener('click', function () { z = 1; nx = 0; ny = 0; slider.value = 100; hienThi(); });
  nutX.addEventListener('click', boChon);

  // Khi lưu: cắt vùng đang xem trước thành ảnh vuông rồi thay vào ô chọn tệp, sau đó gửi form như bình thường
  form.addEventListener('submit', function (e) {
    if (!img) return;
    e.preventDefault();
    var c = document.createElement('canvas');
    c.width = c.height = OUT;
    ve(c.getContext('2d'), OUT, !laPng);
    c.toBlob(function (blob) {
      if (blob) {
        try {
          var file = new File([blob], tenGoc + (laPng ? '.png' : '.jpg'), { type: blob.type });
          var dt = new DataTransfer();
          dt.items.add(file);
          input.files = dt.files;
        } catch (err) { /* trình duyệt cũ: gửi ảnh gốc, máy chủ vẫn kiểm tra như cũ */ }
      }
      form.submit();
    }, laPng ? 'image/png' : 'image/jpeg', 0.9);
  });
})();