# TPB Playwright Tests - Ethics First

## Philosophy

These tests embody the **Golden Rule** foundation of TPB:

> "Do to others what you would have them do to you."

We test for **Maria, Tom, and Jamal** — real people who depend on TPB being accurate, clear, and trustworthy.

---

## Test Structure

### 1. `nav-user-id.spec.js`
Tests the user ID display feature (technical correctness)

**What we're testing:**
- User ID displays correctly
- Non-clickable (just information)
- Styled appropriately
- Works on mobile

### 2. `ethics.spec.js` ⭐ **MOST IMPORTANT**
Tests Golden Rule compliance

**The 5 Quality Standards:**

1. **Accuracy over speed**
   - Benefit amounts are specific ("$9,600/year" not "financial help")
   - Maria gets rejected if dollar amounts are wrong

2. **Official sources (.gov)**
   - All benefits link to .gov sites, not Wikipedia
   - Tom needs to trust the information

3. **Plain language**
   - No unexplained jargon (CHFA → "Connecticut Housing Finance Authority (CHFA)")
   - Tom (67, retired) can understand without a dictionary

4. **Non-partisan**
   - No words like "waste", "bloated", "radical", "extreme"
   - Serve ALL citizens (both parties trust the data)

5. **Cite sources**
   - Benefits pages have sources section
   - Future volunteers can update when data changes

**Persona Tests:**
- Maria (34, single mom) can find childcare help
- Tom (67, retired) can understand senior programs clearly
- Jamal (22, first-time buyer) can find homeownership help

---

## Running Tests

```bash
# Run all tests
npm test

# Run with visible browser (see what's happening)
npm run test:headed

# Debug tests
npm run test:debug

# Run only ethics tests
npm run test:ethics

# Run with UI (interactive)
npm run test:ui
```

---

## Test Philosophy

**Traditional testing:**
- "Does the code work?"
- "Are there bugs?"
- "Does it meet requirements?"

**TPB ethics-first testing:**
- "Would Maria get rejected because our benefit amount is wrong?"
- "Can Tom understand this without calling his grandson?"
- "Is this serving ALL citizens, or just one party?"
- "Are we using trustworthy sources, or convenient ones?"

---

## When Tests Fail

**If ethics tests fail:**

❌ **Partisan language detected:**
- Review the content
- Rewrite to describe, not editorialize
- Ask: "Would both parties trust this?"

❌ **Unexplained jargon:**
- Translate acronyms first: "CHFA" → "Connecticut Housing Finance Authority (CHFA)"
- Think of Tom (67) trying to read this

❌ **No .gov sources:**
- Replace Wikipedia links with official sources
- Maria needs accurate info, not convenient info

❌ **Unclear benefit amounts:**
- Be specific: "Up to $15,000" not "financial assistance"
- Maria's application depends on this

---

## Adding New Tests

When building new features, ask:

1. **For Maria:** "Will this help her find the benefits she needs?"
2. **For Tom:** "Can he understand this without frustration?"
3. **For Jamal:** "Is the information accurate and complete?"
4. **For ALL:** "Is this serving everyone, regardless of politics?"

Then write a test that verifies it.

---

## Test Reports

After running tests:
```bash
npx playwright show-report
```

Opens a detailed HTML report showing:
- Which tests passed/failed
- Screenshots of failures
- Performance metrics
- Ethics compliance score

---

## Philosophy > Technology

**Remember:**
- A fast page with wrong benefit amounts = **harmful to Maria**
- A beautiful page with partisan language = **divisive**
- A clever page with unexplained jargon = **inaccessible to Tom**

**But:**
- A simple page with accurate .gov-sourced benefits = **helpful to ALL**

---

## The Golden Rule in Action

Every test in this directory asks:

**"Would I want my mom (Maria), my grandfather (Tom), or my younger sibling (Jamal) to use this?"**

If the answer is no, the test should fail.

That's the Golden Rule as code.

---

**Related Documentation:**
- [Volunteer Orientation](../docs/state-builder/VOLUNTEER-ORIENTATION.md) - Part 0: Ethics Foundation
- [Ethics Foundation](../docs/state-builder/ETHICS-FOUNDATION.md) - Deep dive into Golden Rule
- [CLAUDE.md](../CLAUDE.md) - Project values and standards
