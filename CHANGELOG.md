# Changelog

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
