# Claudia Unified Phase 1 — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace current c-widget.php + claudia-core.js with a unified draggable/resizable widget shell and full-duplex voice engine (chat mode only). Validate on Putnam town page.

**Architecture:** New `claudia-unified.js` absorbs the voice-poc.html full-duplex state machine (interrupt, echo cancel, silence detect, earphones/speakers) into a draggable widget. New `claudia-widget.php` replaces c-widget.php in footer.php. Pipe bridge (`claude -p`) is primary brain, API fallback. Popout syncs via localStorage events.

**Tech Stack:** Vanilla JS (no framework), PHP 8.4, Browser SpeechRecognition/speechSynthesis APIs, SSE for streaming, existing `api/claude-chat.php` backend.

**Design doc:** `docs/plans/2026-03-11-claudia-unified-design.md`

**Proving ground:** `z-states/ct/putnam/index.php` — content-rich page with government data, budget, schools, audio narration.

---

## File Structure

### New files (3)
- `assets/claudia/claudia-unified.js` — Single voice engine + widget logic (~800-1000 lines)
  - Voice state machine (from voice-poc.html)
  - Widget shell (drag, resize, position persistence)
  - Chat UI (messages, input, typing, settings)
  - Mode system (chat only in Phase 1, extensible for talk/mandate)
  - Popout coordination via localStorage events
  - Earphones/speakers toggle
  - Command mode (voice commands)
- `assets/claudia/claudia-unified.css` — All widget styles (~300 lines)
  - Widget container (draggable, resizable)
  - Chat bubbles, input area, typing indicator
  - Voice state indicators (recording, speaking, idle)
  - Settings menu
  - Scratchpad placeholder (hidden in Phase 1)
  - Responsive/mobile
- `includes/claudia-widget.php` — PHP include, replaces c-widget.php (~80 lines)
  - Site-wide toggle check
  - Per-user toggle check
  - Page config merge with defaults
  - Renders widget HTML shell
  - Passes config to JS via data attributes or inline JSON

### Modified files (2)
- `includes/footer.php:173` — Change `require_once __DIR__ . '/c-widget.php'` to `require_once __DIR__ . '/claudia-widget.php'`
- `claudia.php` — Update popout to load claudia-unified.js instead of claudia-core.js

### Reference files (read-only, absorb patterns from)
- `mockups/voice-poc.html` (in c:\tpb) — Full duplex state machine, the foundation
- `includes/c-widget.php` — Current widget (toggle checks, config system, user context)
- `assets/claudia/claudia-core.js` — Current chat logic, API calls, message rendering
- `api/claude-chat.php` — Backend (unchanged, but need to understand request/response format)

### NOT touched in Phase 1
- `api/claude-chat.php` — backend stays as-is
- `includes/ai-context.php` — context builder stays
- `scripts/maintenance/claudia-*` — local listener + tunnel unchanged
- All talk/mandate files — those are Phase 2-3
- Old claudia JS files — deleted in Phase 4 (kept for now as fallback reference)

---

## Chunk 1: Widget PHP Include

### Task 1: Create claudia-widget.php

**Files:**
- Create: `includes/claudia-widget.php`
- Reference: `includes/c-widget.php` (current implementation)

- [ ] **Step 1: Read current c-widget.php thoroughly**

Understand: toggle checks, config merging, user context extraction, HTML output, JS initialization. The new file must replicate all toggle/config behavior but output the new widget HTML.

- [ ] **Step 2: Create includes/claudia-widget.php**

```php
<?php
/**
 * Claudia Unified Widget — Platform-Wide Conversational Assistant
 * Include on any page via footer.php.
 * Requires: $pdo in scope.
 * Optional: $claudiaConfig array set before include.
 */

// Auto-derive context from $currentPage + request URI
if (!isset($claudiaConfig)) {
    $autoContext = isset($currentPage) ? $currentPage : 'general';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?? '';
    $basename = basename($path, '.php');
    if ($basename && $basename !== 'index' && $basename !== $autoContext) {
        $autoContext .= '-' . preg_replace('/[^a-z0-9-]/', '', strtolower($basename));
    }
    $claudiaConfig = [
        'context' => $autoContext,
        'mode_default' => 'chat',
        'mode_available' => ['chat'],
        'capabilities' => ['auth'],
        'events' => false,
    ];
}

// Ensure mode fields exist
$claudiaConfig['mode_default'] = $claudiaConfig['mode_default'] ?? 'chat';
$claudiaConfig['mode_available'] = $claudiaConfig['mode_available'] ?? ['chat'];

// Site-wide toggle check
$claudiaWidgetEnabled = '0';
if (isset($pdo) && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute(['claudia_widget_enabled']);
        $claudiaWidgetEnabled = $stmt->fetchColumn() ?: '0';
    } catch (Exception $e) {
        $claudiaWidgetEnabled = '0';
    }
}
if ($claudiaWidgetEnabled !== '1') return;

// Get user
if (!isset($dbUser)) {
    require_once __DIR__ . '/get-user.php';
    $dbUser = getUser($pdo);
}

// Per-user toggle (logged-in users)
if ($dbUser && isset($dbUser['claudia_enabled']) && $dbUser['claudia_enabled'] === 0) return;

// Build user context for JS
$claudiaUser = $dbUser ? [
    'user_id' => (int)$dbUser['user_id'],
    'display_name' => $dbUser['display_name'] ?? $dbUser['first_name'] ?? 'Friend',
    'town_name' => $dbUser['town_name'] ?? null,
    'state_name' => $dbUser['state_name'] ?? null,
    'district' => $dbUser['us_congress_district'] ?? null,
    'civic_points' => (int)($dbUser['civic_points'] ?? 0),
    'identity_level' => (int)($dbUser['identity_level_id'] ?? 1),
] : null;

// CSS + JS versioning
$cssVer = filemtime(__DIR__ . '/../assets/claudia/claudia-unified.css') ?: 0;
$jsVer = filemtime(__DIR__ . '/../assets/claudia/claudia-unified.js') ?: 0;
?>
<link rel="stylesheet" href="/assets/claudia/claudia-unified.css?v=<?= $cssVer ?>">

<!-- Claudia Widget Shell -->
<div id="claudia-widget" class="claudia-widget" style="display:none;">
    <div class="claudia-header" id="claudia-drag-handle">
        <span class="claudia-title">Claudia</span>
        <div class="claudia-header-controls">
            <button class="claudia-mode-btn" id="claudia-mode-btn" title="Chat mode">Chat</button>
            <button class="claudia-settings-btn" id="claudia-settings-btn" title="Settings">&#9881;</button>
            <button class="claudia-popout-btn" id="claudia-popout-btn" title="Pop out">&#8599;</button>
            <button class="claudia-minimize-btn" id="claudia-minimize-btn" title="Minimize">&minus;</button>
        </div>
    </div>

    <div class="claudia-settings-menu" id="claudia-settings-menu" style="display:none;">
        <div class="claudia-settings-item claudia-toggle-item" data-action="toggle-voice">
            <span>Voice Mode</span>
            <span class="claudia-toggle" id="claudia-voice-toggle">
                <span class="claudia-toggle-knob"></span>
                <span class="claudia-toggle-label" id="claudia-voice-label">OFF</span>
            </span>
        </div>
        <div class="claudia-settings-item claudia-toggle-item" data-action="toggle-audio-mode">
            <span>Earphones</span>
            <span class="claudia-toggle" id="claudia-earphones-toggle">
                <span class="claudia-toggle-knob"></span>
                <span class="claudia-toggle-label" id="claudia-earphones-label">OFF</span>
            </span>
        </div>
        <div class="claudia-settings-item claudia-toggle-item" data-action="toggle-websearch">
            <span>Web Search</span>
            <span class="claudia-toggle" id="claudia-websearch-toggle">
                <span class="claudia-toggle-knob"></span>
                <span class="claudia-toggle-label" id="claudia-ws-label">OFF</span>
            </span>
        </div>
    </div>

    <div class="claudia-body">
        <div class="claudia-voice-bar" id="claudia-voice-bar" style="display:none;">
            <span class="claudia-voice-state" id="claudia-voice-state">IDLE</span>
            <button class="claudia-mic-btn" id="claudia-mic-btn" title="Voice input">&#127908;</button>
        </div>

        <div class="claudia-input-area">
            <input type="text" class="claudia-text-input" id="claudia-text-input"
                   placeholder="Ask Claudia..." autocomplete="off">
            <button class="claudia-send-btn" id="claudia-send-btn">Send</button>
        </div>

        <div class="claudia-typing" id="claudia-typing" style="display:none;">Claudia is thinking...</div>

        <div class="claudia-messages" id="claudia-messages"></div>
    </div>

    <div class="claudia-resize-handle" id="claudia-resize-handle"></div>
</div>

<!-- Claudia Bubble (minimized state) -->
<div id="claudia-bubble" class="claudia-bubble">
    <span class="claudia-bubble-icon">C</span>
</div>

<script>
window.claudiaConfig = <?= json_encode($claudiaConfig) ?>;
window.claudiaUser = <?= json_encode($claudiaUser) ?>;
</script>
<script src="/assets/claudia/claudia-unified.js?v=<?= $jsVer ?>"></script>
```

- [ ] **Step 3: Verify file renders correctly**

Visit `http://localhost/tpb2/z-states/ct/putnam/` in browser. Confirm:
- Widget HTML is present in page source (inspect element)
- No PHP errors in the page
- `window.claudiaConfig` and `window.claudiaUser` are set in console

- [ ] **Step 4: Commit**

```bash
git add includes/claudia-widget.php
git commit -m "feat: add claudia-widget.php — new unified widget shell"
```

---

### Task 2: Wire footer.php to new widget

**Files:**
- Modify: `includes/footer.php:173`

- [ ] **Step 1: Swap include in footer.php**

Change line 173 from:
```php
    require_once __DIR__ . '/c-widget.php';
```
to:
```php
    require_once __DIR__ . '/claudia-widget.php';
```

- [ ] **Step 2: Verify on multiple pages**

Visit these pages and confirm widget loads without errors:
- `http://localhost/tpb2/` (home)
- `http://localhost/tpb2/z-states/ct/putnam/` (town page)
- `http://localhost/tpb2/story.php` (content page)
- `http://localhost/tpb2/profile.php` (auth page)

Check browser console for JS errors on each.

- [ ] **Step 3: Commit**

```bash
git add includes/footer.php
git commit -m "feat: wire footer.php to claudia-widget.php"
```

---

## Chunk 2: Widget CSS

### Task 3: Create claudia-unified.css

**Files:**
- Create: `assets/claudia/claudia-unified.css`

- [ ] **Step 1: Create the CSS file**

This must cover: widget container (fixed position, draggable, resizable), header bar, settings menu, voice bar, messages area, input area, typing indicator, bubble (minimized), resize handle, toggles, responsive. Use existing dark theme colors (`#0d0d1a`, `#1a1a2e`, `#d4af37` gold accent, minimum `#b0b0b0` for text).

```css
/* ================================================================
   Claudia Unified Widget — Styles
   ================================================================ */

/* ── Widget Container ────────────────────────────────────────── */
.claudia-widget {
    position: fixed;
    z-index: 10000;
    background: #0d0d1a;
    border: 1px solid #2a2a3e;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-width: 320px;
    min-height: 400px;
    /* Default size — overridden by localStorage */
    width: 380px;
    height: 520px;
}

/* ── Header (drag handle) ────────────────────────────────────── */
.claudia-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: #111125;
    border-bottom: 1px solid #2a2a3e;
    cursor: grab;
    user-select: none;
    flex-shrink: 0;
}
.claudia-header:active { cursor: grabbing; }

.claudia-title {
    color: #d4af37;
    font-weight: 700;
    font-size: 14px;
    letter-spacing: 0.5px;
}

.claudia-header-controls {
    display: flex;
    gap: 6px;
    align-items: center;
}
.claudia-header-controls button {
    background: none;
    border: 1px solid #333;
    color: #b0b0b0;
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s;
}
.claudia-header-controls button:hover {
    border-color: #d4af37;
    color: #d4af37;
}

.claudia-mode-btn {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 11px !important;
    letter-spacing: 0.5px;
}

/* ── Settings Menu ───────────────────────────────────────────── */
.claudia-settings-menu {
    background: #111125;
    border-bottom: 1px solid #2a2a3e;
    padding: 8px 14px;
    flex-shrink: 0;
}
.claudia-settings-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    color: #b0b0b0;
    font-size: 13px;
}
.claudia-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    width: 36px;
    height: 20px;
    background: #333;
    border-radius: 10px;
    position: relative;
    transition: background 0.2s;
}
.claudia-toggle.on { background: #4ade80; }
.claudia-toggle-knob {
    width: 16px;
    height: 16px;
    background: #fff;
    border-radius: 50%;
    position: absolute;
    left: 2px;
    top: 2px;
    transition: left 0.2s;
}
.claudia-toggle.on .claudia-toggle-knob { left: 18px; }
.claudia-toggle-label {
    position: absolute;
    right: -32px;
    font-size: 11px;
    color: #888;
    width: 28px;
}

/* ── Body ────────────────────────────────────────────────────── */
.claudia-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Voice Bar ───────────────────────────────────────────────── */
.claudia-voice-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 14px;
    background: #0a0a18;
    border-bottom: 1px solid #222;
    flex-shrink: 0;
}
.claudia-voice-state {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 3px 10px;
    border-radius: 4px;
    min-width: 90px;
    text-align: center;
}
.claudia-voice-state.idle { background: #222; color: #888; }
.claudia-voice-state.speaking { background: #1a3a1a; color: #4a4; }
.claudia-voice-state.listening { background: #1a1a3a; color: #66f; }
.claudia-voice-state.interrupted { background: #3a2a1a; color: #d4af37; }
.claudia-voice-state.processing { background: #2a2a1a; color: #aa6; }

.claudia-mic-btn {
    background: none;
    border: 2px solid #444;
    color: #b0b0b0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}
.claudia-mic-btn.listening {
    border-color: #f44336;
    color: #f44336;
    animation: mic-pulse 1.5s infinite;
}
.claudia-mic-btn.command-mode {
    border-color: #d4af37;
    color: #d4af37;
}

@keyframes mic-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.5); }
    50% { box-shadow: 0 0 0 8px rgba(244, 67, 54, 0); }
}

/* ── Input Area ──────────────────────────────────────────────── */
.claudia-input-area {
    display: flex;
    gap: 8px;
    padding: 10px 14px;
    border-bottom: 1px solid #222;
    flex-shrink: 0;
}
.claudia-text-input {
    flex: 1;
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 8px;
    color: #eee;
    padding: 8px 12px;
    font-size: 13px;
    outline: none;
}
.claudia-text-input:focus { border-color: #d4af37; }
.claudia-text-input::placeholder { color: #666; }

.claudia-send-btn {
    background: #d4af37;
    color: #000;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.claudia-send-btn:hover { background: #e5c040; }
.claudia-send-btn:disabled { background: #333; color: #666; cursor: not-allowed; }

/* ── Typing Indicator ────────────────────────────────────────── */
.claudia-typing {
    padding: 8px 14px;
    color: #888;
    font-size: 12px;
    font-style: italic;
    flex-shrink: 0;
}

/* ── Messages ────────────────────────────────────────────────── */
.claudia-messages {
    flex: 1;
    overflow-y: auto;
    padding: 10px 14px;
    display: flex;
    flex-direction: column-reverse;
}
.claudia-msg {
    margin-bottom: 10px;
    padding: 10px 14px;
    border-radius: 10px;
    font-size: 13px;
    line-height: 1.5;
    color: #e0e0e0;
    max-width: 90%;
    word-wrap: break-word;
}
.claudia-msg.user {
    background: #1a2a4a;
    align-self: flex-end;
    border-bottom-right-radius: 4px;
}
.claudia-msg.assistant {
    background: #1a1a2e;
    border-left: 3px solid #d4af37;
    align-self: flex-start;
    border-bottom-left-radius: 4px;
}
.claudia-msg.system {
    background: transparent;
    color: #888;
    font-style: italic;
    font-size: 12px;
    align-self: center;
    text-align: center;
}
.claudia-msg-time {
    font-size: 10px;
    color: #555;
    margin-top: 4px;
}

/* ── Resize Handle ───────────────────────────────────────────── */
.claudia-resize-handle {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 16px;
    height: 16px;
    cursor: nwse-resize;
    background: linear-gradient(135deg, transparent 50%, #333 50%);
    border-radius: 0 0 12px 0;
}

/* ── Bubble (minimized) ──────────────────────────────────────── */
.claudia-bubble {
    position: fixed;
    z-index: 10000;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #d4af37, #b8962e);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 4px 16px rgba(212, 175, 55, 0.3);
    transition: transform 0.2s;
}
.claudia-bubble:hover { transform: scale(1.1); }
.claudia-bubble-icon {
    color: #000;
    font-weight: 800;
    font-size: 20px;
    font-family: Georgia, serif;
}

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 480px) {
    .claudia-widget {
        width: 100% !important;
        height: 60vh !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        border-radius: 12px 12px 0 0;
    }
    .claudia-resize-handle { display: none; }
}
```

- [ ] **Step 2: Verify styles render**

Visit `http://localhost/tpb2/z-states/ct/putnam/`. Widget should appear styled (even though JS isn't wired yet, the HTML elements from claudia-widget.php should be visible if you remove the `style="display:none"`).

- [ ] **Step 3: Commit**

```bash
git add assets/claudia/claudia-unified.css
git commit -m "feat: add claudia-unified.css — widget styles"
```

---

## Chunk 3: Widget JS — Core Shell

### Task 4: Create claudia-unified.js — Widget shell (drag, resize, persist, minimize, popout)

**Files:**
- Create: `assets/claudia/claudia-unified.js`

- [ ] **Step 1: Create the file with IIFE wrapper and initialization**

Start with the widget shell — no voice, no chat, just the draggable/resizable container that persists position.

```javascript
/**
 * Claudia Unified — One Widget, One Voice, One Pipe
 * Phase 1: Chat mode + full duplex voice engine
 */
(function() {
    'use strict';

    // ── Config ──────────────────────────────────────────────
    var CONFIG = window.claudiaConfig || { context: 'general', mode_default: 'chat', mode_available: ['chat'] };
    var USER = window.claudiaUser || null;

    // ── State ───────────────────────────────────────────────
    var state = {
        open: false,
        mode: CONFIG.mode_default || 'chat',
        position: JSON.parse(localStorage.getItem('claudia_position') || 'null'),
        size: JSON.parse(localStorage.getItem('claudia_size') || 'null'),
        audioMode: localStorage.getItem('claudia_audio_mode') || 'speakers',
        voiceEnabled: localStorage.getItem('claudia_voice_mode') === 'on',
        webSearch: localStorage.getItem('claudia_websearch') === '1',
        messages: [],
        sessionId: sessionStorage.getItem('claudia_session') || crypto.randomUUID(),
    };
    sessionStorage.setItem('claudia_session', state.sessionId);

    // ── DOM refs ────────────────────────────────────────────
    var widget, bubble, dragHandle, resizeHandle;
    var messagesEl, inputEl, sendBtn, typingEl;
    var settingsMenu, settingsBtn;
    var voiceBar, voiceStateEl, micBtn;
    var modeBtn, popoutBtn, minimizeBtn;

    // ── Init ────────────────────────────────────────────────
    function init() {
        widget = document.getElementById('claudia-widget');
        bubble = document.getElementById('claudia-bubble');
        if (!widget || !bubble) return;

        dragHandle = document.getElementById('claudia-drag-handle');
        resizeHandle = document.getElementById('claudia-resize-handle');
        messagesEl = document.getElementById('claudia-messages');
        inputEl = document.getElementById('claudia-text-input');
        sendBtn = document.getElementById('claudia-send-btn');
        typingEl = document.getElementById('claudia-typing');
        settingsMenu = document.getElementById('claudia-settings-menu');
        settingsBtn = document.getElementById('claudia-settings-btn');
        voiceBar = document.getElementById('claudia-voice-bar');
        voiceStateEl = document.getElementById('claudia-voice-state');
        micBtn = document.getElementById('claudia-mic-btn');
        modeBtn = document.getElementById('claudia-mode-btn');
        popoutBtn = document.getElementById('claudia-popout-btn');
        minimizeBtn = document.getElementById('claudia-minimize-btn');

        setDefaultPosition();
        restoreState();
        bindEvents();
        loadHistory();

        // Start minimized (bubble visible)
        bubble.style.display = 'flex';
        widget.style.display = 'none';
    }

    // ── Default Position ────────────────────────────────────
    function setDefaultPosition() {
        if (state.position) return; // Already have saved position

        // Right side, below navs — proportionally spaced
        var navHeight = 0;
        var navEls = document.querySelectorAll('.tpb-nav, .tpb-secondary-nav, nav');
        navEls.forEach(function(el) { navHeight += el.offsetHeight; });
        if (navHeight === 0) navHeight = 100; // fallback

        state.position = {
            right: 20,
            top: navHeight + 20
        };
    }

    function applyPosition() {
        if (state.position.right !== undefined) {
            widget.style.right = state.position.right + 'px';
            widget.style.left = 'auto';
            widget.style.top = state.position.top + 'px';
        } else {
            widget.style.left = state.position.left + 'px';
            widget.style.top = state.position.top + 'px';
            widget.style.right = 'auto';
        }

        // Bubble mirrors widget position
        if (state.position.right !== undefined) {
            bubble.style.right = state.position.right + 'px';
            bubble.style.left = 'auto';
            bubble.style.top = state.position.top + 'px';
        } else {
            bubble.style.left = state.position.left + 'px';
            bubble.style.top = state.position.top + 'px';
            bubble.style.right = 'auto';
        }

        if (state.size) {
            widget.style.width = state.size.width + 'px';
            widget.style.height = state.size.height + 'px';
        }
    }

    function savePosition() {
        localStorage.setItem('claudia_position', JSON.stringify(state.position));
        if (state.size) {
            localStorage.setItem('claudia_size', JSON.stringify(state.size));
        }
    }

    function restoreState() {
        applyPosition();

        // Restore toggles
        updateToggle('claudia-voice-toggle', 'claudia-voice-label', state.voiceEnabled);
        updateToggle('claudia-earphones-toggle', 'claudia-earphones-label', state.audioMode === 'earphones');
        updateToggle('claudia-websearch-toggle', 'claudia-ws-label', state.webSearch);

        if (state.voiceEnabled && voiceBar) {
            voiceBar.style.display = 'flex';
        }
    }

    // ── Events ──────────────────────────────────────────────
    function bindEvents() {
        // Bubble → open widget
        bubble.addEventListener('click', function() {
            openWidget();
        });

        // Minimize → close widget, show bubble
        minimizeBtn.addEventListener('click', function() {
            closeWidget();
        });

        // Send message
        sendBtn.addEventListener('click', function() { sendMessage(); });
        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Settings toggle
        settingsBtn.addEventListener('click', function() {
            settingsMenu.style.display = settingsMenu.style.display === 'none' ? 'block' : 'none';
        });

        // Settings toggles
        document.querySelectorAll('.claudia-toggle-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var action = item.dataset.action;
                if (action === 'toggle-voice') toggleVoice();
                else if (action === 'toggle-audio-mode') toggleAudioMode();
                else if (action === 'toggle-websearch') toggleWebSearch();
            });
        });

        // Popout
        popoutBtn.addEventListener('click', function() {
            openPopout();
        });

        // Drag
        initDrag();

        // Resize
        initResize();

        // Cross-tab sync (for popout)
        window.addEventListener('storage', function(e) {
            if (e.key === 'claudia_sync') {
                var data = JSON.parse(e.newValue);
                if (data && data.type === 'message') {
                    state.messages = data.messages;
                    renderMessages();
                }
            }
        });
    }

    // ── Open / Close ────────────────────────────────────────
    function openWidget() {
        state.open = true;
        widget.style.display = 'flex';
        bubble.style.display = 'none';
        applyPosition();
        inputEl.focus();
    }

    function closeWidget() {
        state.open = false;
        widget.style.display = 'none';
        bubble.style.display = 'flex';
        applyPosition();
    }

    // ── Drag ────────────────────────────────────────────────
    function initDrag() {
        var dragging = false, startX, startY, startLeft, startTop;

        dragHandle.addEventListener('mousedown', function(e) {
            if (e.target.tagName === 'BUTTON') return;
            dragging = true;
            var rect = widget.getBoundingClientRect();
            startX = e.clientX;
            startY = e.clientY;
            startLeft = rect.left;
            startTop = rect.top;
            widget.style.right = 'auto';
            widget.style.left = startLeft + 'px';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!dragging) return;
            var dx = e.clientX - startX;
            var dy = e.clientY - startY;
            var newLeft = startLeft + dx;
            var newTop = startTop + dy;

            // Keep on screen
            newLeft = Math.max(0, Math.min(newLeft, window.innerWidth - widget.offsetWidth));
            newTop = Math.max(0, Math.min(newTop, window.innerHeight - widget.offsetHeight));

            widget.style.left = newLeft + 'px';
            widget.style.top = newTop + 'px';
        });

        document.addEventListener('mouseup', function() {
            if (!dragging) return;
            dragging = false;
            // Save as left/top (not right, since we switched during drag)
            state.position = {
                left: parseInt(widget.style.left),
                top: parseInt(widget.style.top)
            };
            savePosition();
        });

        // Touch support
        dragHandle.addEventListener('touchstart', function(e) {
            if (e.target.tagName === 'BUTTON') return;
            dragging = true;
            var rect = widget.getBoundingClientRect();
            var touch = e.touches[0];
            startX = touch.clientX;
            startY = touch.clientY;
            startLeft = rect.left;
            startTop = rect.top;
            widget.style.right = 'auto';
            widget.style.left = startLeft + 'px';
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (!dragging) return;
            var touch = e.touches[0];
            var dx = touch.clientX - startX;
            var dy = touch.clientY - startY;
            widget.style.left = Math.max(0, startLeft + dx) + 'px';
            widget.style.top = Math.max(0, startTop + dy) + 'px';
        }, { passive: true });

        document.addEventListener('touchend', function() {
            if (!dragging) return;
            dragging = false;
            state.position = {
                left: parseInt(widget.style.left),
                top: parseInt(widget.style.top)
            };
            savePosition();
        });
    }

    // ── Resize ──────────────────────────────────────────────
    function initResize() {
        var resizing = false, startX, startY, startW, startH;

        resizeHandle.addEventListener('mousedown', function(e) {
            resizing = true;
            startX = e.clientX;
            startY = e.clientY;
            startW = widget.offsetWidth;
            startH = widget.offsetHeight;
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!resizing) return;
            var w = Math.max(320, startW + (e.clientX - startX));
            var h = Math.max(400, startH + (e.clientY - startY));
            widget.style.width = w + 'px';
            widget.style.height = h + 'px';
        });

        document.addEventListener('mouseup', function() {
            if (!resizing) return;
            resizing = false;
            state.size = {
                width: widget.offsetWidth,
                height: widget.offsetHeight
            };
            savePosition();
        });
    }

    // ── Popout ──────────────────────────────────────────────
    function openPopout() {
        var w = state.size ? state.size.width : 380;
        var h = state.size ? state.size.height : 520;
        var popout = window.open('/claudia.php', 'claudia_popout',
            'width=' + w + ',height=' + h + ',resizable=yes,scrollbars=no');
        if (popout) {
            closeWidget();
        }
    }

    // ── Toggle Helpers ──────────────────────────────────────
    function updateToggle(toggleId, labelId, isOn) {
        var toggle = document.getElementById(toggleId);
        var label = document.getElementById(labelId);
        if (toggle) {
            toggle.classList.toggle('on', isOn);
        }
        if (label) {
            label.textContent = isOn ? 'ON' : 'OFF';
        }
    }

    function toggleVoice() {
        state.voiceEnabled = !state.voiceEnabled;
        localStorage.setItem('claudia_voice_mode', state.voiceEnabled ? 'on' : 'off');
        updateToggle('claudia-voice-toggle', 'claudia-voice-label', state.voiceEnabled);
        if (voiceBar) {
            voiceBar.style.display = state.voiceEnabled ? 'flex' : 'none';
        }
        if (state.voiceEnabled) {
            initVoiceEngine();
        }
    }

    function toggleAudioMode() {
        state.audioMode = state.audioMode === 'earphones' ? 'speakers' : 'earphones';
        localStorage.setItem('claudia_audio_mode', state.audioMode);
        updateToggle('claudia-earphones-toggle', 'claudia-earphones-label', state.audioMode === 'earphones');
    }

    function toggleWebSearch() {
        state.webSearch = !state.webSearch;
        localStorage.setItem('claudia_websearch', state.webSearch ? '1' : '0');
        updateToggle('claudia-websearch-toggle', 'claudia-ws-label', state.webSearch);
    }

    // ── Chat ────────────────────────────────────────────────
    function sendMessage() {
        var content = inputEl.value.trim();
        if (!content) return;

        addMessage('user', content);
        inputEl.value = '';
        showTyping();

        // Build history for API
        var history = [];
        for (var i = 0; i < state.messages.length - 1; i++) {
            var m = state.messages[i];
            if (m.role === 'system') continue;
            history.push({ role: m.role, content: m.content });
        }

        fetch('/api/claude-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: content,
                history: history,
                clerk: 'guide',
                session_id: state.sessionId,
                context: CONFIG.context,
                web_search: state.webSearch
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideTyping();
            if (data.response) {
                addMessage('assistant', data.response);
                if (state.voiceEnabled) {
                    speak(data.response);
                }
            } else if (data.error) {
                addMessage('system', 'Error: ' + data.error);
            }
        })
        .catch(function(err) {
            hideTyping();
            addMessage('system', 'Connection error — try again.');
        });
    }

    function addMessage(role, content) {
        var msg = { role: role, content: content, ts: new Date().toISOString() };
        state.messages.push(msg);
        renderMessage(msg);
        saveHistory();
        syncToPopout();
    }

    function renderMessage(msg) {
        var div = document.createElement('div');
        div.className = 'claudia-msg ' + msg.role;

        // Format: escape HTML, bold, newlines
        var html = msg.content
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
        div.innerHTML = html;

        // Timestamp
        var timeDiv = document.createElement('div');
        timeDiv.className = 'claudia-msg-time';
        var d = new Date(msg.ts);
        var h = d.getHours(), m = d.getMinutes();
        timeDiv.textContent = (h % 12 || 12) + ':' + (m < 10 ? '0' : '') + m + ' ' + (h >= 12 ? 'PM' : 'AM');
        div.appendChild(timeDiv);

        messagesEl.appendChild(div);
        messagesEl.scrollTop = 0; // column-reverse: 0 = newest
    }

    function renderMessages() {
        messagesEl.innerHTML = '';
        state.messages.forEach(renderMessage);
    }

    function showTyping() {
        typingEl.style.display = 'block';
        sendBtn.disabled = true;
    }

    function hideTyping() {
        typingEl.style.display = 'none';
        sendBtn.disabled = false;
    }

    // ── History Persistence ─────────────────────────────────
    function saveHistory() {
        try {
            localStorage.setItem('claudia_history_' + state.sessionId, JSON.stringify(state.messages));
        } catch(e) {}
    }

    function loadHistory() {
        try {
            var data = localStorage.getItem('claudia_history_' + state.sessionId);
            if (data) {
                state.messages = JSON.parse(data);
                renderMessages();
            }
        } catch(e) {}
    }

    function syncToPopout() {
        localStorage.setItem('claudia_sync', JSON.stringify({
            type: 'message',
            messages: state.messages,
            ts: Date.now()
        }));
    }

    // ── Voice Engine (Full Duplex) ──────────────────────────
    var voice = {
        recognition: null,
        claudiaVoice: null,
        isSpeaking: false,
        sttActive: false,
        ignoreSTT: false,
        micOn: false,
        commandMode: false,
        silenceTimer: null,
        humanUtterance: '',
        started: false
    };

    function initVoiceEngine() {
        if (voice.started) return;
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return;

        voice.started = true;
        voice.recognition = new SR();
        voice.recognition.continuous = true;
        voice.recognition.interimResults = true;
        voice.recognition.lang = 'en-US';

        voice.recognition.onstart = function() {
            voice.sttActive = true;
            updateVoiceUI('listening');
        };

        voice.recognition.onresult = function(event) {
            var result = event.results[event.results.length - 1];
            var text = result[0].transcript.trim();
            if (!text || voice.ignoreSTT) return;

            // Interrupt: human spoke while Claudia is speaking
            if (voice.isSpeaking && state.audioMode === 'earphones') {
                speechSynthesis.cancel();
                voice.isSpeaking = false;
                voice.ignoreSTT = false;
                updateVoiceUI('idle');
                addMessage('system', '— Interrupted —');
            }

            if (!voice.isSpeaking) {
                voice.humanUtterance = text;
                updateVoiceUI('listening');

                clearTimeout(voice.silenceTimer);
                if (result.isFinal) {
                    voice.silenceTimer = setTimeout(function() {
                        humanFinished(voice.humanUtterance);
                    }, 1500);
                }
            }
        };

        voice.recognition.onerror = function(e) {
            if (e.error === 'no-speech') return;
        };

        voice.recognition.onend = function() {
            voice.sttActive = false;
            if (voice.micOn) {
                setTimeout(function() {
                    try { voice.recognition.start(); } catch(e) {}
                }, 200);
            }
        };

        // Pick voice
        pickVoice();
        speechSynthesis.onvoiceschanged = pickVoice;
    }

    function pickVoice() {
        var v = speechSynthesis.getVoices();
        voice.claudiaVoice = v.find(function(x) { return /zira|eva|samantha|karen|susan/i.test(x.name); })
            || v.find(function(x) { return /female/i.test(x.name) && x.lang && x.lang.startsWith('en'); })
            || v.find(function(x) { return x.lang && x.lang.startsWith('en'); });
    }

    function speak(text) {
        if (!state.voiceEnabled) return;
        speechSynthesis.cancel();
        var u = new SpeechSynthesisUtterance(text);
        u.rate = 0.9;
        u.pitch = 1.05;
        if (voice.claudiaVoice) u.voice = voice.claudiaVoice;

        u.onstart = function() {
            voice.isSpeaking = true;
            voice.ignoreSTT = true;
            updateVoiceUI('speaking');
        };
        u.onend = function() {
            voice.isSpeaking = false;
            setTimeout(function() { voice.ignoreSTT = false; }, 500);
            updateVoiceUI('idle');
        };
        speechSynthesis.speak(u);
    }

    function humanFinished(text) {
        updateVoiceUI('processing');
        // Feed into chat
        inputEl.value = text;
        sendMessage();
    }

    function startMic() {
        if (!voice.recognition) initVoiceEngine();
        if (!voice.recognition) return;
        voice.micOn = true;
        try { voice.recognition.start(); } catch(e) {}
        if (micBtn) micBtn.classList.add('listening');
    }

    function stopMic() {
        voice.micOn = false;
        if (voice.recognition) {
            try { voice.recognition.stop(); } catch(e) {}
        }
        if (micBtn) micBtn.classList.remove('listening');
    }

    function updateVoiceUI(newState) {
        if (voiceStateEl) {
            voiceStateEl.textContent = newState.toUpperCase();
            voiceStateEl.className = 'claudia-voice-state ' + newState;
        }
    }

    // Mic button
    if (micBtn) {
        // Defer binding — micBtn may not exist yet at this point
        setTimeout(function() {
            var btn = document.getElementById('claudia-mic-btn');
            if (btn) {
                btn.addEventListener('click', function() {
                    if (voice.micOn) stopMic();
                    else startMic();
                });
            }
        }, 0);
    }

    // ── Boot ────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
```

- [ ] **Step 2: Verify widget works on Putnam page**

Visit `http://localhost/tpb2/z-states/ct/putnam/`. Test:
1. Gold bubble appears (bottom-right area, below nav)
2. Click bubble → widget opens
3. Minimize button → widget closes, bubble returns
4. Drag header → widget moves, position persists after refresh
5. Resize handle → widget resizes, size persists after refresh
6. Type message + Send → message appears, Claudia responds (via API)
7. Settings gear → toggles appear
8. Popout button → separate window opens

- [ ] **Step 3: Verify on home page**

Visit `http://localhost/tpb2/`. Same checks as above. Position should carry over from Putnam.

- [ ] **Step 4: Commit**

```bash
git add assets/claudia/claudia-unified.js
git commit -m "feat: claudia-unified.js — widget shell + chat + full duplex voice"
```

---

## Chunk 4: Popout + Final Wiring

### Task 5: Update claudia.php for popout

**Files:**
- Modify: `claudia.php`

- [ ] **Step 1: Read current claudia.php**

Understand how the popout currently works — it loads claudia-core.js and renders a full-page chat. Needs to load claudia-unified.js instead.

- [ ] **Step 2: Update claudia.php to load unified JS/CSS**

Replace references to `claudia-core.js` and `claudia.css` with `claudia-unified.js` and `claudia-unified.css`. The popout should render the widget at full page size, not as a floating widget.

- [ ] **Step 3: Verify popout works**

Click popout button in widget → separate window opens → can send messages → conversation syncs back to main page via localStorage events.

- [ ] **Step 4: Commit**

```bash
git add claudia.php
git commit -m "feat: update claudia.php popout to use unified JS/CSS"
```

---

### Task 6: End-to-end testing on Putnam

**Files:** None (testing only)

- [ ] **Step 1: Test chat mode**

On `http://localhost/tpb2/z-states/ct/putnam/`:
1. Open widget, type "What is Putnam?" → Claudia responds with page-aware answer
2. Type "How many board vacancies?" → Claudia responds (this tests page context)
3. Scroll messages — newest on top (column-reverse)
4. Clear conversation (if clear button exists)

- [ ] **Step 2: Test voice mode**

1. Toggle Voice Mode ON in settings
2. Voice bar appears with IDLE state
3. Click mic → state changes to LISTENING
4. Speak → text appears in input → auto-sends after 1.5s silence
5. Claudia responds with TTS
6. Toggle earphones → verify label updates

- [ ] **Step 3: Test persistence**

1. Send a few messages
2. Refresh page → messages still there
3. Drag widget to new position → refresh → position persists
4. Resize widget → refresh → size persists
5. Navigate to different page (`/story.php`) → widget position same

- [ ] **Step 4: Test popout sync**

1. Send message in main page widget
2. Click popout → new window shows same conversation
3. Send message in popout → main page shows it (via storage event)

- [ ] **Step 5: Test multiple pages**

Verify no JS errors on:
- Home (`/`)
- Story (`/story.php`)
- Profile (`/profile.php`)
- Elections (`/elections/`)
- Help (`/help/`)
- Talk (`/talk/`)
- Poll (`/poll/`)

---

### Task 7: Deploy to staging

- [ ] **Step 1: Push to git**

```bash
git push origin master
```

- [ ] **Step 2: Pull on staging**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

- [ ] **Step 3: Verify on staging**

Visit `https://tpb2.sandgems.net/z-states/ct/putnam/`. Confirm widget loads and functions.

- [ ] **Step 4: Verify Claudia local mode works through tunnel**

If tunnel is running (`claudia-local-start.bat`), test that chat responses come from `claude -p` not API.
