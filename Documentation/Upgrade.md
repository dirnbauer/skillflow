# Upgrading to the current TYPO3 14 release

Requirements: TYPO3 14.3.6+, PHP 8.4+, nr_llm 0.34.x, stable EXT:solr 14.0.1+
and CommonMark 2.10+. Older TYPO3/PHP versions and Solr betas are not supported.

## Skill ownership migration

The previous migration moved assignments and lookups to nr_llm but retained
an incompatible import/review module. This update removes folder/repository/
rule import, attachment materialization, local quarantine/license scanning,
and `skillflow:sync`, `skillflow:import-rules` and `skillflow:check`.

Manage sources and activation in **AI → Authoring → Skills**. Skillflow owns
assignments and run history. Optional SkillSpector checks belong to a separate
integration; this update does not install one.

For installations predating nr_llm ownership:

1. Export the database and old skills. Retain old tables until their contents
   and historic run references have been reviewed.
2. Import and review sources in nr_llm, then map former skill identifiers to
   the new records. UIDs from the two skill tables are not interchangeable.
3. Reassign pages, users and stages using `tx_skillflow_nrllm_skills`. Review
   historic `tx_skillflow_run.skill` references before remapping them.
4. Remove cron jobs/listeners for the deleted commands and
   `AfterSkillsSyncedEvent`. No database data is deleted or automatically
   remapped by this code cleanup.

Already migrated installations retain nr_llm assignments and runs. Review
skills that rely on supporting scripts/assets before enabling them.

## Apply and verify locally

```bash
ddev snapshot --name before-skillflow-update
ddev composer update webconsulting/skillflow netresearch/nr-llm apache-solr-for-typo3/solr 'typo3/*' -W
ddev typo3 extension:setup
ddev typo3 upgrade:list
ddev typo3 referenceindex:update
ddev typo3 cache:flush
ddev typo3 cache:warmup
```

Use the Solr server/configset supported by EXT:solr 14. Replace old
`type:tx_skillflow_skill` catalogue filters with `type:tx_nrllm_skill`, update
detail route enhancers, then run `skillflow:solr:index --site=<identifier>`.
Verify detail/search, assignments, editor permissions, stage auto-run and
reports. Inactive skills must stay absent from search/details and blocked
through CLI/module requests.

## Deployment handover

Verification runs locally. For each target environment, use its reviewed
Composer lock, database backup, schema/wizard steps, cache rebuild and Solr
reindex. Keep hostnames, mail transport and credentials in that installation.
Preserve the local-only execution setting on shared environments.
