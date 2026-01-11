# MyCTOBot UX Audit Report

**Date:** 2026-01-11
**Branch:** `feature/ux-overhaul-orchestration`

---

## Executive Summary

This audit examines the complete user journey from signup to first AI Developer job. The goal is to identify areas where we can make MyCTOBot simple enough for anyone to use while maintaining its power for advanced users.

---

## Strengths

### Visual Design
- Clean, modern Bootstrap 5 design with consistent color usage
- Good use of icons (Bootstrap Icons) for visual cues
- Card-based layouts that group related information
- Responsive design that works on mobile

### Information Architecture
- Setup status indicators with clear green/gray badges
- Tab-based agent editing interface (General, Provider, MCP, Hooks, Capabilities)
- Connected services dashboard with feature badges

### Landing Page
- Strong value proposition: "Your AI-Powered Development Team"
- Clear 3-step workflow explanation
- Social proof stats (500+ tickets, 98% PR acceptance)
- Shopify-focused positioning (clear market fit)

---

## Critical Issues

### 1. Nomenclature Inconsistencies

| Current Term | Also Called | Recommendation |
|--------------|-------------|----------------|
| `runner_type` | `provider` | **Standardize on "Provider"** |
| `shard` | `workstation` | **Standardize on "Workstation"** |
| `AI Developer` | `Enterprise` | **Use "AI Developer" consistently** |
| `Dashboard` | appears at 2 URLs | **Single dashboard at `/dashboard`** |

**Files affected:**
- `views/settings/connections.php:197` - "runner type" should be "provider"
- `views/analysis/*.php` - Mix of "Shard" and "Workstation"
- `views/admin/shards.php` - Title says "Workstation Shards" (conflicting)

### 2. Login Flow Confusion

**Issue:** "Workspace Code" field is confusing for new users
- Text "Leave blank for main site login" is unclear
- Users don't know their workspace code before first login

**Recommendation:**
- For new signups: Auto-redirect to workspace after email verification
- For returning users: Remember workspace in cookie
- Add "Find my workspace" helper link

### 3. Missing Onboarding Journey

**Current state:** Users land on dashboard with no clear next step

**Recommendation:** Implement a guided setup wizard with progress:
1. Connect GitHub ✓
2. Connect Jira ✓
3. Add Repository
4. Create Board
5. Label a ticket with `ai-dev`
6. Watch the magic happen!

### 4. Footer Issues

**Problems:**
- Shows "A modern PHP application built with Flight, RedBean, and Bootstrap" (developer text)
- Contact email: `support@clicksimple.com` (should be myctobot.ai)
- Too technical for end users

**Recommendation:** User-friendly footer with:
- Company info
- Quick links (Docs, Support, Pricing)
- Social links
- © 2026 MyCTOBot.ai

### 5. Duplicate Dashboards

**Current:**
- `/dashboard` - Shows boards, analyses, Jira sites
- `/settings/connections` - Shows profile, subscription, services, setup status

**Recommendation:**
- Merge into single `/dashboard`
- Move settings to `/settings`
- Clear separation of concerns

---

## Medium Priority Issues

### 6. Landing Page Assets (404s)

**Problem:** Placeholder images return 404 errors:
- "Jira Board View" image placeholder
- "AI at Work" image placeholder
- "Pull Request" image placeholder

**Recommendation:** Create real screenshots or use SVG illustrations

### 7. Form Accessibility

**Problems found:**
- Password fields missing `autocomplete` attribute
- No visible focus indicators on some elements
- Some form labels not properly associated

**Recommendation:** Add proper autocomplete attributes:
```html
<input type="password" autocomplete="new-password">  <!-- signup -->
<input type="password" autocomplete="current-password">  <!-- login -->
```

### 8. Navigation Inconsistencies

**Current navigation paths:**
- "Register" → `/auth/register` (different from `/signup`)
- "Dashboard" → unclear which one
- "Settings" → `/settings` or `/settings/connections`?

**Recommendation:** Clear navigation structure:
```
Home
Dashboard (after login)
├── AI Developer
│   ├── Jobs
│   ├── Repositories
│   └── Agents
├── Boards
├── Analysis
└── Settings
    ├── Profile
    ├── Connections
    └── Subscription
```

---

## Low Priority Issues

### 9. Terminology Simplification

| Technical Term | User-Friendly Alternative |
|----------------|---------------------------|
| MCP Servers | Integrations / Extensions |
| Provider | AI Engine |
| Hooks | Automation Rules |
| Capabilities | Features |

### 10. Empty States

Current empty states are minimal. Add:
- Illustrations
- Clear call-to-action buttons
- Example use cases

---

## User Journey Analysis

### Current Flow (New User)
1. Land on `/signup` ✓
2. Fill form → Email verification
3. Click link → Redirected to `/login/{workspace}?welcome=1`
4. Login → Redirected to `/settings/connections`
5. **Gap:** User sees dashboard with "incomplete" status but no clear next step
6. Must figure out: Anthropic API → GitHub → Repository → Board → Start job

### Recommended Flow (New User)
1. Land on `/signup` ✓
2. Fill form → Email verification
3. Click link → Auto-logged in
4. **New:** Guided wizard opens automatically:
   - Step 1: "Let's connect your GitHub" [One-click button]
   - Step 2: "Great! Now pick a repository" [Repository list]
   - Step 3: "Connect your Jira" [OAuth button]
   - Step 4: "Select a board to track" [Board selector]
   - Step 5: "Ready! Add `ai-dev` label to any ticket"
5. Dashboard shows clear progress and recent activity

---

## Recommended GitHub Issues

### Epic: UX Overhaul for Signup-to-First-Job Journey

1. **[NOMENCLATURE]** Standardize terminology (provider, workstation, AI Developer)
2. **[ONBOARDING]** Create guided setup wizard with progress tracking
3. **[NAVIGATION]** Consolidate dashboards and simplify navigation
4. **[LANDING]** Replace placeholder images with real screenshots/SVGs
5. **[FOOTER]** Update footer with user-friendly content
6. **[LOGIN]** Improve workspace selection UX
7. **[FORMS]** Add accessibility attributes (autocomplete, labels)
8. **[EMPTY STATES]** Design engaging empty states with CTAs
9. **[PLAYWRIGHT]** Create E2E tests for signup-to-job flow

---

## Implementation Priority

### Phase 1: Quick Wins (1-2 days)
- Fix nomenclature inconsistencies (search/replace)
- Update footer content
- Add autocomplete attributes
- Fix 404 image errors

### Phase 2: Core UX (3-5 days)
- Implement guided onboarding wizard
- Consolidate dashboards
- Improve navigation structure

### Phase 3: Polish (2-3 days)
- Design empty states
- Create real screenshots for landing page
- Playwright E2E tests

---

## Screenshots

Screenshots captured during audit are stored in:
`.playwright-mcp/ux-audit/`

- `01-signup-page.png` - Landing/signup page
- `02-login-page.png` - Login page with workspace field

---

## Next Steps

1. Create GitHub issues for each improvement area
2. Prioritize based on user impact
3. Implement Phase 1 quick wins first
4. Test with Playwright after each phase
