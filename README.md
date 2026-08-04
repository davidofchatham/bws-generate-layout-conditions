# BWS GP Layout Conditions

WordPress plugin that adds GeneratePress layout-state condition types to GenerateBlocks Pro, so blocks inside a Block Element can be hidden when the corresponding theme element is disabled.

## Problem A: Layout Element settings don't apply to Block Elements

The **Disable Element configuration in a Layout Element** doesn't apply to Block Elements that replace theme sections. Of course, you can match or mirror the Location inclusion/exclusion settings between Layout and Block Elements, but if you want to toggle multiple elements in one place, rather than in every site-wide Block Element, you're out of luck.

This plugin tries to detect all the applicable settings from both Layout Elements and post settings at render time, and offers two ways to use them:

### Workaround 1: GB Conditions

The plugin adds two new GenerateBlocks Pro condition types you can add to a block *within* a Block Element:

- **Theme Element Status** (`gp_theme_element`) — 7 rules, one per element state.
- **Theme Sidebar** (`gp_theme_sidebar`) — 3 rules for the resolved sidebar layout.

Nothing is hidden automatically; you must configure the conditions yourself, with the granularity you want (e.g. separate conditions for the outer container of your Site Header, for the top bar section, and for the menu section).

Note: In the conditions, "Active" means *not disabled by config*, not that the element source is populated (e.g. **Featured Image Active** is true on a thumbnail-less post — in case you want to use a fallback image).

### Workaround 2: `body` classes

The plugin injects `gp-no-{component}` for each disabled state (unless GeneratePress already injects a class), to support custom CSS. See the table under [Detection reference](#detection-reference) for the full list.

## Problem B: Post-level toggles can hide too much

The **post-level Disable Elements** metabox applies `display:none` to GP wrappers like `.site-header` and `.site-footer`, so it will probably work if you're using the standard locations, but in my experience it's too broad: for example, it will hide the top bar inside your header element or the legal section at the bottom of footer element, even if you don't want that.

### Workaround

The plugin prevent GeneratePress from using `display:none` to hide theme elements when they are disabled via the post metabox, leaving the PHP hook-based suppression active where available.

## Detection reference

What each condition rule detects and its corresponding `body` class:

| Condition rule | True when | `body` class | GP native class |
|---|---|---|---|
| Header Active | Header not disabled | `gp-no-header` (when false) | — |
| Footer Active | Footer not disabled | `gp-no-footer` (when false) | — |
| Primary Nav Active | Primary nav not disabled | `gp-no-primary-nav` (when false) | — |
| Secondary Nav Active | Secondary nav not disabled (post metabox only) | `gp-no-secondary-nav` (when false) | — |
| Top Bar Active | Top bar not disabled | `gp-no-top-bar` (when false) | — |
| Featured Image Active | Featured image not disabled by config | `gp-no-featured-image` (when false) | `featured-image-active` (different — render-based) |
| Content Title Active | The **page** title is not disabled — by the per-post metabox or a Layout Element. Moving the title into a Page Hero does not count as disabling it. Always true off singular pages (see limitations) | `gp-no-content-title` (when false) | — |
| Left Sidebar Active | Left sidebar renders (layout = left or both) | — | `left-sidebar` |
| Right Sidebar Active | Right sidebar renders (layout = right or both) | — | `right-sidebar` |
| No Sidebars Active | Sidebar layout = none | — | `no-sidebar` |

Sidebar `body` classes are emitted by GeneratePress natively; this plugin adds none of its own. Sidebar rules are **membership tests** — "Left Sidebar Active" is true on a both-sidebars page too. To match the both-sidebars layout exactly, combine "Left Sidebar Active" and "Right Sidebar Active" with AND.

## Known limitations

A few configurations cannot be detected reliably or at all:

- **Moving the featured image into another element reads as disabling it.** When an element renders the featured image itself, GeneratePress suppresses the native one so you don't get a duplicate — and this plugin sees that suppression, not the relocation. Affects the **Page Hero "Disable featured image" toggle**.

  Two catches that may not be obvious:
  - If you apply display conditions to blocks *within* the element hoping to allow post-level control of the featured image, those blocks will be hidden regardless of post settings — they count as disabled.
  - `gp-no-featured-image` is injected into the `body` classes regardless of whether the image is shown within the element.

  **The content title no longer works this way** — moving the title into a Page Hero, a legacy Page Hero, or the Page Header module counts as moving it, not removing it, so **Content Title Active stays true** and blocks inside the hero still render. Only the two settings that genuinely say "no title here" turn it off: the per-post **Disable Elements** metabox and a **Layout Element's** content-title setting.
- **There is no condition for "is the theme's own title slot empty?"** A block placed *outside* a hero, hiding itself to avoid a duplicate title or to compensate for missing spacing, has nothing to key on. Conditions here answer "has the title been disabled", not "where is it rendered".
- **A hero that disables the title but doesn't render one** still reports Content Title Active as true. The setting says the title moved; nothing can tell that it moved into an element that then left it out.
- **Content Title Active describes the page title, not the titles on archive cards.** On an archive it reports the heading at the top of the page — and no GeneratePress setting can disable that heading, so it is always true there. A Layout Element's content-title setting does hide the titles on the loop cards, but those are a different thing and this plugin does not report on them.
- **Featured image disabled on archives via a Layout Element** — detection is limited to singular pages.
- **Secondary nav disabled via a Layout Element** — only the per-post Secondary Nav toggle is detected.

## Design notes

Invariants, signal map, decisions, and terminology: see `docs/` and `CONTEXT.md`.

## Requirements

- GeneratePress Premium (hard — enforced via `Requires Plugins` header)
- GenerateBlocks Pro (soft — condition self-gates; `display:none` disabling + `body` classes run without it)
- WordPress 6.5+, PHP 7.4+

## License

GPL-2.0-or-later
