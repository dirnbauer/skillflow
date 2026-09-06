# Execution engines

Skillflow runs nr_llm-managed skill prose through the built-in runner chain or
a context-aware engine registered by another extension.

## Built-in runners

`RunnerFactory` reads the `runner` extension setting:

- `api`: use nr_llm's effective default configuration when available, otherwise
  the direct Anthropic Messages API.
- `anthropic`: force the direct Anthropic runner.
- `cli`: use Claude Code in print mode with the skill's allowed tools.

nr_llm owns `tx_nrllm_skill`. Importers, attachment storage, license checks and
SkillSpector scanning are not part of Skillflow.

## Register a context engine

Implement [`ContextAwareSkillRunnerInterface`](../Classes/Runner/ContextAwareSkillRunnerInterface.php)
in an autoconfigured service. `Configuration/Services.php` tags implementations
with `skillflow.context_runner`.

- `getIdentifier()` returns a stable identifier; `classic` is reserved.
- `canRun()` performs a fast availability check without throwing.
- `wantsCollectedContent()` determines whether Skillflow collects workspace
  content. Engines that read through their own tools can return `false`.
- `runInContext()` returns `SkillRunResult`: use `failed` for failures and
  `pending` for asynchronous acceptance.

`SkillRunContext` contains target table/UID, workspace, stage, resolved
instructions, triggering backend user, requested engine and pre-created run
UID. The backend user identifies the actor for audit; it does not authorize
impersonation by an external engine.

The optional `$files` argument remains in public runner interfaces for
integrations. Skillflow passes an empty array and does not materialize files.

## Selection and fallback

Precedence is the explicit run request, the skill's `engine` frontmatter,
then `defaultEngine`. `classic` forces the built-in chain.

For a missing or unavailable context engine, `engineFallback=1` permits the
classic chain and records the requested/used engines. With fallback disabled,
the run is blocked. A failure after execution starts never causes an automatic
second provider call.

## Run records and events

`SkillExecutionService::runSkillOnRecord()` inserts `tx_skillflow_run` before
execution and updates it afterwards. The stable UID lets engines cross-link
their own records. Lifecycle and local-environment checks apply to all engines;
hidden, disabled and orphaned skills are blocked centrally.

| Event | Purpose |
|---|---|
| `BeforeSkillRunEvent` | Change engine/instructions or prevent execution after the run UID exists. |
| `AfterSkillRunEvent` | Read the result, context, run UID and requested/used engines. |

`SkillRunResult` exposes `status`, `output`, `runner`, optional `verdict`,
`score` (−1 when absent), `resultJson`, `externalEngine`, `externalRef` and
`externalUrl`. Statuses are `success`, `failed`, `blocked`, `pending`; the
orchestrator uses `running` until a result arrives.

Runs left `running` for over 30 minutes are failed when the module lists runs.
`pending` is preserved: the asynchronous engine must settle it. Publishing or
discarding workspace content does not remove audit records. Auto-run only
follows successful workspace stage transitions.

`AfterSkillsSyncedEvent` was removed with the old importer. Synchronization
consumers should integrate with nr_llm instead.
