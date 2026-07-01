# Execution Engines

Skillflow executes skills through pluggable **execution engines**. The built-in
("classic") engines run a skill as a single LLM call against pre-collected record
content: `nr_llm` (when installed and configured), the Anthropic Messages API, or
the local Claude CLI — selected by `RunnerFactory` via the `runner` extension
setting.

Other extensions can register **context-aware engines** that receive the *target
record context* instead of pre-collected content — for example an agent runtime
that reads the record live through MCP tools, runs multi-step loops, and returns
a structured verdict. Skillflow itself stays engine-agnostic: it defines the seam,
dispatches events, persists results, and never references a concrete external
engine by name.

## Concepts

| Term | Meaning |
|---|---|
| Classic chain | The existing `SkillRunnerInterface` runners picked by `RunnerFactory` (`nrllm` / `api` / `cli`). Unchanged. |
| Context runner | A service implementing `ContextAwareSkillRunnerInterface`, auto-registered via DI tag `skillflow.context_runner`. |
| Engine identifier | Stable slug returned by `getIdentifier()` (e.g. `flue`); `classic` is reserved for the classic chain. |
| Verdict | Free-form short string an engine may attach to a run (e.g. `READY`, `NEEDS_WORK`, `BLOCKER`). Skillflow displays it, never interprets it. |

## The seam

### `Webconsulting\Skillflow\Domain\SkillRunContext`

Readonly value object handed to context runners:

```php
final readonly class SkillRunContext
{
    public function __construct(
        public string $table,          // target record table
        public int $recordUid,         // target record uid
        public int $workspaceId,       // workspace at trigger time
        public int $stageUid,          // workspace stage (0 = none)
        public string $instructions,   // per-run instructions, {uid}/{table}/... tokens already resolved
        public int $backendUserUid,    // the TRIGGERING backend user (audit; engines must not impersonate)
        public string $requestedEngine,// what was requested ('' = auto)
        public int $skillRunUid,       // pre-created tx_skillflow_run uid (two-phase persistence)
    ) {}
}
```

### `Webconsulting\Skillflow\Runner\ContextAwareSkillRunnerInterface`

Deliberately does **not** extend `SkillRunnerInterface` — implementors are not
forced to support the content-based path.

```php
interface ContextAwareSkillRunnerInterface
{
    /** Stable engine identifier, e.g. 'flue'. 'classic' is reserved. */
    public function getIdentifier(): string;

    /** Fast availability probe (backend reachable, environment allowed). MUST NOT throw. */
    public function canRun(array $skill, SkillRunContext $context): bool;

    /** Whether SkillExecutionService should run ContentCollector for this engine.
     *  Engines that read the record live (e.g. via MCP) return false and receive $content = ''. */
    public function wantsCollectedContent(): bool;

    /**
     * Execute one skill in the given record context. MUST NOT throw — map every
     * failure to SkillRunResult with status 'failed' (or 'pending' for async).
     *
     * @param array<string, mixed> $skill tx_skillflow_skill row, body token-resolved
     * @param array<int, array<string, mixed>> $files tx_skillflow_file rows
     */
    public function runInContext(array $skill, SkillRunContext $context, string $content = '', array $files = []): SkillRunResult;
}
```

### Registration (nothing to configure)

Skillflow's `Configuration/Services.php` calls
`registerForAutoconfiguration(ContextAwareSkillRunnerInterface::class)` and tags
every implementation with `skillflow.context_runner`. Any extension that defines
an autoconfigured service implementing the interface is thereby registered —
no skillflow configuration, no hard dependency in either direction. When no
implementation exists, the tagged iterator is empty and skillflow behaves exactly
as before.

## Engine resolution

`EngineResolver::resolve(array $skill, SkillRunContext $context): EngineResolution`
picks the engine per run. Precedence:

1. **Per-run request** — `SkillRunContext::$requestedEngine` (run-form select /
   `runSkillOnRecord(..., engine:)`). The value `classic` forces the classic chain.
2. **Skill metadata** — frontmatter key `engine:` (stored in
   `tx_skillflow_skill.metadata` JSON), matched against registered identifiers.
3. **Extension setting** — `defaultEngine` (default `classic`).

`EngineResolution` records both `engineRequested` and `engineUsed`; they diverge
on fallback.

### Fallback semantics

If a context runner was selected but `canRun()` returns `false`:

- `engineFallback = 1` (default): fall back to the classic chain, log a warning.
  The run row records the classic runner; `AfterSkillRunEvent` carries the
  divergent `engineRequested` / `engineUsed`.
- `engineFallback = 0`: the run is persisted as `blocked` with the reason; no
  LLM call happens.

An engine failure *after* selection (`runInContext()` returned `failed`) is never
retried on the classic chain automatically — that would double LLM spend. Re-run
manually.

## Run persistence (two-phase)

`SkillExecutionService::runSkillOnRecord()` inserts the `tx_skillflow_run` row
with status `running` *before* the engine executes and updates it afterwards.
This gives engines a stable `skillRunUid` for cross-linking, and enables
asynchronous settlement:

- Status values: `pending`, `running`, `success`, `failed`, `blocked`.
  `pending` means "the engine accepted the run and continues asynchronously";
  the engine (or its extension) is responsible for updating the row later.
- New columns engines may fill (via `SkillRunResult`):
  `verdict` (varchar 32, free-form), `score` (smallint, −1 = none),
  `result_json` (structured result), `external_engine`, `external_ref`
  (e.g. `tx_flue_run:123`), `external_url` (backend deep link — rendered as a
  generic "Open engine run" button in the Skills module).
- Crash hygiene: rows stuck in `running`/`pending` older than 30 minutes are
  marked `failed` lazily when the module lists runs.

### `SkillRunResult` (extended, backward compatible)

```php
final readonly class SkillRunResult
{
    public function __construct(
        public string $status,               // success | failed | blocked | pending
        public string $output,
        public string $runner,               // engine identifier or classic runner name
        public string $verdict = '',
        public int $score = -1,
        public string $resultJson = '',
        public string $externalEngine = '',
        public string $externalRef = '',
        public string $externalUrl = '',
    ) {}
}
```

## PSR-14 events

| Event | When | Mutability |
|---|---|---|
| `BeforeSkillRunEvent` | After the pending run row exists, before the engine executes | `setEngine()`, `setInstructions()`, `preventRun(reason)` |
| `AfterSkillRunEvent` | After the run row was updated | read-only: skill, context, result, runUid, engineRequested, engineUsed |
| `AfterSkillsSyncedEvent` | At the end of `SkillImportService::importFromPath()` (covers folder scans **and** repository syncs) | read-only: sourceType, repositoryUid, ImportResult, per-skill `{uid, identifier, status, contentHash}` |

Typical consumers: an engine extension exports changed skills to its runtime on
`AfterSkillsSyncedEvent` (use the per-skill `status`/`contentHash` to export
incrementally); audit/notification listeners on `AfterSkillRunEvent`.

## Skill metadata contract

Skillflow interprets exactly one generic frontmatter key:

```yaml
engine: flue        # preferred engine identifier for this skill
```

Everything else in the frontmatter round-trips through
`tx_skillflow_skill.metadata` untouched. Engine extensions may define their own
**namespaced block** (e.g. `flue:`) and interpret it themselves; skillflow never
reads inside such blocks.

## Extension settings

| Setting | Default | Meaning |
|---|---|---|
| `defaultEngine` | `classic` | Engine used when neither the run nor the skill requests one. |
| `engineFallback` | `1` | Fall back to the classic chain when the selected context runner is unavailable. |

## Security posture

- `EnvironmentGuard` runs before engine resolution — the local-only execution
  gate applies to every engine equally. Context runners should additionally
  enforce their own guards inside `canRun()` (defense in depth); a mismatch
  degrades to fallback instead of a hard error.
- `SkillRunContext::$backendUserUid` identifies the triggering editor for audit.
  Engines that talk to TYPO3 APIs must use their own (least-privilege, sandboxed)
  identity — never mint credentials for the triggering user.
