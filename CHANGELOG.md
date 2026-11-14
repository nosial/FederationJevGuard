# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.3] - 2026-11-14

This update introduces new configuration properties for the plugin

### Added
 - `reports.maintenance_batch` (`FEDERATION_JEVGUARD_REPORTS_MAINTENANCE_BATCH`, 500 by default) sets how many opened
   reports every maintenance run queues



## [1.0.2] - 2026-11-14

This update introduces a minor bug fix

### Fixed
 - Entity metadata is no longer treated as a fact: reports about, evidence from and the blacklisting of entities whose
   metadata claims `is_bot` are reviewed like any other, the metadata is only part of what the model is shown



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