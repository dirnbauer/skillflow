..  _catalogue:

=================
Skill catalogues
=================

A SKILL.md says what a skill does. What a collection knows about its skills
beyond that, a catalogue file says: the topic a skill belongs to, whether it
is one of the collection's showcase skills ("superfeature"), who it is for,
who publishes it, whether the collection maintains it or vendors it
unchanged from upstream, its eval coverage, the day of its last change, and
how to install one skill or a whole topic. The skills search indexes these
facts and the detail page shows them.

Skillflow ships the catalogue of github.com/dirnbauer/typo3-skills in
:file:`Resources/Private/Catalog/typo3-skills.yaml`. The site setting
:yaml:`skillflow.catalogs` lists the catalogue files a site uses (default:
that one); a catalogue applies to the skills whose nr_llm source has its
``repository``.

File format
===========

..  code-block:: yaml

    repository: 'https://github.com/dirnbauer/typo3-skills'
    title: 'TYPO3 agent skills'
    # Licence of the collection's own skills that declare none
    license: 'MIT AND CC-BY-SA-4.0'
    install:
      all: 'git clone https://github.com/dirnbauer/typo3-skills.git && cd typo3-skills && ./install.sh'
      # {skill}: one folder name, {skills}: the names of a topic, {dir}: the agent's skills folder
      skill: 'git clone … ~/typo3-skills; mkdir -p {dir} && ln -s ~/typo3-skills/skills/{skill} {dir}/{skill}'
      pack: 'git clone … ~/typo3-skills; mkdir -p {dir} && for s in {skills}; do ln -s ~/typo3-skills/skills/$s {dir}/$s; done'
    clients:                 # the first is the default of the agent switch
      - { key: claude, label: 'Claude Code', directory: '~/.claude/skills', call: '/{skill}' }
    audiences:
      site: 'LLL:EXT:skillflow/Resources/Private/Language/catalog.xlf:audience.site'
    groups:                  # in display order
      proven-site-upgrade:
        title: 'LLL:EXT:skillflow/Resources/Private/Language/catalog.xlf:group.proven-site-upgrade'
        description: 'LLL:…:group.proven-site-upgrade.description'
        page: proven-site-upgrade   # below skillflow.search.groupPages
    skills:                  # a group's skills in this order
      typo3-upgrade-run:
        group: proven-site-upgrade
        superfeature: true
        audience: [site]
        owner: webconsulting
        origin: maintained   # or vendored
        upstream: ''         # repository a vendored skill comes from
        license: ''          # only when the skill brings a licence file
        evals: { cases: 94, reviewed: 25 }
        updated: '2026-10-31'

Titles and descriptions are labels or LLL references, so the topics are
translated with the site's languages; each language's Solr core gets the topic
in its language.

Editorial fields and facts
==========================

The groups and every skill's ``group``, ``superfeature`` and ``audience``
are editorial: edit them by hand. ``owner``, ``origin``, ``upstream``,
``license``, ``evals`` and ``updated`` are facts of the repository; refresh
them from a checkout:

..  code-block:: bash

    php Build/Scripts/refresh-skill-catalog.php \
        Resources/Private/Catalog/typo3-skills.yaml ~/projects/typo3-skills

The script reads :file:`catalog/skill-sources.json` (owner and upstream URL),
the inventory table of :file:`VENDORED.md`, each skill's
:file:`evals/evals.json` and licence files, and the day of the last commit
that touched the skill folder. It keeps the editorial fields, appends new
skills without a topic and names them, and keeps listed skills it cannot
find. Reindex afterwards (:ref:`command-solr-index`).

Solr fields
===========

=============================== ===========================================
Field                           Content
=============================== ===========================================
``skillGroup_stringS``          topic key (facet ``group``)
``skillGroupTitle_stringS``     topic title in the core's language
``skillSuperfeature_intS``      ``1`` for a showcase skill
``skillRank_intS``              order "superfeatures first"
``skillOwner_stringS``          publisher (facet ``owner``)
``skillOrigin_stringS``         ``maintained`` or ``vendored``
``skillType_stringS``           ``capability`` or ``preference`` (front matter)
``skillAudience_stringM``       audience keys
``skillEvals_intS``             eval cases
``skillEvalsReviewed_intS``     reviewed eval cases
``skillUpdated_dateS``          day of the last change
``skillUpstream_stringS``       upstream repository
``license_stringS``             front matter licence, else the catalogue's
``skillWords_stringEdgeNgramM`` name words as prefixes (type-ahead)
``skillPrefix_textEdgeNgramM``  name, description and topic as prefixes
=============================== ===========================================
