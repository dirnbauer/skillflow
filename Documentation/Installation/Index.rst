..  _installation:

============
Installation
============

..  code-block:: bash
    :caption: Install with Composer

    composer require webconsulting/skillflow
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

:bash:`extension:setup` creates the assignment fields on :sql:`pages`,
:sql:`be_users` and :sql:`sys_workspace_stage` and the
:sql:`tx_skillflow_run` table. nr_llm and EXT:solr are installed as
dependencies; configure an LLM provider in nr_llm's :guilabel:`LLM` module
before running skills.

..  _installation-solr:

Skills search
=============

Include the site set :yaml:`webconsulting/skillflow-solr` in the site
configuration, **after** the sets that bring EXT:solr's default TypoScript
(EXT:solr's own set, a theme's Solr defaults): its page-scoped search
settings must load last. Then set the site settings
(:file:`config/sites/<site>/settings.yaml`):

..  code-block:: yaml
    :caption: config/sites/<site>/settings.yaml

    # Pages whose Solr results plugin lists skills, in the site's theme
    skillflow.search.pages: '1030'
    # ... or in the typo3.org look
    skillflow.search.typo3Pages: ''
    # Page with the "Skill detail" content element (results link there)
    skillflow.detailPage: 1066
    # Topic pages are found below this slug (optional)
    skillflow.search.groupPages: '/ai/agentic-skills'

Only the listed pages get the skills query, the topics and filters, the
sorting options and the templates; the site's general search keeps its own
facets and templates and lists skills as one more content type. Add a
route enhancer for readable detail URLs:

..  code-block:: yaml
    :caption: config/sites/<site>/config.yaml

    routeEnhancers:
      SkillDetail:
        type: Extbase
        extension: Skillflow
        plugin: SkillDetail
        limitToPages: [1066]
        routes:
          - { routePath: '/{skill}', _controller: 'SkillDetail::show' }
        defaultController: 'SkillDetail::show'
        aspects:
          skill:
            type: PersistedAliasMapper
            tableName: tx_nrllm_skill
            routeFieldName: name

Storage folders outside the site go into
:typoscript:`plugin.tx_solr.index.queue.skills.additionalPageIds`; root
records (PID ``0``) are always included. The constant
:typoscript:`plugin.tx_solr.index.queue.skills.detailPageId` still works when
:yaml:`skillflow.detailPage` is ``0``.

..  code-block:: bash
    :caption: Index the skills queue

    vendor/bin/typo3 skillflow:solr:index --site=<site-identifier>

See :ref:`usage-catalogue` for the search, :ref:`catalogue` for topics,
publishers and install commands, and :ref:`command-solr-index` for the
command options.
