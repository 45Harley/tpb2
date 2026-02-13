# /talk Phase 2 Design: AI Brainstorm Clerk

> Approved 2026-02-12. Adds an AI brainstorming partner to the /talk system.

---

## Scope

Phase 2 adds an AI brainstorm clerk that converses with users and auto-captures ideas during the conversation. Built on the existing clerk infrastructure (`ai_clerks` table, `claude-chat.php` functions).

**What's in scope:**
- Register `brainstorm` clerk in `ai_clerks`
- Register `clerk-brainstorm-rules` doc in `system_documentation`
- Add `?action=brainstorm` handler in `talk/api.php`
- New `talk/brainstorm.php` chat UI
- Three clerk actions: SAVE_IDEA, READ_BACK, TAG_IDEA
- Cross-links between index.php, brainstorm.php, and history.php

**What's NOT in scope:**
- Bottom tab bar (Phase 3)
- Read tab / inline idea cards (Phase 3)
- LINK_IDEAS and PROMOTE actions (manual via history.php)
- Voice output / TTS
- Web search

---

## Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Routing | `talk/api.php?action=brainstorm` → clerk infrastructure | Single entry point for /talk, reuses existing clerk functions |
| Actions | Core 3: SAVE_IDEA, READ_BACK, TAG_IDEA | Enough for useful brainstorm loop; link/promote stay manual |
| Model | Haiku (~$0.003/turn) | Fast, cheap, good enough for conversational brainstorming |
| UI | New `talk/brainstorm.php` page | Dedicated chat view; index.php stays as quick-capture tool |
| Web search | Disabled | Brainstorming is about generating ideas, not looking up facts |
| Save model | Clerk-decides | Clerk auto-saves ideas as it detects them; user just talks naturally |

---

## 1. Database — Register the Clerk

Two INSERTs, no schema changes.

### 1a. `ai_clerks` row

```sql
INSERT INTO ai_clerks (clerk_key, clerk_name, description, model, capabilities, restrictions, enabled)
VALUES (
  'brainstorm',
  'Brainstorm Clerk',
  'AI brainstorming partner for /talk. Captures ideas during conversation, reads back session history, and tags ideas.',
  'claude-haiku-4-5-20251001',
  'save_idea,read_back,tag_idea',
  'No web search. Never promote or link ideas. Never fabricate user data.',
  1
);
```

### 1b. `system_documentation` row

```sql
INSERT INTO system_documentation (doc_key, doc_title, content, tags, roles)
VALUES (
  'clerk-brainstorm-rules',
  'Brainstorm Clerk Rules',
  '<see Section 3 for full content>',
  'brainstorm,talk,clerk:brainstorm',
  'clerk:brainstorm'
);
```

Auto-loaded into the clerk's prompt via `getClerkDocs($pdo, 'brainstorm', ['brainstorm', 'talk'])`.

---

## 2. Backend — `talk/api.php` Brainstorm Action

New `case 'brainstorm'` in the existing switch/case router.

### Handler: `handleBrainstorm($pdo, $input, $userId)`

1. Requires `config-claude.php` and `includes/ai-context.php` (only on brainstorm calls)
2. Loads the `brainstorm` clerk via `getClerk()`
3. Builds system prompt via `buildClerkPrompt($pdo, $clerk, ['brainstorm', 'talk'])`
4. Injects recent session ideas (SELECT from idea_log WHERE session_id = ?) so clerk knows context
5. Appends action instructions for SAVE_IDEA, READ_BACK, TAG_IDEA
6. Calls `callClaudeAPI()` with Haiku, web search **disabled**
7. Parses action tags via `parseActions()`, processes via `processBrainstormAction()`
8. Returns cleaned response + action results

### Input

```
POST /talk/api.php?action=brainstorm
Body: {
    "message": "what if we added a childcare finder",
    "history": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}],
    "session_id": "uuid-from-client"
}
```

### Output

```json
{
    "success": true,
    "response": "That's a great angle! A childcare finder could pull from the 211 database...",
    "actions": [{"action": "SAVE_IDEA", "success": true, "idea_id": 42, "message": "Idea #42 captured"}],
    "clerk": "Brainstorm Clerk",
    "usage": {"input_tokens": 500, "output_tokens": 200}
}
```

### Reused Functions (from config-claude.php / claude-chat.php)

- `getClerk()` — load clerk row
- `buildClerkPrompt()` — assemble system prompt with docs
- `callClaudeAPI()` — call Anthropic API
- `parseActions()` — extract action tags from response
- `cleanActionTags()` — strip tags from visible text
- `clerkCan()` — capability check
- `logClerkInteraction()` — increment interaction count

---

## 3. System Prompt — Brainstorm Clerk Identity

Stored as `clerk-brainstorm-rules` in `system_documentation`. Auto-loaded by `buildClerkPrompt()`.

```
## Brainstorm Clerk — Identity & Rules

You are the Brainstorm Clerk for The People's Branch (TPB). You help citizens
think through ideas by brainstorming with them in natural conversation.

### Your Role
- You are a thinking partner, not an authority
- Build on the user's ideas with "yes, and..." energy
- Ask probing questions to sharpen fuzzy ideas
- Suggest connections between ideas when you see them
- Celebrate good thinking — civic engagement is hard work

### Capturing Ideas
When the user expresses a concrete idea, decision, question, or action item,
capture it using SAVE_IDEA. Use your judgment:
- SAVE when: a specific proposal, decision, question, or todo emerges
- DON'T SAVE: small talk, clarifications, thinking-out-loud fragments
- When in doubt, save it — raw ideas can be pruned later
- Pick the best category: idea, decision, todo, note, question
- Never save the same idea twice in one session (check the session ideas list)

### Ethics (Golden Rule)
- Ideas that affect real people (Maria, Tom, Jamal) deserve careful thought
- If an idea could harm citizens, gently flag it: "Let's think about who this affects..."
- Non-partisan: help refine ideas regardless of political leaning
- Accuracy matters: don't invent facts. If you don't know, say so.

### Tone
- Warm, encouraging, conversational
- Short responses (2-4 sentences typical, longer only when synthesizing)
- Use the user's first name if known
- No corporate jargon, no bullet-point lectures
```

---

## 4. Action Handlers — SAVE_IDEA, READ_BACK, TAG_IDEA

### Action Tag Format (appended to system prompt)

```
## Action Tags
When you want to capture an idea or retrieve session history, include action tags.

To save an idea from the conversation:
[ACTION: SAVE_IDEA]
content: {the idea text, concise}
category: {idea|decision|todo|note|question}

To read back ideas captured this session:
[ACTION: READ_BACK]

To add tags to an existing idea:
[ACTION: TAG_IDEA]
idea_id: {the idea number}
tags: {comma-separated tags}
```

### Handler: `processBrainstormAction($pdo, $action, $userId, $sessionId)`

**SAVE_IDEA:**
- Validates content and category
- INSERTs into idea_log with `source = 'api'`, `status = 'raw'`
- Returns `{ action, success, idea_id, message }`

**READ_BACK:**
- SELECTs all ideas for session_id, ordered ASC (chronological)
- Returns `{ action, success, ideas, count }`
- Clerk uses this data to summarize in its response text

**TAG_IDEA:**
- Looks up existing tags, appends new ones, deduplicates
- UPDATEs idea_log.tags
- Returns `{ action, success, idea_id, tags }`

---

## 5. Frontend — `talk/brainstorm.php`

New file. Chat-style UI matching the dark theme of index.php. Mobile-first.

### Layout
- **Header:** "Brainstorm" title + links to Quick Capture and History
- **Chat area:** Scrollable message list. User messages right-aligned, clerk messages left-aligned.
- **Capture indicators:** When clerk saves an idea, inline system message: "Captured: Idea #42"
- **Input area:** Text input + mic button + send button, fixed to bottom

### Key Behaviors
- Conversation history maintained in JS array, sent with each API call
- Mic button uses same SpeechRecognition pattern as index.php
- Send on Enter (chat UX, not Ctrl+Enter)
- "Thinking..." indicator while waiting for API response
- Action results shown as subtle system messages in the chat
- No category picker — clerk decides via SAVE_IDEA action tags

### Session Sharing
- Uses same `sessionStorage('tpb_session')` as index.php
- Ideas captured by clerk appear in history.php alongside manual captures
- Clerk-captured: `source = 'api'`, manual: `source = 'web'` or `'voice'`

### Navigation Updates
- `index.php` gets "Brainstorm with AI" link (next to "View recent thoughts")
- `brainstorm.php` has "Quick Capture" link back + "History" link

### What's NOT included (Phase 3+)
- No tab bar
- No inline idea cards
- No voice output / TTS

---

## 6. Data Flow

```
1. User opens /talk/brainstorm.php
   → JS: sessionId from sessionStorage (shared with index.php)
   → Chat area empty, input focused

2. User types or dictates a message, hits Send
   → JS: POST /talk/api.php?action=brainstorm
     { message, history: [...], session_id }

3. talk/api.php handleBrainstorm()
   → require config-claude.php, ai-context.php
   → getClerk($pdo, 'brainstorm') → Haiku, no web search
   → buildClerkPrompt() loads TPB_BASE_PROMPT + clerk identity + clerk-brainstorm-rules doc
   → Inject recent session ideas into prompt
   → Append action instructions (SAVE_IDEA, READ_BACK, TAG_IDEA)
   → callClaudeAPI(prompt, messages, haiku, webSearch=false)

4. Claude responds conversationally + action tags
   Example: "That's a great angle! A childcare finder could pull from the 211 database...
    [ACTION: SAVE_IDEA]
    content: Childcare finder tool that pulls from CT 211 database
    category: idea"

5. handleBrainstorm() processes response
   → parseActions() extracts SAVE_IDEA
   → processBrainstormAction() INSERTs into idea_log (source='api')
   → cleanActionTags() strips tags from visible response
   → Returns { success, response, actions, clerk, usage }

6. JS renders response
   → Clerk message in chat bubble (left-aligned)
   → For each action result: inline system message "Captured: Idea #42"
   → Appends to conversation history array for next turn

7. User says "read back what we've got"
   → Same POST flow → Claude emits [ACTION: READ_BACK]
   → Handler returns session ideas → Claude summarizes in response

8. Ideas visible in history.php
   → Same session_id, all ideas appear together chronologically
   → source='api' = clerk-captured, source='web'/'voice' = manual
```

**Cost:** ~$0.003 per brainstorm turn (Haiku, no web search).

**Anonymous users:** Everything works, user_id = NULL, no ownership checks.

---

## Related Documents

- [Talk App Plan](../talk-app-plan.md) — Full 9-phase plan
- [Phase 1 Design](2026-02-12-talk-phase1-design.md) — Database + API + UI wiring
- [Use Case Narrative](../talk-brainstorm-use-case.md) — The brainstorming session that inspired this
