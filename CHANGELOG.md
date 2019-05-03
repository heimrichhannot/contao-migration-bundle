# Changelog
All notable changes to this project will be documented in this file.

## [0.2.0-DEV] - 2019-05-03

* [BC BREAKING] abstract AbstractModuleMigrationCommand::getType method replaces AbstractModuleMigrationCommand::types property
* [NEED ATTENTION] added dry-run option to AbstractModuleMigrationCommand -> must be implemented in migrate method