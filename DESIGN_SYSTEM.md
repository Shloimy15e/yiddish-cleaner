# Yiddish Cleaner Design System

Based on Finko-AI's "Midnight Neon Studio" aesthetic - a premium dark interface with cinematic gradients and electric accent colors.

## Design Philosophy

**Core Aesthetic:** Professional creative tool with dark elegance and neon accents for hierarchy.

**Principles:**
- Dark elegance as foundation, neon accents for hierarchy
- Solid accent colors for visual impact
- Bold typography paired with technical precision
- Visual impact without sacrificing data clarity
- RTL-friendly for Yiddish text display

---

## Color System

### Surface Palette
| Token | Hex | Usage |
|-------|-----|-------|
| `--background` | `#0a0a0f` | Primary background (deepest charcoal) |
| `--card` | `#1a1a24` | Elevated surfaces, cards |
| `--muted` | `#252533` | Hover states, subtle highlights |
| `--border` | `#2a2a3a` | Subtle separators |
| `--border-accent` | `#3a3a4d` | Stronger dividers |

### Electric Accent Colors
| Token | Hex | Usage |
|-------|-----|-------|
| `--primary` (Electric Azure) | `#00d4ff` | Primary CTAs, links, active states |
| `--secondary` (Magenta Pink) | `#ff006e` | Secondary accents, status indicators |
| `--warning` (Amber) | `#ffa726` | Warnings, processing states |
| `--success` (Teal) | `#00e5cc` | Success states, validated items |

### Text Hierarchy
| Token | Hex | Usage |
|-------|-----|-------|
| `--foreground` | `#f5f5f7` | Headlines, body text |
| `--muted-foreground` | `#a8a8b8` | Metadata, descriptions |
| `--tertiary-text` | `#6e6e7e` | Captions, timestamps |

### Clean Rate Category Colors
| Category | Color | Background |
|----------|-------|------------|
| Excellent (90%+) | Teal `#00e5cc` | `rgba(0, 229, 204, 0.15)` |
| Good (75-89%) | Azure `#00d4ff` | `rgba(0, 212, 255, 0.15)` |
| Moderate (50-74%) | Amber `#ffa726` | `rgba(255, 167, 38, 0.15)` |
| Low (25-49%) | Magenta `#ff006e` | `rgba(255, 0, 110, 0.15)` |
| Poor (<25%) | Red `#ef4444` | `rgba(239, 68, 68, 0.15)` |

---

## Typography

### Font Stack
- **Primary (UI):** Inter (via Google Fonts)
- **Monospace:** JetBrains Mono (for Yiddish text, timestamps, technical data)

### Scale
| Level | Size | Weight | Usage |
|-------|------|--------|-------|
| H1 | `text-4xl` (36px) | Bold | Page titles |
| H2 | `text-2xl` (24px) | Semibold | Section headers |
| H3 | `text-xl` (20px) | Semibold | Card titles |
| Body | `text-base` (16px) | Normal | Primary content |
| Small | `text-sm` (14px) | Normal | Secondary content |
| Caption | `text-xs` (12px) | Medium | Labels, badges |

---

## Component Patterns

### Cards
```html
<div class="rounded-xl border border-border bg-card p-6 hover:border-primary/50 hover:shadow-glow transition-all">
  <!-- content -->
</div>
```

### Primary Button
```html
<button class="rounded-lg bg-primary px-6 py-3 font-medium text-primary-foreground hover:bg-primary/90 hover:shadow-glow-sm transition-all">
  Action
</button>
```

### Secondary Button
```html
<button class="rounded-lg border border-secondary px-6 py-3 font-medium text-secondary hover:bg-secondary/10 transition-all">
  Action
</button>
```

### Status Badges
```html
<!-- Processing -->
<span class="rounded-full bg-amber-500/15 text-amber-500 px-3 py-1 text-xs font-medium animate-pulse">
  Processing
</span>

<!-- Validated -->
<span class="rounded-full bg-teal-500/15 text-teal-500 px-3 py-1 text-xs font-medium">
  Validated
</span>
```

### Clean Rate Badges
```html
<span class="rounded-full px-2 py-1 text-xs font-medium bg-teal-500/15 text-teal-500">
  92% Excellent
</span>
```

### Form Inputs
```html
<input class="h-12 w-full rounded-lg border border-border bg-muted px-4 text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all" />
```

### Tabs
```html
<button class="px-4 py-2 font-medium border-b-2 -mb-px transition-colors border-primary text-primary">
  Active Tab
</button>
<button class="px-4 py-2 font-medium border-b-2 -mb-px transition-colors border-transparent text-muted-foreground hover:text-foreground">
  Inactive Tab
</button>
```

---

## Effects

### Glow Effects
```css
.shadow-glow {
  box-shadow: 0 0 20px rgba(0, 212, 255, 0.3), 0 0 40px rgba(255, 0, 110, 0.1);
}
.shadow-glow-sm {
  box-shadow: 0 0 10px rgba(0, 212, 255, 0.2);
}
```

---

## Layout

### Spacing
Use Tailwind units: `4, 6, 8, 12, 16, 24, 32`

### Containers
- Dashboard: `max-w-7xl mx-auto px-6 py-8`
- Cards: `p-6` standard, `p-8` for feature cards

### RTL Support
For Yiddish text display:
```html
<div dir="rtl" class="font-mono text-right">
  יידיש טעקסט
</div>
```

---

## Animations

### Transitions
- Default: `transition-all duration-200`
- Hover lift: `hover:-translate-y-0.5`
- Glow intensify: `hover:shadow-glow`

### Loading States
- Spinner with gradient
- Skeleton shimmer (azure to magenta)
- Pulse animation for processing badges

---

## Accessibility

- Minimum touch target: 44x44px
- Focus rings: `focus:ring-2 focus:ring-primary`
- All text meets WCAG AA contrast against dark backgrounds
- RTL support for Yiddish content
