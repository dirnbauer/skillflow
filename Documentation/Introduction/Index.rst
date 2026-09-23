..  _introduction:

============
Introduction
============

..  _what-it-does:

What it does
============

Skillflow — AI-assisted TYPO3 review workflows. Assign nr_llm-managed skills
to TYPO3 pages, backend users and workspace review stages, review draft
content with a configured AI engine and keep status and reports in TYPO3. The
Solr catalogue exposes active skills with metadata, facets and detail pages.

..  _scope:

Scope
=====

*   nr_llm manages skill sources, imports (synchronization), activation and
    the lifecycle of every skill record (:sql:`tx_nrllm_skill`).
*   Skillflow manages assignments, execution and run history
    (:sql:`tx_skillflow_run`), and adds CLI commands for the nr_llm
    operations that deployments need (see :ref:`commands`).
*   Execution defaults to a local DDEV environment; reports are advisory.
*   Supporting scripts and assets of a skill are not materialized, and
    license or SkillSpector scanning is not bundled (the separate
    ``webconsulting/skillspector`` extension provides advisory checks).

..  _requirements:

Requirements
============

..  list-table::
    :header-rows: 1

    *   -   Component
        -   Version
    *   -   TYPO3
        -   14.3.7 or later on the 14.x line
    *   -   PHP
        -   8.4 or later
    *   -   netresearch/nr-llm
        -   0.34.x or 0.35.x
    *   -   apache-solr-for-typo3/solr
        -   14.0.1 or later, with a Solr server and configset from the
            `EXT:solr version matrix
            <https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Appendix/VersionMatrix.html>`__

..  _links:

Links
=====

*   Demo: `typo3-lab.webconsulting.at <https://typo3-lab.webconsulting.at>`__
    (the site may require HTTP authentication)
*   Repository: `github.com/dirnbauer/skillflow
    <https://github.com/dirnbauer/skillflow>`__
*   Issues: `github.com/dirnbauer/skillflow/issues
    <https://github.com/dirnbauer/skillflow/issues>`__
