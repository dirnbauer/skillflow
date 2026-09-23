CREATE TABLE pages (
    tx_skillflow_nrllm_skills varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE be_users (
    tx_skillflow_nrllm_skills varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE sys_workspace_stage (
    tx_skillflow_nrllm_skills varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_nrllm_skill (
    tx_skillflow_abilities text
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
