# Input Inspector - Quick Reference Card

## The Problem in 30 Seconds

**OLD WAY (without Input Inspector):**
```
User sees: {query_products.output.data.products.edges}
User writes: . | .edges[]
Pipeline fails: "Cannot index array with string 'edges'"
User: 😡 "But the variable shows .edges exists!"
```

**NEW WAY (with Input Inspector):**
```
User sees STDIN: {"data": {"products": {"edges": [...]}}}
User writes: . | .data.products.edges[]
Pipeline works: ✅
User: 😊 "Oh! I see the full structure now!"
```

## Visual Comparison

```
┌────────────────────────────────────────────────────────────────┐
│                    BEFORE (Confusing!)                         │
├────────────────────────────────────────────────────────────────┤
│                                                                │
│  Step Editor for: store_original_state (jq parser)            │
│                                                                │
│  ┌────────────────────────────────────────────────┐           │
│  │ 📦 Variable Browser                            │           │
│  │                                                │           │
│  │ {query_products.output.data.products.edges}    │ ← Click   │
│  │ {query_products.output.extensions.cost}        │           │
│  └────────────────────────────────────────────────┘           │
│                                                                │
│  JQ Expression:                                                │
│  ┌────────────────────────────────────────────────┐           │
│  │ . | .edges[]                                   │ ← WRONG!  │
│  └────────────────────────────────────────────────┘           │
│                                                                │
│  [Save]  ← Pipeline will fail                                 │
│                                                                │
│  User has NO IDEA what jq actually receives!                  │
│                                                                │
└────────────────────────────────────────────────────────────────┘

                               vs

┌────────────────────────────────────────────────────────────────┐
│                    AFTER (Crystal Clear!)                      │
├────────────────────────────────────────────────────────────────┤
│                                                                │
│  Step Editor for: store_original_state (jq parser)            │
│                                                                │
│  ┌────────────────────────────────────────────────┐           │
│  │ 🔍 Input Inspector  [STDIN: Previous Step ▼]  │ ← NEW!    │
│  │                                                │           │
│  │ Tabs: [Data Preview] [Test] [Compare]         │           │
│  │                                                │           │
│  │ STDIN (what jq receives):                      │           │
│  │ {                                              │           │
│  │   "data": {                                    │           │
│  │     "products": {                              │           │
│  │       "edges": [...]  ← AH HA! Full structure  │           │
│  │     }                                          │           │
│  │   }                                            │           │
│  │ }                                              │           │
│  └────────────────────────────────────────────────┘           │
│                                                                │
│  ┌────────────────────────────────────────────────┐           │
│  │ 📦 Variable Browser                            │           │
│  │                                                │           │
│  │ {query_products.output.data.products.edges}    │           │
│  │ {query_products.output.extensions.cost}        │           │
│  └────────────────────────────────────────────────┘           │
│                                                                │
│  JQ Expression:                                                │
│  ┌────────────────────────────────────────────────┐           │
│  │ . | .data.products.edges[]                     │ ← RIGHT!  │
│  └────────────────────────────────────────────────┘           │
│                                                                │
│  [Test Expression] ← See output before saving!                │
│                                                                │
│  Output Preview:                                               │
│  ✅ [{node: {...}}, {node: {...}}]                            │
│                                                                │
│  [Save]  ← Pipeline will work!                                │
│                                                                │
└────────────────────────────────────────────────────────────────┘
```

## STDIN vs Variables - The Golden Rule

```
┌──────────────────────────────────────────────────────────────┐
│                    STDIN vs VARIABLES                         │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  STDIN                          VARIABLES                    │
│  ══════                         ═════════                    │
│                                                               │
│  What: Full output              What: Specific paths         │
│  From: Previous/getfrom step    From: Exported by user       │
│  Used by: jq/bash/parser        Used in: Config fields       │
│  Syntax: Raw JSON               Syntax: {step.path}          │
│                                                               │
│  ┌─────────────────────┐       ┌──────────────────────────┐ │
│  │ {                   │       │ {query_products.output   │ │
│  │   "data": {         │       │  .data.products.edges}   │ │
│  │     "products": {   │       │                          │ │
│  │       "edges": [...] │       │ Used like:              │ │
│  │     }               │       │ url: "shop/{context.id}" │ │
│  │   }                 │       │                          │ │
│  │ }                   │       │ Gets REPLACED before     │ │
│  │                     │       │ execution                │ │
│  │ Piped to jq/bash    │       │                          │ │
│  └─────────────────────┘       └──────────────────────────┘ │
│                                                               │
│  Example jq:                   Example config:               │
│  . | .data.products.edges[]    command: "echo {var}"         │
│      ↑                                      ↑                │
│      Uses STDIN                             Uses VARIABLE    │
│                                                               │
└──────────────────────────────────────────────────────────────┘
```

## The Three Tabs

```
┌────────────────────────────────────────────────────────────┐
│  Tab 1: DATA PREVIEW                                       │
├────────────────────────────────────────────────────────────┤
│  Purpose: See what STDIN looks like                        │
│  Actions: Copy, Download, Inspect structure                │
│  Best for: Understanding data shape                        │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│  Tab 2: TEST PLAYGROUND                                    │
├────────────────────────────────────────────────────────────┤
│  Purpose: Test jq expressions before running pipeline      │
│  Actions: Write jq, test, see output                       │
│  Best for: Debugging transformations                       │
└────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────┐
│  Tab 3: STDIN VS VARIABLES                                 │
├────────────────────────────────────────────────────────────┤
│  Purpose: Understand the difference                        │
│  Actions: Compare side-by-side                             │
│  Best for: Learning data flow                              │
└────────────────────────────────────────────────────────────┘
```

## Workflow Cheat Sheet

```
┌─────────────────────────────────────────────────────────────┐
│              PERFECT WORKFLOW FOR JQ STEPS                  │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  1. Open step editor                                        │
│     ↓                                                       │
│  2. Click "Input Inspector"                                 │
│     ↓                                                       │
│  3. Look at "Data Preview" tab                              │
│     → Understand STDIN structure                            │
│     ↓                                                       │
│  4. Switch to "Test Playground" tab                         │
│     ↓                                                       │
│  5. Write jq expression incrementally:                      │
│     .data            ← Test                                 │
│     .data.products   ← Test                                 │
│     .data.products.edges[]  ← Test                          │
│     .data.products.edges[] | .node  ← Test                  │
│     ↓                                                       │
│  6. Verify output looks correct                             │
│     ↓                                                       │
│  7. Copy expression to step config                          │
│     ↓                                                       │
│  8. Save step                                               │
│     ↓                                                       │
│  9. Run pipeline                                            │
│     ↓                                                       │
│  ✅ SUCCESS ON FIRST TRY!                                   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Common Mistakes & Fixes

```
❌ MISTAKE 1: Using variable syntax in jq
   Bad:  {query_products.output.data.products}
   Good: .data.products

❌ MISTAKE 2: Wrong nesting level
   Bad:  .products.edges[]
   Good: .data.products.edges[]  ← Check STDIN!

❌ MISTAKE 3: Not testing before saving
   Bad:  Write expression → Save → Run → Fail → Debug
   Good: Write expression → Test → See output → Save → Run → Success

❌ MISTAKE 4: Confusing STDIN with exported variables
   Bad:  "I exported .edges, why doesn't .edges[] work?"
   Good: "Exported vars are for configs, jq uses full STDIN"

❌ MISTAKE 5: Forgetting input_source affects STDIN
   Bad:  Assuming STDIN is from previous step (might be getfrom)
   Good: Check Input Inspector badge: "STDIN: from query_products"
```

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Tab` | Switch between tabs |
| `Ctrl+Enter` | Test expression (in playground) |
| `Ctrl+C` | Copy STDIN (when preview focused) |
| `Space` | Expand/collapse panel (when header focused) |

## When to Use Each Feature

```
┌─────────────────────────────────────────────────────────────┐
│  WHEN TO USE DATA PREVIEW                                   │
├─────────────────────────────────────────────────────────────┤
│  ✓ First time seeing this data                              │
│  ✓ Data structure is complex/nested                         │
│  ✓ Need to reference while writing jq                       │
│  ✓ Want to download for external analysis                   │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  WHEN TO USE TEST PLAYGROUND                                │
├─────────────────────────────────────────────────────────────┤
│  ✓ Writing new jq expression                                │
│  ✓ Debugging why expression fails                           │
│  ✓ Trying different transformation approaches               │
│  ✓ Learning jq syntax with real data                        │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  WHEN TO USE STDIN VS VARIABLES                             │
├─────────────────────────────────────────────────────────────┤
│  ✓ Confused about difference                                │
│  ✓ New to pipelines                                         │
│  ✓ Pipeline failing and not sure why                        │
│  ✓ Need to explain to someone else                          │
└─────────────────────────────────────────────────────────────┘
```

## Troubleshooting Decision Tree

```
Pipeline step failing?
    │
    ├─ Is it a jq/parser step?
    │  │
    │  ├─ YES → Open Input Inspector
    │  │        │
    │  │        ├─ Check Data Preview
    │  │        │  └─ Does STDIN match what you expected?
    │  │        │     │
    │  │        │     ├─ NO → Fix input_source setting
    │  │        │     │
    │  │        │     └─ YES → Go to Test Playground
    │  │        │              └─ Test your expression
    │  │        │                 │
    │  │        │                 ├─ Error? → Read error, fix expression
    │  │        │                 │
    │  │        │                 └─ Works? → Copy to step, save, run
    │  │
    │  └─ NO → Check other debugging methods
    │
    └─ Still confused?
       └─ Go to "STDIN vs Variables" tab
          └─ Read explanation
          └─ Compare both sides
          └─ Aha moment! 💡
```

## Success Indicators

**You're doing it right when:**
- ✅ You always check Input Inspector before writing jq
- ✅ You test expressions in playground first
- ✅ Your pipelines succeed on first run
- ✅ You understand STDIN vs variables clearly
- ✅ You can explain the difference to others

**You need more practice when:**
- ❌ You skip Input Inspector and go straight to writing jq
- ❌ You run the pipeline multiple times to debug
- ❌ You're confused why variables don't work in jq
- ❌ You rely on trial-and-error

## One-Sentence Summary

**Input Inspector shows you the ACTUAL data your jq expression will receive, so you can test and debug BEFORE running the pipeline.**

---

## Quick Links

| Document | Purpose |
|----------|---------|
| `INPUT_INSPECTOR_DESIGN.html` | Visual mockup (open in browser) |
| `INPUT_INSPECTOR_IMPLEMENTATION.md` | Developer guide |
| `INPUT_INSPECTOR_ARCHITECTURE.md` | Architecture diagrams |
| `INPUT_INSPECTOR_USER_GUIDE.md` | End-user manual with examples |
| `INPUT_INSPECTOR_SUMMARY.md` | Implementation checklist |
| `INPUT_INSPECTOR_QUICK_REF.md` | This file |

**Print this quick ref and keep it handy while building pipelines!**
