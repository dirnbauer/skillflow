# skillflow — Agent Skills for TYPO3 Workspaces

Brings [Anthropic-style agent skills](https://docs.claude.com/en/docs/agents-and-tools/agent-skills) (folders with a
`SKILL.md`: YAML frontmatter `name`/`description` + markdown instructions) into the TYPO3 backend and wires them into
the workspace review workflow.

**⚠️ Local installations only.** Skill execution is hard-gated to local DDEV development installations — see
[Security](#security).

## Features

- **Skill records** (`tx_skillflow_skill`): editable in the backend with the SKILL.md structure — name, identifier,
  description, markdown body (code editor), `allowed-tools`, extra frontmatter as JSON.
- **Folder import**: scans a configurable project folder (default `<project>/skills/`, each subfolder containing a
  `SKILL.md`) and imports/updates skills.
- **Repository import**: point a repository record at a GitHub/GitLab/Gitea URL (or a direct `.zip`). Sync downloads
  the archive, imports all skills and **updates existing ones in place** — uids stay stable, so workspace-stage and
  page assignments survive re-syncs. Private repos: store the *name* of an env var holding the token
  (e.g. `GITHUB_TOKEN`); the token itself never touches the database.
- **Backend module** *Content → Skills*: list/edit skills, manage repositories, trigger imports, run skills on pages,
  inspect run reports.
- **Workspace integration**:
  - Assign skills to any custom workspace stage (*Skills* tab on the stage record). With *auto-run* enabled, the
    skills review a record whenever it is sent to that stage; the report is stored and a notification is shown.
  - Per workspace: *auto workflow for new elements* — new records created in the workspace are automatically sent to
    a configured stage, so every new element immediately enters the review workflow (and its skills).
- **Page skills**: assign QM skills (SEO, tone of voice, content QA, …) to a page (*Skills* tab in page properties)
  and run them from the module against the page **in your current workspace** (draft content is reviewed via
  workspace overlays).
- **CLI**: `vendor/bin/typo3 skillflow:sync` (cron-able) refreshes the folder and all repositories.

## Runners & MCP

Configured in *Settings → Extension Configuration → skillflow*:

| Runner | How it works | MCP support |
|---|---|---|
| `api` (default) | Anthropic Messages API; the skill body becomes the system prompt. Key read from env var (`ANTHROPIC_API_KEY` by default). | Yes — **remote** MCP servers via the Anthropic MCP connector: put a JSON array into `mcpServersJson`. |
| `cli` | Executes the local Claude Code CLI in print mode with the project root as cwd. | Yes — **local** MCP servers from the project's `.mcp.json` are available, but only tools whitelisted in the skill's `allowed-tools` frontmatter are permitted; everything else is denied in print mode. |

So: yes, skills can use MCP — remote servers through the API connector, local servers through the CLI runner. The
`allowed-tools` frontmatter is the per-skill permission boundary for the CLI runner.

## Security

Read this before using the extension.

1. **Local-only execution (enforced).** Skill runs send editorial content to an AI model and (with the CLI runner)
   execute a local binary that can use tools. Execution is therefore blocked unless **both** are true:
   - TYPO3 application context is `Development`, and
   - the process runs inside DDEV (`IS_DDEV_PROJECT=true`).

   The check sits in `EnvironmentGuard` and is enforced on every run (module, stage auto-run, CLI). The
   `requireLocalEnvironment` toggle exists for lab experiments only — **never disable it on shared, staging or
   production systems.** Editing/importing skills is allowed everywhere; only *execution* is gated.
2. **Credentials.** No API keys or repo tokens are stored in the database or in records. The extension only stores
   the *names* of environment variables (`apiKeyEnvVar`, repository `token_env_var`). Put the actual secrets into
   your local DDEV env (e.g. `.ddev/config.local.yaml` → `web_environment`), which stays out of git.
3. **Treat skills like code.** A skill is a prompt that steers an AI over your content; with the CLI runner it can
   also invoke whitelisted tools. Review skills from third-party repositories before importing, prefer pinned
   branches/tags, and keep `allowed-tools` minimal. Repository sync only happens when an admin triggers it.
4. **Prompt injection.** Record content is wrapped as data and the system prompt instructs the model to treat it as
   data, but prompt injection can never be fully excluded — another reason execution is restricted to local
   installations and reports are *suggestions*, never auto-applied changes.
5. **Data egress.** With the `api` runner, record content leaves the machine (Anthropic API). On a local lab with
   demo content that is fine; do not point this at confidential data.
6. **Permissions.** Repositories are `adminOnly`; folder/repo imports in the module are admin-gated. Editors only
   need list/module access plus read access to skills to run them. Reports are stored server-side
   (`tx_skillflow_run`) and shown in the module.

## Quick start

```bash
composer require webconsulting/skillflow:@dev
ddev exec vendor/bin/typo3 extension:setup
# put your key into the DDEV web environment:
#   .ddev/config.local.yaml: web_environment: ["ANTHROPIC_API_KEY=sk-ant-..."]
ddev restart
ddev exec vendor/bin/typo3 skillflow:sync     # imports <project>/skills/*
```

Then, in the backend:

1. *Content → Skills*: check the green "local installation" banner, see imported skills.
2. Edit a workspace → custom stage → *Skills* tab: assign skills + enable auto-run.
3. Workspace record → *Skills* tab: enable auto-workflow for new elements and pick the stage.
4. Page properties → *Skills* tab: assign SEO/tone/QA skills; run them from the module.

## Skill format

```markdown
---
name: seo-optimizer
description: Reviews page titles, meta descriptions and headings for SEO.
allowed-tools: Read, Grep
---

# SEO Optimizer

When reviewing content, check ...
```
