# BWS GP Layout Conditions

WordPress plugin. Makes GeneratePress disable states and sidebar layout usable by GenerateBlocks Pro Block Elements.

See `readme.txt` for user-facing description and changelog.

## Architecture

- `docs/architecture.md` — invariants, signal map, element toggle map, bug ledger
- `docs/adr/` — design decisions (hybrid detection, post-meta reads, dependency gating)
- `CONTEXT.md` — terminology, detection model, known gaps, operator/rule model

## Structure

```
bws-generate-layout-conditions.php   Plugin entry — bootstrap hooks
includes/
  class-disable-elements.php         CSS-neutralize (the fix)
  class-detector.php                 Single source of truth for disable states
  class-body-classes.php             Emits gp-no-* body classes
  class-condition.php                GB Pro condition types (gp_theme_element, gp_theme_sidebar)
plugin-update-checker/               Bundled PUC v5p7
docs/
  architecture.md                    Invariants + signal map
  adr/                               Architectural decision records
```

## Requirements

- GeneratePress Premium (hard — enforced via `Requires Plugins` header)
- GenerateBlocks Pro (soft — condition self-gates; fix + body classes run without it)
- WordPress 6.5+, PHP 7.4+

## Development

No build step. PHP only.

Deploy on GB Pro sites: this plugin + your Block Elements together. The fix alone on a GB Pro site is a regression (removes CSS hide without condition replacement).

## License

GPL-2.0-or-later
