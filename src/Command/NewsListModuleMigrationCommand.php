<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle\Command;

use Contao\Model;
use Contao\ModuleModel;
use HeimrichHannot\ListBundle\Module\ModuleList;
use HeimrichHannot\MigrationBundle\Extensions\MoveTemplateTrait;
use HeimrichHannot\MigrationBundle\Extensions\NewsListToFilterTrait;
use HeimrichHannot\MigrationBundle\Extensions\NewsListToListTrait;
use Symfony\Component\Console\Input\InputOption;

class NewsListModuleMigrationCommand extends AbstractModuleMigrationCommand
{
    /**
     * @var array
     */
    protected static $types = ['newslist', 'newslist_plus'];

    use NewsListToFilterTrait;
    use NewsListToListTrait;
    use MoveTemplateTrait;

    public static function getTypes(): array
    {
        return static::$types;
    }

    protected function configure()
    {
        $this->setName('migration:module:newslist')->setDescription(
            'Migration of tl_module type:newslist modules to huhlist and creates list configurations from old tl_module settings.'
        );

        $this->addOption('category-field', null, InputOption::VALUE_OPTIONAL, 'Add the **new** category field (necessary for category filters)?', '');

        parent::configure();
    }

    /**
     * Run custom migration on each module.
     *
     * @param ModuleModel|Model $module
     *
     * @return mixed
     */
    protected function migrate(Model $module): int
    {
        $filterConfigData = $this->createNewsFilter($module);
        $filterConfig = $filterConfigData['config'];
        $listConfigData = $this->createListConfig($module, $filterConfig->id);
        $listConfig = $listConfigData['config'];
        $this->migrateFrontendModule($module, $listConfig->id);
        $this->moveTemplate($module, 'news_template', $listConfig, 'itemTemplate');

        if (!$this->isDryRun()) {
            $listConfig->save();
        }

        // Manuell: Slider Texte

        return 0;
    }

    /**
     * @return ModuleModel
     */
    protected function migrateFrontendModule(ModuleModel $module, int $listConfigId)
    {
        $module->tstamp = time();
        $module->type = ModuleList::TYPE;
        $module->listConfig = $listConfigId;

        if (!$this->isDryRun()) {
            $module->save();
        }

        $this->addMigrationSql("UPDATE tl_module SET type='".$module->type."', listConfig=".$module->listConfig.' WHERE id='.$module->id.';');

        return $module;
    }

    /**
     * This method is used to check, if migration command could be execute.
     * This is the place to check if a needed bundle is installed or database fields exist.
     * Return false, to stop the migration command.
     */
    protected function beforeMigrationCheck(): bool
    {
        return true;
    }
}
