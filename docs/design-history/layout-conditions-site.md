# Spec: move this plugin's fixtures to a dedicated `layout-conditions` site

Status: implemented 2026-11-27 (testbed cleanup tracked as FW-5)

Tracker: FW-5

## Problem Statement

This plugin's fixture blueprint, `layout-states`, tests site-wide theme behavior, so it cannot keep to its own pages. It writes the GP Premium Menu Plus and Blog options, assigns a menu to the primary and secondary nav locations, requires five GP Premium modules to be on, and places GP Elements on an archive that another blueprint, `core-structures`, owns.

On the shared testbed those writes leak into sibling work. The committed `core-structures` page baseline already contains this plugin's menu item, mobile header, secondary nav and one of its marker blocks, so seeding `core-structures` without this blueprint cannot match its own baseline. The environment's isolation run cannot give a clean verdict while that is true.

## Solution

The blueprint moves to its own site in the same local environment, named `layout-conditions` at `layout.test`, and takes the same name. It owns everything it reads, including its archive, so it composes on nothing and leaves the seed family. One command in this repository runs its whole suite against that site. The testbed keeps the plugin installed and active, with the fixtures and the site-wide settings they needed removed.

## User Stories

1. As the plugin maintainer, I want the fixtures on a site of their own, so that my site-wide theme settings cannot change what sibling plugins render.
2. As a sibling plugin maintainer, I want my page baselines free of this plugin's menu, mobile header, secondary nav and marker blocks, so that a baseline reflects only my blueprint and its bases.
3. As the environment maintainer, I want the blueprint out of the seed family, so that the isolation run judges only blueprints that can pass it.
4. As the plugin maintainer, I want the blueprint named `layout-conditions`, so that its name matches the project the way my other names do.
5. As the plugin maintainer, I want the site served at `layout.test`, so that the domain is short and recognizable.
6. As a developer reading a fixture slug or marker, I want the `lc-` and `LC:` prefix, so that it matches the blueprint name and does not abbreviate a retired one.
7. As the plugin maintainer, I want the blueprint to seed its own archive term and post, so that no other blueprint, taxonomy plugin or content set is needed.
8. As the plugin maintainer, I want the archive built on the core category taxonomy, so that the blueprint registers nothing and needs no schema or must-use plugin.
9. As a test author, I want the archive checks to hard-fail when the archive holds no post, so that an absence assertion never passes against a 404.
10. As a test author, I want proof that each archive element's display condition applies to the new archive, so that a condition that matches nothing cannot turn a render check green.
11. As the plugin maintainer, I want the manifest to declare no base blueprint and no foreign dependency, so that the environment's manifest check reports the truth.
12. As the plugin maintainer, I want the blueprint version raised to 9, so that a site seeded at an older version is rejected instead of silently passing.
13. As the plugin maintainer, I want one command that takes a site name and runs the upstream surface probe, seed, verify, seam fidelity, poisoned signal and render steps, so that leaving the seed family costs me no coverage on the new site.
14. As the plugin maintainer, I want the upstream surface probe to run first, so that upstream drift is named before it shows up as confusing local failures.
15. As the plugin maintainer, I want the command to stop at the first failing step and exit nonzero, so that no later step asserts against a half-seeded site and no failure is lost.
16. As the plugin maintainer, I want the poisoned signal step in its own process after the other eval-file suites, so that the hooks it strips cannot affect them.
17. As the plugin maintainer, I want each step to remain runnable on its own, so that I can debug one without running all six.
18. As the plugin maintainer, I want the new site to hold only GeneratePress, GP Premium, GenerateBlocks, GenerateBlocks Pro and this plugin, so that a failure cannot come from unrelated content or plugins.
19. As the environment maintainer, I want the new site installed fresh instead of cloned, so that it carries no leftover state from the testbed.
20. As a developer, I want every hidden assumption the suites make about the testbed to fail loudly or be set explicitly, so that a green run on a fresh site means something.
21. As a developer, I want the new site to run my working copy of the plugin, so that edits are tested without a copy step.
22. As the plugin maintainer, I want the plugin to stay installed and active on the testbed, so that it is still exercised alongside sibling plugins.
23. As the plugin maintainer, I want the Elements and Disable Elements modules left on there, so that the plugin keeps what it reads.
24. As a sibling plugin maintainer, I want the old fixtures, menu, options and nav assignments removed from the testbed, and the Menu Plus, Secondary Nav and Blog modules turned off unless something else needs them, so that the leaks actually end.
25. As the `core-structures` maintainer, I want the testbed cleaned before I re-capture my baseline, so that I capture it once.
26. As the environment maintainer, I want the empty base snapshot remade after cleanup, so that it holds no stale options or dangling menu assignment.
27. As the plugin maintainer, I want the old family coverage to stay until the environment has the new site ready, so that the order of work never breaks another repository's run.
28. As a developer, I want the live documentation to name the new blueprint, prefix, site and command, so that following it works.
29. As a developer, I want live documentation and comments to stop saying the testbed receives upstream betas, so that nobody trusts a lookahead that is not happening.
30. As a reader of history, I want changelog and design history entries left as written, so that they still describe what was true then.
31. As the plugin maintainer, I want the environment and `core-structures` changes written up as handoffs I can deliver, so that this repository's work edits nothing outside it.

## Implementation Decisions

- **One name.** Site and blueprint are both `layout-conditions`; the domain is `layout.test`; the slug and title prefix becomes `lc-` / `LC:`, including fixture slugs, marker classes and text, the menu, and the attachment. The prefix change is a full rename with no data migration, because the new site seeds from empty.
- **Composition.** The manifest declares that the blueprint composes on nothing. The base version pin, the base manifest read, the foreign dependency block and the foreign dependency checks in seed, verify and seam fidelity go. The declaration of structures read from elsewhere drops the `department` entry and keeps the GP Premium and GenerateBlocks Pro ones.
- **Own archive.** A core category term `lc-archive` with at least one published `lc-` post, both declared in the manifest and seeded idempotently. Elements that targeted the foreign term target this one, with the term ID as the condition object. The archive keeps the same three roles: non-singular featured image rule, page title check, and the archive row of the featured image combination table.
- **Preconditions replace the foreign ones.** The empty-archive guards move to the blueprint's own term. They keep their current strength: seed warns or fails, verify records a failure, and the render step hard-aborts.
- **Runner.** A small script beside the blueprint takes a required site argument and runs, in order: upstream surface probe, seed, verify, seam fidelity, poisoned signal, render. It stops at the first failure. It accepts the same environment root override as the render step and passes it on. It adds no assertions of its own. The step order is then recorded here instead of in the environment's seeding script, which is the accepted cost of running outside the family.
- **Site requirements** (built by the environment, listed here because the suites depend on them): pretty permalinks, since the render step fetches pages and the archive by path; the GP Premium Elements, Disable Elements, Secondary Nav, Menu Plus and Blog modules activated; GenerateBlocks Pro block conditions enabled; this plugin active from the working copy.
- **Versions.** Theme and plugin versions are copied from the testbed and updated by hand. No beta channel is assumed.
- **Order of work.**
  1. The environment creates the site and removes the blueprint from the seed family.
  2. This repository makes the changes on a branch.
  3. The runner passes on `layout.test`, then the branch merges.
  4. The environment cleans the testbed and remakes the empty base snapshot.
  5. `core-structures` re-captures its baseline.

  Step 1 must come first: the environment reads this repository's working tree, so renaming the blueprint while it is still in the family breaks the manifest check for every family run, even from an unmerged branch.
- **Testbed end state.** Plugin installed and active. No `ls-` content or menu, neither GP option the blueprint wrote, no nav assignment to the removed menu. Elements and Disable Elements on. Menu Plus, Secondary Nav and Blog off unless another user is found. No upstream surface probe runs there.
- **Documentation.** The glossary, the architecture home, the blueprint, probe and inspect READMEs, comments that name the old blueprint, prefix or testbed, and the future work index describe the new state. The changelog and design history are not rewritten.

## Testing Decisions

- **One seam: the runner against `layout.test`.** It is the highest existing seam, since it drives the real suites against real WordPress, GP Premium and GenerateBlocks Pro. No new assertions layer is added.
- **A good test here** asserts what the site stores, evaluates or serves, and hard-fails when its precondition is missing. An absence check counts only when a control on the same response proves the response is the intended, complete page.
- **Done means** all six steps pass on a freshly installed site holding only the listed theme and plugins, and pass again on an immediate second run, which proves the seed is idempotent.
- **Prove the new guards can fail**, by name, per the project's mutation-check rule: with the archive post removed the archive checks must abort or fail; with an archive element's condition object set to the slug, verify must report that element as not applying; with a step forced to fail, the runner must exit nonzero and run nothing after it.
- **Rename completeness** is checked by search: no live file outside changelog and history still uses the old blueprint name, prefix or `department` term.
- **The unit suite** is unaffected and must stay green, as must the tracked content guards.
- **Prior art:** the existing verify, seam fidelity, poisoned signal and render suites and their precondition sections; the `user-term-rules` blueprint in another repository, which already runs outside the family and composes on nothing.

## Out of Scope

- Edits to the environment repository or to `core-structures`. Both get handoffs.
- Fixing beta delivery. It was broken before this work and is unchanged by it; it is noted in the environment handoff.
- Teaching the environment's seeding script to run blueprints outside the family.
- Running the upstream surface probe on the testbed.
- A page snapshot check for this blueprint.
- New fixtures, new invariants or any change to plugin runtime behavior.
- Moving the client site clones or changing how the plugin is tested on them.

## Further Notes

- Accepted costs: family seed runs no longer cover this blueprint; a second site must be kept current by hand; the two sites can drift apart in upstream versions, and the testbed copy of the plugin has no automatic check against newer ones.
- Between steps 1 and 3 nothing runs these fixtures automatically. They stay seeded on the testbed and can be run by hand until the rename reaches the working tree.
- The empty base snapshot is reported to still hold both GP options and an assignment to a menu that no longer exists. This was not verified.
- Why beta updates do not arrive, as read on 2026-11-27: automatic updates are disabled in the site configuration; GenerateBlocks Pro 2.8.0 sends no beta flag to its updater; GP Premium 2.5.6 sends it but registers its updater only for admin requests; the free theme and plugin update from wordpress.org, which serves no betas. Whether the vendor currently offers a beta was not checked.
