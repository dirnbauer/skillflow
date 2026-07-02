#
# Only columns that need a larger type than the TCA-generated default.
#
CREATE TABLE tx_skillflow_skill (
    body mediumtext,
    content_hash varchar(40) DEFAULT '' NOT NULL,
    check_level varchar(16) DEFAULT '' NOT NULL,
    check_report mediumtext,
    KEY check_level (check_level)
);

CREATE TABLE tx_skillflow_run (
    instructions text,
    output mediumtext,
    result_json mediumtext,
    verdict varchar(32) DEFAULT '' NOT NULL,
    score smallint DEFAULT '-1' NOT NULL,
    external_engine varchar(32) DEFAULT '' NOT NULL,
    external_ref varchar(190) DEFAULT '' NOT NULL,
    external_url text,
    KEY external_lookup (external_engine, external_ref)
);

CREATE TABLE tx_skillflow_file (
    skill int(11) unsigned DEFAULT '0' NOT NULL,
    content mediumtext,
    content_hash varchar(40) DEFAULT '' NOT NULL,
    KEY skill (skill)
);
