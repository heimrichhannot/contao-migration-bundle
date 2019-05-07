# Changelog
All notable changes to this project will be documented in this file.

## [0.2.0] - 2019-05-07

* [BC BREAKING] abstract AbstractModuleMigrationCommand::getType method replaces AbstractModuleMigrationCommand::types property
* [NEED ATTENTION] added dry-run option to AbstractModuleMigrationCommand -> must be implemented in migrate method
* added `huh:migration:module:owlcarousel` command
* added 3 traits for reusable use cases (see Extensions namespace)