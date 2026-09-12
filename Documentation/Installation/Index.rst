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

Search catalogue
================

Include the site set :yaml:`webconsulting/skillflow-solr` in the site
configuration and set :typoscript:`plugin.tx_solr.index.queue.skills.detailPageId`
to a page that contains the :guilabel:`Skill detail` content element
(CType :php:`skillflow_skilldetail`). Storage folders outside the site go into
:typoscript:`plugin.tx_solr.index.queue.skills.additionalPageIds`; root
records (PID ``0``) are always included.

..  code-block:: bash
    :caption: Index the skills queue

    vendor/bin/typo3 skillflow:solr:index --site=<site-identifier>

See :ref:`usage-catalogue` for the catalogue fields and :ref:`command-solr-index`
for the command options.
