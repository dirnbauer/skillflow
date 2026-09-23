# Skillflow

Skillflow runs [nr_llm](https://github.com/netresearch/t3x-nr-llm)-managed
skills in TYPO3 page and workspace review workflows. nr_llm owns skill
sources, imports, activation and lifecycle state; Skillflow stores the
assignments (pages, backend users, workspace stages), executes skills against
records, keeps the run reports and exposes active skills in a Solr catalogue.

[TYPO3 Lab](https://typo3-lab.webconsulting.at) ·
[Documentation](Documentation/Index.rst) ·
[Source](https://github.com/dirnbauer/skillflow) ·
[Issues](https://github.com/dirnbauer/skillflow/issues)

## Requirements

- TYPO3 **14.3.7+** on the 14.x line, PHP **8.4+**
- `netresearch/nr-llm` **0.34.x or 0.35.x**, EXT:solr **14.0.1+**
- Optional: `webconsulting/typo3-abilities` **1.3+** for skills that
  declare `abilities`
- For the catalogue: a Solr server/configset from the
  [EXT:solr version matrix](https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Appendix/VersionMatrix.html)

## Install

```bash
composer require webconsulting/skillflow
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

For search, include the `webconsulting/skillflow-solr` site set and point
`plugin.tx_solr.index.queue.skills.detailPageId` to a page with the
`skillflow_skilldetail` content element.

## Configure

Extension settings (Admin Tools → Settings → Extension Configuration):

| Setting | Default | Purpose |
|---|---|---|
| `runner` | `api` | Prefer nr_llm's configured provider, fall back to Anthropic; `anthropic` forces Anthropic, `cli` uses Claude Code. |
| `model` | `claude-sonnet-5` | Model for the direct Anthropic runner (a configured model is kept). |
| `apiKeyEnvVar` | `ANTHROPIC_API_KEY` | Env var holding the Anthropic key (never stored in the DB). |
| `maxTokens` | `2048` | Output token budget for API runners. |
| `claudeBinary` | `claude` | Claude Code executable. |
| `mcpServersJson` | empty | Remote MCP servers (`name`, `url`, optional `authorization_token`) for the Anthropic runner's MCP connector. |
| `mcpConfigJson` | empty | Claude Code `.mcp.json` content. |
| `abilitiesMcpServer` | `typo3` | Server in `mcpConfigJson` that serves the abilities registry; a skill's `abilities` become `mcp__<server>__ability_<ns>_<name>`. |
| `defaultEngine` | `classic` | Built-in chain or a registered context engine. |
| `engineFallback` | `1` | Fall back to the classic chain when an engine is unavailable. |
| `requireLocalEnvironment` | `1` | Only run inside DDEV in Development context. |

Model calls transmit editorial content to the configured provider and reports
are advisory: keep execution local, and keep credentials in nr_llm's vault or
environment variables.

## Use

1. Add skill sources in **AI → Authoring → Skills** (nr_llm) and synchronize
   them there or from the CLI. New skills arrive disabled for review.
2. Review and enable skills in the module or from the CLI.
3. Assign skills in the **Skills** tab of pages, backend users or custom
   workspace stages (optionally with auto-run on stage transitions).
4. Run them in **Content → Skills** on the page selected in the page tree, or
   from the CLI. A single run opens its report; reports keep status, verdict,
   engine and the output rendered as Markdown.
5. Publish the catalogue: the `skillflow_skilldetail` content element shows
   one skill, sets the page title and description from it and follows the
   site's theme tokens; the `webconsulting/skillflow-solr` set indexes and
   lists the skills with translated facets.

```bash
vendor/bin/typo3 skillflow:skills:sync --all                  # every enabled source
vendor/bin/typo3 skillflow:skills:sync "Company skills"       # one source (uid or title)
vendor/bin/typo3 skillflow:skills:list --source=3 [--enabled]
vendor/bin/typo3 skillflow:skills:enable 12 "3:skills/review/SKILL.md"
vendor/bin/typo3 skillflow:skills:enable --source=3 --all     # skips orphaned skills
vendor/bin/typo3 skillflow:skills:check [--as-user=editor] [--json]  # abilities: ability_missing / ability_denied
vendor/bin/typo3 skillflow:run <skill-uid-or-identifier> <page-uid> [--engine=…]
vendor/bin/typo3 skillflow:solr:index --site=<site-identifier>
```

### Abilities

A skill can declare the [typo3-abilities](https://github.com/dirnbauer/typo3-abilities)
abilities it needs in its `SKILL.md` front matter:

```yaml
abilities: [news/list, solr/index-queue]
```

`skillflow:skills:sync` stores the list, the Claude CLI runner allows exactly
those abilities as MCP tools (`--allowedTools mcp__typo3__ability_news_list,…`),
and `skillflow:skills:check` (alias `skillflow:check`) reports
`ability_missing` and `ability_denied`. The Skills module and the detail
plugin show the declared abilities. typo3-abilities is optional; without it
every declared ability is reported as missing. See
[Abilities](Documentation/Abilities/Index.rst).

`skillflow:skills:sync` exits non-zero when a source ends in status `error`;
`skillflow:skills:enable` mirrors nr_llm's backend toggle (Extbase update plus
audit row) and refuses orphaned skills. Hidden, disabled and orphaned skills
never run and stay out of public detail views.

## Develop

```bash
ddev start && ddev composer install
ddev exec Build/Scripts/runTests.sh -s lint        # php -l
ddev exec Build/Scripts/runTests.sh -s cgl         # php-cs-fixer, TYPO3 ruleset (cgl:fix to apply)
ddev exec Build/Scripts/runTests.sh -s phpstan     # level 8, no baseline
ddev exec Build/Scripts/runTests.sh -s unit        # Build/phpunit/UnitTests.xml
ddev exec Build/Scripts/runTests.sh -s functional  # Build/phpunit/FunctionalTests.xml (SQLite)
ddev composer rector && ddev composer fractor && ddev composer audit
```

`.github/workflows/ci.yml` runs the same gates: lint, cgl, phpstan, unit on
PHP 8.4 and 8.5, functional against MariaDB 10.11 on PHP 8.4 and 8.5 and once
more with the lowest supported nr_llm line (0.34). Set the
`typo3Database*` environment variables to run functional tests on MariaDB
locally. `ddev setup-site` creates a disposable browser test site at
`https://skillflow.ddev.site/typo3/`.

## Docs

`Documentation/` is a TYPO3 RST manual (`guides.xml`): introduction,
installation, configuration, usage, [commands](Documentation/Commands/Index.rst),
[abilities](Documentation/Abilities/Index.rst),
[execution engines](Documentation/ExecutionEngines/Index.rst) and
[upgrading](Documentation/Upgrade/Index.rst). Release notes are in
[CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
