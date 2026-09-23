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

..  _usage-module:

The Skills module
=================

:guilabel:`Content > Skills` works on the page selected in the page tree.

*   **Run a skill**: the skill list shows every active skill once, grouped
    into the skills assigned to the page, those assigned to your backend user
    and all others. Choose an engine when other extensions register one, and
    add instructions for this run; ``{uid}``, ``{table}``, ``{pid}``,
    ``{title}`` and ``{workspace}`` are replaced with the page's values.
    :guilabel:`Run assigned skills` runs every skill assigned to the page.
    While a skill runs the form is marked busy and announces the progress to
    screen readers; a second click does not start the run again.
*   After a single run the module opens its report; after several runs it
    returns to the page with one message per run. Runs are started by a
    ``POST`` followed by a redirect, so reloading a report never repeats a run.
*   **Reports** lists the runs of the selected page (the page record and its
    content elements) or, with :guilabel:`All pages`, every run you may read,
    newest first and paginated. Status, verdict, score and engine are shown
    per run. The skill's name links to its nr_llm record for users who may
    edit it; a run whose skill was deleted in nr_llm is marked
    :guilabel:`Deleted skill` with the name and identifier it ran under.
*   **Abilities of the skills** lists the abilities the page's skills declare
    and whether each is available to you, missing or denied (see
    :ref:`abilities`).
*   A report shows the run's metadata, the instructions it received and the
    output rendered as Markdown. Raw HTML in the output is escaped and unsafe
    links are removed; the plain text and any structured engine result are
    available below it.
*   :guilabel:`Edit assignments` opens the page properties; administrators
    reach nr_llm's skill sources from the module header.

The module follows the backend's light and dark scheme and is translated into
English and German.

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
*   Facet and sorting labels are translated (English and German) through the
    site language.

..  _usage-detail:

Skill detail plugin
===================

The :guilabel:`Skill detail` content element (``skillflow_skilldetail``)
renders one active skill: name, description, category, source, tags, the skill
ID with a copy button, license, version, allowed tools and the Markdown body.

*   The skill's name becomes the page title and its description the meta
    description, through TYPO3's record title provider, so every detail URL
    has its own title in browser tabs, bookmarks and search results.
*   The default template ships a small stylesheet that follows the site's
    theme: it uses the shadcn-style custom properties (``--foreground``,
    ``--muted-foreground``, ``--border``, ``--card``, ``--primary``,
    ``--radius``) when the site defines them and otherwise derives every
    colour from the text colour, so light and dark themes both work. It is
    loaded only on pages that render the plugin.
*   The skill's name is the page's only :html:`<h1>`. The Markdown body is
    rendered one level lower, so a SKILL.md ``# Title`` becomes an
    :html:`<h2>` and ``##`` an :html:`<h3>` (capped at :html:`<h6>`).
*   Themes that print the page title as an :html:`<h1>` of their own can step
    aside on these pages: Skillflow adds an entry to the TypoScript registry
    :typoscript:`lib.pageHeadingOwnedByContent` on every page that holds a
    :guilabel:`Skill detail` element. Desiderio 4.4 reads the registry; see
    :ref:`usage-heading-registry` for other themes.
*   Override :file:`SkillDetail/Show.html` through
    :typoscript:`plugin.tx_skillflow_skilldetail.view.templateRootPaths` to
    replace the markup; the Markdown ViewHelper stays available, with an
    optional heading offset:

    ..  code-block:: html

        <sf:markdown source="{skill.body}" headingOffset="1" />

    ``headingOffset`` moves every heading down that many levels (``0``, the
    default, keeps them). The backend run report uses ``2`` because it sits
    under the module's :html:`<h1>` and the :guilabel:`Report` :html:`<h2>`.

..  _usage-heading-registry:

Page heading registry
=====================

:typoscript:`lib.pageHeadingOwnedByContent` is a :typoscript:`COA` that a
theme declares and renders: when any of its entries outputs something, the
page's content renders the :html:`<h1>` and the theme leaves its page-title
:html:`<h1>` out. Skillflow registers key ``7545``:

..  code-block:: typoscript

    lib.pageHeadingOwnedByContent.7545 = TEXT
    lib.pageHeadingOwnedByContent.7545 {
      value = 1
      if.isTrue.numRows {
        table = tt_content
        select {
          pidInList = this
          where = {#CType} = 'skillflow_skilldetail'
        }
      }
    }

A theme without the registry never renders the entry, so Skillflow does not
depend on any theme. A theme adopts it with a :typoscript:`COA` of the same
name (assign the type only, never clear the path with ``>``, or the entries of
extensions loaded earlier are lost) and a condition on its page-title markup,
for example a :typoscript:`PAGEVIEW` variable:

..  code-block:: typoscript

    lib.pageHeadingOwnedByContent = COA
    lib.fluidPage.variables.pageHeadingOwnedByContent = TEXT
    lib.fluidPage.variables.pageHeadingOwnedByContent {
      value = 1
      if.isTrue.cObject =< lib.pageHeadingOwnedByContent
    }
