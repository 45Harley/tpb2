// ========================================
// MORE PERFECT — Shared Engine
// ========================================

const canvas = document.getElementById('c');
const ctx = canvas.getContext('2d');
const narrationEl = document.getElementById('narration');
const hintEl = document.getElementById('hint');
const audioBtn = document.getElementById('audioBtn');
const W = 900, H = 480;

const GOLD = '#d4af37';
const BLUE = '#62a4d0';
const GREEN = '#5cb85c';
const PURPLE = '#9b59b6';
const CORAL = '#e07050';
const TEAL = '#45b5aa';
const BG = '#0d0d1a';

// ========================================
// ERA NAVIGATION
// ========================================
const ALL_ERAS = [
  { file: '1770-1790.html', label: 'The Founding', years: '1770–1790' },
  { file: '1790-1861.html', label: 'Seeds Grow', years: '1790–1861' },
  { file: '1861-1870.html', label: 'Civil War', years: '1861–1870' },
  { file: '1870-1900.html', label: 'Gilded Age', years: '1870–1900' },
  { file: '1900-1920.html', label: 'Empire & Suffrage', years: '1900–1920' },
  { file: '1920-1935.html', label: 'Prohibition & Depression', years: '1920–1935' },
  { file: '1935-1945.html', label: 'New Deal & WWII', years: '1935–1945' },
  { file: '1945-1960.html', label: 'Cold War', years: '1945–1960' },
  { file: '1960-1975.html', label: 'Civil Rights & Vietnam', years: '1960–1975' },
  { file: '1975-2000.html', label: 'Watergate & Deregulation', years: '1975–2000' },
  { file: '2000-2010.html', label: '9/11 & Surveillance', years: '2000–2010' },
  { file: '2010-2025.html', label: 'Polarization', years: '2010–2025' },
  { file: '2025-future.html', label: 'The Future', years: '2025–' },
];

function getCurrentEraFile() {
  const path = window.location.pathname;
  const file = path.split('/').pop();
  return file;
}

function navigateToEra(file) {
  window.location.href = file;
}

function makePanelZoomable(panel) {
  let scale = 1;
  panel.style.transformOrigin = 'top left';
  panel.addEventListener('wheel', (e) => {
    e.preventDefault();
    e.stopPropagation();
    scale += e.deltaY < 0 ? 0.15 : -0.15;
    scale = Math.max(0.8, Math.min(3.0, scale));
    panel.style.transform = `scale(${scale})`;
  }, { passive: false });
}

function buildEraSidebar() {
  const leftEl = document.getElementById('timeline-left');
  const rightEl = document.getElementById('timeline-right');
  if (!leftEl || !rightEl) return;
  leftEl.innerHTML = ''; rightEl.innerHTML = '';

  const currentFile = getCurrentEraFile();
  const half = Math.ceil(ALL_ERAS.length / 2);

  ALL_ERAS.forEach((era, i) => {
    const panel = i < half ? leftEl : rightEl;
    const node = document.createElement('div');
    node.className = 'tl-item';
    node.textContent = era.years;
    node.title = era.label;
    node.style.color = era.file === currentFile ? '#fff' : '#999';
    if (era.file === currentFile) {
      node.classList.add('active');
      node.style.background = GOLD + '44';
      node.style.borderColor = GOLD;
    }
    node.addEventListener('click', () => navigateToEra(era.file));
    panel.appendChild(node);
  });

  makePanelZoomable(leftEl);
  makePanelZoomable(rightEl);

  // Block wheel zoom on center panel so browser doesn't zoom page
  const centerEl = document.getElementById('center-panel');
  if (centerEl) centerEl.addEventListener('wheel', (e) => {
    e.preventDefault();
    e.stopPropagation();
  }, { passive: false });
}

// ========================================
// PHASE TIMELINE (within current era)
// ========================================
let timelineBuilt = false;
let lastTimelinePhase = '';

function buildTimeline(phases) {
  const leftEl = document.getElementById('timeline-left');
  const rightEl = document.getElementById('timeline-right');
  if (!leftEl || !rightEl) return;
  leftEl.innerHTML = ''; rightEl.innerHTML = '';

  // Era nav at top of left panel
  const eraHeader = document.createElement('div');
  eraHeader.className = 'tl-act-label';
  eraHeader.textContent = 'ERAS';
  eraHeader.style.color = GOLD;
  leftEl.appendChild(eraHeader);

  const currentFile = getCurrentEraFile();
  ALL_ERAS.forEach(era => {
    const node = document.createElement('div');
    node.className = 'tl-item';
    node.textContent = era.years;
    node.title = era.label;
    if (era.file === currentFile) {
      node.classList.add('active');
      node.style.color = '#fff';
      node.style.background = GOLD + '33';
      node.style.borderColor = GOLD;
    } else {
      node.style.color = '#666';
    }
    node.addEventListener('click', () => navigateToEra(era.file));
    leftEl.appendChild(node);
  });

  // Phase nav on right panel
  if (phases && phases.length > 0) {
    const phaseHeader = document.createElement('div');
    phaseHeader.className = 'tl-act-label';
    phaseHeader.textContent = 'PHASES';
    phaseHeader.style.color = '#aaa';
    rightEl.appendChild(phaseHeader);

    phases.forEach(p => {
      const node = document.createElement('div');
      node.id = 'tl-' + p.id;
      node.className = 'tl-item';
      node.textContent = p.label;
      node.style.color = '#999';
      if (typeof jumpToPhase === 'function') {
        node.addEventListener('click', () => jumpToPhase(p.id));
      }
      rightEl.appendChild(node);
    });
  }

  timelineBuilt = true;
}

function updateTimeline(currentPhase) {
  if (currentPhase === lastTimelinePhase) return;
  lastTimelinePhase = currentPhase;
  const nodes = document.querySelectorAll('#timeline-right .tl-item');
  nodes.forEach(node => {
    const isActive = node.id === 'tl-' + currentPhase;
    node.classList.toggle('active', isActive);
    node.style.color = isActive ? '#fff' : '#999';
    node.style.background = isActive ? '#ffffff22' : 'transparent';
    node.style.borderColor = isActive ? '#fff' : 'transparent';
  });
}

// ========================================
// AUDIO
// ========================================
let audioEnabled = false;

function toggleAudio() {
  audioEnabled = !audioEnabled;
  audioBtn.textContent = audioEnabled ? 'Audio On' : 'Audio Off';
  audioBtn.classList.toggle('active', audioEnabled);
  if (!audioEnabled) speechSynthesis.cancel();
}

let claudiaVoice = null;
function pickVoice() {
  const v = speechSynthesis.getVoices();
  claudiaVoice = v.find(x => /female/i.test(x.name) && /en.US/i.test(x.lang))
    || v.find(x => /zira|eva|samantha|karen|susan/i.test(x.name))
    || v.find(x => x.lang && x.lang.startsWith('en'));
}
speechSynthesis.onvoiceschanged = pickVoice;
pickVoice();

let ttsPlaying = false;
function speak(text) {
  if (!audioEnabled) { ttsPlaying = false; return; }
  speechSynthesis.cancel();
  const u = new SpeechSynthesisUtterance(text);
  u.rate = 0.92; u.pitch = 1.05;
  if (claudiaVoice) u.voice = claudiaVoice;
  ttsPlaying = true;
  u.onend = () => { ttsPlaying = false; };
  u.onerror = () => { ttsPlaying = false; };
  speechSynthesis.speak(u);
}

function narrate(text) {
  narrationEl.textContent = text;
  speak(text);
}

// ========================================
// DWELL TIME TRACKING
// ========================================
let dwellLog = [];
let phaseStartTime = Date.now();

function logDwell(fromPhase) {
  const now = Date.now();
  dwellLog.push({ phase: fromPhase, dwellMs: now - phaseStartTime });
  phaseStartTime = now;
}

function reportDwell() {
  const total = dwellLog.reduce((s, d) => s + d.dwellMs, 0);
  console.log('=== Dwell Time Report ===');
  dwellLog.forEach(d => console.log('  ' + d.phase + ': ' + (d.dwellMs/1000).toFixed(1) + 's'));
  console.log('  TOTAL: ' + (total/1000).toFixed(1) + 's');
  console.log('=========================');
}

// ========================================
// STATE
// ========================================
let phase = 'opening';
let phaseT = 0;
let lastTime = 0;
let threatSpawnT = 0;
let fightSpawnT = 0;
let waitingForClick = false;

function makeBall(x, y, r, label, color) {
  return { x, y, r, label, color, alpha: 1, targetX: x, targetY: y };
}

let peopleBall = makeBall(W / 2, H / 2, 90, 'The People', GOLD);
let townBalls = [];
let stateBalls = [];

let spheres = [];
let dragging = null;
let dragOffX = 0, dragOffY = 0;
let dragStartX = 0, dragStartY = 0;
let wasDrag = false;

const JITTER_DUR = 0.8;
const SPLIT_DUR = 0.7;

// ========================================
// DRAWING UTILITIES
// ========================================
function drawSphere(x, y, r, color, label, alpha, showPlus) {
  if (alpha < 0.01 || r < 1) return;
  ctx.save();
  ctx.globalAlpha = alpha;

  const glow = ctx.createRadialGradient(x, y, r * 0.2, x, y, r * 1.4);
  glow.addColorStop(0, color + '33');
  glow.addColorStop(1, 'transparent');
  ctx.fillStyle = glow;
  ctx.beginPath();
  ctx.arc(x, y, r * 1.4, 0, Math.PI * 2);
  ctx.fill();

  const grad = ctx.createRadialGradient(x - r * 0.3, y - r * 0.3, r * 0.1, x, y, r);
  grad.addColorStop(0, lighten(color, 60));
  grad.addColorStop(0.5, color);
  grad.addColorStop(1, darken(color, 40));
  ctx.fillStyle = grad;
  ctx.beginPath();
  ctx.arc(x, y, r, 0, Math.PI * 2);
  ctx.fill();
  ctx.strokeStyle = color;
  ctx.lineWidth = Math.max(1, r * 0.025);
  ctx.stroke();

  const hlGrad = ctx.createRadialGradient(x - r * 0.25, y - r * 0.35, r * 0.05, x - r * 0.25, y - r * 0.35, r * 0.4);
  hlGrad.addColorStop(0, 'rgba(255,255,255,0.3)');
  hlGrad.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = hlGrad;
  ctx.beginPath();
  ctx.arc(x - r * 0.25, y - r * 0.35, r * 0.4, 0, Math.PI * 2);
  ctx.fill();

  if (label && r > 8) {
    const words = label.split(' ');
    let lines;
    if (words.length > 1 && ctx.measureText && label.length > 10 && r < 100) {
      const mid = Math.ceil(words.length / 2);
      lines = [words.slice(0, mid).join(' '), words.slice(mid).join(' ')];
    } else {
      lines = [label];
    }
    const fontSize = Math.max(8, Math.min(r * (lines.length > 1 ? 0.24 : 0.28), 18));
    ctx.font = '700 ' + fontSize + 'px Segoe UI, system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = '#1a1030';
    ctx.shadowColor = 'rgba(255,255,255,0.25)';
    ctx.shadowBlur = 2;
    const lineH = fontSize * 1.2;
    const startY = y - (lines.length - 1) * lineH / 2;
    lines.forEach((line, li) => {
      ctx.fillText(line, x, startY + li * lineH);
    });
    ctx.shadowBlur = 0;

    if (showPlus) {
      ctx.font = '700 ' + (fontSize * 0.65) + 'px Segoe UI, system-ui, sans-serif';
      ctx.globalAlpha = alpha * 0.45;
      ctx.fillText('+', x, y + r * 0.45);
    }
  }

  ctx.restore();
}

function lighten(hex, amt) {
  let r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
  return '#' + [Math.min(255, Math.round(r + amt)), Math.min(255, Math.round(g + amt)), Math.min(255, Math.round(b + amt))]
    .map(c => c.toString(16).padStart(2, '0')).join('');
}
function darken(hex, amt) {
  let r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
  return '#' + [Math.max(0, Math.round(r - amt)), Math.max(0, Math.round(g - amt)), Math.max(0, Math.round(b - amt))]
    .map(c => c.toString(16).padStart(2, '0')).join('');
}
function easeOutBack(t) {
  const c1 = 1.70158, c3 = c1 + 1;
  return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
}
function easeInOut(t) { return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; }
function dist(x1, y1, x2, y2) { return Math.sqrt((x2 - x1) ** 2 + (y2 - y1) ** 2); }
function lerp(a, b, t) { return a + (b - a) * t; }

function drawLeaf(lx, ly, size, angle, color, alpha) {
  ctx.save();
  ctx.globalAlpha = alpha;
  ctx.translate(lx, ly);
  ctx.rotate(angle);
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.moveTo(0, 0);
  ctx.bezierCurveTo(size*0.5, -size*0.8, size, -size*0.3, size*0.9, 0);
  ctx.bezierCurveTo(size, size*0.3, size*0.5, size*0.8, 0, 0);
  ctx.fill();
  ctx.strokeStyle = darken(color, 30);
  ctx.lineWidth = 0.5;
  ctx.beginPath();
  ctx.moveTo(0, 0);
  ctx.lineTo(size * 0.8, 0);
  ctx.stroke();
  ctx.restore();
}

function drawGrid() {
  ctx.save();
  ctx.globalAlpha = 0.03;
  ctx.strokeStyle = '#fff';
  ctx.lineWidth = 1;
  for (let gx = 0; gx < W; gx += 40) { ctx.beginPath(); ctx.moveTo(gx, 0); ctx.lineTo(gx, H); ctx.stroke(); }
  for (let gy = 0; gy < H; gy += 40) { ctx.beginPath(); ctx.moveTo(0, gy); ctx.lineTo(W, gy); ctx.stroke(); }
  ctx.restore();
}

function drawTitle(text, alpha) {
  ctx.save();
  ctx.globalAlpha = Math.max(alpha, 0.8);
  ctx.font = '600 16px Segoe UI, system-ui, sans-serif';
  ctx.fillStyle = '#ffffff';
  ctx.textAlign = 'center';
  ctx.fillText(text, W / 2, 25);
  ctx.restore();
}

// ========================================
// PARTICLES
// ========================================
let particles = [];
function spawnParticles(x, y, color, count) {
  for (let i = 0; i < count; i++) {
    const angle = Math.random() * Math.PI * 2;
    const speed = 40 + Math.random() * 120;
    particles.push({ x, y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed, life: 0.6 + Math.random() * 0.4, age: 0, color, r: 2 + Math.random() * 2 });
  }
}
function updateParticles(dt) {
  particles.forEach(p => { p.x += p.vx * dt; p.y += p.vy * dt; p.age += dt; p.vx *= 0.97; p.vy *= 0.97; });
  particles = particles.filter(p => p.age < p.life);
}
function drawParticles() {
  particles.forEach(p => {
    ctx.save();
    ctx.globalAlpha = Math.max(0, 1 - p.age / p.life) * 0.7;
    ctx.fillStyle = p.color;
    ctx.beginPath();
    ctx.arc(p.x, p.y, p.r * (1 - p.age / p.life), 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  });
}

// ========================================
// FLOATING WORDS
// ========================================
let floatingWords = [];

function makeWord(label, positive, color) {
  const edge = Math.floor(Math.random() * 4);
  let x, y, vx, vy;
  if (edge === 0) { x = Math.random() * W; y = -20; vx = (Math.random()-0.5)*10; vy = 5+Math.random()*7; }
  else if (edge === 1) { x = W+20; y = Math.random()*H; vx = -(5+Math.random()*7); vy = (Math.random()-0.5)*10; }
  else if (edge === 2) { x = Math.random()*W; y = H+20; vx = (Math.random()-0.5)*10; vy = -(5+Math.random()*7); }
  else { x = -20; y = Math.random()*H; vx = 5+Math.random()*7; vy = (Math.random()-0.5)*10; }
  return {
    label, positive, color, x, y, vx, vy,
    alpha: 0, targetAlpha: 1,
    fontSize: positive ? 16 + Math.random()*8 : 15 + Math.random()*7,
    phase: 'entering',
    life: 0,
    pulseOffset: Math.random() * Math.PI * 2,
  };
}

function updateWords(dt) {
  floatingWords.forEach(w => {
    w.life += dt;
    if (w.positive && (w.phase === 'drifting' || w.phase === 'winning')) {
      w.vx += (W/2 - w.x) * 0.08 * dt;
      w.vy += (H/2 - w.y) * 0.08 * dt;
    }
    if (w.attacking && (w.phase === 'drifting' || w.phase === 'entering')) {
      const atkForce = w.positive ? 0.06 : 0.12;
      w.vx += (W/2 - w.x) * atkForce * dt;
      w.vy += (H/2 - w.y) * atkForce * dt;
      const d = Math.sqrt((w.x - W/2)**2 + (w.y - H/2)**2);
      if (d < 110 && w.phase !== 'melting') {
        w.phase = 'melting';
        w.meltT = 0;
        spawnParticles(w.x, w.y, w.color, 6);
      }
    }
    if (!w.orbiting) floatingWords.forEach(other => {
      if (other === w) return;
      const dx = w.x - other.x, dy = w.y - other.y;
      const distW = Math.sqrt(dx*dx + dy*dy) || 1;
      if (distW < 80) {
        const force = (80 - distW) * 0.02;
        w.vx += (dx / distW) * force;
        w.vy += (dy / distW) * force;
      }
    });
    w.vx *= 0.99; w.vy *= 0.99;
    w.x += w.vx * dt; w.y += w.vy * dt;

    if (w.phase === 'entering') {
      w.alpha = Math.min(1, w.life * 2);
      if (w.life > 0.5) w.phase = 'drifting';
    } else if (w.phase === 'drifting') {
      w.alpha = w.targetAlpha;
    } else if (w.phase === 'winning') {
      w.alpha = 0.8 + Math.sin(w.life * 4 + w.pulseOffset) * 0.2;
      w.fontSize = Math.min(w.fontSize + dt * 2, 28);
    } else if (w.phase === 'shrinking') {
      w.alpha = Math.max(0, w.alpha - dt * 1.5);
    } else if (w.phase === 'melting') {
      w.meltT += dt;
      w.alpha = Math.max(0, 1 - w.meltT * 2);
      w.fontSize *= (1 - dt * 0.5);
      w.vx *= 0.9; w.vy *= 0.9;
    }
  });
  floatingWords = floatingWords.filter(w => w.phase !== 'melting' || w.alpha > 0.02);
}

function drawWords() {
  floatingWords.forEach(w => {
    if (w.alpha < 0.02) return;
    ctx.save();
    ctx.globalAlpha = w.alpha;
    ctx.font = (w.positive ? '700 ' : '400 ') + w.fontSize + 'px Segoe UI, system-ui, sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.shadowColor = w.color;
    ctx.shadowBlur = w.positive ? 12 : 14;
    ctx.fillStyle = w.color;
    ctx.fillText(w.label, w.x, w.y);
    ctx.shadowBlur = 0;
    ctx.restore();
  });
}

// ========================================
// SEEDS
// ========================================
let seeds = [];

const SEED_DEFS = [
  { label: 'Tyranny', color: '#ff6666', seedX: 60, seedY: H-20, sproutPhase: null, childSeed: null },
  { label: 'Slavery', color: '#ff5555', seedX: 150, seedY: H-18, sproutPhase: 'swirl-civil-war',
    childSeed: { label: 'Inequality', color: '#ff7777', seedX: 180, seedY: H-22, sproutPhase: 'swirl-civil-rights', childSeed: null }},
  { label: 'Property over People', color: '#ffaa55', seedX: 300, seedY: H-20, sproutPhase: 'swirl-robber-barons', childSeed: null },
  { label: 'Men Only', color: '#ffbb77', seedX: 450, seedY: H-18, sproutPhase: 'swirl-suffrage', childSeed: null },
  { label: "King's Rule", color: '#ff8888', seedX: 600, seedY: H-20, sproutPhase: null, childSeed: null },
  { label: 'Landed Gentry', color: '#ffcc88', seedX: 750, seedY: H-18, sproutPhase: null, childSeed: null },
];

function makeSeed(def) {
  return {
    label: def.label, color: def.color,
    x: def.seedX, y: def.seedY,
    r: 4, alpha: 0, targetAlpha: 0.3,
    state: 'dormant',
    sproutPhase: def.sproutPhase,
    childSeed: def.childSeed,
    sproutT: 0,
  };
}

function dropSeed(wordObj) {
  const def = SEED_DEFS.find(d => d.label === wordObj.label);
  if (!def) return;
  const seed = makeSeed(def);
  seed.x = wordObj.x; seed.y = wordObj.y;
  seed.state = 'falling';
  seeds.push(seed);
}

function updateSeeds(dt) {
  seeds.forEach(s => {
    if (s.state === 'falling') {
      s.y += 60 * dt;
      s.alpha = Math.min(s.targetAlpha, s.alpha + dt * 0.5);
      const def = SEED_DEFS.find(d => d.label === s.label);
      if (def && s.y >= def.seedY) {
        s.y = def.seedY; s.x = def.seedX;
        s.state = 'dormant';
      }
    } else if (s.state === 'dormant') {
      s.alpha = s.targetAlpha + Math.sin(Date.now()/1000 + s.x) * 0.08;
    } else if (s.state === 'sprouting') {
      s.sproutT += dt;
      s.r = 4 + s.sproutT * 25;
      s.alpha = Math.min(0.7, 0.3 + s.sproutT * 0.3);
      const dx = W/2 - s.x, dy = H/2 - s.y;
      const d = Math.sqrt(dx*dx + dy*dy) || 1;
      s.x += (dx / d) * 35 * dt;
      s.y += (dy / d) * 35 * dt;
      if (s.sproutT > 2.5) s.state = 'popped';
    }
  });
}

function drawSeeds() {
  seeds.forEach(s => {
    if (s.alpha < 0.02 || s.state === 'popped') return;
    ctx.save();
    ctx.globalAlpha = s.alpha;
    const glow = ctx.createRadialGradient(s.x, s.y, 0, s.x, s.y, s.r * 2.5);
    glow.addColorStop(0, s.color + '88');
    glow.addColorStop(1, 'transparent');
    ctx.fillStyle = glow;
    ctx.beginPath(); ctx.arc(s.x, s.y, s.r * 2.5, 0, Math.PI*2); ctx.fill();
    ctx.fillStyle = s.color;
    ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, Math.PI*2); ctx.fill();
    if (s.state === 'sprouting') {
      const labelSize = Math.min(22, 8 + s.sproutT * 8);
      const labelAlpha = Math.min(1, s.sproutT * 0.6);
      ctx.save();
      ctx.globalAlpha = labelAlpha;
      ctx.font = '700 ' + labelSize + 'px Segoe UI, system-ui, sans-serif';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = s.color;
      ctx.shadowColor = s.color; ctx.shadowBlur = 15;
      ctx.fillText(s.label, s.x, s.y);
      ctx.restore();
    }
    ctx.restore();
  });
}

function sproutSeedsForPhase(phaseName) {
  seeds.forEach(s => {
    if (s.state === 'dormant' && s.sproutPhase === phaseName) {
      s.state = 'sprouting'; s.sproutT = 0;
    }
  });
}

function popSeed(label, dropChild) {
  const s = seeds.find(sd => sd.label === label);
  if (!s) return;
  s.state = 'popped';
  spawnParticles(s.x, s.y, s.color, 8);
  if (dropChild && s.childSeed) {
    const child = makeSeed(s.childSeed);
    child.x = s.x; child.y = s.y; child.state = 'falling';
    seeds.push(child);
  }
}

// ========================================
// NEBULAE
// ========================================
let nebulae = [];

function makeNebula(x, y, r, color, label) {
  return {
    x, y, r, color, label, alpha: 0,
    targetX: x, targetY: y, targetR: r,
    lobes: Array.from({length: 5}, () => ({
      angle: Math.random() * Math.PI * 2,
      dist: 0.3 + Math.random() * 0.5,
      size: 0.4 + Math.random() * 0.4,
      speed: 0.3 + Math.random() * 0.4,
    })),
    phase: 'forming',
    life: 0,
  };
}

function updateNebulae(dt) {
  nebulae.forEach(n => {
    n.life += dt;
    if (n.phase === 'forming') {
      n.alpha = Math.min(0.6, n.life * 0.3);
      n.r = n.targetR * Math.min(1, n.life * 0.5);
      if (n.alpha >= 0.6) n.phase = 'stable';
    } else if (n.phase === 'condensing') {
      n.x = lerp(n.x, n.targetX, dt * 1.5);
      n.y = lerp(n.y, n.targetY, dt * 1.5);
      n.r = Math.max(10, n.r - dt * 30);
      n.lobes.forEach(l => { l.dist = Math.max(0.05, l.dist - dt * 0.3); l.size = Math.max(0.1, l.size - dt * 0.2); });
    }
  });
}

function drawNebulae(now) {
  nebulae.forEach(n => {
    if (n.alpha < 0.02) return;
    ctx.save();
    ctx.globalAlpha = n.alpha;
    const coreGrad = ctx.createRadialGradient(n.x, n.y, 0, n.x, n.y, n.r);
    coreGrad.addColorStop(0, n.color + '44');
    coreGrad.addColorStop(0.6, n.color + '22');
    coreGrad.addColorStop(1, 'transparent');
    ctx.fillStyle = coreGrad;
    ctx.beginPath(); ctx.arc(n.x, n.y, n.r, 0, Math.PI*2); ctx.fill();
    n.lobes.forEach(l => {
      const lx = n.x + Math.cos(l.angle + now/1000 * l.speed) * n.r * l.dist;
      const ly = n.y + Math.sin(l.angle + now/1000 * l.speed) * n.r * l.dist;
      const lr = n.r * l.size;
      const lobeGrad = ctx.createRadialGradient(lx, ly, 0, lx, ly, lr);
      lobeGrad.addColorStop(0, n.color + '33');
      lobeGrad.addColorStop(1, 'transparent');
      ctx.fillStyle = lobeGrad;
      ctx.beginPath(); ctx.arc(lx, ly, lr, 0, Math.PI*2); ctx.fill();
    });
    if (n.label && n.r > 20) {
      ctx.globalAlpha = n.alpha * 0.8;
      ctx.font = '400 14px Segoe UI, system-ui, sans-serif';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = '#ddd';
      ctx.shadowColor = n.color; ctx.shadowBlur = 6;
      ctx.fillText(n.label, n.x, n.y);
      ctx.shadowBlur = 0;
    }
    ctx.restore();
  });
}

// ========================================
// VINE HELPERS (shared across eras)
// ========================================
function drawVinesGrowing(vines, phaseT, now) {
  vines.forEach((vine, vi) => {
    if (phaseT < vi * 1.2) return;
    const vProgress = Math.min(1, (phaseT - vi * 1.2) / 5);
    const segments = 20;
    const reached = Math.floor(vProgress * segments);

    const vinePath = [];
    for (let s = 0; s <= segments; s++) {
      const t = s / segments;
      const cx1 = vine.startX + (W/2 - vine.startX) * 0.3;
      const cy1 = vine.startY - 100 - vi * 40;
      const px = vine.startX * (1-t)*(1-t) + cx1 * 2*t*(1-t) + W/2 * t*t;
      const py = vine.startY * (1-t)*(1-t) + cy1 * 2*t*(1-t) + H/2 * t*t;
      const wave = Math.sin(t * 8 + vi * 2 + now/800) * (12 - t * 10);
      vinePath.push({ x: px + wave, y: py });
    }

    ctx.save();
    ctx.strokeStyle = vine.color;
    ctx.lineWidth = 2.5 + vProgress * 2;
    ctx.shadowColor = vine.color; ctx.shadowBlur = 8;
    ctx.globalAlpha = 0.7;
    ctx.beginPath();
    for (let s = 0; s <= reached; s++) {
      if (s === 0) ctx.moveTo(vinePath[s].x, vinePath[s].y);
      else ctx.lineTo(vinePath[s].x, vinePath[s].y);
    }
    ctx.stroke();

    for (let s = 3; s <= reached; s += 4) {
      const pt = vinePath[s], prevPt = vinePath[s - 1];
      const stemAngle = Math.atan2(pt.y - prevPt.y, pt.x - prevPt.x);
      const leafSide = (s % 8 < 4) ? 1 : -1;
      const leafSize = 8 + Math.sin(s + vi) * 3;
      drawLeaf(pt.x, pt.y, leafSize, stemAngle + leafSide * 1.2, darken(vine.color, 20), 0.6);
    }
    ctx.restore();

    // Vine label — starts huge, shrinks
    if (vProgress > 0.1) {
      const shrink = Math.min(1, Math.max(0, (phaseT - 4) / 4));
      const labelSize = 36 - shrink * 22;
      const labelAlpha = Math.min(1, vProgress) * (1 - shrink * 0.7);
      ctx.save();
      ctx.globalAlpha = labelAlpha;
      ctx.font = '700 ' + labelSize + 'px Segoe UI, system-ui, sans-serif';
      ctx.fillStyle = vine.color;
      ctx.shadowColor = vine.color; ctx.shadowBlur = 20;
      ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
      ctx.fillText(vine.label, vine.startX, vine.startY - 20);
      ctx.restore();
    }

    // Entangling tendrils
    if (vProgress > 0.8) {
      ctx.save();
      const wrapT = (vProgress - 0.8) / 0.2;
      ctx.globalAlpha = wrapT * 0.5;
      ctx.strokeStyle = vine.color; ctx.lineWidth = 1.5;
      for (let t = 0; t < 3; t++) {
        const wAngle = (vi * 2.1 + t * 1.5) + now/1200;
        ctx.beginPath();
        ctx.arc(W/2, H/2, 100, wAngle, wAngle + wrapT * 1.2);
        ctx.stroke();
      }
      ctx.restore();
    }
  });
}

function drawVinesFading(vines, progress, now) {
  const vineAlpha = Math.max(0, 1 - progress * 2.5);
  vines.forEach((vine, vi) => {
    ctx.save();
    ctx.strokeStyle = vine.color;
    ctx.lineWidth = Math.max(0.5, 3 - progress * 5);
    ctx.globalAlpha = vineAlpha * 0.6;
    ctx.shadowColor = vine.color; ctx.shadowBlur = 6;
    const segments = 20;
    const vinePath = [];
    ctx.beginPath();
    for (let s = 0; s <= segments; s++) {
      const t = s / segments;
      const cx1 = vine.startX + (W/2 - vine.startX) * 0.3;
      const cy1 = vine.startY - 100 - vi * 40;
      const px = vine.startX * (1-t)*(1-t) + cx1 * 2*t*(1-t) + W/2 * t*t;
      const py = vine.startY * (1-t)*(1-t) + cy1 * 2*t*(1-t) + H/2 * t*t;
      const wave = Math.sin(t * 8 + vi * 2 + now/800) * (12 - t * 10);
      vinePath.push({ x: px + wave, y: py });
      if (s === 0) ctx.moveTo(px + wave, py);
      else ctx.lineTo(px + wave, py);
    }
    ctx.stroke();
    for (let s = 3; s <= segments; s += 4) {
      const pt = vinePath[s], prevPt = vinePath[s-1];
      const stemAngle = Math.atan2(pt.y - prevPt.y, pt.x - prevPt.x);
      const leafSide = (s % 8 < 4) ? 1 : -1;
      drawLeaf(pt.x, pt.y, 8 + Math.sin(s+vi)*3, stemAngle + leafSide*1.2, darken(vine.color, 20), vineAlpha * 0.5);
    }
    ctx.restore();
  });
}

function spawnOrbitingWords(burnWords, colorOverride) {
  const clr = colorOverride || '#ffe088';
  burnWords.forEach((label, i) => {
    const angle = (i / burnWords.length) * Math.PI * 2 - Math.PI/2;
    const w = makeWord(label, false, clr);
    w.x = W/2 + Math.cos(angle) * 130;
    w.y = H/2 + Math.sin(angle) * 100;
    w.vx = (-Math.sin(angle)) * 15;
    w.vy = (Math.cos(angle)) * 15;
    w.fontSize = 16; w.baseFontSize = 16;
    w.orbiting = true; w.orbitReady = true;
    w.phase = 'winning';
    floatingWords.push(w);
  });
}

function updateOrbitingWords(targetR) {
  const tr = targetR || 120;
  floatingWords.forEach(w => {
    if (w.orbiting) {
      const dx = w.x - W/2, dy = w.y - H/2;
      const d = Math.sqrt(dx*dx + dy*dy) || 1;
      const radialForce = (tr - d) * 0.05;
      w.vx += (dx / d) * radialForce;
      w.vy += (dy / d) * radialForce;
      w.vx += (-dy / d) * 1.2;
      w.vy += (dx / d) * 1.2;
      w.vx *= 0.98; w.vy *= 0.98;
    }
  });
}

// ========================================
// RENDER FRAME HELPERS
// ========================================
function beginFrame(now) {
  const dt = lastTime ? Math.min((now - lastTime) / 1000, 0.05) : 0;
  lastTime = now;
  ctx.fillStyle = BG;
  ctx.fillRect(0, 0, W, H);
  drawGrid();
  updateParticles(dt);
  updateSeeds(dt);
  updateNebulae(dt);
  return dt;
}

function endFrame(now) {
  drawSeeds();
  drawNebulae(now);
  drawParticles();
  requestAnimationFrame(render);
}

// ========================================
// SHARED HTML TEMPLATE
// ========================================
function getSharedCSS() {
  return `
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    background: #0d0d1a;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    min-height: 100vh; overflow: hidden;
    font-family: 'Segoe UI', system-ui, sans-serif;
  }
  .stage { display: flex; align-items: flex-start; gap: 12px; margin-top: 8px; }
  .side-panel { width: 140px; display: flex; flex-direction: column; gap: 3px; padding-top: 4px; }
  .side-panel .tl-item {
    padding: 3px 6px; border-radius: 3px;
    font: 11px/1.3 'Segoe UI', system-ui, sans-serif;
    color: #ccc; border: 1px solid transparent;
    white-space: nowrap; transition: all 0.3s; cursor: pointer;
  }
  .side-panel .tl-item:hover { background: #2a2a4e; color: #fff; }
  .side-panel .tl-item.active { border-color: #fff; color: #fff; font-weight: 700; }
  .side-panel .tl-act-label {
    font: 700 12px/1.3 'Segoe UI', system-ui, sans-serif;
    color: #888; padding: 6px 6px 2px; text-transform: uppercase; letter-spacing: 1px;
  }
  canvas { cursor: pointer; border-radius: 8px; }
  .controls {
    position: fixed; top: 12px; right: 16px;
    display: flex; gap: 8px; z-index: 10;
  }
  .controls button {
    background: #1a1a2e; border: 1px solid #666; color: #ccc;
    padding: 6px 12px; border-radius: 4px; font-size: 12px;
    cursor: pointer; font-family: inherit;
  }
  .controls button.active { border-color: #d4af37; color: #d4af37; }
  .controls button:hover { border-color: #555; color: #ccc; }
  .narration {
    margin-top: 14px; text-align: center; font-size: 1rem;
    color: #b0b0b0; min-height: 28px; max-width: 700px;
  }
  .hint {
    margin-top: 6px; font-size: 0.8rem; color: #d4af37;
    text-align: center; min-height: 18px;
  }`;
}

function getSharedHTML() {
  return `
<div class="controls">
  <button id="audioBtn" onclick="toggleAudio()">Audio Off</button>
</div>
<div class="stage">
  <div class="side-panel" id="timeline-left"></div>
  <div>
    <canvas id="c" width="900" height="480"></canvas>
    <div class="narration" id="narration"></div>
    <div class="hint" id="hint">Click to begin</div>
  </div>
  <div class="side-panel" id="timeline-right"></div>
</div>`;
}
