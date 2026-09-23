..  _commands:

========
Commands
========

All commands are Symfony console commands run through :bash:`vendor/bin/typo3`
and can be scheduled with the Scheduler task
:guilabel:`Execute console commands`.

..  _command-skills-sync:

skillflow:skills:sync
=====================

Synchronizes nr_llm skill sources — the CLI counterpart of
:guilabel:`Sync now` in :guilabel:`AI > Authoring > Skills`. nr_llm ships no
sync command of its own.

..  code-block:: bash
    :caption: Usage

    vendor/bin/typo3 skillflow:skills:sync <source>
    vendor/bin/typo3 skillflow:skills:sync --all

``<source>`` is the uid or the title of a skill source; ``--all`` (``-a``)
processes every enabled source. For each source the command prints the sync
status and the number of created, updated, disabled-on-change, orphaned and
injection-blocked skills, followed by nr_llm's error list.

*   New skills are created disabled for review.
*   A re-sync disables enabled skills whose body changed and orphans skills
    that disappeared upstream.
*   The ``abilities`` each skill declares are stored for the Claude CLI
    runner and :ref:`command-skills-check` (``Declaring abilities`` counts
    the skills that declare any, see :ref:`abilities`).
*   Exit code ``1`` when the source is unknown or disabled, or when a sync
    ends in status ``error`` or was skipped because another sync holds the
    lock; ``2`` when neither a source nor ``--all`` was given.

..  _command-skills-enable:

skillflow:skills:enable
=======================

Enables reviewed skills exactly like nr_llm's backend toggle: an Extbase
update, :php:`persistAll()` and an append-only row in the skill audit trail
(:sql:`tx_nrllm_skill_audit`, event ``enabled``). Orphaned skills are never
enabled.

..  code-block:: bash
    :caption: Usage

    vendor/bin/typo3 skillflow:skills:enable [--source=<uid|title>] <skill>...
    vendor/bin/typo3 skillflow:skills:enable [--source=<uid|title>] --all

A ``<skill>`` is a uid, a full nr_llm identifier (``<source uid>:<path>``,
for example ``3:skills/review/SKILL.md``), the path alone when ``--source``
is given, or a unique skill name.

*   With an explicit list, an unknown or orphaned skill yields exit code
    ``1``; skills that are already enabled are reported and skipped.
*   With ``--all`` (``-a``), orphaned skills are skipped and the exit code
    stays ``0``.

..  _command-skills-list:

skillflow:skills:list
=====================

..  code-block:: bash
    :caption: Usage

    vendor/bin/typo3 skillflow:skills:list [--source=<uid|title>] [--enabled]

Prints one row per skill: uid, identifier, name, source, enabled, support
and orphaned state. Support is nr_llm's assessment — ``full``, or
``partial`` when the skill declares tools or references scripts; ``-v`` adds
the assessment notes.

..  _command-skills-check:

skillflow:skills:check
======================

Alias ``skillflow:check``.

..  code-block:: bash
    :caption: Usage

    vendor/bin/typo3 skillflow:skills:check [--enabled] [--as-user=<username>] [--json]

Checks the abilities every skill declares (see :ref:`abilities`) and prints
one row per finding: ``ability_missing`` (not registered, or typo3-abilities
not active) or ``ability_denied`` (denied by the abilities policy, not
exposed to MCP, or a scope the backend user lacks). The running backend user
is checked unless ``--as-user`` names another one. ``--enabled`` limits the
check to enabled, non-orphaned skills; ``--json`` prints
``{"checked": <n>, "findings": [...]}``. Exit code ``1`` when there is at
least one finding, ``2`` for an unknown ``--as-user``.

..  _command-run:

skillflow:run
=============

..  code-block:: bash
    :caption: Usage

    vendor/bin/typo3 skillflow:run <skill> <uid> [--table=pages] [--workspace=0] [--engine=] [--instructions=]

Runs one skill against one record — the counterpart of the run form in
:guilabel:`Content > Skills`. ``<skill>`` is an nr_llm skill uid or
identifier. ``--engine`` selects ``classic`` or a registered context engine
(empty = automatic, see :ref:`engine-selection`); ``--instructions`` adds
per-run instructions. Exit code ``0`` for ``success`` and ``pending``, ``1``
otherwise.

..  _command-solr-index:

skillflow:solr:index
====================

..  code-block:: bash
    :caption: Usage

    vendor/bin/typo3 skillflow:solr:index [--site=<identifier>] [--limit=500]

Rebuilds and processes the ``skills`` index queue for one Solr-enabled site
or, without ``--site``, for all of them. Increase ``--limit`` if the command
reports pending records. Exit code ``1`` when a site fails, items fail to
index or the rebuild stays incomplete.
