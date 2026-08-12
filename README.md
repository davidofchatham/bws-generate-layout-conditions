# BWS GP Layout Conditions

WordPress plugin that adds GeneratePress layout-state condition types to GenerateBlocks Pro, so blocks inside a Block Element can be hidden when the corresponding theme element is disabled.

## Problem A: Layout Element settings don't apply to Block Elements

The **Disable Element configuration in a Layout Element** doesn't apply to Block Elements that replace theme sections. Of course, you can match or mirror the Location inclusion/exclusion settings between Layout and Block Elements, but if you want to toggle multiple elements in one place, rather than in every site-wide Block Element, you're out of luck.

This plugin tries to detect all the applicable settings from both Layout Elements and post settings at render time, and offers two ways to use them:

### Workaround 1: GB Conditions

The plugin adds two new GenerateBlocks Pro condition types you can add to a block *within* a Block Element:

- **Theme Element Status** (`gp_theme_element`) — 8 rules: one per element state, plus one for GeneratePress' own featured-image slot.
- **Theme Sidebar** (`gp_theme_sidebar`) — 3 rules for the resolved sidebar layout.

Nothing is hidden automatically; you must configure the conditions yourself, with the granularity you want (e.g. separate conditions for the outer container of your Site Header, for the top bar section, and for the menu section).

Note: In the conditions, "Active" means *not disabled by config*, not that the element source is populated (e.g. **Featured Image Active (post setting)** is true on a thumbnail-less post — in case you want to use a fallback image).

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
| Secondary Nav Active | Secondary nav not disabled | `gp-no-secondary-nav` (when false) | — |
| Top Bar Active | Top bar not disabled | `gp-no-top-bar` (when false) | — |
| Featured Image Active (post setting) | The per-post **Disable Elements → Featured Image** checkbox is off. Nothing else counts: not a Layout Element's featured-image setting, not the Customizer, not what any element is drawing. Always true off singular pages | `gp-no-featured-image` (when false) | `featured-image-active` (different — render-based) |
| Featured Image Slot Active (theme) | GeneratePress still has its own featured image wired up for this page. False when a Page Hero has taken it over, when a Layout Element has switched it off, when the Customizer's global image toggle is off, and on every non-singular page. Says nothing about whether a thumbnail exists — or, in one case, about whether an image appears at all (see limitations) | — (deliberately no class) | `featured-image-active` (different — requires a thumbnail) |
| Content Title Active | The **page** title is not disabled — by the per-post metabox or a Layout Element. Moving the title into a Page Hero does not count as disabling it. Always true off singular pages (see limitations) | `gp-no-content-title` (when false) | — |
| Left Sidebar Active | Left sidebar renders (layout = left or both) | — | `left-sidebar` |
| Right Sidebar Active | Right sidebar renders (layout = right or both) | — | `right-sidebar` |
| No Sidebars Active | Sidebar layout = none | — | `no-sidebar` |

Sidebar `body` classes are emitted by GeneratePress natively; this plugin adds none of its own. Sidebar rules are **membership tests** — "Left Sidebar Active" is true on a both-sidebars page too. To match the both-sidebars layout exactly, combine "Left Sidebar Active" and "Right Sidebar Active" with AND.

## Known limitations

### Not detected

**Turning an element off globally in the Customizer is not detected.** Affects three rules: setting Primary or Secondary Navigation to *No Navigation*, or featured images to off, removes the element from every page while the matching rule still reports it active. The conditions here read the per-page layers — Layout Elements and post settings — which is where the "disable" toggles this plugin exists for actually live. The featured image has a way out on sites running GP Premium's **Blog** module, which is where that Customizer toggle lives: **Featured Image Slot Active (theme)** does see it, because it reports what GeneratePress has wired up rather than what you configured per page. Without that module GeneratePress has no global image toggle to detect, and the slot rule is correspondingly coarser there — see below. There is no equivalent for either nav rule.

**Featured Image Active (post setting) reports the checkbox in the post sidebar, and only that.** A Layout Element's featured-image setting does not turn it off, on any page type. That is deliberate rather than an omission: on real sites that setting is how you *move* the image — a Content Template or Page Hero draws it and GeneratePress' own copy has to be switched off to avoid a duplicate — so reading it would hide the very blocks drawing the image. If you want to switch the image off for a page, use the per-post toggle; that one this plugin reports, and it now removes the image on both of the routes GeneratePress can render it through.

### Out of scope by design

- **Featured Image Active (post setting) means "you have not switched the image off for this post", never "GeneratePress is drawing an image here".** Moving the image into a Content Template or Page Hero counts as moving it, so the condition stays true and blocks inside that template render — that is the point. It is also true on a post with no thumbnail at all, which is what makes a fallback image work: your template renders one, and the condition does not hide it. Ask the other question with **Featured Image Slot Active (theme)** instead — see below.
- **Featured Image Slot Active (theme) says nothing about pixels either.** It reports that GeneratePress has its own image callbacks wired up for this page, not that an image appears — on a post with no thumbnail it is still true. Use it with `is_not` to keep a block from duplicating GeneratePress' image, or to draw a fallback banner where a Layout Element has genuinely switched the image off and nothing has put one back. It gets no `body` class; the class surface stays where it is.
- **A Content Template on its own does not make Featured Image Slot Active false — and can make it wrong.** A Content Template replaces the part of the page GeneratePress would have drawn the image into, but it has no *Disable featured image* setting of its own (only a Page Hero does), so GeneratePress' image is still wired up and the rule still reports the slot active. With the image position set to *Below title* or *Inside content* — the two positions whose output lives in the part being replaced — nothing is actually drawn, so the rule says yes where you see nothing. *Above content* is unaffected: it renders outside the template, and you will get it alongside whatever your template draws. This is the one place the rule can hide a block you wanted: if you use it with `is_not` to supply a fallback banner, the banner will not appear. Two ways round it, both one setting: switch the featured image off in a Layout Element covering those pages (which is what a relocation normally does anyway, and the rule reads it correctly), or move the image position to *Above content*.
- **Featured Image Slot Active (theme) is sharper on some sites than others.** GeneratePress has two ways of drawing the featured image and only one is live per site. With GP Premium's **Blog** module active, the rule is exact: that module only wires its image up when the Customizer toggle is on, and it stands down for a Page Header that has its own content, so the rule follows both. Without the Blog module, the theme's own image callbacks are always registered, so the rule reports the slot active on any singular page unless an element has explicitly switched it off — including where a Page Header is drawing the image instead. The failure direction is the visible one: you may see a duplicate image, rather than a block silently not rendering.
- **The two featured-image rules are nested, not independent.** Switching the image off with the per-post checkbox removes GeneratePress' own image callbacks, so the slot rule reads false there too. "Post setting off, slot active" cannot happen. Read the slot rule as answering "given you have not switched it off yourself, is GeneratePress drawing one?"
- **Content Title Active means "the title is not disabled", never "the theme is rendering it in its usual place".** Moving the title into a Page Hero counts as moving it, so the condition stays true and blocks inside the hero render — that is the point. Only the per-post **Disable Elements** metabox and a **Layout Element's** content-title setting turn it off. The trade: a block *outside* the hero wanting to hide itself to avoid a duplicate title has no rule to key on. Put it inside the hero, or gate it with the element's own display conditions.
- **A Page Hero that disables the title and then doesn't render one** still reports Content Title Active as true. The setting says the title moved; nothing can tell it moved somewhere you left it out.
- **On archives, Content Title Active describes the heading at the top of the page** — which no GeneratePress setting can disable, so it is always true there. A Layout Element's content-title setting does hide the titles on the loop cards, but those are a different element and this plugin does not report on them. Don't use this rule to gate anything per-card.

## Design notes

Invariants, signal map, decisions, and terminology: see `docs/` and `CONTEXT.md`.

## Requirements

- GeneratePress Premium (hard — enforced via `Requires Plugins` header)
- GenerateBlocks Pro (soft — condition self-gates; `display:none` disabling + `body` classes run without it)
- WordPress 6.5+, PHP 7.4+

## License

GPL-2.0-or-later
