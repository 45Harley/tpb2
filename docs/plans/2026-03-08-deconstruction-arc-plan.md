# Deconstruction Arc — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Expand `mockups/deconstruction.html` from a founding-only animation into a full 3-act history of American democracy with idea swirls, nebula blobs, seed mechanics, and friction cycles.

**Architecture:** Single-file HTML/Canvas 2D animation. State machine drives phases. New rendering primitives (floating words, nebula blobs, seeds) are added as functions alongside existing sphere renderer. All work happens in `c:\tpb\mockups\deconstruction.html` on the experiment branch.

**Tech Stack:** Vanilla JS, Canvas 2D, SpeechSynthesis API (audio deferred to later pass)

**Verification:** All steps verified by opening `localhost/tpb/mockups/deconstruction.html` in browser, hard-refreshing, and clicking through phases visually.

---

### Task 1: Floating Word System

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html` (add after particle system, ~line 337)

**What:** Build the reusable floating-word renderer used by all swirl phases.

**Step 1: Add word data structure and creation function**

Add after the `drawParticles()` function (~line 337):

```javascript
// ========================================
// FLOATING WORDS
// ========================================
let floatingWords = [];

function makeWord(label, positive, color) {
  const edge = Math.floor(Math.random() * 4); // 0=top,1=right,2=bottom,3=left
  let x, y, vx, vy;
  if (edge === 0) { x = Math.random() * W; y = -20; vx = (Math.random()-0.5)*30; vy = 15+Math.random()*20; }
  else if (edge === 1) { x = W+20; y = Math.random()*H; vx = -(15+Math.random()*20); vy = (Math.random()-0.5)*30; }
  else if (edge === 2) { x = Math.random()*W; y = H+20; vx = (Math.random()-0.5)*30; vy = -(15+Math.random()*20); }
  else { x = -20; y = Math.random()*H; vx = 15+Math.random()*20; vy = (Math.random()-0.5)*30; }
  return {
    label, positive, color, x, y, vx, vy,
    alpha: 0, targetAlpha: 1,
    fontSize: positive ? 16 + Math.random()*8 : 12 + Math.random()*6,
    phase: 'entering', // entering | drifting | winning | shrinking | seeding
    life: 0,
    pulseOffset: Math.random() * Math.PI * 2,
  };
}

function updateWords(dt) {
  floatingWords.forEach(w => {
    w.life += dt;
    // Drift toward center if positive, slow down if negative
    if (w.positive && (w.phase === 'drifting' || w.phase === 'winning')) {
      w.vx += (W/2 - w.x) * 0.3 * dt;
      w.vy += (H/2 - w.y) * 0.3 * dt;
    }
    // Damping
    w.vx *= 0.995; w.vy *= 0.995;
    w.x += w.vx * dt; w.y += w.vy * dt;

    // Phase transitions
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
    }
  });
}

function drawWords() {
  floatingWords.forEach(w => {
    if (w.alpha < 0.02) return;
    ctx.save();
    ctx.globalAlpha = w.alpha;
    ctx.font = (w.positive ? '700 ' : '400 ') + w.fontSize + 'px Segoe UI, system-ui, sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    // Glow
    ctx.shadowColor = w.color;
    ctx.shadowBlur = w.positive ? 12 : 4;
    ctx.fillStyle = w.color;
    ctx.fillText(w.label, w.x, w.y);
    ctx.shadowBlur = 0;
    ctx.restore();
  });
}
```

**Step 2: Verify** — No visual change yet (words not spawned). Refresh browser, confirm existing flow still works.

**Step 3: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): add floating word system"
```

---

### Task 2: Seed System

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html` (add after floating word system)

**What:** Build the seed mechanic — dormant embers at canvas edges that persist and can sprout later.

**Step 1: Add seed data, rendering, and sprouting**

```javascript
// ========================================
// SEEDS — dormant ideas at canvas edges
// ========================================
let seeds = [];

const SEED_DEFS = [
  { label: 'Tyranny', color: '#aa4444', seedX: 60, seedY: H-20, sproutPhase: null, childSeed: null },
  { label: 'Slavery', color: '#aa3333', seedX: 150, seedY: H-18, sproutPhase: 'swirl-civil-war',
    childSeed: { label: 'Inequality', color: '#994444', seedX: 180, seedY: H-22, sproutPhase: 'swirl-civil-rights', childSeed: null }},
  { label: 'Property over People', color: '#996633', seedX: 300, seedY: H-20, sproutPhase: 'swirl-robber-barons', childSeed: null },
  { label: 'Men Only', color: '#997755', seedX: 450, seedY: H-18, sproutPhase: 'swirl-suffrage', childSeed: null },
  { label: "King's Rule", color: '#885555', seedX: 600, seedY: H-20, sproutPhase: null, childSeed: null },
  { label: 'Landed Gentry', color: '#887766', seedX: 750, seedY: H-18, sproutPhase: null, childSeed: null },
];

function makeSeed(def) {
  return {
    label: def.label, color: def.color,
    x: def.seedX, y: def.seedY,
    r: 4, alpha: 0, targetAlpha: 0.3,
    state: 'dormant', // dormant | sprouting | popped
    sproutPhase: def.sproutPhase,
    childSeed: def.childSeed,
    sproutT: 0,
  };
}

function dropSeed(wordObj) {
  const def = SEED_DEFS.find(d => d.label === wordObj.label);
  if (!def) return;
  const seed = makeSeed(def);
  seed.x = wordObj.x; seed.y = wordObj.y; // start at word position
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
      s.y -= 40 * dt;
      if (s.sproutT > 2) s.state = 'popped';
    }
  });
}

function drawSeeds() {
  seeds.forEach(s => {
    if (s.alpha < 0.02 || s.state === 'popped') return;
    ctx.save();
    ctx.globalAlpha = s.alpha;
    // Ember glow
    const glow = ctx.createRadialGradient(s.x, s.y, 0, s.x, s.y, s.r * 2.5);
    glow.addColorStop(0, s.color + '88');
    glow.addColorStop(1, 'transparent');
    ctx.fillStyle = glow;
    ctx.beginPath(); ctx.arc(s.x, s.y, s.r * 2.5, 0, Math.PI*2); ctx.fill();
    // Core
    ctx.fillStyle = s.color;
    ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, Math.PI*2); ctx.fill();
    // Label (only when sprouting)
    if (s.state === 'sprouting' && s.r > 10) {
      ctx.font = '400 10px Segoe UI, system-ui, sans-serif';
      ctx.textAlign = 'center'; ctx.fillStyle = '#ddd';
      ctx.fillText(s.label, s.x, s.y - s.r - 6);
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
```

**Step 2: Add `drawSeeds()` and `updateSeeds(dt)` calls to the render loop**

In the `render()` function, after `updateParticles(dt)` (~line 409), add:

```javascript
  updateSeeds(dt);
```

Before the final `drawParticles()` at the end of render (~line 669), add:

```javascript
  drawSeeds();
```

**Step 3: Verify** — No visual change yet (no seeds created). Confirm existing flow still works.

**Step 4: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): add seed system with dormant/sprout/pop lifecycle"
```

---

### Task 3: Nebula Blob System

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html` (add after seed system)

**What:** Cloud/gas shapes that form from clustering words and condense into spheres.

**Step 1: Add nebula data structure and renderer**

```javascript
// ========================================
// NEBULA BLOBS — fuzzy idea clusters
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
    phase: 'forming', // forming | stable | condensing
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
      // Lobes shrink as it condenses
      n.lobes.forEach(l => { l.dist = Math.max(0.05, l.dist - dt * 0.3); l.size = Math.max(0.1, l.size - dt * 0.2); });
    }
  });
}

function drawNebulae(now) {
  nebulae.forEach(n => {
    if (n.alpha < 0.02) return;
    ctx.save();
    ctx.globalAlpha = n.alpha;

    // Core cloud
    const coreGrad = ctx.createRadialGradient(n.x, n.y, 0, n.x, n.y, n.r);
    coreGrad.addColorStop(0, n.color + '44');
    coreGrad.addColorStop(0.6, n.color + '22');
    coreGrad.addColorStop(1, 'transparent');
    ctx.fillStyle = coreGrad;
    ctx.beginPath(); ctx.arc(n.x, n.y, n.r, 0, Math.PI*2); ctx.fill();

    // Undulating lobes (layered sine-wave approximation of organic edges)
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

    // Label
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
```

**Step 2: Add update/draw calls to render loop** (same pattern as seeds — `updateNebulae(dt)` after updateSeeds, `drawNebulae(now)` before drawSeeds).

**Step 3: Verify** — No visual change yet. Confirm existing flow works.

**Step 4: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): add nebula blob system with undulating lobes"
```

---

### Task 4: Dwell Time Tracking

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html`

**What:** Record timestamp on each click-to-continue, calculate dwell per phase.

**Step 1: Add tracking state and logger**

Add near the top of the STATE section (~line 180):

```javascript
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
  dwellLog.forEach(d => console.log(`  ${d.phase}: ${(d.dwellMs/1000).toFixed(1)}s`));
  console.log(`  TOTAL: ${(total/1000).toFixed(1)}s`);
  console.log('=========================');
}
```

**Step 2: Call `logDwell(phase)` at the start of every click-to-continue handler** (inside the `if (waitingForClick)` block and phase transitions). Call `reportDwell()` when entering `final` phase.

**Step 3: Verify** — Click through existing flow. Check browser console for dwell report.

**Step 4: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): add dwell time tracking per phase"
```

---

### Task 5: Act I — Opening + Idea Swirl Phases

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html`

**What:** Replace current `start` phase with `opening` (MBE quote) and add `swirl-founding` phase. This is where positive words win and negative words drop seeds.

**Step 1: Change initial phase to 'opening'**

Change `let phase = 'start';` to `let phase = 'opening';`

**Step 2: Define the swirl word lists**

```javascript
const FOUNDING_POSITIVE = [
  { label: 'Liberty', color: '#f0d060' },
  { label: 'Equality', color: '#f0d060' },
  { label: 'Consent', color: '#e8c840' },
  { label: 'Self-Governance', color: '#f0d060' },
  { label: 'Natural Rights', color: '#e8c840' },
  { label: 'Social Contract', color: '#f0d060' },
  { label: 'Common Good', color: '#e8c840' },
  { label: 'Justice', color: '#f0d060' },
  { label: 'Representation', color: '#e8c840' },
];

const FOUNDING_NEGATIVE = [
  { label: 'Tyranny', color: '#aa4444' },
  { label: 'Slavery', color: '#aa3333' },
  { label: 'Property over People', color: '#996633' },
  { label: 'Men Only', color: '#997755' },
  { label: "King's Rule", color: '#885555' },
  { label: 'Landed Gentry', color: '#887766' },
];
```

**Step 3: Add `opening` phase to render loop**

Replace the `if (phase === 'start')` block with:

```javascript
  if (phase === 'opening') {
    // MBE quote fade-in
    phaseT += dt;
    const quoteAlpha = easeInOut(Math.min(1, phaseT / 2));
    ctx.save(); ctx.globalAlpha = quoteAlpha * 0.8;
    ctx.font = 'italic 20px Segoe UI, system-ui, sans-serif';
    ctx.fillStyle = GOLD; ctx.textAlign = 'center';
    ctx.fillText('"The focus of ideas"', W/2, H/2 - 15);
    ctx.font = '400 13px Segoe UI, system-ui, sans-serif';
    ctx.fillStyle = '#888';
    ctx.fillText('— Mary Baker Eddy', W/2, H/2 + 15);
    ctx.restore();
```

**Step 4: Add `swirl-founding` phase**

After the opening block, add the swirl phase that spawns words over time, positive words win (grow brighter, drift center), negative words shrink and drop seeds:

```javascript
  } else if (phase === 'swirl-founding') {
    drawTitle('The Enlightenment', 0.5);
    phaseT += dt;

    // Spawn words over first 3 seconds
    if (phaseT < 3) {
      const allWords = [...FOUNDING_POSITIVE.map(w=>({...w,positive:true})), ...FOUNDING_NEGATIVE.map(w=>({...w,positive:false}))];
      const spawnInterval = 3 / allWords.length;
      const shouldHave = Math.floor(phaseT / spawnInterval);
      while (floatingWords.length < shouldHave && floatingWords.length < allWords.length) {
        const def = allWords[floatingWords.length];
        floatingWords.push(makeWord(def.label, def.positive, def.color));
      }
    }

    // After 4 seconds, negative words start shrinking
    if (phaseT > 4) {
      floatingWords.filter(w => !w.positive && w.phase === 'drifting').forEach(w => {
        w.phase = 'shrinking';
      });
    }

    // After 5 seconds, positive words start winning
    if (phaseT > 5) {
      floatingWords.filter(w => w.positive && w.phase === 'drifting').forEach(w => {
        w.phase = 'winning';
      });
    }

    // Drop seeds when negative words fade out
    floatingWords.filter(w => !w.positive && w.phase === 'shrinking' && w.alpha < 0.05).forEach(w => {
      if (w.phase !== 'seeding') { w.phase = 'seeding'; dropSeed(w); }
    });

    updateWords(dt);
    drawWords();

    // Once swirl stabilizes (~7s), show click-to-continue
    if (phaseT > 7 && !waitingForClick) {
      waitingForClick = true;
      hintEl.textContent = 'Click to continue';
      narrate('Ideas take hold. Not all survive.');
    }
```

**Step 5: Update click handlers** — In the click handler, update `start` references to `opening`, add `swirl-founding` transition:

```javascript
  // Opening → swirl
  if (phase === 'opening') {
    phase = 'swirl-founding'; phaseT = 0;
    floatingWords = [];
    hintEl.textContent = '';
    return;
  }
```

And in the `waitingForClick` block, add the swirl-founding → condense transition (next task).

**Step 6: Update restart** — Change `phase = 'start'` in the ended/restart handler to `phase = 'opening'`, and clear `floatingWords = []; seeds = []; nebulae = [];`

**Step 7: Verify** — Opening shows MBE quote. Click → words float in. Positive drift center, negative shrink. Seeds fall to edges. Click to continue appears. Existing flow still reachable.

**Step 8: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): Act I opening + founding idea swirl with seed drops"
```

---

### Task 6: Act I — Nebula Condense + Form People

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html`

**What:** After swirl, positive words cluster into nebula blobs, then blobs condense into The People sphere.

**Step 1: Add `condense-founding` phase**

When entering this phase, create 3 nebulae from the winning words, fade out individual words:

```javascript
  } else if (phase === 'condense-founding') {
    drawTitle('Principles Form', 0.5);
    phaseT += dt;

    // Fade out individual words
    floatingWords.forEach(w => {
      if (w.positive) w.alpha = Math.max(0, w.alpha - dt * 0.5);
    });
    drawWords();

    updateNebulae(dt);
    drawNebulae(now);

    if (phaseT > 4 && !waitingForClick) {
      waitingForClick = true;
      hintEl.textContent = 'Click to continue';
      narrate('Principles form. Still rough. Still unfinished.');
    }
```

**Step 2: Add `form-people` phase**

Nebulae condense toward center, shrink, merge into The People sphere:

```javascript
  } else if (phase === 'form-people') {
    drawTitle('The People', 0.5);
    phaseT += dt;
    const progress = Math.min(1, phaseT / 3);
    const ease = easeInOut(progress);

    // Condensing nebulae
    nebulae.forEach(n => {
      n.phase = 'condensing';
      n.targetX = W/2; n.targetY = H/2;
    });
    updateNebulae(dt);
    if (progress < 0.8) drawNebulae(now);

    // People sphere fading in
    if (progress > 0.3) {
      const sAlpha = easeInOut((progress - 0.3) * 2);
      const sR = 90 * easeOutBack(Math.min(1, (progress - 0.3) * 1.8));
      drawSphere(W/2, H/2, sR, GOLD, 'The People', sAlpha);
    }

    if (progress >= 1) {
      phase = 'people-ready'; phaseT = 0;
      peopleBall = makeBall(W/2, H/2, 90, 'The People', GOLD);
      nebulae = [];
      waitingForClick = true;
      hintEl.textContent = 'Click to continue';
      narrate('The People.');
    }
```

**Step 3: Add `people-ready` phase** — Same as old `start` visually (breathing People sphere, "circa 1750" label). On click → `jitter-people`.

```javascript
  } else if (phase === 'people-ready') {
    drawTitle('The Origin of American Government', 0.5);
    const breathe = Math.sin(now / 600) * 4;
    drawSphere(peopleBall.x, peopleBall.y, peopleBall.r + breathe, peopleBall.color, peopleBall.label, 1);
    ctx.save(); ctx.globalAlpha = 0.4; ctx.font = '400 14px Segoe UI, system-ui, sans-serif';
    ctx.fillStyle = '#888'; ctx.textAlign = 'center';
    ctx.fillText('circa 1750', W/2, H/2 + 120); ctx.restore();
```

**Step 4: Wire click handlers** — `swirl-founding` waitingForClick → enter `condense-founding` (create 3 nebulae). `condense-founding` waitingForClick → enter `form-people`. `people-ready` waitingForClick → enter `jitter-people`.

**Step 5: Remove old `start` phase** — Replace with `opening`. The old `start` rendering code becomes `people-ready`.

**Step 6: Verify** — Full Act I pre-sequence: Opening → Swirl → Condense → Form People → (existing towns/colonies/gov flow).

**Step 7: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): Act I nebula condense + people formation from idea blobs"
```

---

### Task 7: Act I — Post-Deconstruction Transition

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html`

**What:** Change `closing`/`ended` to be a transition into Act II instead of the final state.

**Step 1: Modify `closing` phase**

After "A more perfect union" text appears and progress completes, instead of going to `ended`, go to `post-deconstruct`:

```javascript
    if (progress >= 1 && !ttsPlaying) {
      phase = 'post-deconstruct';
      phaseT = 0;
      waitingForClick = true;
      hintEl.textContent = 'Click to continue';
    }
```

**Step 2: Add `post-deconstruct` phase**

Government sphere reassembles (reverse visual). Seeds still visible at edges.

```javascript
  } else if (phase === 'post-deconstruct') {
    drawTitle('A More Perfect Union', 0.5);
    // Show reassembled government sphere (breathing)
    const breathe = Math.sin(now / 600) * 4;
    drawSphere(W/2, H/2, 95 + breathe, GOLD, 'Constitutional Government', 1);
    ctx.save(); ctx.globalAlpha = 0.5; ctx.font = '400 14px Segoe UI, system-ui, sans-serif';
    ctx.fillStyle = GOLD; ctx.textAlign = 'center';
    ctx.fillText('We the People', W/2, H/2 + 115); ctx.restore();
    // Seeds visible at edges (drawn by drawSeeds in main loop)
```

**Step 3: Wire click** — `post-deconstruct` waitingForClick → enter `swirl-civil-war` (Task 8).

**Step 4: Verify** — After interactive deconstruction, closing plays, then click → government reassembles. Seeds visible at edges.

**Step 5: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): post-deconstruction transition to Act II"
```

---

### Task 8: Act II — Civil War Cycle

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html`

**What:** First friction cycle: Slavery seed sprouts, idea swirl, government sphere cracks and repairs.

**Step 1: Add Civil War word list**

```javascript
const CIVIL_WAR_WORDS = [
  { label: 'Abolition', color: '#f0d060', positive: true },
  { label: 'Freedom', color: '#f0d060', positive: true },
  { label: 'Union', color: '#e8c840', positive: true },
  { label: 'All Men', color: '#f0d060', positive: true },
];
```

**Step 2: Add `swirl-civil-war` phase**

Slavery seed sprouts. New positive words swirl. Dark blob grows from seed and collides with sphere.

```javascript
  } else if (phase === 'swirl-civil-war') {
    drawTitle('The Seeds of Slavery', 0.5);
    phaseT += dt;

    // Sprout the slavery seed
    if (phaseT < 0.1) sproutSeedsForPhase('swirl-civil-war');

    // Spawn positive words
    if (phaseT < 2) {
      const spawnInterval = 2 / CIVIL_WAR_WORDS.length;
      const shouldHave = Math.floor(phaseT / spawnInterval);
      while (floatingWords.length < shouldHave && floatingWords.length < CIVIL_WAR_WORDS.length) {
        const def = CIVIL_WAR_WORDS[floatingWords.length];
        floatingWords.push(makeWord(def.label, def.positive, def.color));
      }
    }

    updateWords(dt);
    drawWords();

    // Government sphere visible
    const breathe = Math.sin(now / 600) * 3;
    drawSphere(W/2, H/2, 95 + breathe, GOLD, 'Constitutional Government', 0.8);

    if (phaseT > 5 && !waitingForClick) {
      waitingForClick = true;
      hintEl.textContent = 'Click to continue';
      narrate('The seeds of slavery were never truly gone.');
    }
```

**Step 3: Add `friction-civil-war` phase**

Government sphere cracks, jitters violently, nearly splits, then repairs with scars.

```javascript
  } else if (phase === 'friction-civil-war') {
    drawTitle('Civil War — 1861', 0.5);
    phaseT += dt;
    const progress = Math.min(1, phaseT / 5);
    floatingWords = []; // clear words

    let govR = 95, govColor = GOLD, govAlpha = 1;

    if (progress < 0.4) {
      // Violent jitter
      const intensity = Math.sin(progress / 0.4 * Math.PI) * 20;
      const jx = (Math.random()-0.5) * intensity * 2;
      const jy = (Math.random()-0.5) * intensity * 2;
      drawSphere(W/2 + jx, H/2 + jy, govR, govColor, 'Constitutional Government', govAlpha);
      // Crack lines
      ctx.save(); ctx.globalAlpha = progress * 2;
      ctx.strokeStyle = '#ff3333'; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.moveTo(W/2 - 20 + jx, H/2 - 60 + jy); ctx.lineTo(W/2 + 5 + jx, H/2 + jy); ctx.lineTo(W/2 - 10 + jx, H/2 + 50 + jy); ctx.stroke();
      ctx.restore();
    } else if (progress < 0.6) {
      // Nearly splits — two halves
      const splitDist = Math.sin((progress - 0.4) / 0.2 * Math.PI) * 30;
      drawSphere(W/2 - splitDist, H/2, govR * 0.55, '#6688cc', 'North', 0.7);
      drawSphere(W/2 + splitDist, H/2, govR * 0.55, '#cc8866', 'South', 0.7);
      ctx.save(); ctx.globalAlpha = 0.5; ctx.strokeStyle = '#ff3333'; ctx.lineWidth = 3;
      ctx.setLineDash([8,4]); ctx.beginPath(); ctx.moveTo(W/2, H/2 - 80); ctx.lineTo(W/2, H/2 + 80); ctx.stroke();
      ctx.setLineDash([]); ctx.restore();
    } else {
      // Repair — come back together, brighter but scarred
      const repairT = (progress - 0.6) / 0.4;
      const ease = easeInOut(repairT);
      const splitDist = 30 * (1 - ease);
      const brightness = ease * 20;
      drawSphere(W/2, H/2, govR, lighten(GOLD, brightness), 'Constitutional Government', govAlpha);
      // Scar line (fading but visible)
      if (repairT < 0.8) {
        ctx.save(); ctx.globalAlpha = 0.3 * (1 - repairT);
        ctx.strokeStyle = '#ff6666'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(W/2 - 5, H/2 - 50); ctx.lineTo(W/2 + 3, H/2); ctx.lineTo(W/2 - 3, H/2 + 45); ctx.stroke();
        ctx.restore();
      }
    }

    if (progress >= 1) {
      popSeed('Slavery', true); // pops slavery, drops Inequality child seed
      waitingForClick = true;
      hintEl.textContent = 'Click to continue';
      narrate('The freedom slog begins.');
    }
```

**Step 4: Wire click** — `swirl-civil-war` waitingForClick → `friction-civil-war`. `friction-civil-war` waitingForClick → `swirl-robber-barons` (Task 9).

**Step 5: Verify** — After post-deconstruct click, slavery seed sprouts, words swirl, then government cracks/splits/repairs.

**Step 6: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): Act II Civil War friction cycle with seed sprout"
```

---

### Task 9: Act II — Robber Barons + Suffrage + Civil Rights

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html`

**What:** Three more friction cycles following the same pattern as Civil War.

**Step 1: Add word lists**

```javascript
const ROBBER_BARON_WORDS = [
  { label: 'Labor Rights', color: '#f0d060', positive: true },
  { label: 'Trust-Busting', color: '#e8c840', positive: true },
  { label: 'Fair Wages', color: '#f0d060', positive: true },
];

const SUFFRAGE_WORDS = [
  { label: 'Suffrage', color: '#f0d060', positive: true },
  { label: '19th Amendment', color: '#e8c840', positive: true },
  { label: 'Half the People', color: '#f0d060', positive: true },
];

const CIVIL_RIGHTS_WORDS = [
  { label: 'Equal Protection', color: '#f0d060', positive: true },
  { label: 'Voting Rights', color: '#e8c840', positive: true },
  { label: 'Justice', color: '#f0d060', positive: true },
];
```

**Step 2: Add swirl + friction phases for each era**

Each follows the same structure as Civil War but with distinct visuals:

- **Robber Barons** (`swirl-robber-barons`, `friction-robber-barons`): "Property over People" seed sprouts. Dark blobs press against sphere from outside. Sphere resists, reforms. Narration: "Concentrated power, confronted."

- **Suffrage** (`swirl-suffrage`, `friction-suffrage`): "Men Only" seed sprouts. Half the sphere was dimmer — now illuminates fully. Seed pops FOR REAL (no child seed). Narration: "Half the people, finally counted."

- **Civil Rights** (`swirl-civil-rights`, `friction-civil-rights`): "Inequality" seed sprouts (child of Slavery). Key visual: words echo/pulse from Act I's original positions. Sphere brightens, scars fade. Narration: "Not new principles. The same ones — restated, reclaimed."

**Step 3: Create a reusable `renderFrictionCycle()` helper** to avoid duplicating the swirl → friction pattern 3 more times. Each cycle takes config:

```javascript
function renderSwirl(config, dt, now) {
  // config: { title, words, sproutPhase, narration, govSphere }
  // Reusable swirl rendering — sprout seeds, spawn words, show gov sphere
}

function renderFriction(config, dt, now) {
  // config: { title, visualType, seedToPop, dropChild, narration }
  // visualType: 'crack' | 'pressure' | 'illuminate' | 'echo'
}
```

**Step 4: Wire all click transitions** — Each friction phase's waitingForClick → next era's swirl. Civil Rights friction → `swirl-technology` (Task 10).

**Step 5: Verify** — Click through all 4 friction cycles. Each seed sprouts at the right time. Government sphere transforms distinctly for each era.

**Step 6: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): Act II complete — robber barons, suffrage, civil rights cycles"
```

---

### Task 10: Act III — Technology + People Power + Final

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html`

**What:** Final act — technology as network, people re-emerge connected, "the building continues."

**Step 1: Add technology word list**

```javascript
const TECH_WORDS = [
  { label: 'Internet', color: '#62a4d0', positive: true },
  { label: 'Open Data', color: '#62a4d0', positive: true },
  { label: 'Transparency', color: '#6af', positive: true },
  { label: 'Connection', color: '#6af', positive: true },
  { label: 'Every Voice', color: '#62a4d0', positive: true },
];
```

**Step 2: Add `swirl-technology` phase**

Different visual style — electric blue, network tendrils instead of clouds. Words form a web/network shape that wraps around the Government sphere.

```javascript
  } else if (phase === 'swirl-technology') {
    drawTitle('A New Kind of Power', 0.5);
    phaseT += dt;

    // Spawn tech words
    // ...same spawn pattern...

    updateWords(dt);
    drawWords();

    // Government sphere
    drawSphere(W/2, H/2, 95, GOLD, 'Constitutional Government', 0.7);

    // Network tendrils growing around sphere
    if (phaseT > 3) {
      const tendrilProgress = Math.min(1, (phaseT - 3) / 3);
      ctx.save(); ctx.globalAlpha = tendrilProgress * 0.4;
      ctx.strokeStyle = '#6af'; ctx.lineWidth = 1.5;
      for (let i = 0; i < 12; i++) {
        const angle = (i / 12) * Math.PI * 2 + now/3000;
        const r1 = 100, r2 = 140 + Math.sin(now/1000 + i) * 20;
        ctx.beginPath();
        ctx.moveTo(W/2 + Math.cos(angle) * r1, H/2 + Math.sin(angle) * r1);
        ctx.lineTo(W/2 + Math.cos(angle + 0.3) * r2, H/2 + Math.sin(angle + 0.3) * r2);
        ctx.stroke();
      }
      ctx.restore();
    }

    if (phaseT > 6 && !waitingForClick) {
      waitingForClick = true;
      hintEl.textContent = 'Click to continue';
      narrate('A new kind of power. Not concentrated. Connected.');
    }
```

**Step 3: Add `people-power` phase**

Government sphere cracks from inside (positive crack, not friction). Many small connected spheres emerge.

```javascript
  } else if (phase === 'people-power') {
    drawTitle('People Power', 0.5);
    phaseT += dt;
    const progress = Math.min(1, phaseT / 4);

    // Sphere cracks from inside — golden light
    if (progress < 0.4) {
      const crackIntensity = progress / 0.4;
      drawSphere(W/2, H/2, 95, GOLD, 'Constitutional Government', 1 - crackIntensity * 0.3);
      // Golden light through cracks
      ctx.save(); ctx.globalAlpha = crackIntensity * 0.6;
      ctx.strokeStyle = '#fff'; ctx.lineWidth = 2;
      // Multiple crack lines radiating outward
      for (let i = 0; i < 6; i++) {
        const angle = (i / 6) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(W/2 + Math.cos(angle) * 30, H/2 + Math.sin(angle) * 30);
        ctx.lineTo(W/2 + Math.cos(angle) * 95 * crackIntensity, H/2 + Math.sin(angle) * 95 * crackIntensity);
        ctx.stroke();
      }
      ctx.restore();
    } else {
      // Small connected spheres emerge
      const emergeT = (progress - 0.4) / 0.6;
      const ease = easeOutBack(Math.min(1, emergeT));
      const nodeCount = 12;
      const nodes = [];
      for (let i = 0; i < nodeCount; i++) {
        const angle = (i / nodeCount) * Math.PI * 2;
        const orbitR = 60 + (i % 3) * 40;
        const nx = W/2 + Math.cos(angle + now/4000) * orbitR * ease;
        const ny = H/2 + Math.sin(angle + now/4000) * orbitR * 0.7 * ease;
        nodes.push({x: nx, y: ny});
        drawSphere(nx, ny, 12 + (i%3)*4, GOLD, '', ease * 0.8);
      }
      // Connection lines
      ctx.save(); ctx.globalAlpha = ease * 0.3; ctx.strokeStyle = '#d4af37'; ctx.lineWidth = 1;
      nodes.forEach((a, i) => {
        nodes.forEach((b, j) => {
          if (j > i && dist(a.x, a.y, b.x, b.y) < 140) {
            ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
          }
        });
      });
      ctx.restore();
    }

    // Remaining seeds dissolve
    seeds.forEach(s => { if (s.state === 'dormant') s.alpha = Math.max(0, s.alpha - dt * 0.2); });

    if (progress >= 1 && !waitingForClick) {
      waitingForClick = true;
      hintEl.textContent = 'Click to continue';
      narrate('The People re-emerge.');
    }
```

**Step 4: Add `final` phase**

Network resolves into TPB gold theme. "The building continues." Pulsing network. Click to restart.

```javascript
  } else if (phase === 'final') {
    phaseT += dt;
    drawTitle('A More Perfect Union', 0.5);

    // Pulsing network of people-spheres
    const nodeCount = 12;
    const nodes = [];
    for (let i = 0; i < nodeCount; i++) {
      const angle = (i / nodeCount) * Math.PI * 2;
      const orbitR = 60 + (i % 3) * 40;
      const nx = W/2 + Math.cos(angle + phaseT * 0.15) * orbitR;
      const ny = H/2 + Math.sin(angle + phaseT * 0.15) * orbitR * 0.7;
      nodes.push({x: nx, y: ny});
      const pulse = 0.6 + Math.sin(now/1000 + i) * 0.2;
      drawSphere(nx, ny, (12 + (i%3)*4) * pulse, GOLD, '', 0.7);
    }
    ctx.save(); ctx.globalAlpha = 0.25; ctx.strokeStyle = '#d4af37'; ctx.lineWidth = 1;
    nodes.forEach((a, i) => {
      nodes.forEach((b, j) => {
        if (j > i && dist(a.x, a.y, b.x, b.y) < 140) {
          ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
        }
      });
    });
    ctx.restore();

    const pulse = 0.85 + Math.sin(now / 1200) * 0.15;
    ctx.save(); ctx.globalAlpha = 0.9 * pulse;
    ctx.font = '700 28px Segoe UI, system-ui, sans-serif';
    ctx.fillStyle = GOLD; ctx.textAlign = 'center';
    ctx.fillText('A more perfect union.', W/2, H/2 - 15); ctx.restore();
    ctx.save(); ctx.globalAlpha = 0.55;
    ctx.font = '400 16px Segoe UI, system-ui, sans-serif';
    ctx.fillStyle = '#b0b0b0'; ctx.textAlign = 'center';
    ctx.fillText('The building continues.', W/2, H/2 + 18); ctx.restore();

    hintEl.textContent = 'Click to begin again';
```

**Step 5: Wire clicks** — `swirl-technology` → `people-power` → `final`. `final` click → restart at `opening` (clear all state). Call `reportDwell()` on entering `final`.

**Step 6: Verify** — Full 3-act experience end to end. Click through every phase. Dwell report in console.

**Step 7: Commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "feat(deconstruct): Act III complete — technology, people power, final network"
```

---

### Task 11: Polish + Deploy

**Files:**
- Modify: `c:\tpb\mockups\deconstruction.html`

**Step 1: Full click-through test** — Verify all phases in order, no premature transitions, no visual glitches, seeds visible throughout, dwell log accurate.

**Step 2: Edge cases** — Test rapid clicking, click during animations, browser resize, touch on mobile.

**Step 3: Clean up** — Remove any debug console.logs (except dwell report). Ensure audio toggle still works (audio is deferred but the toggle/narrate infrastructure should still function).

**Step 4: Push to experiment branch and deploy**

```bash
cd c:/tpb && git push origin experiment
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb.sandgems.net && git pull"
```

**Step 5: Final commit**

```bash
cd c:/tpb && git add mockups/deconstruction.html && git commit -m "polish(deconstruct): full 3-act arc complete and deployed"
```

---

## Phase Transition Map (Quick Reference)

```
opening → swirl-founding → condense-founding → form-people → people-ready
→ jitter-people → scatter → towns → coalesce1 → states → coalesce2
→ gov-arrive → interactive → closing → post-deconstruct
→ swirl-civil-war → friction-civil-war
→ swirl-robber-barons → friction-robber-barons
→ swirl-suffrage → friction-suffrage
→ swirl-civil-rights → friction-civil-rights
→ swirl-technology → people-power → final
→ (restart → opening)
```

All `→` transitions are click-to-continue except:
- `jitter-people → scatter` (auto after jitter completes)
- `scatter → towns` (auto after scatter completes)
- `coalesce1 → states` (auto after coalesce completes)
- `coalesce2 → gov-arrive` (auto after coalesce completes)
- `interactive → closing` (auto when fully deconstructed)
