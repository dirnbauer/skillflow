# Changelog

## 1.8.1 — 2026-09-23

### Fixed

- The skill detail page has exactly one `<h1>`, the skill title. The Markdown
  body is rendered one level lower (`# Title` → `<h2>`, `##` → `<h3>`, capped
  at `<h6>`); its stylesheet sizes the shifted levels as before.
- Themes that print the page title as an `<h1>` can step aside on skill
  detail pages: `ext_localconf.php` adds an entry to the TypoScript registry
  `lib.pageHeadingOwnedByContent` on every page holding a `skillflow_skilldetail`
  element (read by Desiderio 4.4; no dependency on any theme).
- The backend run report renders the Markdown output from `<h3>` on, below the
  module `<h1>` and the "Report" `<h2>`.

### Added

- `<sf:markdown headingOffset="1">` and `MarkdownRenderer::toHtml($markdown,
  $headingOffset)` move every heading down by the offset (default 0).

## 1.8.0 — 2026-09-23

### Added

- **Abilities contract.** Skills declare the typo3-abilities they need in
  their SKILL.md front matter (`abilities: [news/list, solr/index-queue]`).
  `skillflow:skills:sync` stores the list in
  `tx_nrllm_skill.tx_skillflow_abilities` (read-only on the skill record's
  new "Skillflow abilities" tab); skills synchronized elsewhere are read from
  their front matter.
- The Claude CLI runner derives `--allowedTools` from the declared abilities:
  `mcp__<server>__` plus `AbilityDefinition::mcpToolName()`, for every ability
  the registry knows and exposes to MCP, added to the skill's own
  `allowed-tools`. `allowed-tools: []` still switches the built-in tools off
  but keeps the abilities. New setting `abilitiesMcpServer` (default `typo3`)
  names the server in `mcpConfigJson`.
- `skillflow:skills:check` (alias `skillflow:check`) reports
  `ability_missing` (not registered, or typo3-abilities not active) and
  `ability_denied` (site policy, review required, not exposed to MCP, or a
  scope the running backend user lacks); `--as-user`, `--enabled`, `--json`;
  exit code 1 on findings.
- The Skills module lists the abilities of the page's skills with their
  status for the logged-in user; a run report lists its skill's abilities;
  the skill detail plugin lists the declared abilities.
- typo3-abilities is optional: `SkillAbilityResolver` takes its registry,
  policy and scope services as nullable constructor arguments. Functional
  tests boot with and without the extension (installed but not active).

### Changed

- Default model `claude-sonnet-4-6` → `claude-sonnet-5` (Anthropic runner).
  A configured model is kept.
- Reports name their skill instead of `#<uid>`: the title links to the nr_llm
  skill record for users who may edit it, and a run whose skill was deleted
  shows "Deleted skill" with the name and identifier it ran under. Runs store
  both (`tx_skillflow_run.skill_name`, `skill_identifier`) from now on;
  older runs of a deleted skill show their uid.
- `ClaudeCliRunner` receives `SkillAbilityResolver` (autowired);
  `ClaudeCliRunner::buildCommand()` and `::allowedTools()` build the CLI
  invocation.
- Development: `webconsulting/typo3-abilities` ^1.3 as dev dependency and
  suggestion.

### Upgrade

- Run `vendor/bin/typo3 extension:setup -e skillflow` for the new columns,
  then `skillflow:skills:sync --all` once to store existing declarations.

## 1.7.0 — 2026-09-23

### Added

- The Skills module is a native TYPO3 v14 module: page breadcrumb, automatic
  reload and bookmark buttons, a link to nr_llm's skill sources for
  administrators, `f:be.infobox` notices and an empty state. The run form
  groups the skills into those assigned to the page, to the current backend
  user and all others; a progress note (announced to screen readers) marks
  a running skill and ignores repeated clicks.
- Runs redirect after the `POST` (a reload never repeats a run); a single run
  opens its report.
- Reports list per page (the page and its content elements) or for all pages,
  paginated, with colour-coded status, verdict, score, engine and the target
  record's icon and title.
- The report view renders the output as Markdown (raw HTML escaped, unsafe
  links removed) and keeps the plain text and the structured engine result
  one click away; workspace and stage are shown by name.
- All module, TCA, plugin and catalogue labels in English and German
  (XLIFF, two-space indentation); module labels use the v14
  `skillflow.modules.skills` translation domain.
- The skill detail plugin sets the page title and meta description from the
  skill (core `recordTitle` provider). Its default template shows category,
  source, tags, the skill ID with a copy button, license, version and allowed
  tools, with a small stylesheet that follows the site's theme tokens in light
  and dark mode.
- Translated facet and sorting labels in the `webconsulting/skillflow-solr`
  set and in the search templates.
- `RunStatus` enum, `SkillRunResult::$runUid`, `SkillRunResult::blocked()`,
  `::failed()` and `::runStatus()`.

### Changed

- The Anthropic runner uses the current MCP connector beta
  (`mcp-client-2025-11-20`) and derives the required `mcp_toolset` entries;
  tool allowlists written for the retired 2025-04-04 format are translated.
- The extension configuration is read once into the typed `ExtensionSettings`
  service (with a `RunnerMode` enum) instead of in every runner.
- PHP 8.4 idioms: readonly service classes, typed class constants,
  `#[\Override]`, `new` without parentheses; the backend controller registers
  through `#[AsController]`.
- Development dependencies: PHPUnit 11.5 → 13.3, testing-framework 9.6 → 9.7,
  PHPStan 2.1 → 2.2, phpstan-typo3 3.0 → 3.1, typo3-rector 3.14 → 3.16,
  php-cs-fixer 3.0 → 3.95; `symfony/process` constrained to `^7.4` as TYPO3
  itself requires. Rector now also checks the PHP 8.4 level set; Fractor
  keeps XLIFF at two-space indentation.
- CI runs PHP 8.5 as a required leg and the functional suite against both
  supported nr_llm lines (0.34, 0.35).

### Fixed

- `<sf:markdown>` received HTML-escaped Markdown, so code examples showed
  `&lt;Type&gt;` and `>` quotes became paragraphs on skill detail pages.
- The runner column no longer shows the placeholder `none` for runs that never
  reached a runner.

### Removed

- `Resources/Private/Language/locallang_mod.xlf` (replaced by
  `Modules/skills.xlf`).

## 1.6.3 — 2026-09-20

### Fixed

- The Solr results template no longer prints a hardcoded English "Skills"
  heading. It is registered at `templateRootPaths.200` and so applies to every
  site that resolves the `webconsulting/skillflow-solr` set, including sites
  that reach it only through an optional dependency — which gave those sites a
  second top-level heading, in the wrong language and about the wrong subject.
  The page title supplies the heading instead, and the results region keeps its
  own screen-reader one.

## 1.6.1 — 2026-09-13

### Fixed

- `skillflow:solr:index` no longer fails a multi-site run because some sites
  do not index skills. A site can be Solr-enabled for its own content without
  the `webconsulting/skillflow-solr` site set; those sites are skipped and
  listed instead of raising an error, and the exit code now reflects only the
  sites that actually index skills. An explicitly requested `--site` without
  the set still fails, as does a run in which no site indexes skills at all.

## 1.6.0 — 2026-09-12

### Added

- `skillflow:skills:sync <source>|--all` runs nr_llm's `SkillSyncService` for
  one source (uid or title) or every enabled source and prints the sync
  status with the created / updated / disabled-on-change / orphaned /
  injection-blocked counts. Exit code 1 when a source is unknown or disabled,
  ends in status `error` or is locked by a running sync. nr_llm 0.34 ships no
  CLI for this.
- `skillflow:skills:enable [--source=<uid|title>] [--all] <skills…>` enables
  reviewed skills the way nr_llm's backend toggle does: Extbase update,
  `persistAll()` and an append-only `enabled` row in `tx_nrllm_skill_audit`.
  Orphaned skills are refused (exit code 1 with an explicit list, skipped with
  `--all`). Skills may be given as uid, `<source uid>:<path>` identifier, the
  path alone with `--source`, or a unique name.
- `skillflow:skills:list [--source] [--enabled]` tabulates uid, identifier,
  name, source, enabled, support badge and orphaned state.
- `SkillAdministrationService` as the single place Skillflow touches nr_llm's
  skill API; functional tests run the commands end to end with a fixture
  extension that aliases `GitHubClientInterface` to an in-memory fake.

### Changed

- Requires TYPO3 14.3.7+ (TYPO3-CORE-SA-2026-022), PHP 8.4+, nr_llm 0.34.x,
  EXT:solr 14.0.1+ and CommonMark 2.10+.
- Complete nr_llm ownership of skills: the duplicate import, attachment,
  review and quarantine implementations and their commands/events
  (`skillflow:sync`, `skillflow:import-rules`, `skillflow:check`,
  `AfterSkillsSyncedEvent`) are gone; Skillflow keeps assignments and runs.
- Workflow and detail screens repaired; hidden, disabled and orphaned skills
  are blocked through every execution path.
- Backend page/web-mount and report access enforced; reviews only auto-run
  after successful workspace stage transitions.
- Solr indexing scoped to skills, root records included, incomplete batches
  reported; TYPO3's Schema API and Solr's own search translations are used.
- Flue-era wording removed from the engine seam (`ContextAwareSkillRunnerInterface`,
  `skillflow:run --engine`, `SkillRunResult::externalRef`, `defaultEngine`);
  the seam and the `defaultEngine`/`engineFallback` settings are unchanged.
- Quality baseline: PHPStan level 8 (phpstan-typo3 + phpstan-phpunit, no
  baseline) over `Classes`, `Configuration` and `Tests`; php-cs-fixer with the
  TYPO3 ruleset; PHPUnit configs in `Build/phpunit/`; a single CI workflow
  (`ci.yml`: lint, cgl, phpstan + audit, unit on PHP 8.4 and 8.5 as allowed
  failure, functional on MariaDB 10.11).
- Documentation is a TYPO3 RST manual under `Documentation/` (`guides.xml`);
  the README is condensed to install, configure, use and develop.

### nr_llm coupling (pinned to `^0.34`, verified against v0.34.0)

- Public API used: `Service\Skill\SkillSyncService` (`final`, `sync()`),
  `Service\Skill\SkillAuditService`, `Domain\ValueObject\SyncResult`,
  `Domain\Enum\{SyncStatus,SkillAuditEvent}`, `Domain\Model\{Skill,SkillSource}`.
- `@internal` API used (ADR-127): `Domain\Repository\SkillRepository` and
  `Domain\Repository\SkillSourceRepository`, limited to the base Extbase
  methods (`findAll`, `findByUid`, `update`) and `findBySource()`. Both are
  confined to `SkillAdministrationService`.

## 1.5.0 and earlier

See the Git history; no changelog was kept before 1.6.0.
