# Design System Documentation: Tactical Precision & Calm Authority

This design system is engineered for high-stakes environments where split-second decisions meet technical rigor. It rejects the "utility-only" aesthetic of legacy emergency software in favor of a sophisticated, editorial approach that prioritizes cognitive load reduction through tonal depth and typographic hierarchy.

---

## 1. Overview & Creative North Star: "The Vigilant Sentinel"

The Creative North Star for this system is **The Vigilant Sentinel**. The interface must feel like a calm, expert advisor standing by in a crisis. It achieves this by breaking the "standard grid" through intentional asymmetry and layered surfaces. 

Instead of overwhelming the user with a flat wall of data, we use **Tonal Layering** and **High-Contrast Typography** to create a path for the eye. We move away from "software-as-a-tool" and toward "software-as-intelligence," utilizing breathing room and glassmorphism to ensure the UI feels modern, academic, and authoritative.

---

## 2. Colors: Tonal Intelligence

The palette is rooted in urgency but executed with professional restraint. We use Material Design tokens to manage light and shadow across complex data environments.

### The "No-Line" Rule
**Explicit Instruction:** Designers are prohibited from using 1px solid borders to section content. Boundaries must be defined solely through background shifts (e.g., a `surface-container-low` card sitting on a `surface` background). This creates a seamless, high-end feel that reduces visual noise during high-stress operations.

### Surface Hierarchy & Nesting
Treat the UI as physical layers of frosted glass.
- **Base:** `surface` (#f4faff) for the main application background.
- **Secondary Areas:** `surface-container-low` (#e7f6ff) for sidebars or utility panels.
- **Active Focus:** `surface-container-lowest` (#ffffff) for the primary content cards to create a "lift" toward the user.
- **Deep Nesting:** Use `surface-container-highest` (#d4e5ef) for recessed elements like search bars or inactive data wells.

### The "Glass & Gradient" Rule
To avoid a "flat" corporate look, primary CTAs and critical alert headers must use a subtle linear gradient (e.g., `primary` #af101a to `primary-container` #d32f2f at 135°). For floating overlays or connectivity status bars (Wi-Fi/LoRa), use Glassmorphism: `surface` color at 70% opacity with a `20px` backdrop-blur.

---

## 3. Typography: The Authority of Scale

We utilize **Inter** to bridge the gap between technical legibility and modern editorial design.

*   **Display (lg/md/sm):** Reserved for critical status counts (e.g., "14 Active Alerts"). These should use a tight letter-spacing (-0.02em) to feel "engineered."
*   **Headline & Title:** Use `headline-sm` (#0d1e25) for section headers. Combine with `primary` accents to draw immediate attention to urgent data points.
*   **Body (lg/md/sm):** Use `body-md` for data readouts. High-contrast colors (`on-surface`) are mandatory to ensure readability in low-light or outdoor emergency scenarios.
*   **Label (md/sm):** Used for technical metadata (LoRa signal strength, GPS coordinates). These are always uppercase with +0.05em tracking to feel academic and precise.

---

## 4. Elevation & Depth: Tonal Layering

Traditional shadows are too heavy for a technical interface. We achieve depth through atmospheric physics.

*   **The Layering Principle:** Instead of a shadow, place a `surface-container-lowest` object on a `surface-dim` (#cbdde7) background. The delta in luminance provides all the separation required.
*   **Ambient Shadows:** For high-level modals (e.g., Dispatch Confirmations), use a shadow with a `40px` blur, `0%` spread, and `6%` opacity. The color should be a tint of `on-surface` (#0d1e25), not pure black.
*   **The "Ghost Border" Fallback:** If accessibility requires a stroke, use `outline-variant` (#e4beba) at **15% opacity**. This creates a "suggestion" of a border that doesn't clutter the technical layout.

---

## 5. Components: Precision Elements

### Buttons
*   **Primary (Urgent):** Gradient fill (`primary` to `primary-container`), `md` (0.375rem) rounded corners. Text is `on-primary`.
*   **Secondary (Trust):** `secondary` (#005faf) with a glass effect.
*   **Tertiary:** No background; `label-md` typography with a `primary` icon.

### Connectivity Chips (Wi-Fi vs. LoRa)
*   Do not use standard pills. Use a technical "Status Block."
*   **Style:** `surface-container-high` background, `sm` (0.125rem) roundedness for a "crisp" academic look. Include a `primary` or `secondary` colored dot for active status.

### Input Fields
*   **Style:** "Bottom-line only" or "Soft-fill." Use `surface-container-highest` with a `2px` bottom border of `outline` (#8f6f6c).
*   **Error State:** Transition the bottom border to `error` (#ba1a1a) with a `surface-container` fill of `error-container`.

### Cards & Lists (The Forbearance of Lines)
*   **Forbid dividers.** To separate list items, use a `1.1rem` (spacing 5) vertical gap.
*   **Contextual Shift:** When hovering over a list item, shift its background from `surface` to `surface-container-low`.

---

## 6. Do’s and Don'ts

### Do
*   **Use Asymmetry:** Place critical incident maps in large, non-standard aspect ratio containers to break the "dashboard" feel.
*   **Prioritize Breathing Room:** Use the Spacing Scale (specifically `spacing-8` and `12`) to separate disparate data streams.
*   **Layer for Importance:** Move critical diagnostic tools to the "highest" tonal layer (`surface-container-lowest`).

### Don't
*   **No High-Contrast Borders:** Never use a 100% opaque border for a card. It creates "visual friction."
*   **No Standard Drop Shadows:** Avoid the "floating button" look of consumer apps. We want an integrated, technical "OS" feel.
*   **No Center Alignment:** For technical data, stick to left-aligned editorial layouts. Center alignment is for marketing; left alignment is for speed.

---

## 7. Technical Scales

### Spacing (The 0.2rem Base)
*   **Tight (Metadata):** `spacing-1` (0.2rem) or `spacing-2` (0.4rem)
*   **Standard (Component Padding):** `spacing-4` (0.9rem)
*   **Sectional (Gutter):** `spacing-10` (2.25rem)

### Roundedness (The "Authoritative" Curve)
*   **Large (Cards/Modals):** `lg` (0.5rem)
*   **Standard (Buttons/Inputs):** `md` (0.375rem)
*   **Technical (Chips/Small Tags):** `sm` (0.125rem)