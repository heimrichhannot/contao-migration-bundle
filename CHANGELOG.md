# Changelog
All notable changes to this project will be documented in this file.

## [0.8.0] - 2020-05-13

- fixed syndication support in `NewsReaderModuleMigrationCommand`
- added php codestyle fixer config

## [0.7.0] - 2020-05-12

- added new options for `NewsCategoriesMigrationCommand` 

## [0.6.0] - 2019-08-23

- `MoveTemplateTrait` is now added comments about migration to the template and comments out existing PHP code
- removed unused query builder from AbstractMigrationCommand and AbstractModuleMigrationCommand
- fixed initial year filter on NewsArchiveMenu migration
- unset formHybridDataContainer field since it couses database errors

## [0.5.0] - 2019-06-12

- [ADDED] `huh:migration:db:news_tags` command

## [0.4.1] - 2019-06-06

- fixed id issue in news categories migration

## [0.4.0] - 2019-05-15

- [BC BREAK] `AbstractMigrationCommand::addUpgradeNotices()` expect a type name as first parameter
- [BC BREAK] `MigrateNewsListItemTemplateToListTemplateTrait` were replaced by the more generic `MoveTemplateTrait`
- AbstractModuleMigrationCommand now extends AbstractMigrationCommand
- added news archive module migration command `huh:migration:module:newsmenu`

## [0.3.0] - 2019-05-14

- added `AbstractMigrationCommand` class
- added `AbstractContentElementMigrationCommand` class
- added Tab Control bundle migration command `huh:migration:ce:tab_control_bundle`

## [0.2.2] - 2019-05-08

- fixed missing licence file

## [0.2.1] - 2019-05-07

- licence now GNU General Public License v3.0
- cleaned up composer.json

## [0.2.0] - 2019-05-07

- [BC BREAKING] abstract AbstractModuleMigrationCommand::getType method replaces AbstractModuleMigrationCommand::types property
- [NEED ATTENTION] added dry-run option to AbstractModuleMigrationCommand -> must be implemented in migrate method
- added `huh:migration:module:owlcarousel` command
- added 3 traits for reusable use cases (see Extensions namespace)
