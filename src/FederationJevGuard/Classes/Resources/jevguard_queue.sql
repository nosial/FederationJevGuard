create table jevguard_queue
(
    subject_type enum ('REPORT', 'EVIDENCE', 'ENTITY', 'ATTACHMENT') not null comment 'What is reviewed: a report, an evidence record, an entity or an image attachment',
    subject      varchar(36)                                         not null comment 'The UUID of the report, evidence record, entity or attachment',
    due          timestamp default current_timestamp()               not null comment 'The Timestamp from which the subject is reviewed',
    attempts     int       default 0                                 not null comment 'The number of reviews that failed, eg; the System One endpoint was unreachable',
    created      timestamp default current_timestamp()               not null comment 'The Timestamp for when the subject was queued',
    primary key (subject_type, subject)
)
    comment 'FederationJevGuard: the subjects waiting to be reviewed by the model';

create index jevguard_queue_due_index
    on jevguard_queue (due);
