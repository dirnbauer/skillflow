..  _upgrade:

=========
Upgrading
=========

..  _upgrade-1-9:

1.9.0
=====

No database changes. Clear the caches.

*   Relative links in a skill body no longer resolve below the detail page's
    URL, where they answered 404: they open the linked skill's detail page or
    the file in the source repository. An overridden
    :file:`SkillDetail/Show.html` keeps the old links until it passes
    ``links="{links}"`` to :html:`<sf:markdown>`.

..  _upgrade-1-8-1:

1.8.1
=====

No database changes. Clear the caches.

*   The skill detail body starts at :html:`<h2>`: SKILL.md headings move one
    level down, so the skill title stays the page's only :html:`<h1>`. An
    overridden :file:`SkillDetail/Show.html` keeps the old levels until it
    passes ``headingOffset="1"`` to :html:`<sf:markdown>`.
*   Pages holding a :guilabel:`Skill detail` element register themselves in
    :typoscript:`lib.pageHeadingOwnedByContent` (see
    :ref:`usage-heading-registry`). With Desiderio 4.4 the theme's page-title
    :html:`<h1>` disappears there; a site package condition that did the same
    can go.

..  _upgrade-1-8:

1.8.0
=====

Run :bash:`vendor/bin/typo3 extension:setup -e skillflow`: it adds
:sql:`tx_nrllm_skill.tx_skillflow_abilities` and
:sql:`tx_skillflow_run.skill_name` / :sql:`skill_identifier`.

*   The default :confval:`model` is ``claude-sonnet-5``. A configured model
    is kept.
*   Skills can declare ``abilities`` (see :ref:`abilities`); run
    ``skillflow:skills:sync`` once to store the declarations, then
    ``skillflow:skills:check``. typo3-abilities stays optional.
*   New setting :confval:`abilitiesMcpServer` (default ``typo3``).
*   The reports name their skill and link the nr_llm record; runs of a
    deleted skill say so. Runs recorded before 1.8.0 have no stored name and
    show the skill uid.
*   For developers: :php:`ClaudeCliRunner` takes :php:`SkillAbilityResolver`
    as a third constructor argument (autowired).

..  _upgrade-1-7:

1.7.0
=====

No database changes and no required action.

*   The Anthropic runner speaks the current MCP connector beta
    (``mcp-client-2025-11-20``) and derives the required ``mcp_toolset``
    entries from :confval:`mcpServersJson`. Existing settings keep working,
    including tool allowlists written for the 2025-04-04 beta.
*   :html:`<sf:markdown>` now receives the Markdown verbatim. Before, Fluid
    HTML-escaped it first, so code examples showed ``&lt;Type&gt;`` and
    ``>`` quotes turned into paragraphs. Templates that render
    ``<sf:markdown>{skill.body}</sf:markdown>`` need no change; clear the
    caches so compiled templates pick up the ViewHelper change.
*   The skill detail plugin sets the page title and meta description from the
    skill (TYPO3's ``recordTitle`` provider).
*   Backend module labels moved to the ``skillflow.modules.skills`` translation
    domain and the module templates to :file:`*.fluid.html`. Both are internal;
    the module route and path are unchanged.
*   For developers: :php:`SkillRunResult` gained ``$runUid`` (set by
    :php:`SkillExecutionService`), the named constructors ``blocked()`` and
    ``failed()`` and :php:`runStatus()`, which returns the new
    :php:`RunStatus` enum. The ``$status`` string and its values are
    unchanged. The extension configuration is read once into the
    :php:`ExtensionSettings` service; runners and the engine resolver receive it
    instead of :php:`ExtensionConfiguration`.

..  _upgrade-1-6:

1.6.0
=====

*   Requirements: TYPO3 14.3.7+, PHP 8.4+, nr_llm 0.34.x, EXT:solr 14.0.1+
    and CommonMark 2.10+. TYPO3 14.3.7 closes TYPO3-CORE-SA-2026-022.
*   New commands ``skillflow:skills:sync``, ``skillflow:skills:enable`` and
    ``skillflow:skills:list`` (see :ref:`commands`). No database changes.
*   The engine seam is unchanged; only the Flue-era examples in its
    documentation were replaced. ``defaultEngine`` and ``engineFallback``
    keep their meaning.
*   For contributors: PHPUnit configurations moved to
    :file:`Build/phpunit/`, php-cs-fixer uses the TYPO3 ruleset and PHPStan
    runs at level 8 (see the README).

..  _upgrade-ownership:

Skill ownership migration
=========================

Older releases imported skills themselves. Since the move to nr_llm ownership
folder/repository/rule import, attachment materialization, local
quarantine/license scanning and the commands ``skillflow:sync`` and
``skillflow:import-rules`` are gone. The name ``skillflow:check`` returned in
1.8.0 as an alias of :ref:`command-skills-check`, which checks abilities.

Manage sources and activation in :guilabel:`AI > Authoring > Skills`.
Skillflow owns assignments and run history. Optional SkillSpector checks
belong to a separate integration; this extension does not install one.

For installations predating nr_llm ownership:

#.  Export the database and the old skills. Retain old tables until their
    contents and historic run references have been reviewed.
#.  Import and review sources in nr_llm, then map former skill identifiers
    to the new records. UIDs from the two skill tables are not
    interchangeable.
#.  Reassign pages, users and stages using
    :sql:`tx_skillflow_nrllm_skills`. Review historic
    :sql:`tx_skillflow_run.skill` references before remapping them.
#.  Remove cron jobs and listeners for the deleted commands and
    :php:`AfterSkillsSyncedEvent`. No database data is deleted or
    automatically remapped.

Already migrated installations retain nr_llm assignments and runs. Review
skills that rely on supporting scripts or assets before enabling them.

..  _upgrade-apply:

Apply and verify locally
========================

..  code-block:: bash
    :caption: Update inside DDEV

    ddev snapshot --name before-skillflow-update
    ddev composer update webconsulting/skillflow netresearch/nr-llm apache-solr-for-typo3/solr 'typo3/*' -W
    ddev typo3 extension:setup
    ddev typo3 upgrade:list
    ddev typo3 referenceindex:update
    ddev typo3 cache:flush
    ddev typo3 cache:warmup

Use the Solr server and configset supported by EXT:solr 14. Replace old
``type:tx_skillflow_skill`` catalogue filters with ``type:tx_nrllm_skill``,
update detail route enhancers, then run
:bash:`skillflow:solr:index --site=<identifier>`. Verify detail and search
pages, assignments, editor permissions, stage auto-run and reports. Inactive
skills must stay absent from search and detail views and blocked through
CLI and module requests.

..  _upgrade-handover:

Deployment handover
===================

Verification runs locally. For each target environment use its reviewed
Composer lock, database backup, schema and wizard steps, cache rebuild and
Solr reindex. Keep hostnames, mail transport and credentials in that
installation, and preserve the local-only execution setting on shared
environments.
