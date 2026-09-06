# Changelog

## Unreleased

- Require TYPO3 14.3.6+, PHP 8.4+, nr_llm 0.34.x, stable EXT:solr 14.0.1+
  and CommonMark 2.10+.
- Complete nr_llm ownership; remove duplicate import, attachment, review and
  quarantine implementations and their obsolete commands/events.
- Repair workflow and detail screens; block hidden, disabled and orphaned
  skills through all execution paths and retain availability query filters.
- Enforce backend page/web-mount and report access; only auto-run reviews
  after successful workspace stage transitions.
- Scope Solr indexing to skills, include root records, and report incomplete batches.
- Use TYPO3's Schema API and Solr's own search translations.
- Update TYPO3 APIs, catalogue metadata, labels, documentation and Lab links.
- Add local integration setup and behavior regression checks.

See [Upgrading](Documentation/Upgrade.md) for removed interfaces and the review
required for data from before nr_llm ownership.
