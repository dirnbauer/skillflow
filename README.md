# Skillflow — skills in TYPO3 review workflows

Skillflow connects nr_llm-managed skills to TYPO3 pages, backend users and
workspace review stages. It stores assignments and run reports; nr_llm owns
skill sources, imports, activation and lifecycle state.

[TYPO3 Lab](https://typo3-lab.webconsulting.at) ·
[Source](https://github.com/dirnbauer/skillflow) ·
[Issues](https://github.com/dirnbauer/skillflow/issues)

## Requirements

- TYPO3 **14.3.6+ on the 14.x line**, PHP **8.4+**.
- `netresearch/nr-llm` **0.34.x**, EXT:solr **14.0.1+**.
- For search: a Solr server/configset matching the
  [EXT:solr version matrix](https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Appendix/VersionMatrix.html).

## Install and use

```bash
composer require webconsulting/skillflow:dev-main
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

1. Add and synchronize sources in **AI → Authoring → Skills** (nr_llm).
   Review and enable skills there before assigning them.
2. Assign skills in the **Skills** tab of page properties, backend users or
   custom workspace stages. Enable stage auto-run when required.
3. Open **Content → Skills**, select a page and run a skill or the
   page's assigned skills. Reports contain status, output and engine details.

```bash
vendor/bin/typo3 skillflow:run <skill-uid-or-identifier> <page-uid>
```

Workspace reviews collect draft content in the selected workspace. Runs are
unversioned audit records. Hidden, disabled and orphaned skills cannot run or
appear in public detail views. Editors can only run skills on readable pages
in their web mounts and view accessible reports in their current workspace.

## Execution and configuration

Configure the extension in TYPO3's extension settings:

| Setting | Default | Purpose |
|---|---|---|
| `runner` | `api` | Prefer nr_llm's configured provider; fall back to Anthropic. `anthropic` forces Anthropic; `cli` selects Claude Code. |
| `model` | `claude-sonnet-4-6` | Model for the direct Anthropic runner. |
| `apiKeyEnvVar` | `ANTHROPIC_API_KEY` | Environment variable containing the direct Anthropic key. |
| `maxTokens` | `2048` | Output token budget for API runners. |
| `claudeBinary` | `claude` | Claude Code executable. |
| `mcpServersJson` | empty | Remote MCP servers for the direct Anthropic runner. |
| `mcpConfigJson` | empty | MCP configuration for Claude Code. |
| `defaultEngine` | `classic` | Built-in runner chain or a registered context engine. |
| `engineFallback` | `1` | Allow fallback when a context engine is unavailable. |
| `requireLocalEnvironment` | `1` | Require Development context inside DDEV. |

Keep execution local. Model calls transmit editorial content to the configured
provider; the CLI can use allowed tools. Reports remain advisory. Store
provider credentials in nr_llm's vault or environment variables, never in skill
records. Supporting files are not imported or materialized by Skillflow; this
integration supplies skill prose.

See [Execution engines](Documentation/ExecutionEngines.md) for interfaces and
events, and [Upgrading](Documentation/Upgrade.md) before updating an older site.

## Search catalogue

Include the `webconsulting/skillflow-solr` site set. Set
`plugin.tx_solr.index.queue.skills.detailPageId` to a page containing
`skillflow_skilldetail`. Configure
`plugin.tx_solr.index.queue.skills.additionalPageIds` for storage folders
outside the site. Root records (PID `0`) are always included. The detail plugin
accepts an nr_llm skill UID.

```bash
vendor/bin/typo3 skillflow:solr:index --site=<site-identifier>
```

The command processes only the `skills` queue. Increase `--limit` if it reports
pending records. Category/tags use editable catalogue fields on nr_llm records,
falling back to frontmatter; license/version come from frontmatter. Source,
allowed-tool, trust and support facets are available. Filter search listings
by `type:tx_nrllm_skill`, and use `tx_nrllm_skill` in detail route enhancers.

## Local development

```bash
ddev start
ddev composer install
ddev exec Build/Scripts/runTests.sh -s unit -p 8.4
ddev exec Build/Scripts/runTests.sh -s functional -p 8.4
ddev composer analyse
ddev composer lint
ddev composer rector
ddev composer fractor
ddev composer audit
```

Functional tests use an isolated SQLite database. Create the disposable browser
test site with `ddev setup-site`, then open
`https://skillflow.ddev.site/typo3/` (admin / `Joh316!!`). Generated files stay
under `.Build/`; DDEV owns the local database.

[Website information](Documentation/Website.md) contains the current Lab copy
and project links. The public website may require HTTP authentication.
