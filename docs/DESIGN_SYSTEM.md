# Settle — Design System

> Working tokens, components, and rationale for the Settle UI.
> See `preview.html` for a live, themable showcase. See `tailwind.config.js` for the production config.

## 1. Direction

**Restrained fintech polish with a warm teal accent.** Reference points from CLAUDE.md hold:

| Reference | What we borrow |
|---|---|
| **Stripe** | Clarity, hierarchy, trust signals near money |
| **Linear** | Refinement, motion, sidebar density |
| **Vercel** | Typography rhythm, whitespace, dark mode parity |
| **Coinbase** | Crypto credibility without crypto clichés |

**Anti-patterns** (do not introduce):
- Neon gradients, glassmorphism overload, glitch effects
- Emoji as structural icons
- Stock fintech corporate-blue
- "AI purple/pink" hero gradients
- Decorative motion that doesn't convey meaning

## 2. Color tokens

### Brand — deep teal

| Token | Hex | Usage |
|---|---|---|
| `brand-50` | `#f0fdfa` | Subtle hover wash, badge bg in light mode |
| `brand-100` | `#ccfbf1` | — |
| `brand-200` | `#99f6e4` | Borders on brand surfaces |
| `brand-400` | `#2dd4bf` | Hero mesh accents |
| `brand-500` | `#14b8a6` | Focus rings, dot indicators |
| **`brand-600`** | **`#0d9488`** | **Primary CTA, active nav, links** |
| `brand-700` | `#0f766e` | CTA hover |
| `brand-800` | `#115e59` | CTA active |
| `brand-950` | `#042f2e` | Dark-mode brand surfaces |

### Neutrals — slate (Tailwind default)

- Body text: `slate-900` (light) / `slate-100` (dark)
- Secondary text: `slate-600` / `slate-400`
- Borders: `slate-200` / `slate-800`
- Surfaces: `white` / `slate-900`
- Background: `slate-50` (subtle), `slate-100` (muted)

### Status

| Use | Light | Dark | Token |
|---|---|---|---|
| Paid / valid | `success-50` bg + `success-600` text | `success-500/10` bg + `success-500` text | `success` |
| Sent / info | `info-50` + `info-600` | `info-500/10` + `info-500` | `info` |
| Viewed / pending | `warning-50` + `warning-600` | `warning-500/10` + `warning-500` | `warning` |
| Overdue / error | `danger-50` + `danger-600` | `danger-500/10` + `danger-500` | `danger` |

**Rule:** status colors only on badges, alerts, and explicit indicators. Never as decoration.

## 3. Typography

| Family | Use | Source |
|---|---|---|
| **Inter** | Body, UI, headings | Google Fonts, weights 400/500/600/700 |
| **Inter Display** | Hero / large headings (>=32px) | Optical-size variant |
| **JetBrains Mono** | Hashes, addresses, invoice numbers, code | Google Fonts, weights 400/500 |

### Scale

| Role | Size / line-height | Weight | Tracking |
|---|---|---|---|
| Display | `text-6xl` (60/64) | 600 | `-0.025em` |
| H1 | `text-4xl` (36/40) | 600 | `-0.015em` |
| H2 | `text-2xl` (24/32) | 600 | `-0.015em` |
| H3 | `text-lg` (18/28) | 600 | normal |
| Body | `text-base` (16/24) | 400 | normal |
| Small | `text-sm` (14/20) | 400 | normal |
| Micro | `text-xs` (12/16) | 500 | normal |
| 2x-small | `text-2xs` (11/16) | 500 | normal — used in badges |

**Always use `tabular-nums` on amounts and timestamps.** Prevents column shifting.

## 4. Geometry

- Radii: `rounded-lg` (controls), `rounded-xl` (buttons/inputs), `rounded-2xl` (cards), `rounded-3xl` (hero card, modal)
- Slightly softer than default — friendlier without being playful
- 4 / 8 / 12 / 16 / 20 / 24 / 32 / 48 spacing rhythm
- Container: `max-w-7xl mx-auto px-6` (marketing), `max-w-6xl` (dashboard content), `max-w-md` (payment card)

## 5. Shadow scale

| Token | Use |
|---|---|
| `shadow-xs` | Subtle field/badge separation |
| `shadow-card` | Default card resting state |
| `shadow-card-hover` | Card hover lift |
| `shadow-pop` | Floating elements (toasts, modals, hero invoice card) |
| `shadow-ring-brand` | Focus ring on brand-colored controls |

## 6. Motion

- 150ms `ease-out` — hover, focus, color transitions
- 240ms `cubic-bezier(0.16, 1, 0.3, 1)` (`out-expo`) — layout changes, slide-up
- Always respect `prefers-reduced-motion`
- Pulse-dot animation for "live" indicators (active payment, listening)

## 7. Components

The full visual catalog lives in `preview.html`. The structural rules:

### Button
- **Primary**: `bg-brand-600 hover:bg-brand-700 active:bg-brand-800 text-white rounded-xl px-4 py-2.5 shadow-sm`
- **Secondary**: `bg-white border border-slate-200 hover:bg-slate-50` (dark: `bg-slate-900 border-slate-700 hover:bg-slate-800`)
- **Ghost**: `text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-lg px-3 py-2`
- **Destructive**: `bg-danger-600 hover:bg-danger-700 text-white`
- **Loading state**: keep text, swap leading icon for spinner, set `disabled` + `cursor-not-allowed opacity-50`
- **Touch target**: enforced ≥44px height through `py-2.5` on default size

### Input
- `rounded-xl border border-slate-200 px-3.5 py-2.5`
- Focus: `border-brand-500 ring-2 ring-brand-500/20`
- Error: `border-2 border-danger-500` + helper text `text-xs text-danger-600`
- Success: `border-2 border-success-500` + helper text `text-xs text-success-600`
- **Always** visible label above (`text-sm font-medium mb-1.5`) — never placeholder-only
- Helper text below — persistent, not just on error

### Card
- `rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-card hover:shadow-card-hover p-6`
- Featured/elevated cards: `shadow-pop` + larger padding (`p-8 lg:p-10`)

### Status badge
- `inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-2xs font-medium`
- Always paired with a dot indicator (`h-1.5 w-1.5 rounded-full bg-{status}-500`)
- Color is never the only carrier — dot + label work even with color blindness

### Table
- Sticky header `bg-slate-50/60 dark:bg-slate-950/40`
- Row hover `hover:bg-slate-50/60 dark:hover:bg-slate-950/40`
- Right-align numbers, use `tabular-nums font-medium` for amounts
- Amounts in same currency line up; due dates in `text-slate-500`

## 8. Three sacred layouts

### Marketing landing
Full-bleed hero with subtle grid + brand mesh background, 7/5 column split (copy / floating invoice card). Below the fold: feature triplet, dashboard mock, pricing, footer. Max width `max-w-7xl`.

### Dashboard
Sidebar (`w-64`) + main content. Sidebar has account picker on top, nav middle, upgrade CTA at bottom. Main: greeting → 3 metric cards → invoice table. Mobile: collapsible drawer.

### Payment checkout (revenue page — sacred)
Single focused card, `max-w-md` centered. No nav, no distractions.
1. Logo + locale switcher (top)
2. Amount in display type (60px)
3. Token + chain selector
4. Recipient address (mono, in muted card)
5. **One** primary action: "Connect wallet" — `py-4 rounded-2xl text-base`
6. Trust micro-row (non-custodial · secured by your wallet · settles in seconds)

## 9. Accessibility floor

- Body text contrast ≥ 4.5:1 in both modes (verified)
- Focus states visible via `ring-focus` utility on every interactive element
- All icons paired with text or `aria-label`
- Status colors paired with icon/dot
- `prefers-reduced-motion` respected
- Touch targets ≥ 44px
- Semantic HTML: `<button>` not `<div onclick>`, proper `<label for>` linkage
- Headings sequential; focus moved to main content on route change

## 10. Localization considerations

- All copy must come from translation YAML — no hardcoded strings in templates
- Use `Intl.NumberFormat` / `format_currency` Twig filter — never concat `$`
- Ukrainian & Spanish strings can be ~30% longer; never rely on fixed pixel widths for text containers
- All three locales are LTR; no RTL handling needed
- Date display: relative ("2 hours ago") in dashboard, absolute on receipts/PDFs

## 11. Files

```
design/
├── DESIGN_SYSTEM.md      ← this document
├── preview.html          ← self-contained visual showcase (open in browser)
└── tailwind.config.js    ← production Tailwind config
```

When the Symfony scaffolding lands, the config moves to repo root and `preview.html` is replaced by Twig templates that share the same tokens.
