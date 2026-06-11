#
# Only columns that need a larger type than the TCA-generated default.
#
CREATE TABLE tx_skillflow_skill (
    body mediumtext,
    content_hash varchar(40) DEFAULT '' NOT NULL
);

CREATE TABLE tx_skillflow_run (
    output mediumtext
);
