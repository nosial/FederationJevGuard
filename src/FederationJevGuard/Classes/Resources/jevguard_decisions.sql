create table jevguard_decisions
(
    uuid         varchar(36)                                                          not null comment 'The UUID of the decision'
        primary key,
    subject_type enum ('REPORT', 'EVIDENCE', 'ENTITY', 'ATTACHMENT')                  not null comment 'What was decided about',
    subject      varchar(36)                                                          not null comment 'The UUID of the report, evidence record, entity or attachment',
    entity       varchar(36)                                                          null comment 'Optional. The UUID of the entity the subject concerns, eg; the reported entity',
    outcome      enum ('APPLIED', 'PREVIEWED', 'UNCERTAIN', 'DECLINED', 'SUPPRESSED') not null comment 'APPLIED=Acted on, PREVIEWED=Would have been acted on, UNCERTAIN=Left to the operators, DECLINED=Nothing to do, SUPPRESSED=An operator lifted or deleted a blacklist record of the entity',
    verdict      varchar(32)                                                          null comment 'Optional. What the model decided, eg; MALICIOUS or BLACKLIST',
    probability  float                                                                null comment 'Optional. The probability of the verdict',
    reason       varchar(255)                                                         null comment 'Optional. Why the decision has its outcome',
    record       varchar(36)                                                          null comment 'Optional. The UUID of the record the decision created, eg; the blacklist record',
    input_hash   char(64)                                                             null comment 'Optional. The SHA-256 of what the decision was made on, the subject is only reviewed again once it changes',
    model        varchar(64)                                                          null comment 'Optional. The model that answered, eg; jev-1.14.0',
    questions    varchar(64)                                                          null comment 'Optional. The version of the questions the model was asked, eg; 1.0.0+3f2a9c41d0be',
    answers      longtext                                                             null comment 'Optional. Every answer of the model, as JSON',
    input_tokens int       default 0                                                  not null comment 'The input tokens of the requests the decision took',
    created      timestamp default current_timestamp()                                not null comment 'The Timestamp for when the decision was made',
    constraint jevguard_decisions_entities_uuid_fk
        foreign key (entity) references entities (uuid)
            on update cascade on delete cascade
)
    comment 'FederationJevGuard: the decisions of the model and what came of them';

create index jevguard_decisions_subject_index
    on jevguard_decisions (subject_type, subject, created);

create index jevguard_decisions_entity_index
    on jevguard_decisions (entity, created);

create index jevguard_decisions_record_index
    on jevguard_decisions (record);

create index jevguard_decisions_created_index
    on jevguard_decisions (created);
