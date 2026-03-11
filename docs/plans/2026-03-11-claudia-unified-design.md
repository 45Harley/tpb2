# Claudia Unified: One Widget, One Voice, One Pipe

**Date:** 2026-03-11
**Status:** Approved
**Approach:** Core-Out (Phase 1-4 incremental)
**Supersedes:** 2026-03-10-claudia-platform-assistant-design.md

## 1. Vision — The Human-AI Pair

Every TPB user gets a Claudia — not a chatbot, a partner. The pair is the atomic unit of the platform.

- **Surrogate** — acts on the user's behalf (saves mandates, tracks reps, reads bills)
- **Guide** — knows where you are, what you haven't seen, what matters to your town
- **Educator** — explains the constitution, translates jargon, teaches civics in context
- **Developer** — refines raw frustration into actionable positions through dialog

The user drives. Claudia rides shotgun. Full duplex — both can contribute at any time.

### The Fractal (future phases)

- Pairs discover like-minded pairs (Claudia-to-Claudia on behalf of humans)
- Pairs cluster into working groups around shared priorities
- Groups deliberate, Claudias facilitate and identify common ground
- Convergence across towns rolls up into mandates
- AI-mediated democracy: organic consensus, discovered through pairs collaborating

Group/discovery is out of scope for this design but shapes every architectural decision.

## 2. Architecture — One Widget, One Voice Engine, One Pipe

### Widget Shell

- Single draggable, resizable widget rendered on every page via footer
- Default position: right side, below nav rows, proportionally spaced
- Position and size persist in localStorage across pages
- Popout = separate browser window, conversation state syncs between inline and popout
- Minimum size for quick exchanges, stretch for deep collaboration

### One Voice Engine

Replaces all 5 existing voice subsystems plus inline copies:

1. `claudia-core.js` — widget STT + TTS (one-shot, voice/text/both modes)
2. `mandate-chat.js` — STT + TTS + command mode toggle (continuous, echo prevention)
3. `talk-stream.js` — STT continuous dictation
4. `thought-form.php` — STT dictation (reusable include)
5. `voice-poc.html` — full duplex state machine (interrupt, echo cancel, silence detect)

Plus inline SpeechRecognition in: thought.php, thought-no-ai.php, talk/brainstorm.php, c-guide.php, 3 town HTML pages, putnam/index-old2.php.

The unified engine:

- Browser SpeechRecognition (STT) — continuous, interim results
- Browser speechSynthesis (TTS) — female voice preference
- Two audio modes: **earphones** (full duplex, preferred) and **speakers** (echo cancellation, mute mic during TTS)
- Full duplex state machine from voice-poc.html: idle → speaking → listening → interrupted → yielding → processing
- Human can interrupt Claudia mid-speech
- Silence detection (end-of-utterance threshold)
- Command mode toggle (say "command" to switch between dictation and voice commands)
- No server-side voice processing — ears and mouth stay in the browser

### One Pipe (the brain)

- Primary: `claude -p` via persistent stdin/stdout process, bridged through reverse SSH tunnel + local PHP listener
- Streaming: tokens flow to widget as generated (WebSocket or SSE from listener)
- Context: maintained by the Claude process itself — no message history replay
- Fallback: Anthropic API (Haiku) when pipe unavailable
- Toggle: `claudia_local_enabled` in site_settings

### Page Awareness

- Each page passes context config (same pattern as existing `$claudiaConfig`)
- Widget capabilities shift by page — Putnam page loads town context (boards, budget, schools), elections page loads race data, etc.
- Claudia knows where you are without being told

## 3. Modes & Scratchpad

### Three Modes

Mode switcher in the widget header. One active at a time.

| Mode | Purpose | Scratchpad default |
|------|---------|-------------------|
| **Chat** | Q&A, help, navigation, page-aware guidance | OFF |
| **Talk** | Brainstorming, idea refinement, thinking out loud | ON |
| **Mandate** | Refine positions, save to government levels, aggregation | ON |

- Chat is the default mode on all pages
- Mode persists in localStorage
- All modes share the same conversation history within a session
- Mode determines what voice commands are available and what save actions exist

### Chat Mode

- Half duplex feel (but full duplex capable)
- Q&A about the page, the platform, civics
- Navigation assistance ("take me to my profile")
- Action tags (SET_TOWN, LOOKUP_USER, NAVIGATE, etc.)
- Scratchpad available but hidden by default

### Talk Mode

- Brainstorming workspace
- Continuous dictation flows into conversation
- Claudia refines, challenges, asks clarifying questions
- Scratchpad ON — pin promising ideas as they emerge
- Save pinned ideas to idea_log (private)

### Mandate Mode

- Full triplex from mandate-chat
- Claudia helps refine raw frustration into 1-2 sentence policy positions
- Scratchpad ON — curate refined mandates
- Save bar with government levels: Federal / State / Town / Private
- On town pages (Putnam), town-level is pre-selected
- Reads back saved mandates from aggregation API
- Tone/sentiment auto-tagging

### Universal Scratchpad

- Available in every mode, toggle on/off
- Pin ideas from any AI response (pin button on each response bubble)
- Numbered items, removable
- Select dropdown for choosing which to save
- Save action depends on mode: Talk saves to idea_log, Mandate saves with government level
- Chat mode pins are just bookmarks (save as note)
- Persists in localStorage per session

## 4. Voice Command System

### Two Input Modes (toggled by saying "command")

| Mode | Mic icon | Behavior |
|------|----------|----------|
| **Dictation** | Red circle (recording) | Speech flows into text input |
| **Command** | Gear icon | Speech executes actions |

Long phrases (5+ words) in command mode auto-switch to dictation.

### Universal Commands (all modes)

- "send" / "submit" / "go" — send current input to Claudia
- "pin" — pin last AI response to scratchpad
- "delete #3" — remove pinned item
- "clear all" / "start over" — reset session
- "clear prompt" — clear text input
- "clear response" — clear chat bubbles, keep pins
- "read #2" — read back a pinned item (TTS)
- "help" / "commands" — list available commands
- "chat mode" / "talk mode" / "mandate mode" — switch modes
- "scratchpad on" / "scratchpad off" — toggle
- "popout" — open separate window
- "earphones" / "speakers" — switch audio mode

### Talk Mode Commands

- "save idea" / "save #2 as idea" — save pinned item to idea_log

### Mandate Mode Commands

- "save federal" / "save federal mandate" — save to mandate-federal
- "save state" / "save state mandate" — save to mandate-state
- "save town" / "save town mandate" — save to mandate-town
- "read my mandate" — read back saved mandates (TTS)
- "read my federal mandate" / "read my town mandate" — filtered
- "what does [district] want?" — read public aggregation

### Page-Aware Commands

- "what are the board vacancies?" — on town pages with government data
- "read the budget" — on town pages with budget data
- "who is my rep?" — when user has district set

## 5. Consolidation — What Gets Replaced

### Voice engines deleted

| Engine | Replaced by |
|--------|------------|
| `assets/claudia/claudia-core.js` | Unified engine |
| `assets/mandate-chat.js` | Unified engine (mandate mode) |
| `assets/talk-stream.js` | Unified engine (talk mode) |
| `includes/thought-form.php` | Unified engine (scratchpad pin) |
| `mockups/voice-poc.html` | Foundation absorbed into unified engine |

### Inline STT removed from

| Page | Current voice | After |
|------|--------------|-------|
| `thought.php` | Inline SpeechRecognition | Claudia widget |
| `thought-no-ai.php` | Inline SpeechRecognition | Claudia widget |
| `talk/brainstorm.php` | Inline SpeechRecognition | Claudia widget |
| `c-guide.php` | Inline STT + TTS | Replaced entirely by Claudia |
| `api/c-guide.php` | Inline STT + TTS | Deleted (dead) |
| `z-states/ct/putnam/index.html` | Inline SpeechRecognition | Becomes .php, gets Claudia via footer |
| `z-states/ct/woodstock/index.html` | Same | Same |
| `z-states/ct/brooklyn/index.html` | Same | Same |
| `z-states/ct/putnam/index-old2.php` | Inline SpeechRecognition | Deleted (old version) |

### PHP includes replaced

| Include | Replaced by |
|---------|------------|
| `includes/c-widget.php` | New `includes/claudia-widget.php` |
| `includes/talk-stream.php` | Claudia widget in talk mode |
| `includes/mandate-chat.php` | Claudia widget in mandate mode |
| `includes/thought-form.php` | Claudia widget scratchpad |

### Pages modified (include swap)

| Page | Change |
|------|--------|
| `talk/index.php` | Remove talk-stream.php require |
| `elections/the-fight.php` | Remove talk-stream.php require |
| `mandate-poc.php` | Remove mandate-chat.php require |
| `voice.php` | Remove thought-form.php require |
| `thought.php` | Remove inline STT |
| `thought-no-ai.php` | Remove inline STT |
| `talk/brainstorm.php` | Remove inline STT |

## 6. Implementation Phases (Core-Out)

### Phase 1 — New Engine + Widget Shell

- Build `claudia-unified.js` with full duplex voice state machine (from voice-poc.html)
- Draggable, resizable widget shell with position/size persistence
- Earphones/speakers audio mode toggle
- Chat mode only (Q&A, navigation, page awareness)
- Pipe bridge: persistent `claude -p` ↔ listener ↔ SSE/WebSocket ↔ browser
- API fallback when pipe unavailable
- New `includes/claudia-widget.php` replaces `includes/c-widget.php`
- Popout as separate window with state sync
- **Proving ground: Putnam town page** — validate widget on a real content-rich page
- Old c-widget.php removed, all ~56 footer pages get new widget automatically

### Phase 2 — Scratchpad + Talk Mode

- Universal scratchpad (pin, curate, delete, numbered items)
- Scratchpad toggle (default per mode: OFF for chat, ON for talk)
- Talk mode: continuous dictation, brainstorming, save to idea_log
- Talk voice commands (save idea, pin, read back)
- Migrate `talk/index.php` — remove talk-stream.php include
- Migrate `elections/the-fight.php` — remove talk-stream.php include
- Migrate `voice.php` — remove thought-form.php include
- Delete `assets/talk-stream.js`, `includes/talk-stream.php`, `includes/thought-form.php`
- Remove inline STT from `thought.php`, `thought-no-ai.php`, `talk/brainstorm.php`

### Phase 3 — Mandate Mode

- Mandate mode: refinement dialog, government level saving (federal/state/town/private)
- Mandate voice commands (save federal, read my mandate, etc.)
- Tone/sentiment auto-tagging
- Save bar with level selector in scratchpad
- Town page context: pre-select town level, load boards/budget/schools into Claudia's awareness
- Aggregation readback ("what does CT-2 want?")
- Migrate `mandate-poc.php` — remove mandate-chat.php include
- Delete `assets/mandate-chat.js`, `includes/mandate-chat.php`

### Phase 4 — Page Cleanup

- Convert town HTML pages to PHP (putnam/index.html, woodstock/index.html, brooklyn/index.html)
- Delete `c-guide.php`, `api/c-guide.php`
- Delete `z-states/ct/putnam/index-old2.php`
- Remove old JS files: `claudia-core.js`, `claudia-auth.js`, `claudia-onboarding.js`, `claudia-popout.js`
- Clean up old CSS files (`claudia.css`, `claudia-popout.css`, `mandate-chat.css`)
- Verify every page that had voice still works through unified widget

## 7. Data & Storage

### Browser (localStorage)

| Key | Purpose |
|-----|---------|
| `claudia_position` | Widget x/y coordinates |
| `claudia_size` | Widget width/height |
| `claudia_mode` | Current mode (chat/talk/mandate) |
| `claudia_scratchpad` | Toggled on/off state |
| `claudia_audio_mode` | earphones / speakers |
| `claudia_voice_mode` | voice / text / both |
| `claudia_session` | Session ID (crypto.randomUUID) |
| `claudia_history_{session}` | Conversation messages + pinned ideas |
| `claudia_websearch` | Web search toggle (default OFF) |

### Database (existing tables, no schema changes needed)

| Table | Used by | Purpose |
|-------|---------|---------|
| `idea_log` | Talk + Mandate modes | Save ideas and mandates (category distinguishes: idea, mandate-federal, mandate-state, mandate-town) |
| `idea_log.tags` | Mandate mode | Tone/sentiment JSON tags |
| `idea_log.parent_id` | Mandate mode | Refinement dialog threading |
| `site_settings` | Admin | `claudia_widget_enabled`, `claudia_local_enabled` |
| `users.claudia_enabled` | Per-user toggle | User can disable Claudia |
| `conversation_history` | API | Chat history for clerk context |

### Pipe (no database)

- `claude -p` process maintains its own context window
- No history replay needed — the process remembers the conversation
- Session starts when pipe connects, ends when it disconnects
- Fallback to API replays from localStorage history

### Page Context (passed at render time)

```php
$claudiaConfig = [
    'context' => 'town',
    'mode_default' => 'chat',
    'mode_available' => ['chat', 'talk', 'mandate'],
    'town_id' => 119,
    'town_name' => 'Putnam',
    'data' => [/* boards, vacancies, budget, schools */]
];
```

Each page decides which modes are available and what context Claudia gets. Pages without config get chat mode only with general context.

## 8. File Structure

### New files

```
assets/claudia/claudia-unified.js    — One voice engine, one widget, all modes
assets/claudia/claudia-unified.css   — Consolidated styles (widget, scratchpad, modes)
includes/claudia-widget.php          — New widget include (replaces c-widget.php)
```

### Deleted after all phases complete

```
# Voice engines
assets/claudia/claudia-core.js
assets/claudia/claudia-auth.js
assets/claudia/claudia-onboarding.js
assets/claudia/claudia-popout.js
assets/claudia/claudia.css
assets/claudia/claudia-popout.css
assets/mandate-chat.js
assets/mandate-chat.css
assets/talk-stream.js

# PHP includes
includes/c-widget.php
includes/talk-stream.php
includes/mandate-chat.php
includes/thought-form.php

# Dead pages
c-guide.php
api/c-guide.php
z-states/ct/putnam/index-old2.php

# Converted .html → .php (inline STT removed)
z-states/ct/putnam/index.html
z-states/ct/woodstock/index.html
z-states/ct/brooklyn/index.html
```

### Modified (include swap)

```
includes/footer.php          — require claudia-widget.php instead of c-widget.php
claudia.php                  — popout loads claudia-unified.js
talk/index.php               — remove talk-stream.php require
talk/brainstorm.php          — remove inline STT
elections/the-fight.php      — remove talk-stream.php require
mandate-poc.php              — remove mandate-chat.php require
voice.php                    — remove thought-form.php require
thought.php                  — remove inline STT
thought-no-ai.php            — remove inline STT
```

### Unchanged

```
api/claude-chat.php              — backend stays the same (pipe or API)
includes/ai-context.php          — page context builder stays
scripts/maintenance/claudia-*    — local listener + tunnel unchanged
```
