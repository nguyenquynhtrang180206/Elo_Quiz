/* =====================================================================
   COSMIC NEBULA - hệ thống hạt sao 3D tương tác (Three.js / WebGL)
   Chỉ lo phần đồ họa; không liên quan nghiệp vụ PHP.

   Thành phần:
     1. Nebula  : fragment shader (domain-warped fbm) vẽ ở độ phân giải thấp -> tinh vân lỏng.
     2. Stars   : 1 BufferGeometry (3 lớp xa/giữa/gần), vật lý CPU: spring + damping + lực hút
                  xoáy theo chuột + sóng xung kích khi click, vẽ bằng ShaderMaterial (additive).
     3. FX pool : vòng đệm hạt cho vệt chuột và tia sáng khi click.
     4. Links   : đường sáng nối các hạt đang bị hút; glow tại tâm; vòng shockwave.
   Tối ưu: không cấp phát trong vòng lặp, tự giảm chất lượng khi FPS thấp, dừng khi tab ẩn,
           tôn trọng prefers-reduced-motion, có nút Tự động / Nhẹ / Tắt (lưu localStorage).
   ===================================================================== */
import * as THREE from '../../vendor/three.module.min.js';

const root = document.documentElement;
const KEY = 'cosmic-fx';
const MODES = ['auto', 'low', 'off'];
const MODE_LABEL = { auto: 'Tự động', low: 'Nhẹ', off: 'Tắt' };

let mode = 'auto';
try { mode = localStorage.getItem(KEY) || 'auto'; } catch (e) { /* bỏ qua */ }
if (!MODES.includes(mode)) mode = 'auto';

/* ---------- Nút chọn chế độ hiệu ứng ---------- */
(function makeToggle() {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'fx-toggle';
  b.textContent = '✦ Hiệu ứng: ' + MODE_LABEL[mode];
  b.title = 'Đổi chế độ hiệu ứng nền (tự động / nhẹ / tắt)';
  b.addEventListener('click', () => {
    const next = MODES[(MODES.indexOf(mode) + 1) % MODES.length];
    try { localStorage.setItem(KEY, next); } catch (e) { /* bỏ qua */ }
    location.reload();
  });
  document.body.appendChild(b);
})();

if (mode === 'off') { root.classList.add('cosmic-off'); }
else { init(); }

function init() {
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const weak = mode === 'low' || matchMedia('(pointer: coarse)').matches || Math.min(innerWidth, innerHeight) < 600;
  const Q = weak
    ? { neb: 0.3, oct: 4, n: 1500, dpr: 1, fx: 160, sparks: 40, links: false, nebEvery: 2 }
    : { neb: 0.5, oct: 5, n: 5200, dpr: Math.min(devicePixelRatio || 1, 2), fx: 420, sparks: 90, links: true, nebEvery: 1 };

  /* ---------- Hai renderer (nebula nền + hạt phía trên) ---------- */
  let rN, rP;
  const cN = document.createElement('canvas');
  const cP = document.createElement('canvas');
  cN.id = 'cosmic-nebula'; cP.id = 'cosmic-stars';
  cN.className = cP.className = 'cosmic-canvas';
  cN.setAttribute('aria-hidden', 'true'); cP.setAttribute('aria-hidden', 'true');
  try {
    rN = new THREE.WebGLRenderer({ canvas: cN, antialias: false, alpha: false, powerPreference: 'low-power' });
    rP = new THREE.WebGLRenderer({ canvas: cP, antialias: false, alpha: true, powerPreference: 'default' });
  } catch (err) {
    root.classList.add('no-webgl');   // giữ nền tĩnh dự phòng trong CSS
    return;
  }
  document.body.insertBefore(cP, document.body.firstChild);
  document.body.insertBefore(cN, document.body.firstChild);
  rP.setClearColor(0x000000, 0);
  root.classList.add('cosmic-on');

  const onLost = (e) => { e.preventDefault(); stop(); root.classList.remove('cosmic-on'); root.classList.add('no-webgl'); cN.remove(); cP.remove(); };
  cN.addEventListener('webglcontextlost', onLost);
  cP.addEventListener('webglcontextlost', onLost);

  const additive = (params) => new THREE.ShaderMaterial(Object.assign({
    transparent: true, depthWrite: false, depthTest: false,
    blending: THREE.CustomBlending, blendEquation: THREE.AddEquation,
    blendSrc: THREE.OneFactor, blendDst: THREE.OneFactor,
    blendSrcAlpha: THREE.OneFactor, blendDstAlpha: THREE.OneFactor,
  }, params));

  /* ---------- 1. Nebula (tinh vân lỏng) ---------- */
  const sceneN = new THREE.Scene();
  const camN = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
  const nebMat = new THREE.ShaderMaterial({
    defines: { OCT: Q.oct },
    uniforms: { uTime: { value: 0 }, uRes: { value: new THREE.Vector2(1, 1) }, uPar: { value: new THREE.Vector2() } },
    depthWrite: false, depthTest: false,
    vertexShader: 'varying vec2 vUv; void main(){ vUv=uv; gl_Position=vec4(position.xy,0.,1.); }',
    fragmentShader: `
      uniform float uTime; uniform vec2 uRes; uniform vec2 uPar; varying vec2 vUv;
      float hash(vec2 p){ p=fract(p*vec2(123.34,456.21)); p+=dot(p,p+45.32); return fract(p.x*p.y); }
      float noise(vec2 p){ vec2 i=floor(p), f=fract(p); f=f*f*(3.-2.*f);
        return mix(mix(hash(i),hash(i+vec2(1,0)),f.x), mix(hash(i+vec2(0,1)),hash(i+vec2(1,1)),f.x), f.y); }
      float fbm(vec2 p){ float v=0., a=.5; mat2 m=mat2(1.6,1.2,-1.2,1.6);
        for(int i=0;i<OCT;i++){ v+=a*noise(p); p=m*p; a*=.5; } return v; }
      void main(){
        vec2 uv=vUv-.5; uv.x*=uRes.x/uRes.y; uv+=uPar*.06;
        float t=uTime*.045; vec2 p=uv*2.3;
        vec2 q=vec2(fbm(p+vec2(0.,t)), fbm(p+vec2(5.2,1.3)-t));
        vec2 r=vec2(fbm(p+3.*q+vec2(1.7,9.2)+t*1.3), fbm(p+3.*q+vec2(8.3,2.8)-t));
        float f=fbm(p+3.5*r);
        vec3 bg=vec3(.02,.03,.09), navy=vec3(.03,.10,.23), blue=vec3(.03,.48,1.),
             cyan=vec3(0.,.85,1.), purple=vec3(.545,.21,1.), mag=vec3(.88,.27,1.);
        vec3 col=mix(bg,navy,smoothstep(.1,.5,f));
        col=mix(col,blue,smoothstep(.35,.75,q.x)*.8);
        col=mix(col,cyan,smoothstep(.55,.9,r.y)*.7*smoothstep(.3,.7,f));
        col=mix(col,purple,smoothstep(.4,.8,r.x)*.75);
        col=mix(col,mag,smoothstep(.65,.95,r.x*f*1.6)*.6);
        float fil=pow(1.-abs(fbm(p*3.+3.*r)-.5)*2.,6.);     /* sợi sáng như trong ảnh tham chiếu */
        col+=mix(cyan,mag,r.x)*fil*.22*f;
        float vig=smoothstep(1.25,.25,length(vUv-.5)*1.5);
        col*=.62+.38*vig;                                   /* tối nhẹ để nội dung dễ đọc */
        gl_FragColor=vec4(col,1.);
      }`,
  });
  const nebMesh = new THREE.Mesh(new THREE.PlaneGeometry(2, 2), nebMat);
  nebMesh.frustumCulled = false;
  sceneN.add(nebMesh);

  /* ---------- 2. Sao 3 lớp ---------- */
  const CAMZ = 30, tanH = Math.tan(THREE.MathUtils.degToRad(30));
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(60, 1, 0.1, 300);
  camera.position.set(0, 0, CAMZ);

  const COL = [[0, .85, 1], [.03, .48, 1], [.55, .21, 1], [.88, .27, 1], [.54, .96, 1], [.96, .97, 1]];
  const starVS = `
    attribute float aSize; attribute vec3 aColor; attribute float aGlow; attribute float aPhase;
    uniform float uTime; uniform float uPx; uniform float uBase;
    varying vec3 vColor; varying float vA;
    void main(){
      vec4 mv=modelViewMatrix*vec4(position,1.);
      gl_Position=projectionMatrix*mv;
      gl_PointSize=clamp(aSize*uPx*60./max(-mv.z,1.), 1.3*uPx, 70.*uPx);
      vColor=aColor;
      vA=(.75+.25*sin(uTime*1.5+aPhase))*(uBase+aGlow);
    }`;
  const starFS = `
    varying vec3 vColor; varying float vA;
    void main(){
      float d=length(gl_PointCoord-.5)*2.; if(d>1.) discard;
      float core=1.-d; float a=core*core+pow(core,8.)*.9;
      vec3 c=mix(vColor,vec3(1.),pow(core,6.)*.6);
      gl_FragColor=vec4(c*a*vA, a*vA*.9);
    }`;
  const mkPoints = (n, base) => {
    const g = new THREE.BufferGeometry();
    const pos = new Float32Array(n * 3), glow = new Float32Array(n);
    const size = new Float32Array(n), col = new Float32Array(n * 3), ph = new Float32Array(n);
    const pa = new THREE.BufferAttribute(pos, 3).setUsage(THREE.DynamicDrawUsage);
    const ga = new THREE.BufferAttribute(glow, 1).setUsage(THREE.DynamicDrawUsage);
    const sa = new THREE.BufferAttribute(size, 1).setUsage(THREE.DynamicDrawUsage);
    const ca = new THREE.BufferAttribute(col, 3).setUsage(THREE.DynamicDrawUsage);
    g.setAttribute('position', pa); g.setAttribute('aGlow', ga); g.setAttribute('aSize', sa);
    g.setAttribute('aColor', ca); g.setAttribute('aPhase', new THREE.BufferAttribute(ph, 1));
    const m = additive({
      uniforms: { uTime: { value: 0 }, uPx: { value: 1 }, uBase: { value: base } },
      vertexShader: starVS, fragmentShader: starFS,
    });
    const pts = new THREE.Points(g, m);
    pts.frustumCulled = false;
    return { g, m, pts, pos, glow, size, col, ph, pa, ga, sa, ca };
  };

  const N = Q.n;
  const S = mkPoints(N, 0.7);
  scene.add(S.pts);
  const vel = new Float32Array(N * 3);
  const nx = new Float32Array(N), ny = new Float32Array(N), nz = new Float32Array(N);
  const hx = new Float32Array(N), hy = new Float32Array(N);
  const layer = new Uint8Array(N), seed = new Float32Array(N);
  for (let i = 0; i < N; i++) {
    const r = Math.random();
    const L = r < 0.55 ? 0 : (r < 0.88 ? 1 : 2);
    layer[i] = L; seed[i] = Math.random();
    nz[i] = L === 0 ? -90 + Math.random() * 60 : (L === 1 ? -30 + Math.random() * 38 : 8 + Math.random() * 14);
    nx[i] = (Math.random() * 2 - 1) * 1.2;
    ny[i] = (Math.random() * 2 - 1) * 1.2;
    S.size[i] = L === 0 ? 1.5 + Math.random() * 1.5 : (L === 1 ? 2.5 + Math.random() * 2.5 : 3 + Math.random() * 5);
    const k = Math.random();
    const c = COL[k < 0.3 ? 4 : k < 0.5 ? 0 : k < 0.7 ? 1 : k < 0.86 ? 2 : k < 0.95 ? 3 : 5];
    S.col[i * 3] = c[0]; S.col[i * 3 + 1] = c[1]; S.col[i * 3 + 2] = c[2];
    S.ph[i] = seed[i] * 6.2832;
    S.glow[i] = 0;
  }
  S.g.attributes.aSize.needsUpdate = true; S.g.attributes.aColor.needsUpdate = true;
  S.g.attributes.aPhase.needsUpdate = true;
  let drawN = N;
  S.g.setDrawRange(0, drawN);

  /* ---------- 3. FX pool: vệt chuột + tia sáng ---------- */
  const FXN = Q.fx;
  const F = mkPoints(FXN, 0);
  scene.add(F.pts);
  const fvel = new Float32Array(FXN * 3), flife = new Float32Array(FXN), fmax = new Float32Array(FXN).fill(1);
  const fint = new Float32Array(FXN), fdrag = new Float32Array(FXN);
  let fxHead = 0, fxAlive = 0, fxColDirty = false;
  function spawn(x, y, z, vx, vy, vz, life, size, ci, intensity, drag) {
    const j = fxHead; fxHead = (fxHead + 1) % FXN;
    F.pos[j * 3] = x; F.pos[j * 3 + 1] = y; F.pos[j * 3 + 2] = z;
    fvel[j * 3] = vx; fvel[j * 3 + 1] = vy; fvel[j * 3 + 2] = vz;
    flife[j] = fmax[j] = life; F.size[j] = size; fint[j] = intensity; fdrag[j] = drag;
    const c = COL[ci]; F.col[j * 3] = c[0]; F.col[j * 3 + 1] = c[1]; F.col[j * 3 + 2] = c[2];
    fxAlive++; fxColDirty = true;
  }

  /* ---------- 4. Links, glow, shockwave ---------- */
  const MAXSEG = 90;
  const linkPos = new Float32Array(MAXSEG * 6);
  const linkGeo = new THREE.BufferGeometry();
  linkGeo.setAttribute('position', new THREE.BufferAttribute(linkPos, 3).setUsage(THREE.DynamicDrawUsage));
  linkGeo.setDrawRange(0, 0);
  const linkMat = additive({
    uniforms: { uColor: { value: new THREE.Color(0.2, 0.85, 1.0) }, uOp: { value: 0.3 } },
    vertexShader: 'void main(){ gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.); }',
    fragmentShader: 'uniform vec3 uColor; uniform float uOp; void main(){ gl_FragColor=vec4(uColor*uOp,uOp*.8); }',
  });
  const links = new THREE.LineSegments(linkGeo, linkMat);
  links.frustumCulled = false; links.visible = Q.links;
  scene.add(links);

  const glowMat = additive({
    uniforms: { uI: { value: 0 } },
    vertexShader: 'varying vec2 vUv; void main(){ vUv=uv; gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.); }',
    fragmentShader: `uniform float uI; varying vec2 vUv;
      void main(){ float r=length(vUv-.5)*2.; if(r>1.) discard; float a=pow(1.-r,2.4)*uI;
        vec3 c=mix(vec3(.55,.21,1.),vec3(0.,.85,1.),pow(1.-r,1.5)); gl_FragColor=vec4(c*a,a); }`,
  });
  const glowMesh = new THREE.Mesh(new THREE.PlaneGeometry(2, 2), glowMat);
  glowMesh.frustumCulled = false; glowMesh.visible = false;
  scene.add(glowMesh);

  const ringGeo = new THREE.RingGeometry(0.72, 1, 96, 1);
  const WAVES = 3;
  const waves = [];
  for (let k = 0; k < WAVES; k++) {
    const m = additive({
      uniforms: { uA: { value: 0 } },
      vertexShader: 'varying float vR; void main(){ vR=length(position.xy); gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.); }',
      fragmentShader: `uniform float uA; varying float vR;
        void main(){ float t=smoothstep(.72,1.,vR); float a=pow(t,3.)*(1.-smoothstep(.985,1.,vR))*uA;
          vec3 c=mix(vec3(.88,.27,1.),vec3(0.,.85,1.),t); gl_FragColor=vec4(c*a,a); }`,
    });
    const mesh = new THREE.Mesh(ringGeo, m);
    mesh.visible = false; mesh.frustumCulled = false;
    scene.add(mesh);
    waves.push({ mesh, mat: m, on: false, age: 0, x: 0, y: 0, power: 1 });
  }
  let wavesOn = 0, waveCursor = 0;

  /* ---------- Trạng thái chuột ---------- */
  const mouse = { x: 0, y: 0, px: 0, py: 0, active: false };  // px: tọa độ màn hình
  const ms = { x: 0, y: 0 };            // vị trí đã làm mượt (có độ trễ)
  let mStr = 0, spS = 0, lastPx = 0, lastPy = 0, lastNx = 0, lastNy = 0;
  const par = { x: 0, y: 0 };
  const cand = new Int16Array(48);

  const setPointer = (e) => {
    mouse.px = e.clientX; mouse.py = e.clientY;
    mouse.x = (e.clientX / innerWidth) * 2 - 1;
    mouse.y = -(e.clientY / innerHeight) * 2 + 1;
    if (!mouse.active) { ms.x = mouse.x; ms.y = mouse.y; lastPx = e.clientX; lastPy = e.clientY; lastNx = mouse.x; lastNy = mouse.y; }
    mouse.active = true;
  };
  const INTERACTIVE = 'a,button,input,select,textarea,label,summary,[role=button]';
  function addEvents() {
    addEventListener('pointermove', setPointer, { passive: true });
    addEventListener('pointerdown', (e) => {
      setPointer(e);
      if (e.target && e.target.closest && e.target.closest(INTERACTIVE)) return;   // không nổ khi bấm nút/form
      explode(mouse.x, mouse.y);
    }, { passive: true });
    addEventListener('pointerup', (e) => { if (e.pointerType !== 'mouse') mouse.active = false; }, { passive: true });
    addEventListener('pointercancel', () => { mouse.active = false; }, { passive: true });
    document.addEventListener('mouseleave', () => { mouse.active = false; });
    addEventListener('blur', () => { mouse.active = false; });
  }

  function explode(ndcX, ndcY) {
    const w = waves[waveCursor]; waveCursor = (waveCursor + 1) % WAVES;
    if (!w.on) wavesOn++;
    w.on = true; w.age = 0; w.x = ndcX; w.y = ndcY; w.mesh.visible = true;
    const d = CAMZ - 6, bx = camera.position.x + ndcX * camera.aspect * tanH * d, by = camera.position.y + ndcY * tanH * d;
    for (let k = 0; k < Q.sparks; k++) {
      const a = Math.random() * 6.2832, sp = 8 + Math.random() * 20;
      spawn(bx, by, 6, Math.cos(a) * sp, Math.sin(a) * sp, (Math.random() - 0.5) * 14,
        0.6 + Math.random() * 0.8, 3 + Math.random() * 4, (Math.random() * 4) | 0, 0.9, 2.2);
    }
  }

  /* ---------- Bố cục / resize ---------- */
  let W = 1, H = 1, nebScale = Q.neb;
  function layout() {
    for (let i = 0; i < N; i++) {
      const hh = tanH * (CAMZ - nz[i]);
      hx[i] = nx[i] * hh * camera.aspect; hy[i] = ny[i] * hh;
    }
  }
  function resize() {
    W = Math.max(1, innerWidth); H = Math.max(1, innerHeight);
    rP.setPixelRatio(Q.dpr); rP.setSize(W, H, false);
    rN.setPixelRatio(nebScale); rN.setSize(W, H, false);
    camera.aspect = W / H; camera.updateProjectionMatrix();
    nebMat.uniforms.uRes.value.set(W, H);
    const px = Q.dpr * H / 900;
    S.m.uniforms.uPx.value = px; F.m.uniforms.uPx.value = px;
    const first = hx[0] === 0 && hy[0] === 0;
    layout();
    if (first) for (let i = 0; i < N; i++) { S.pos[i * 3] = hx[i]; S.pos[i * 3 + 1] = hy[i]; S.pos[i * 3 + 2] = nz[i]; }
    if (reduceMotion) renderOnce();
  }
  let rq = 0;
  addEventListener('resize', () => { cancelAnimationFrame(rq); rq = requestAnimationFrame(resize); });

  /* ---------- Vật lý + vẽ mỗi khung ---------- */
  let time = 0, last = 0, raf = 0, ema = 16, slow = 0, degrade = 0, frameNo = 0;
  const WA = [1.5, 1.8, 2.5], WS = [0.05, 0.09, 0.14];

  function step(dt) {
    time += dt;
    const damp = Math.exp(-dt * 1.8);
    const cx = camera.position.x, cy = camera.position.y;

    // chuột: làm mượt vị trí, đo tốc độ, tính cường độ hút (có quán tính)
    const dpx = mouse.px - lastPx, dpy = mouse.py - lastPy;
    lastPx = mouse.px; lastPy = mouse.py;
    const sp = Math.min(1, Math.hypot(dpx, dpy) / dt / 2200);
    spS += (sp - spS) * (1 - Math.exp(-dt * 8));
    const kf = 1 - Math.exp(-dt * 9);
    ms.x += (mouse.x - ms.x) * kf; ms.y += (mouse.y - ms.y) * kf;
    const target = mouse.active ? 0.38 + 0.62 * spS : 0;
    mStr += (target - mStr) * (1 - Math.exp(-dt * (mouse.active ? 4 : 1.6)));
    if (!mouse.active) spS *= Math.exp(-dt * 3);

    // parallax camera
    par.x += (mouse.x - par.x) * (1 - Math.exp(-dt * 2.5));
    par.y += (mouse.y - par.y) * (1 - Math.exp(-dt * 2.5));
    camera.position.x = par.x * 2.4; camera.position.y = par.y * 1.5;
    nebMat.uniforms.uPar.value.set(par.x, par.y);

    const s = mStr, gk = 1 - Math.exp(-dt * 6);
    const swirl = 0.9 + spS * 1.3, G = 70 * s, asp = camera.aspect;
    const msx = ms.x, msy = ms.y, camx = camera.position.x, camy = camera.position.y;
    let nc = 0;
    const pos = S.pos, glow = S.glow;

    for (let i = 0; i < drawN; i++) {
      const i3 = i * 3, L = layer[i], ph = seed[i] * 6.2832;
      let px = pos[i3], py = pos[i3 + 1], pz = pos[i3 + 2];
      const tx = hx[i] + Math.sin(time * WS[L] + ph) * WA[L];
      const ty = hy[i] + Math.cos(time * WS[L] * 1.3 + ph * 1.7) * WA[L];
      const tz = nz[i] + Math.cos(time * WS[L] * 0.8 + ph * 2.3) * WA[L] * 0.6;
      let ax = (tx - px) * 1.6, ay = (ty - py) * 1.6, az = (tz - pz) * 1.2;   // lực lò xo về vị trí tự nhiên
      const dist = CAMZ - pz, th = tanH * dist;
      let gl = 0;

      if (s > 0.004) {
        const mx = camx + msx * asp * th, my = camy + msy * th;      // điểm chuột ở độ sâu của hạt
        const dx = mx - px, dy = my - py, r = Math.sqrt(dx * dx + dy * dy) + 1e-4;
        const R = 0.42 * th;
        if (r < R) {
          const u = r / R, f = (1 - u) * (1 - u);
          const c0 = Math.min(1, u / 0.18), att = c0 * c0 * (3 - 2 * c0);   // mềm hóa lõi: tạo quỹ đạo thay vì sụp vào một điểm
          const fa = G * f * att * (L === 2 ? 1.15 : L === 1 ? 1 : 0.55);
          ax += (dx / r) * fa - (dy / r) * G * f * swirl * 0.6;
          ay += (dy / r) * fa + (dx / r) * G * f * swirl * 0.6;
          az += (4 - pz) * 3 * s * f;
          gl = s * f * 1.4;
          if (u < 0.7 && L > 0 && nc < 48 && (i & 3) === 0) cand[nc++] = i;
        }
      }

      let vx = vel[i3] + ax * dt, vy = vel[i3 + 1] + ay * dt, vz = vel[i3 + 2] + az * dt;

      if (wavesOn > 0) {                                           // sóng xung kích
        const ndcX = (px - camx) / (th * asp), ndcY = (py - camy) / th;
        for (let k = 0; k < WAVES; k++) {
          const w = waves[k]; if (!w.on) continue;
          const wx = (ndcX - w.x) * asp, wy = ndcY - w.y;
          const dn = Math.sqrt(wx * wx + wy * wy) + 1e-4, rw = w.age * 1.9, bd = 0.24, off = Math.abs(dn - rw);
          if (off < bd) {
            const q = 1 - off / bd, imp = q * q * Math.exp(-w.age * 1.6) * (L === 2 ? 1.1 : L === 1 ? 0.8 : 0.4) * th * 4;
            vx += (wx / dn) * imp; vy += (wy / dn) * imp; vz += (seed[i] - 0.5) * imp * 0.8;
            gl = Math.max(gl, q * 1.2);
          }
        }
      }

      vx *= damp; vy *= damp; vz *= damp;
      vel[i3] = vx; vel[i3 + 1] = vy; vel[i3 + 2] = vz;
      pos[i3] = px + vx * dt; pos[i3 + 1] = py + vy * dt; pos[i3 + 2] = pz + vz * dt;
      glow[i] += (gl - glow[i]) * gk;
    }
    S.pa.needsUpdate = true; S.ga.needsUpdate = true;

    // đường sáng nối các hạt bị hút
    if (Q.links) {
      let seg = 0;
      if (s > 0.08 && nc > 1) {
        for (let a = 0; a < nc && seg < MAXSEG; a++) {
          const ia = cand[a] * 3; let made = 0;
          for (let b = a + 1; b < nc && made < 2 && seg < MAXSEG; b++) {
            const ib = cand[b] * 3, ddx = pos[ia] - pos[ib], ddy = pos[ia + 1] - pos[ib + 1], ddz = pos[ia + 2] - pos[ib + 2];
            if (ddx * ddx + ddy * ddy + ddz * ddz < 36) {
              const o = seg * 6;
              linkPos[o] = pos[ia]; linkPos[o + 1] = pos[ia + 1]; linkPos[o + 2] = pos[ia + 2];
              linkPos[o + 3] = pos[ib]; linkPos[o + 4] = pos[ib + 1]; linkPos[o + 5] = pos[ib + 2];
              seg++; made++;
            }
          }
        }
      }
      linkGeo.setDrawRange(0, seg * 2);
      linkGeo.attributes.position.needsUpdate = true;
      linkMat.uniforms.uOp.value = Math.min(0.45, s * 0.5);
    }

    // glow ở điểm hội tụ
    if (s > 0.01) {
      const d = CAMZ;
      glowMesh.visible = true;
      glowMesh.position.set(camx + msx * asp * tanH * d, camy + msy * tanH * d, 0);
      glowMesh.scale.setScalar(tanH * d * (0.16 + s * 0.2));
      glowMat.uniforms.uI.value = Math.min(0.85, s * 0.8);
    } else glowMesh.visible = false;

    // vệt chuột: sinh hạt dọc đoạn di chuyển khi chuột đủ nhanh
    if (mouse.active && Math.hypot(dpx, dpy) > 7) {
      const n = Math.min(Q.links ? 7 : 3, Math.floor(Math.hypot(dpx, dpy) / 14) + 1), d = CAMZ - 8;
      for (let k = 0; k < n; k++) {
        const t = (k + Math.random()) / n;
        const ux = lastNx + (mouse.x - lastNx) * t, uy = lastNy + (mouse.y - lastNy) * t;
        spawn(camx + ux * asp * tanH * d, camy + uy * tanH * d, 8,
          (Math.random() - 0.5) * 3, (Math.random() - 0.5) * 3, (Math.random() - 0.5) * 3,
          0.35 + Math.random() * 0.45, 2 + Math.random() * 2.5, Math.random() < 0.5 ? 0 : 2, 0.8, 3);
      }
    }
    lastNx = mouse.x; lastNy = mouse.y;

    // cập nhật pool FX
    if (fxAlive > 0) {
      let alive = 0;
      for (let j = 0; j < FXN; j++) {
        if (flife[j] <= 0) continue;
        flife[j] -= dt;
        if (flife[j] <= 0) { F.glow[j] = 0; continue; }
        const j3 = j * 3, dg = Math.exp(-dt * fdrag[j]);
        fvel[j3] *= dg; fvel[j3 + 1] *= dg; fvel[j3 + 2] *= dg;
        F.pos[j3] += fvel[j3] * dt; F.pos[j3 + 1] += fvel[j3 + 1] * dt; F.pos[j3 + 2] += fvel[j3 + 2] * dt;
        F.glow[j] = Math.pow(flife[j] / fmax[j], 1.3) * fint[j];
        alive++;
      }
      fxAlive = alive;
      F.pa.needsUpdate = true; F.ga.needsUpdate = true; F.sa.needsUpdate = true;
      if (fxColDirty) { F.ca.needsUpdate = true; fxColDirty = false; }
    }

    // vòng shockwave
    for (let k = 0; k < WAVES; k++) {
      const w = waves[k]; if (!w.on) continue;
      w.age += dt;
      const rad = w.age * 1.9 * tanH * CAMZ;
      w.mesh.position.set(camx + w.x * asp * tanH * CAMZ, camy + w.y * tanH * CAMZ, 0);
      w.mesh.scale.setScalar(Math.max(rad, 0.01));
      w.mat.uniforms.uA.value = Math.max(0, 0.55 * Math.exp(-w.age * 1.7));
      if (w.age > 2.2) { w.on = false; w.mesh.visible = false; wavesOn--; }
    }
  }

  function renderStars() {
    S.m.uniforms.uTime.value = time; F.m.uniforms.uTime.value = time;
    rP.render(scene, camera);
  }
  function renderOnce() {
    nebMat.uniforms.uTime.value = 14;
    rN.render(sceneN, camN);
    time = 14;
    for (let i = 0; i < N; i++) { S.pos[i * 3] = hx[i]; S.pos[i * 3 + 1] = hy[i]; S.pos[i * 3 + 2] = nz[i]; }
    S.pa.needsUpdate = true;
    renderStars();
  }

  function frame(now) {
    raf = requestAnimationFrame(frame);
    let dt = (now - last) / 1000; last = now;
    if (dt <= 0) return;
    if (dt > 0.05) dt = 0.05;
    ema += (dt * 1000 - ema) * 0.05;
    // tự giảm chất lượng khi máy yếu (tối đa 2 lần)
    if (ema > 28) slow++; else slow = 0;
    if (slow > 120 && degrade < 2) {
      degrade++; slow = 0; drawN = Math.max(600, (drawN * 0.6) | 0); S.g.setDrawRange(0, drawN);
      nebScale *= 0.75; rN.setPixelRatio(nebScale); rN.setSize(W, H, false);
    }
    step(dt);
    nebMat.uniforms.uTime.value = time;
    if (frameNo++ % Q.nebEvery === 0) rN.render(sceneN, camN);
    renderStars();
  }
  function start() { if (!raf) { last = performance.now(); raf = requestAnimationFrame(frame); } }
  function stop() { cancelAnimationFrame(raf); raf = 0; }
  document.addEventListener('visibilitychange', () => { document.hidden ? stop() : start(); });

  resize();
  if (reduceMotion) { renderOnce(); }          // chuyển động bị tắt: chỉ vẽ một khung tĩnh
  else { addEvents(); start(); }
}
