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
| Secondary Nav Active | Secondary nav not disabled | `gp-no-secondary-nav` (when false) | — |
| Top Bar Active | Top bar not disabled | `gp-no-top-bar` (when false) | — |
| Featured Image Active | Featured image not disabled by config | `gp-no-featured-image` (when false) | `featured-image-active` (different — render-based) |
| Content Title Active | The **page** title is not disabled — by the per-post metabox or a Layout Element. Moving the title into a Page Hero does not count as disabling it. Always true off singular pages (see limitations) | `gp-no-content-title` (when false) | — |
| Left Sidebar Active | Left sidebar renders (layout = left or both) | — | `left-sidebar` |
| Right Sidebar Active | Right sidebar renders (layout = right or both) | — | `right-sidebar` |
| No Sidebars Active | Sidebar layout = none | — | `no-sidebar` |

Sidebar `body` classes are emitted by GeneratePress natively; this plugin adds none of its own. Sidebar rules are **membership tests** — "Left Sidebar Active" is true on a both-sidebars page too. To match the both-sidebars layout exactly, combine "Left Sidebar Active" and "Right Sidebar Active" with AND.

## Known limitations

### Not detected

**Turning an element off globally in the Customizer is not detected.** Affects three rules: setting Primary or Secondary Navigation to *No Navigation*, or featured images to off, removes the element from every page while the matching rule still reports it active. The conditions here read the per-page layers — Layout Elements and post settings — which is where the "disable" toggles this plugin exists for actually live.

Everything below is one rule: **Featured Image Active** on singular pages. Most rules read your settings; this one is inferred from how GeneratePress has wired itself up at render time, which answers a narrower question than the rule's name suggests. (Primary Nav Active and Top Bar Active are inferred the same way, but nothing is known to fool them.) On archives, Featured Image Active reads settings and is unaffected by all of this.

#### Featured Image Active on singular pages

- **Any featured image position other than "Below title" reads as disabled everywhere — including the two defaults.** Read this one before relying on the rule at all: it describes a stock GeneratePress install, not an unusual configuration. Customizer → Layout → Blog offers three positions and this plugin watches only **Below title**, but GeneratePress ships posts as *Inside content* and pages as *Above content*. So on a site where nobody has touched that setting, Featured Image Active is false on every singular page while the image renders normally. Set both to *Below title*, or don't gate on this rule.
- **Moving the featured image into another element reads as disabling it.** When an element renders the featured image itself, GeneratePress suppresses the native one so you don't get a duplicate — and this plugin sees that suppression, not the relocation. Affects the **Page Hero "Disable featured image"** toggle. Two consequences: blocks *within* the element are hidden regardless of post-level settings, and `gp-no-featured-image` is added to the `body` classes whether or not the image renders inside the element.
- **The rule needs GP Premium's Blog module.** The function it watches for ships in that module. With it deactivated the rule is false on every singular page.

Two related notes: the post-level **Disable Elements → Featured Image** toggle is not reported by this rule — it suppresses a different image slot than the one being watched — and a **Page Header** with content of its own also takes over the image without the rule noticing.

### Out of scope by design

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
