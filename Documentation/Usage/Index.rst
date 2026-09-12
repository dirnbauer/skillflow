..  _usage:

=====
Usage
=====

..  _usage-workflow:

Workflow
========

#.  Add and synchronize skill sources in :guilabel:`AI > Authoring > Skills`
    (nr_llm), or from the command line with :ref:`command-skills-sync`.
    Repository sources discover :file:`skills/<name>/SKILL.md`; new skills are
    created disabled for review. A re-sync disables enabled skills whose body
    changed and orphans skills that disappeared upstream.
#.  Review the skills (:ref:`command-skills-list`) and enable them in the
    module or with :ref:`command-skills-enable`.
#.  Assign skills in the :guilabel:`Skills` tab of page properties, backend
    users or custom workspace stages. Enable :guilabel:`Auto-run` on a stage
    to run its skills after a successful stage transition.
#.  Open :guilabel:`Content > Skills`, select a page and run one skill or the
    page's assigned skills, or use :ref:`command-run`. Reports contain the
    status, output and engine details.

..  _usage-rules:

Rules
=====

*   Workspace reviews collect the draft content of the selected workspace.
*   Runs are unversioned audit records; publishing or discarding workspace
    content does not remove them.
*   Hidden, disabled and orphaned skills cannot run and do not appear in
    public detail views.
*   Editors can only run skills on readable pages inside their web mounts and
    see the reports they may access in their current workspace.
*   Auto-run only follows successful workspace stage transitions.

..  _usage-catalogue:

Search catalogue
================

The :yaml:`webconsulting/skillflow-solr` site set indexes active nr_llm skills
into the ``skills`` index queue (see :ref:`installation-solr`).

*   Category and tags come from the editable catalogue fields on the nr_llm
    record, falling back to front matter; license and version come from front
    matter.
*   Source, allowed-tool, trust and support facets are available.
*   Filter search listings with ``type:tx_nrllm_skill`` and use
    ``tx_nrllm_skill`` in detail route enhancers. The detail plugin accepts an
    nr_llm skill UID.
