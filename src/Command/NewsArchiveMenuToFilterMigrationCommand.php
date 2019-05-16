<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2019 Heimrich & Hannot GmbH
 *
 * @author  Thomas Körner <t.koerner@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */


namespace HeimrichHannot\MigrationBundle\Command;


use Contao\Model;
use Contao\ModuleModel;
use HeimrichHannot\FilterBundle\Filter\AbstractType;
use HeimrichHannot\FilterBundle\Filter\Type\YearType;
use HeimrichHannot\FilterBundle\Model\FilterConfigElementModel;
use HeimrichHannot\FilterBundle\Module\ModuleFilter;
use HeimrichHannot\MigrationBundle\Extensions\MoveTemplateTrait;
use HeimrichHannot\MigrationBundle\Extensions\NewsListToFilterTrait;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;

class NewsArchiveMenuToFilterMigrationCommand extends AbstractModuleMigrationCommand
{
    use NewsListToFilterTrait;
    use MoveTemplateTrait;

    protected function configure()
    {
        $this->setName('huh:migration:module:newsmenu')->setDescription('Migrate news archive menu module to filter module.');
        parent::configure();
    }


    /**
     * Returns a list of types that are supported by this command.
     *
     * @return array
     */
    static function getTypes(): array
    {
        return ['newsmenu'];
    }

    /**
     * This method is used to check, if migration command could be execute.
     * This is the place to check if a needed bundle is installed or database fields exist.
     * Return false, to stop the migration command.
     *
     * @return bool
     */
    protected function beforeMigrationCheck(): bool
    {
        return true;
    }

    /**
     * Run custom migration on each element
     * @param ModuleModel|Model $model
     * @return mixed
     */
    protected function migrate(Model $model): int
    {
        $filter = $this->createNewsFilter($model);
        $filterConfig = $filter['config'];
        $this->buildYearFilterConfigElement($model, $filterConfig->id, 16);
        $this->buildYearFilterConfigElement($model, $filterConfig->id, 32);

        $model->type = ModuleFilter::TYPE;
        $model->filter = $filterConfig->id;
        $this->save($model);

        $this->addMigrationSql("UPDATE tl_module SET type='".$model->type."', filter=".$model->filter." WHERE id=".$model->id.";");
        $this->addUpgradeNotices("filter", "Add the filter <fg=green>'$filterConfig->title' [ID $filterConfig->id]</> to your list and reader configs where the filter should be applied.");

        $this->moveTemplate($model, 'customTpl', $filterConfig, 'template', 'filter_form_');
        $model->customTpl = '';
        $this->save($model);

        return 0;
    }

    protected function buildYearFilterConfigElement(ModuleModel $module, $filterId, $sorting)
    {
        $filterElement                   = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->pid              = $filterId;
        $filterElement->tstamp           = time();
        $filterElement->dateAdded        = time();
        $filterElement->sorting          = $sorting;
        $filterElement->title            = 'Year';
        $filterElement->type             = YearType::TYPE;
        $filterElement->field            = 'date';
        $filterElement->expanded         = 1;
        $filterElement->submitOnChange   = 1;
        $filterElement->dynamicOptions   = 1;
        $filterElement->addOptionCount   = 1;
        $filterElement->optionCountLabel = 'huh.filter.option_count.default';
        $filterElement->hideLabel        = 1;
        $filterElement->published        = 1;

        $this->save($filterElement);
        return $filterElement;
    }

    protected function buildInitalYearFilterConfigElement(ModuleModel $module, $filterId, $sorting)
    {
        $filterElement                   = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->pid              = $filterId;
        $filterElement->tstamp           = time();
        $filterElement->dateAdded        = time();
        $filterElement->sorting          = $sorting;
        $filterElement->title            = 'Year';
        $filterElement->type             = YearType::TYPE;
        $filterElement->isInitial        = 1;
        $filterElement->field            = 'date';
        $filterElement->operator         = DatabaseUtil::OPERATOR_EQUAL;
        $filterElement->initialValueType = AbstractType::VALUE_TYPE_LATEST;
        $filterElement->published        = 1;
        $this->save($filterElement);
        return $filterElement;
    }
}