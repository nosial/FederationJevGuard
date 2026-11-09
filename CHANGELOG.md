# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-11-09

This update introduces several changes to the plugin

### Added
 - Assigning an opened report to the `FederationJevGuard` operator now queues it for review (`REPORT_OPERATOR_ASSIGNED`),
   and the review isn't skipped because of an earlier uncertain decision about the same report

### Changed
 - The automatic assignment of reports to the `FederationJevGuard` operator is no longer reset at every startup and
   maintenance run, it's only turned off when the operator is created



## [1.0.0] - 2026-11-01

The first release of FederationJevGuard