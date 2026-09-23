..  _configuration:

=============
Configuration
=============

All settings live in the extension configuration
(:guilabel:`Admin Tools > Settings > Extension Configuration > skillflow`).

..  _configuration-runner:

Runner
======

..  confval:: runner
    :type: options
    :default: ``api``

    ``api`` prefers the LLM connection configured in nr_llm and falls back to
    the Anthropic Messages API with the key from :confval:`apiKeyEnvVar`;
    ``anthropic`` forces that direct API; ``cli`` executes the local Claude
    Code CLI.

..  confval:: model
    :type: string
    :default: ``claude-sonnet-5``

    Model id used by the direct Anthropic runner. A model set before 1.8.0
    (for example ``claude-sonnet-4-6``) stays; only an empty setting uses
    the default.

..  confval:: apiKeyEnvVar
    :type: string
    :default: ``ANTHROPIC_API_KEY``

    Name of the environment variable that holds the Anthropic API key. The
    key itself is never stored in the database.

..  confval:: claudeBinary
    :type: string
    :default: ``claude``

    Path or name of the Claude Code executable (``cli`` runner).

..  confval:: maxTokens
    :type: int
    :default: ``2048``

    Output token budget for the API runners.

..  confval:: mcpServersJson
    :type: string
    :default: empty

    JSON array of remote MCP servers for the Anthropic MCP connector, used by
    the direct Anthropic runner. Each entry needs ``name`` and ``url`` and may
    carry ``authorization_token``, for example
    ``[{"name":"typo3","url":"https://example.org/mcp","authorization_token":"…"}]``.
    Skillflow sends them with the ``mcp-client-2025-11-20`` beta and adds the
    ``mcp_toolset`` entry the connector requires for every server. A
    ``tool_configuration`` block in the format of the retired 2025-04-04 beta
    is translated into the toolset's allowlist. Empty disables the connector.

..  confval:: mcpConfigJson
    :type: string
    :default: empty

    Claude Code :file:`.mcp.json` content for the ``cli`` runner, for example
    ``{"mcpServers":{"typo3":{"command":"vendor/bin/typo3","args":["mcp:server"]}}}``.
    Allow the tools per skill via ``allowed-tools`` or ``abilities``
    (see :ref:`abilities`). Empty disables MCP.

..  confval:: abilitiesMcpServer
    :type: string
    :default: ``typo3``

    Name of the server in :confval:`mcpConfigJson` that serves the TYPO3
    abilities registry. A skill's ``abilities`` become the allowed tools
    ``mcp__<abilitiesMcpServer>__ability_<namespace>_<name>``. Letters,
    digits, ``-`` and ``_`` (not ``__``); anything else falls back to
    ``typo3``.

..  _configuration-engines:

Execution engines
=================

..  confval:: defaultEngine
    :type: string
    :default: ``classic``

    Engine used when neither the run request nor the skill's ``engine``
    front matter selects one. ``classic`` is the built-in runner chain; other
    values match engines registered by other extensions through the
    ``skillflow.context_runner`` DI tag (see :ref:`execution-engines`).

..  confval:: engineFallback
    :type: boolean
    :default: ``1``

    When the selected context-aware engine is unavailable, fall back to the
    classic chain instead of blocking the run.

..  _configuration-security:

Security
========

..  confval:: requireLocalEnvironment
    :type: boolean
    :default: ``1``

    Only allow skill execution when TYPO3 runs in the ``Development`` context
    inside a DDEV project (``IS_DDEV_PROJECT=true``). Do not disable this on
    shared or production systems.

..  warning::

    Model calls transmit editorial content to the configured provider, and
    the CLI runner may use the allowed tools. Reports remain advisory. Store
    provider credentials in nr_llm's vault or in environment variables, never
    in skill records.
