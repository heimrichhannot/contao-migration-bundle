<?php
/**
 * Copyright (c) 2018 Heimrich & Hannot GmbH
 *
 * @author Rico Kaltofen <r.kaltofen@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */

namespace HeimrichHannot\MigrationBundle\Command;


use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\Model\Collection;
use Contao\ModuleModel;;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractModuleMigrationCommand extends AbstractMigrationCommand
{

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * A collection of models or null if there are no
     *
     * @var Collection|ModuleModel[]|ModuleModel|null
     */
    protected $modules;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;
    /**
     * @var ContaoFrameworkInterface
     */
    protected $framework;

    /**
     * @var bool
     */
    protected $dryRun = false;

    protected $migrationSql = [];

    protected $upgradeNotices = [];

    static function getTable(): string
    {
        return 'tl_module';
    }

    static function getElementName(): string
    {
        return 'module';
    }
}


