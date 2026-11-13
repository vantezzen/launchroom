---
name: vantezzen design standard
version: 3
url: https://vantezzen.io/design.md
base: shadcn/ui + Tailwind CSS
---

# vantezzen design standard

This is the design standard for every vantezzen project: web apps, tools, landing pages, portfolios and content sites. It is the only design system in place. Read it before you design, build or review any UI or copy, and follow it unless the user explicitly asks otherwise.

The projects use shadcn/ui on Tailwind CSS. Use their tokens, scales and components. This document defines how to use them, not new primitives.

## 1. The style

- **Clean and neutral.** A white page, near-black text, grey for everything secondary. The interface has no brand colour.
- **Light first.** Design for light mode. Add dark mode only when the project asks for it, and then design it with the shadcn tokens instead of inverting colours.
- **Quiet.** Few elements, generous whitespace, small and confident headings, one thing in focus per view.
- **Precise.** Consistent alignment, spacing, radii and states. The care shows in small details: a correct disabled state, a balanced heading, an icon that sits on the text baseline.
- **Honest.** Real content, real numbers and real states. Nothing decorative pretends to be information, and nothing invented pretends to be data.

## 2. Foundations

### 2.1 Colour

Use the shadcn neutral theme. Set `--primary` to near-black, not to a hue.

| Token                | Light value               | Use                                                             |
| -------------------- | ------------------------- | --------------------------------------------------------------- |
| `--background`       | `oklch(1 0 0)`            | Page                                                            |
| `--foreground`       | neutral-900 / neutral-800 | Headings, primary text                                          |
| `--muted-foreground` | neutral-500               | Secondary text, descriptions                                    |
| `--muted`            | neutral-100               | Subtle surfaces: tags, inset areas, card fills on content sites |
| `--border`           | neutral-200               | Hairlines                                                       |
| `--primary`          | neutral-900               | Primary buttons, inverse surfaces                               |
| `--accent`           | neutral-100               | Hover and selected backgrounds                                  |

Rules:

- Use colour only when it carries meaning: status (success, warning, error), data series, and focus or selection. Every colour comes with a second cue (a word, an icon, a label).
- Content may bring its own colour: screenshots, photos, a product's own imagery. The interface around it stays neutral.
- Use body text in neutral-600 to neutral-700 on content pages and `text-muted-foreground` for secondary text. Use neutral-400 only for meta next to other text (dates, "at", separators done by layout).
- Use status colours sparingly: emerald for success or online, amber for warning or in progress, red for errors and destructive actions. Prefer the soft background variant (`bg-red-50 text-red-700`) over solid fills.
- For charts, use one neutral or blue series for single values and a small fixed categorical palette for comparisons. An entity keeps the same colour across all charts.

### 2.2 Typography

- One sans-serif for all text: DM Sans or Geist. Geist Mono for code, commands, IDs and other technical values. Load fonts with `next/font` or self-host them.
- Weights: `font-normal` and `font-medium`. Use `font-semibold` only for titles in dense app UI. Never `font-bold`. Set `strong` to `font-medium` with the foreground colour.
- Headings: `tracking-tight`, `text-balance`, `font-medium`. Keep them smaller than feels necessary: whitespace gives them weight.
- Body: `text-sm` in app UI, `text-base leading-relaxed` for reading. Limit prose to `max-w-prose`.
- Sentence case everywhere: headings, buttons, labels, tabs.
- `tabular-nums` for numbers in tables, stats, prices, timers and dates.
- No uppercase or letter-spaced labels and eyebrows. No serif or display fonts unless the project is about them.

### 2.3 Shape and depth

- Radii: `rounded-lg` for controls and inputs, `rounded-xl` for cards, `rounded-2xl` to `rounded-3xl` for large blocks and media on content and landing pages. Buttons may be `rounded-full` on sites. Use one radius per element type throughout a project.
- Separate surfaces with a 1 px `border` or a `bg-muted` fill, not with shadows.
- Use shadows only for floating layers (popovers, dialogs, sheets, toasts, a floating button), and keep them soft: low opacity, large blur, negative spread.
- Don't nest cards. Inside a card, use spacing, a `bg-muted` area or hairline dividers.

### 2.4 Icons

- lucide-react, `size-4` in UI text and buttons, `size-5` in larger controls. Default stroke width.
- Icons are `text-muted-foreground` unless they carry status or are the action itself.
- `ArrowUpRight` marks links that leave the site.
- Brand icons only for the brand they represent (GitHub, LinkedIn), in the foreground or muted colour, never in brand colours inside the UI chrome.
- No emoji in UI chrome.

### 2.5 Motion

- Use one easing for entering and changing: ease-out cubic, `cubic-bezier(0.215, 0.61, 0.355, 1)`. Exits are shorter and ease-in.
- Durations: 150 ms for hover and press, 200–250 ms for popovers, dialogs and state changes, up to 500 ms for entrance reveals on content and landing pages.
- Transition only the properties that change (`transition-[background-color,transform]`), never `transition-all`.
- Animate to explain: state changes, something appearing or leaving, continuity between views. Hover and press feedback is subtle: a background change, `active:scale-[0.98]`, an arrow moving 2–4 px.
- Entrance reveals on sites: fade in and move up 8–12 px, once, with a short stagger. Never on app UI that the user returns to repeatedly.
- No looping animation, pulsing dots, shimmer, parallax, scroll-jacking, marquees, typing effects or confetti.
- Respect `prefers-reduced-motion`: make transitions instant, keep the final state identical.

## 3. Composition

- **One focus per view.** Decide what the page is for, make that the largest and clearest element, and keep everything else quieter.
- **Hierarchy through size, weight and colour, not boxes.** Before adding a card, border or background, try spacing and a muted text colour.
- **Whitespace is structure.** Group related things tightly, separate groups generously. Prefer one more blank line over one more divider.
- **Fewer elements.** When a view feels busy, remove something before rearranging it. Every element must earn its place.
- **Consistent widths.** Content on a page shares one container width from top to bottom. Media may break out of it on purpose, headers may not.
- **Alignment.** Every element aligns to a shared edge or an intentional centre. Icons align with the first line of their text. Numbers right-align in columns.
- **Stable layout.** Elements don't jump when data loads, states change or optional parts appear. Reserve their space or show them from the start in a waiting or disabled state.
- **Mobile is a real layout.** Check every view at 390 px: no horizontal scroll, readable text, touch targets of at least 44 px, stacked layouts instead of squeezed ones.

## 4. App pages

For dashboards, tools, editors and anything people use repeatedly.

- **Make it feel designed, not like a form or a database view.** Show the state of things visually: a status with its word, a number with its trend, a small chart, a preview. Use plain tables only for many rows of tabular data.
- **One primary action per view or region,** as the only filled (`default`) button there. Other actions are `outline` or `ghost`. Labels are verbs ("Save changes", "Deploy", "Add exercise").
- **Put actions where the user works,** next to the thing they affect.
- **Never hide important actions** behind a right-click, a hover-only control or a menu alone.
- **Keep unavailable actions visible but disabled,** with a tooltip that says why. Remove an action only when it can never apply in this context.
- **Never show an action that leads nowhere** (a "Review changes" button with no changes).
- **States:** design empty, loading, error and success states for every view and component.
    - **Empty:** one line on what will appear here and how, plus the action that creates it.
    - **Loading:** a skeleton matching the final layout, shown only after ~300 ms, with no shimmer.
    - **Error:** say what failed, whether anything was lost, and how to fix it. Keep the user's input.
- **Status:** one word per state, used identically everywhere. Show a status with a word plus a dot or icon. One indicator per state: never two spinners for the same thing.
- **Continuity:** the current or active item of a list is part of that list (its first or highlighted row), not a separate widget with a different style.
- **Feedback:** confirm an action where it happened (inline or on the button). Use a toast only for results the user can't see on screen or for undo. A toast always has a visible cause.
- **Calm by default:** reserve warnings and red for things that need action. A normal situation never gets a warning style.
- **Destructive actions:** an outline or ghost destructive button that opens a confirmation stating the consequence in one sentence. Require typing a name only for irreversible data loss.
- **Forms:**
    - visible labels;
    - helper text only when needed;
    - inline validation after blur or submit, never while typing the first characters;
    - sensible defaults;
    - prefill what can be known.
- **Speed:** interactions respond immediately (optimistic UI where safe). Load heavy features lazily. A fast plain page beats a slow beautiful one.
- **Width:** a centred container for lists and detail pages (`max-w-5xl` to `max-w-7xl`), a narrower one for forms and step-by-step flows (`max-w-2xl`). Full width only for canvases, editors and large data views.

## 5. Landing pages

For product pages and marketing.

- **Show the product.** The hero contains the product itself: a real, sharp screenshot, a composition of real UI, or a live demo. Not an abstract illustration.
- **Lead with what it does for the reader,** in a short, concrete headline. Follow it with one sentence of how, then proof (a number, a screenshot, a demo).
- **Few sections, one idea each.** Each section has a heading, at most two sentences and one strong visual. Stop when the story is told; a long scroll of similar sections dilutes it.
- **Typography:** larger headlines than in apps (`text-4xl` to `text-6xl`), still `font-medium` and `tracking-tight`. Body copy short and `text-muted-foreground`.
- **Imagery:**
    - Screenshots are crisp at their displayed size on high-density screens, never upscaled or soft.
    - Don't crop a screenshot so far that it loses its context.
    - Present screenshots and product shots with consistent framing: the same radius, border or shadow treatment, and the same background.
- **One primary call to action,** repeated at the end. Secondary links are text links.
- **Honest claims.** No invented testimonials, logos or numbers. No comparison tables against unnamed "others".

## 6. Content and personal pages

For portfolios, blogs, docs and case studies.

- **Reading first.** One left-aligned column, `max-w-prose` for text, `text-base leading-relaxed`, body in neutral-600.
- **Space as hierarchy.** Large vertical gaps between sections (`gap-24` and more), small modest headings (`text-xl font-medium tracking-tight`).
- **Small hero.** A short, medium-weight headline with plenty of space above it often says more than a large one.
- **Media can break the column** for showcases, screenshots and demos, then the text column continues.
- **Interactive details** are welcome when they serve the content: a live demo, a before/after comparison, a small element drawn in code. Build them with care or leave them out.
- **Proof in numbers.** Concrete facts ("230k monthly users", "3 years") in small tags or meta rows instead of adjectives.
- **Voice:** first person, modest, concrete.

## 7. Imagery and media

- Use real content: real screenshots, real photos, real data. Draw small illustrations in code from actual UI pieces when an image is needed.
- Every image is sharp at its displayed size on a high-density screen.
- Frame media consistently within a project: one radius, one border or shadow style, one background treatment.
- Decoration must not pretend to be content: no fake browser windows, fake terminals or fake charts around content.
- Avoid stock photos, stock or AI-generated illustrations, mascots, abstract 3D shapes and decorative gradient blobs.

## 8. Copy

Words are part of the design. Write plain, specific, natural sentences.

Do:

- Sentence case.
- Say the outcome first, then the detail: "Couldn't save: the name is already taken."
- One sentence per description. Move longer explanations to a help text, tooltip or docs link.
- Use the user's words for things, not implementation terms.
- Descriptive section titles that name the content.
- Specific error and empty-state messages that say what to do next.
- Relative times in lists ("2h ago") with the exact time on hover; compact units ("1m 42s", "4.1 GB").

Don't:

- Em dashes. Use a colon, comma or a new sentence.
- "·" or similar glyphs to separate facts. Use layout (spacing, a second line) or write a phrase.
- Short two-beat assertions: "Data is synced. Changes follow instantly."
- Contrast clauses nobody asked for: "…, never the other way round."
- Subtitles that repeat the title: "Notifications: Manage how you get notified."
- Teasers and setups: "Here's why.", "The best part?", "Let's dive in."
- Rhetorical questions, "It's not X, it's Y", "From X to Y", sentences ending in "…, ensuring/allowing/making …".
- Lists of three adjectives where one would do.
- Filler and hype: easy, simple, just, really, very, seamless, robust, powerful, leverage, unlock, supercharge, delve.
- Exclamation marks in UI.
- Generic errors: "Something went wrong."

## 9. Never

- Gradients on interface elements, gradient text, glows, neon colours.
- Glassmorphism or backdrop blur as decoration.
- A brand or accent colour on buttons, headings, links or backgrounds without meaning.
- Uppercase, letter-spaced eyebrows above headings.
- Rows of identical feature cards with an icon in a coloured circle.
- Coloured left borders on cards or callouts.
- Heavy shadows on cards; multiple shadows stacked for depth.
- Bold text for emphasis inside paragraphs.
- Centred long text.
- Tables for a handful of items.
- Pages that are only a stack of inputs or text blocks.
- Two indicators for the same state.
- Elements that appear later and push the layout.
- Toasts without a visible cause.
- Warning styles for normal situations.
- Fake browser chrome, fake terminals, fake data.
- Blurry or upscaled images.
- Emoji in interface chrome.
- Looping or decorative animation.

## 10. Examples

| Do                                                                                 | Don't                                                           | Why                                                            |
| ---------------------------------------------------------------------------------- | --------------------------------------------------------------- | -------------------------------------------------------------- |
| A status shown as "Online" with a small green dot                                  | A green dot alone, or a pulsing dot                             | The word carries the meaning; motion adds nothing              |
| The active item highlighted as the first row of its list                           | The active item in a differently styled box above the list      | It belongs to the list                                         |
| One spinner on the step that is running                                            | A spinner on the step and another on the summary, in two styles | One state, one indicator                                       |
| All steps of a process listed from the start, later ones as "Waiting" or "Skipped" | A step that appears halfway through                             | The layout stays stable and predictable                        |
| "Restart" visible but disabled, tooltip "Nothing is running yet"                   | "Restart" missing until something runs                          | The layout doesn't change, and the user learns what's possible |
| A short hint: "Add a second server for redundancy"                                 | A red warning because there is only one server                  | A normal setup isn't a problem                                 |
| A toast "Export finished" with a "Download" action                                 | A toast on page load about something that happened earlier      | Notifications follow a visible change                          |
| A header that shows where an item runs and how long each step took                 | A decorative browser window frame                               | Show facts, not decoration                                     |
| A sharp product screenshot with a consistent frame                                 | A heavily cropped or upscaled screenshot inside a small card    | Blurry images look cheap                                       |
| Four landing sections, each with one idea and one visual                           | Ten sections and a comparison table                             | Focus                                                          |
| A monochrome logo or icon with depth from tone                                     | A monochrome logo with one random accent stripe                 | Colour without meaning is decoration                           |
| A small, medium-weight hero headline with space around it                          | A huge bold gradient headline                                   | Restraint reads as confidence                                  |
| "Couldn't connect: the API key is invalid. Check it in Settings."                  | "Something went wrong."                                         | Specific and actionable                                        |
| A settings page with a clear section list and grouped rows                         | A long page of unrelated inputs                                 | Structure makes settings findable                              |
| A dashboard leading with a clear summary ("All 4 checks passed"), details below    | A dashboard leading with a raw table                            | The reader knows first whether to act                          |

## 11. Before you finish

1. Is the focus of each view obvious at a glance?
2. Is colour used only where it carries meaning, always with a second cue?
3. Are empty, loading, error and disabled states designed?
4. Is every element aligned, every radius and status consistent, and does nothing jump?
5. Are all images sharp at their displayed size?
6. Does it work at 390 px and with the keyboard, with visible focus rings?
7. Does the copy follow §8, with nothing from the Don't list?
8. Can anything be removed without losing information? Remove it.
