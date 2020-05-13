<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle\Command;

use Contao\Model\Collection;
use Contao\ModuleModel;

abstract class AbstractModuleMigrationCommand extends AbstractMigrationCommand
{
    /**
     * A collection of models or null if there are no.
     *
     * @var Collection|ModuleModel[]|ModuleModel|null
     */
    protected $modules;

    /**
     * @var bool
     */
    protected $dryRun = false;

    protected $migrationSql = [];

    protected $upgradeNotices = [];

    public static function getTable(): string
    {
        return 'tl_module';
    }

    public static function getElementName(): string
    {
        return 'module';
    }
}
