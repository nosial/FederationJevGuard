create table jevguard_images
(
    sha256   char(64)                              not null comment 'The SHA-256 of the image file, the same image is only judged once'
        primary key,
    findings longtext                              not null comment 'The probability of each finding about the image, as JSON',
    model    varchar(64)                           not null comment 'The model that judged the image, eg; jev-1.14.0',
    created  timestamp default current_timestamp() not null comment 'The Timestamp for when the image was judged'
)
    comment 'FederationJevGuard: what the model found in the images of evidence attachments';
