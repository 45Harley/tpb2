# Deconstruction Arc — Full Design

**Date**: 2026-03-08
**File**: `mockups/deconstruction.html` (experiment branch, c:\tpb)
**Status**: Design approved, pending implementation

## Concept

An interactive animated history of American democracy told through a visual grammar:
**words → nebula blobs → spheres → friction → reform → repeat**.

Ideas always precede action. The canvas has memory — rejected ideas drop seeds
that sprout as future conflicts. The user controls all pacing (click-to-continue),
and dwell time per phase is a signal of engagement.

## Visual Grammar

| Stage | Visual | Meaning |
|-------|--------|---------|
| **Words** | Floating, glowing text on dark canvas | Raw ideas, unformed |
| **Nebula blobs** | Fuzzy cloud/gas shapes, semi-transparent, undulating edges | Ideas clustering, still vague |
| **Spheres** | Solid radial-gradient spheres (existing style) | Crystallized institutions |
| **Friction** | Cracks, red glow, violent jitter on sphere | Resistance to change |
| **Seeds** | Dim embers along canvas edges/bottom | Dormant negative ideas, waiting to sprout |
| **Sprouting** | Seed grows back up as dark blob, collides with sphere | Past ignored problems returning |
| **Reform** | Sphere repairs, glows brighter, scars may remain | Imperfect progress |

### The Seed Mechanic

During every idea swirl, BOTH positive and negative words appear:
- **Positive words** survive the swirl, grow brighter, cluster into nebula blobs, condense into spheres
- **Negative words** pop/shrink during the swirl BUT drop **seeds** — small dim embers that sink to the canvas edges
- Seeds are visible throughout subsequent phases (canvas has memory)
- In later phases, specific seeds **sprout** — grow back as dark blobs that create friction
- A seed can partially pop in one era and re-sprout in a later era (e.g., "Slavery" → Civil War → Civil Rights)

## Phase Sequence

All transitions are **click-to-continue**. No auto-advancing.

### Act I: The Founding

#### Phase 1.0 — Opening
- Dark canvas. MBE quote fades in: *"the focus of ideas"*
- Hint: "Click to begin"

#### Phase 1.1 — Idea Swirl (The Enlightenment)
- Words float onto canvas from edges, drifting, glowing:
  - **Positive** (bright, gold/white): "Liberty", "Equality", "Consent", "Self-Governance", "Natural Rights", "Social Contract", "Common Good"
  - **Negative** (dim red/gray): "Tyranny", "Slavery", "Property over People", "Men Only", "King's Rule", "Landed Gentry"
- Positive words pulse brighter, drift toward center
- Negative words shrink/pop — but each drops a **seed** (small ember) that sinks to canvas bottom/edges
- Duration: user-paced, click to continue after swirl stabilizes

#### Phase 1.2 — Ideas Condense (Nebula Formation)
- Surviving positive words cluster into 2-3 **nebula blobs** (cloud/gas shapes)
  - Fuzzy edges, semi-transparent, undulating
  - Labels emerge within: "Rights of Man", "Social Contract", "Self-Governance"
- Seeds remain visible as dim embers at edges
- Click to continue

#### Phase 1.3 — The People Form
- Nebula blobs contract, edges tighten, merge and condense into the gold **"The People"** sphere
- Clean, solid — ideas have crystallized into collective identity
- Subtitle: "circa 1750"
- Click to continue

#### Phase 1.4 — Scatter to Towns
- (Existing) The People sphere jitters and splits into 13 colonial town spheres
- Organized grid layout (3 rows), teal color
- Narration: "The people settled into towns and cities."
- Click to continue

#### Phase 1.5 — Coalesce to Colonies
- (Existing) Towns coalesce into 13 colony spheres
- 2 rows (7+6), blue color, 3.5s transition
- Narration: "The towns coalesced into thirteen colonies."
- Click to continue

#### Phase 1.6 — Forge Constitutional Government
- (Existing) Colonies coalesce into single gold "Constitutional Government" sphere
- 4s transition, "We the People" subtitle, "E Pluribus Unum" title
- Click to continue

#### Phase 1.7 — Interactive Deconstruction
- (Existing) Click spheres to split recursively:
  - Constitutional Government → Branches + Jurisdictions
  - Branches → Congress, Executive, Judicial → deeper
  - Jurisdictions → Federal, States, Towns
- Drag to rearrange, fully interactive
- When fully deconstructed → auto-transition to Phase 1.8

#### Phase 1.8 — "A More Perfect Union"
- (Existing) Closing frame: "A more perfect union. The building continues."
- Leaf spheres drift toward center
- BUT: this is no longer the END — it's a transition. Click to continue into Act II.

### Act II: The Friction

The Government sphere reassembles (reverse of deconstruction closing).
Seeds from Act I are still visible at edges. Now they sprout.

#### Phase 2.1 — Civil War Idea Swirl
- New positive words swirl: "Abolition", "Freedom", "All men created equal — *all*", "Union"
- The **"Slavery" seed sprouts** — grows from ember back into a dark blob
- Dark blob collides with Government sphere
- Click to continue

#### Phase 2.2 — Civil War Friction
- Government sphere develops cracks/fissures, red glow, violent jitter
- The sphere nearly splits in two (North/South visual)
- Then — partial repair. Brighter gold, but **scars visible**
- Narration: "The freedom slog begins."
- "Slavery" seed pops — but drops a smaller seed: "Inequality" (not fully dead)
- Click to continue

#### Phase 2.3 — Robber Barons
- **"Property over People" seed sprouts**
- New words: "Monopoly", "Labor Rights", "Trust-Busting", "The People's Money"
- Dark blobs press against sphere from outside
- Sphere resists, then reforms — antitrust era
- Narration: "Concentrated power, confronted."
- Click to continue

#### Phase 2.4 — Women's Suffrage
- **"Men Only" seed sprouts**
- Words: "Suffrage", "Equality", "19th Amendment", "Half the People"
- Visual: half the Government sphere was subtly dimmer — now it illuminates fully
- "Men Only" seed pops FOR REAL (no new seed dropped — this one is resolved)
- Click to continue

#### Phase 2.5 — Civil Rights / DEI Restated
- **"Inequality" seed sprouts** (the smaller seed from Civil War)
- Key visual: the words are NOT new — they **echo/pulse from Act I's original swirl**
  - "Equal Protection" echoes "Equality"
  - "Voting Rights" echoes "Consent"
  - "Justice" echoes "Common Good"
- Shows these principles were always there — just not fully applied
- Sphere brightens further, scars from Civil War begin to fade
- Remaining seeds dim but some persist (the work isn't done)
- Click to continue

### Act III: The Enablement

#### Phase 3.1 — Technology
- New visual style: electric blue, network-like tendrils instead of clouds
- Words: "Internet", "Open Data", "Transparency", "Connection", "Every Voice"
- These don't form nebula blobs — they form a **web/network** shape
- The network wraps around and through the Government sphere
- Click to continue

#### Phase 3.2 — People Power
- The Government sphere cracks open — not from friction this time, but from the **inside**
- The People re-emerge. Not one sphere — many small connected spheres
- Glowing connection lines between them — a network, not a hierarchy
- The remaining edge seeds finally dissolve (technology + connection as the solvent)
- Click to continue

#### Phase 3.3 — The Building Continues (Final)
- The network resolves. TPB gold theme.
- "A more perfect union. The building continues."
- Final frame: pulsing network of connected people-spheres
- Hint: "Click to begin again" (restart from Phase 1.0)

## Narration Text (Draft)

| Phase | Narration |
|-------|-----------|
| 1.0 | *"The focus of ideas"* — Mary Baker Eddy |
| 1.1 | Ideas take hold. Not all survive. |
| 1.2 | Principles form. Still rough. Still unfinished. |
| 1.3 | The People. |
| 1.4 | The people settled into towns and cities. |
| 1.5 | The towns coalesced into thirteen colonies. |
| 1.6 | And they forged a Constitution, creating a government of the people. |
| 1.7 | Deconstructing. |
| 1.8 | A more perfect union. The building continues. |
| 2.1 | The seeds of slavery were never truly gone. |
| 2.2 | The freedom slog begins. |
| 2.3 | Concentrated power, confronted. |
| 2.4 | Half the people, finally counted. |
| 2.5 | Not new principles. The same ones — restated, reclaimed. |
| 3.1 | A new kind of power. Not concentrated. Connected. |
| 3.2 | The People re-emerge. |
| 3.3 | The building continues. |

## Swirl Word Lists

### Act I Swirl (Founding)
**Positive** (survive): Liberty, Equality, Consent, Self-Governance, Natural Rights, Social Contract, Common Good, Justice, Representation
**Negative** (pop → seed): Tyranny, Slavery, Property over People, Men Only, King's Rule, Landed Gentry

### Act II Swirls
**Civil War**: Abolition, Freedom, Union, All Men (sprouted seed: Slavery)
**Robber Barons**: Labor Rights, Trust-Busting, Fair Wages (sprouted seed: Property over People)
**Suffrage**: Suffrage, 19th Amendment, Half the People (sprouted seed: Men Only)
**Civil Rights**: Equal Protection, Voting Rights, Justice (sprouted seed: Inequality — child of Slavery)

### Act III Swirl
**Technology**: Internet, Open Data, Transparency, Connection, Every Voice, People Power

## Technical Notes

### Nebula Blob Rendering
- Multiple overlapping radial gradients with low alpha
- Perlin noise or simplex noise for undulating edges (or approximate with layered sine waves)
- Semi-transparent fill, no hard edges
- Words rendered inside with slight drift/float animation

### Seed Rendering
- Small circles (r=3-5), dim color matching their origin word
- Subtle pulse animation (breathing)
- Position: bottom edge or side edges of canvas
- On sprout: grow upward, darken, expand into blob shape

### Dwell Time Tracking
- Record `Date.now()` on each click-to-continue
- Store array of `{ phase, dwellMs }` tuples
- On completion (Phase 3.3), could POST to an API endpoint or log to console
- Total time and per-phase breakdown available

### Canvas Size
- Current: 900x600 (fixed)
- Seeds need edge/bottom space — may need slight layout awareness
- Existing sphere layouts (towns 3×5, colonies 7+6) unchanged

### State Machine
Current phases: start, jitter-people, scatter, towns, coalesce1, states, coalesce2, gov-arrive, interactive, closing, ended

New phases (prefix by act):
- `opening` — MBE quote
- `swirl-founding` — Act I idea swirl
- `condense-founding` — nebula formation
- `form-people` — blobs → People sphere
- (existing: scatter, towns, coalesce1, states, coalesce2, gov-arrive, interactive)
- `post-deconstruct` — reassemble, transition to Act II
- `swirl-civil-war`, `friction-civil-war`
- `swirl-robber-barons`, `friction-robber-barons`
- `swirl-suffrage`, `friction-suffrage`
- `swirl-civil-rights`, `friction-civil-rights`
- `swirl-technology`, `friction-technology`
- `people-power` — sphere cracks from inside
- `final` — network resolves, "the building continues"

### Seed Data Structure
```javascript
// Each seed tracks its origin, position, and which phase triggers its sprouting
{
  label: 'Slavery',
  color: '#aa3333',
  x: 120, y: 570,       // edge position
  r: 4,                  // small
  alpha: 0.3,            // dim
  sproutPhase: 'swirl-civil-war',
  childSeed: { label: 'Inequality', sproutPhase: 'swirl-civil-rights' },
  state: 'dormant'       // dormant | sprouting | popped
}
```
