create table jevguard_usage
(
    day           date             not null comment 'The day (UTC)'
        primary key,
    requests      int    default 0 not null comment 'The requests made to the System One endpoint',
    input_tokens  bigint default 0 not null comment 'The billable input tokens of the requests',
    output_tokens bigint default 0 not null comment 'The output tokens of the requests',
    failures      int    default 0 not null comment 'The requests that failed'
)
    comment 'FederationJevGuard: the use of the System One endpoint per day';
