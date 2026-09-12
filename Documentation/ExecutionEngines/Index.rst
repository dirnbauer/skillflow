..  _execution-engines:

=================
Execution engines
=================

Skillflow runs nr_llm-managed skill prose through the built-in runner chain
or through a context-aware engine registered by another extension.

..  _built-in-runners:

Built-in runners
================

:php:`RunnerFactory` reads the :confval:`runner` extension setting:

``api``
    Use nr_llm's effective default configuration when available, otherwise
    the direct Anthropic Messages API.
``anthropic``
    Force the direct Anthropic runner.
``cli``
    Use Claude Code in print mode with the skill's allowed tools.

nr_llm owns :sql:`tx_nrllm_skill`. Importers, attachment storage, license
checks and SkillSpector scanning are not part of Skillflow.

..  _register-engine:

Register a context engine
=========================

Implement :php:`\Webconsulting\Skillflow\Runner\ContextAwareSkillRunnerInterface`
in an autoconfigured service. :file:`Configuration/Services.php` tags every
implementation — in any extension — with ``skillflow.context_runner``;
Skillflow never references a concrete engine.

..  code-block:: php
    :caption: The engine contract (excerpt)

    interface ContextAwareSkillRunnerInterface
    {
        public function getIdentifier(): string;
        public function canRun(array $skill, SkillRunContext $context): bool;
        public function wantsCollectedContent(): bool;
        public function runInContext(array $skill, SkillRunContext $context, string $content = '', array $files = []): SkillRunResult;
    }

*   :php:`getIdentifier()` returns a stable identifier; ``classic`` is
    reserved for the built-in chain.
*   :php:`canRun()` performs a fast availability check without throwing.
*   :php:`wantsCollectedContent()` decides whether Skillflow collects the
    workspace content first. Engines that read the record through their own
    tools return :php:`false` and receive an empty ``$content``.
*   :php:`runInContext()` returns a :php:`SkillRunResult`; use status
    ``failed`` for failures and ``pending`` for asynchronous acceptance. It
    must not throw.

:php:`SkillRunContext` contains the target table and UID, workspace, stage,
resolved instructions, triggering backend user, requested engine and the
pre-created run UID. The backend user identifies the actor for the audit
trail; it does not authorize impersonation by an external engine.

The optional ``$files`` argument remains in the public runner interfaces for
integrations. Skillflow passes an empty array and does not materialize files.

..  _engine-selection:

Selection and fallback
======================

Precedence is the explicit run request, the skill's ``engine`` front matter,
then :confval:`defaultEngine`. ``classic`` forces the built-in chain.

For a missing or unavailable context engine, :confval:`engineFallback` = ``1``
permits the classic chain and records the requested and used engines. With
fallback disabled the run is blocked. A failure after execution has started
never causes an automatic second provider call.

..  _run-records-events:

Run records and events
======================

:php:`SkillExecutionService::runSkillOnRecord()` inserts the
:sql:`tx_skillflow_run` row before execution and updates it afterwards. The
stable UID lets engines cross-link their own records. Lifecycle and
local-environment checks apply to all engines; hidden, disabled and orphaned
skills are blocked centrally.

..  list-table::
    :header-rows: 1

    *   -   Event
        -   Purpose
    *   -   :php:`BeforeSkillRunEvent`
        -   Change engine or instructions, or prevent execution, once the run
            UID exists.
    *   -   :php:`AfterSkillRunEvent`
        -   Read the result, context, run UID and the requested/used engines.

:php:`SkillRunResult` exposes ``status``, ``output``, ``runner``, an optional
``verdict``, ``score`` (``-1`` when absent), ``resultJson``,
``externalEngine``, ``externalRef`` (an engine-side reference such as
``tx_myengine_run:123``) and ``externalUrl``. Statuses are ``success``,
``failed``, ``blocked`` and ``pending``; the orchestrator uses ``running``
until a result arrives.

Runs left in ``running`` for more than 30 minutes are failed when the module
lists runs. ``pending`` is preserved: the asynchronous engine must settle it.
Publishing or discarding workspace content does not remove audit records.
Auto-run only follows successful workspace stage transitions.

:php:`AfterSkillsSyncedEvent` was removed with the old importer.
Synchronization consumers integrate with nr_llm instead — for example by
running :ref:`command-skills-sync` and reading nr_llm's audit trail.
