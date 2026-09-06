-- Disposable local fixture records only; preserve them on repeated setup.
INSERT IGNORE INTO pages (uid, pid, title, slug, doktype, hidden)
VALUES (1001, 1, 'Skill detail', '/skill', 1, 0);

INSERT IGNORE INTO tx_nrllm_skill (uid, pid, name, identifier, description, body, enabled, hidden, orphaned, allowed_tools, raw_frontmatter, tstamp, crdate)
VALUES
    (1001, 0, 'Local review', 'fixture:local-review/SKILL.md', 'A local fixture for workflow and catalogue verification.', '# Local review\n\nReview the supplied page content. Return a short advisory report.', 1, 0, 0, '[]', '{"license":"GPL-2.0-or-later","version":"1.0","metadata":{"category":"Quality","tags":["review","local"]}}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
    (1002, 0, 'Disabled review', 'fixture:disabled/SKILL.md', 'Must never appear in the public catalogue.', 'This skill is disabled.', 0, 0, 0, '[]', '{}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT IGNORE INTO tt_content (uid, pid, CType, header, colPos, hidden)
VALUES
    (1001, 1, 'solr_pi_results', 'Skill catalogue', 0, 0),
    (1002, 1001, 'skillflow_skilldetail', 'Skill detail', 0, 0);

INSERT IGNORE INTO sys_template (uid, pid, title, root, clear, config, constants)
VALUES (1001, 1, 'Skillflow integration fixture', 1, 0,
'page = PAGE\npage.10 < styles.content.get\nplugin.tx_solr.search.initializeWithEmptyQuery = 1\nplugin.tx_solr.search.showResultsOfInitialEmptyQuery = 1\nplugin.tx_solr.search.query.allowEmptyQuery = 1\nplugin.tx_solr.search.query.filter.onlySkills = type:tx_nrllm_skill\nplugin.tx_solr.search.results.siteHighlighting = 0\n',
'plugin.tx_solr.index.queue.skills.additionalPageIds = 0\nplugin.tx_solr.index.queue.skills.detailPageId = 1001\n');
