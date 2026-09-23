..  _abilities:

=========
Abilities
=========

A skill can declare the abilities it needs from the
`typo3-abilities <https://github.com/dirnbauer/typo3-abilities>`__ registry.
Skillflow stores the declaration, lets a Claude CLI run use exactly those
abilities as MCP tools and checks that each of them exists and is allowed.

..  _abilities-declare:

Declaring abilities
===================

Add an ``abilities`` key to the front matter of :file:`SKILL.md`, as a YAML
list or one comma-separated string:

..  code-block:: yaml
    :caption: SKILL.md

    ---
    name: News digest
    description: Summarises the latest news and refreshes the search index
    abilities: [news/list, solr/index-queue]
    ---

Names are ability names as the registry lists them
(:bash:`vendor/bin/typo3 abilities:list`). :ref:`command-skills-sync` parses
the declaration and stores it in :sql:`tx_nrllm_skill.tx_skillflow_abilities`
(shown read-only on the :guilabel:`Skillflow abilities` tab of the skill
record). Skills synchronized with nr_llm's :guilabel:`Sync now` button are
read from their front matter until the next ``skillflow:skills:sync``.

..  _abilities-runner:

Claude CLI runs
===============

The ``cli`` runner passes ``--allowedTools`` to Claude Code. Every declared
ability that the registry knows and exposes to MCP becomes one rule,
``mcp__<server>__`` followed by the ability's MCP tool name
(``ability_<namespace>_<name>``), for example
``mcp__typo3__ability_news_list``. ``<server>`` is
:confval:`abilitiesMcpServer`, the name of the abilities server in
:confval:`mcpConfigJson`:

..  code-block:: json
    :caption: mcpConfigJson

    {"mcpServers": {"typo3": {"command": "vendor/bin/typo3", "args": ["mcp:server"]}}}

The rules are added to the skill's own ``allowed-tools``. A skill with
``allowed-tools: []`` runs without Claude Code's built-in tools but keeps its
abilities. The MCP server applies the abilities policy when a tool is called.

..  _abilities-check:

Checking the declarations
=========================

:ref:`command-skills-check` resolves every declaration for the MCP surface a
skill run uses and reports two findings:

``ability_missing``
    The ability is not registered in this installation, or typo3-abilities
    is not active.

``ability_denied``
    The ability is registered, but the site policy
    (:file:`config/abilities-policy.yaml`) denies it or requires human review,
    it is not exposed to MCP, or the backend user lacks one of its scopes.

The :guilabel:`Content > Skills` module shows the same status next to the
skills of the selected page, checked for the logged-in backend user, and the
report of a run lists the abilities of its skill. The skill detail plugin
lists the declared abilities.

..  _abilities-optional:

typo3-abilities is optional
===========================

Skillflow runs without typo3-abilities. Its registry arrives as a nullable
constructor argument of :php:`SkillAbilityResolver`, so an installation
without the extension (or with the package installed but not active)
compiles as before: skills still synchronize, the runner adds no ability
tools, and the check reports every declared ability as ``ability_missing``.
