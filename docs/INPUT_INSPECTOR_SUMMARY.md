# Input Inspector - Implementation Summary

## Problem Statement

**Critical visibility issue in pipeline editor:**

Users editing jq parser steps cannot see the actual STDIN data that will be piped to their jq expression. They only see "Exported Variables" which are for `{variable}` substitution, NOT the input to jq.

**This causes:**
- Failed pipelines due to incorrect jq expressions
- Confusion between STDIN vs exported variables
- Trial-and-error debugging
- Wasted time and frustration

## Solution Overview

**Input Inspector Panel** - A new component in the step editor that:

1. **Shows actual STDIN data** the step will receive (based on `input_source`)
2. **Provides live jq testing** against real data
3. **Clarifies the difference** between STDIN and exported variables
4. **Enables confident development** with preview and test before run

## Key Features

### 1. Data Preview Tab
- Shows full STDIN data from source step
- Copy/download buttons
- Data size indicator
- Clear explanation of what STDIN is
- Visual indicator of input source

### 2. Test Playground Tab (jq/parser steps)
- Live jq expression editor
- "Test Expression" button
- Instant result preview
- Error messages with context
- Test before running pipeline

### 3. STDIN vs Variables Tab
- Side-by-side comparison
- Left: Full STDIN (what jq receives)
- Right: Exported variables (for substitution)
- Clear explanation of differences
- Helps users understand data flow

### 4. Context-Aware Behavior
- Detects input source (previous, getfrom, context)
- Shows appropriate data based on source
- Handles "no STDIN" cases gracefully
- Updates when input source changes

## Files to Modify

### Frontend Files

#### `/views/pipelines/edit.php`

**Changes:**
1. Add HTML for Input Inspector panel (after line ~540)
2. Add CSS styles (append to existing `<style>` block)
3. Add JavaScript functions (append to existing `<script>` block)

**Estimated lines of code:**
- HTML: ~300 lines
- CSS: ~400 lines
- JavaScript: ~300 lines

### Backend Files

#### `/controls/Pipelines.php`

**New methods to add:**

1. **`getstdin()`** - Fetch STDIN data for preview
   - Query params: row, col, step_id, input_source, getfrom_step
   - Returns: stdin data, exported variables, source step info
   - ~80 lines

2. **`testjq()`** - Test jq expression against stdin
   - POST body: expression, stdin, csrf_token
   - Executes jq in temp environment
   - Returns: output or error
   - ~50 lines

**Estimated total: ~130 lines**

## Implementation Checklist

### Phase 1: Backend API (Day 1)

- [ ] Add `getstdin()` method to Pipelines controller
  - [ ] Parse query parameters
  - [ ] Validate pipeline ownership
  - [ ] Determine source step based on input_source
  - [ ] Fetch last pipeline run
  - [ ] Get step execution for source step
  - [ ] Extract output JSON as STDIN
  - [ ] Build exported variables map
  - [ ] Return success response
  - [ ] Handle error cases (no runs, step not executed, etc.)

- [ ] Add `testjq()` method to Pipelines controller
  - [ ] Validate CSRF token
  - [ ] Parse request body (expression, stdin)
  - [ ] Write stdin to temp file
  - [ ] Execute jq command
  - [ ] Parse jq output
  - [ ] Return success/error response
  - [ ] Clean up temp file

- [ ] Test API endpoints
  - [ ] Test getstdin with `input_source=previous`
  - [ ] Test getstdin with `input_source=getfrom`
  - [ ] Test getstdin with `input_source=context`
  - [ ] Test getstdin error cases
  - [ ] Test testjq with valid expression
  - [ ] Test testjq with invalid expression
  - [ ] Test testjq with complex jq syntax

### Phase 2: Frontend UI (Day 2)

- [ ] Add HTML markup to step editor modal
  - [ ] Input Inspector panel container
  - [ ] Collapsible header with badge
  - [ ] Tab navigation (3 tabs)
  - [ ] Data Preview tab content
  - [ ] Test Playground tab content
  - [ ] STDIN vs Variables tab content
  - [ ] Loading states
  - [ ] Empty/error states

- [ ] Add CSS styles
  - [ ] Panel container styles
  - [ ] Header gradient and hover states
  - [ ] Tab navigation styles
  - [ ] Explainer banner styles
  - [ ] Data preview code block styles
  - [ ] Toolbar button styles
  - [ ] Playground editor styles
  - [ ] Result box styles (success/error)
  - [ ] Comparison grid styles
  - [ ] Mobile responsive styles

- [ ] Add JavaScript functionality
  - [ ] Tab switching logic
  - [ ] `updateInputInspector()` function
  - [ ] `loadStdinData()` function
  - [ ] `showNoStdinData()` helper
  - [ ] `updateComparisonView()` function
  - [ ] `copyStdinToClipboard()` function
  - [ ] `downloadStdinData()` function
  - [ ] `testJqExpression()` function
  - [ ] `formatBytes()` helper
  - [ ] Event listeners (input_source change, etc.)

### Phase 3: Integration (Day 3)

- [ ] Connect to existing step editor
  - [ ] Call `updateInputInspector()` when modal opens
  - [ ] Update on input_source change
  - [ ] Update on getfrom_step change
  - [ ] Show/hide playground tab based on step_type

- [ ] Position panel correctly
  - [ ] After "Input Source" dropdown
  - [ ] Before "Variable Browser"

- [ ] Test integration
  - [ ] Open step editor → panel loads
  - [ ] Change input source → panel updates
  - [ ] Edit step → panel preserves state
  - [ ] Create new step → panel shows correctly

### Phase 4: Testing & Refinement (Day 4)

- [ ] Manual testing
  - [ ] Test with Shopify GraphQL → jq parser flow
  - [ ] Test with multiple step types
  - [ ] Test all three tabs
  - [ ] Test jq expression playground
  - [ ] Test error cases
  - [ ] Test mobile responsiveness
  - [ ] Test with large JSON payloads

- [ ] Edge cases
  - [ ] First step (no previous)
  - [ ] Context-only input
  - [ ] Missing source step
  - [ ] No pipeline runs yet
  - [ ] Invalid jq syntax
  - [ ] jq timeout
  - [ ] Network errors

- [ ] Performance
  - [ ] Large STDIN data handling
  - [ ] jq test response time
  - [ ] Panel expand/collapse smoothness

- [ ] Accessibility
  - [ ] Keyboard navigation
  - [ ] Screen reader compatibility
  - [ ] Focus management
  - [ ] ARIA labels

### Phase 5: Documentation (Day 5)

- [ ] Update user documentation
  - [ ] Add "Input Inspector" section to pipeline docs
  - [ ] Add screenshots/examples
  - [ ] Explain STDIN vs Variables concept

- [ ] Developer documentation
  - [ ] Document API endpoints
  - [ ] Document component architecture
  - [ ] Add code comments

## Estimated Effort

| Phase | Effort | Dependencies |
|-------|--------|--------------|
| Phase 1: Backend API | 4-6 hours | None |
| Phase 2: Frontend UI | 6-8 hours | Phase 1 complete |
| Phase 3: Integration | 2-3 hours | Phase 2 complete |
| Phase 4: Testing | 3-4 hours | Phase 3 complete |
| Phase 5: Documentation | 2-3 hours | Phase 4 complete |
| **Total** | **17-24 hours** | **3-4 days** |

## Success Metrics

**Before (baseline):**
- Average time to write correct jq expression: ~20 minutes (3-4 failed runs)
- User confusion rate: High (frequent support questions)
- Pipeline success rate on first run: ~40%

**After (target):**
- Average time to write correct jq expression: ~5 minutes (test before run)
- User confusion rate: Low (self-service with visual explanation)
- Pipeline success rate on first run: ~80%+

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| jq not installed on server | High | Add jq to server dependencies, check in install script |
| Large STDIN crashes browser | Medium | Limit preview to 100KB, offer download for full data |
| jq test timeout | Low | Set 5-second timeout, show clear error message |
| Old run data confuses users | Medium | Show timestamp of run, add "refresh" button |
| Mobile UI too cramped | Low | Stack comparison view vertically, reduce padding |

## Future Enhancements (Post-MVP)

**Priority 1 (Next sprint):**
- [ ] Monaco Editor for jq syntax highlighting
- [ ] Expression history (save last 5 tested expressions)
- [ ] Auto-populate jq expression from config when opening playground

**Priority 2 (Future):**
- [ ] AI jq expression suggestions based on data structure
- [ ] Visual JSON tree explorer (collapsible nodes)
- [ ] Before/after diff view for transformations
- [ ] Save expression templates library
- [ ] Data sampling for large datasets (test on first 10 items)

**Priority 3 (Nice to have):**
- [ ] Export playground session as shareable link
- [ ] Collaborative expression editing (multiple users)
- [ ] jq expression performance profiling
- [ ] Visual jq builder (drag-drop fields)

## Dependencies

**Server requirements:**
- jq installed (`apt-get install jq`)
- PHP exec() enabled
- Temp file permissions

**Browser requirements:**
- Modern browser (Chrome 90+, Firefox 88+, Safari 14+)
- JavaScript enabled
- LocalStorage for caching (optional)

**Database:**
- Existing tables: pipelines, pipelinesteps, pipelineruns, pipelinestepexecutions
- No new tables needed

## Rollback Plan

If issues arise post-deployment:

1. **Disable panel via feature flag:**
   - Add `$config['enable_input_inspector'] = false;`
   - Wrap panel HTML in conditional: `<?php if ($config['enable_input_inspector']): ?>`

2. **No database changes made:**
   - Safe to rollback without data migration

3. **Frontend-only change:**
   - Can disable by commenting out JavaScript
   - Doesn't break existing functionality

## Deployment Steps

1. **Pre-deployment:**
   - [ ] Verify jq installed on production server: `which jq`
   - [ ] Test API endpoints on staging
   - [ ] Review security (CSRF, input validation)

2. **Deployment:**
   - [ ] Merge feature branch to main
   - [ ] Deploy to staging
   - [ ] Smoke test on staging
   - [ ] Deploy to production
   - [ ] Monitor error logs

3. **Post-deployment:**
   - [ ] Test on production with real data
   - [ ] Monitor performance metrics
   - [ ] Collect user feedback
   - [ ] Iterate based on feedback

## Code Review Checklist

**Security:**
- [ ] CSRF token validated on POST endpoints
- [ ] User owns pipeline before showing STDIN
- [ ] jq expression properly escaped before exec
- [ ] Temp files cleaned up after use
- [ ] No SQL injection risks
- [ ] No XSS risks in displayed JSON

**Performance:**
- [ ] Large STDIN data handled gracefully
- [ ] jq test has timeout
- [ ] Frontend doesn't block on API calls
- [ ] Caching implemented where appropriate

**Code Quality:**
- [ ] Functions well-documented
- [ ] Error handling comprehensive
- [ ] Code follows project conventions
- [ ] No console.log() left in production
- [ ] Mobile responsive tested

## Resources

**Documentation created:**
1. `/docs/INPUT_INSPECTOR_DESIGN.html` - Visual UI mockup
2. `/docs/INPUT_INSPECTOR_IMPLEMENTATION.md` - Technical implementation guide
3. `/docs/INPUT_INSPECTOR_ARCHITECTURE.md` - Architecture and data flow diagrams
4. `/docs/INPUT_INSPECTOR_USER_GUIDE.md` - End-user documentation with examples
5. `/docs/INPUT_INSPECTOR_SUMMARY.md` - This file (implementation summary)

**External references:**
- jq manual: https://stedolan.github.io/jq/manual/
- Bootstrap 5.3 docs: https://getbootstrap.com/docs/5.3/
- RedBeanPHP docs: https://redbeanphp.com/

## Approval

- [ ] Product Owner review
- [ ] UX/UI design review
- [ ] Technical lead review
- [ ] Security review
- [ ] Approved for implementation

---

**Ready to implement!** Follow the checklist above and reference the detailed docs for each phase.
