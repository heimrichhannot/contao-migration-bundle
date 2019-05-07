<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2019 Heimrich & Hannot GmbH
 *
 * @author  Thomas Körner <t.koerner@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */


namespace HeimrichHannot\MigrationBundle\Extensions;


use Contao\ModuleModel;
use Contao\StringUtil;
use HeimrichHannot\FilterBundle\Filter\AbstractType;
use HeimrichHannot\FilterBundle\Model\FilterConfigElementModel;
use HeimrichHannot\FilterBundle\Model\FilterConfigModel;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Trait NewsListToFilterTrait
 * @package HeimrichHannot\MigrationBundle\Extensions
 *
 * @method ContainerInterface getContainer
 * @method bool isDryRun
 * @method void addMigrationSql(string $sql)
 * @method void addUpgradeNotices(string $upgradeNotice)
 *
 * @property SymfonyStyle $io
 */
trait NewsListToFilterTrait
{
    /**
     * @param ModuleModel $module
     * @return array ['config' => FilterConfigModel, 'configElements' => FilterConfigElementModel[]]
     */
    public function createNewsFilter(ModuleModel $module)
    {
        $filterConfig                = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigModel());
        $filterConfig->tstamp        = $filterConfig->dateAdded = time();
        $filterConfig->title         = $module->name;
        $filterConfig->name          = StringUtil::standardize($module->name);
        $filterConfig->dataContainer = 'tl_news';
        $filterConfig->method        = 'GET';
        $filterConfig->template      = 'form_div_layout';
        $filterConfig->published     = 1;
        if (!$this->isDryRun()) {
            $filterConfig->save();
        }
        else {
            $filterConfig->id = 0;
        }
        $parentFilterConfigElement = $this->addParentFilterConfigElement($filterConfig->id, 8, StringUtil::deserialize($module->news_archives, true));
        $publishedFilterConfigElement = $this->addPublishedFilterConfigElement($filterConfig->id, 16);
        $featuredFilterConfigElement = $this->addFeaturesFilterConfigElement($filterConfig->id, 32, $module->news_featured);

        return [
            'config' => $filterConfig,
            'configElements' => [
                'parent' => $parentFilterConfigElement,
                'published' => $publishedFilterConfigElement,
                'featured' => $featuredFilterConfigElement,
            ]
        ];
    }

    /**
     * @param int $filterId
     * @param int $sorting
     * @param array $pids
     * @return FilterConfigElementModel|null
     */
    protected function addParentFilterConfigElement(int $filterId, int $sorting, array $pids)
    {
        if (empty($pids))
        {
            return null;
        }

        $initialValueArray = [];
        foreach ($pids as $pid)
        {
            $initialValueArray[] = ['value' => $pid];
        }

        $filterElement                    = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title             = 'News Archives';
        $filterElement->pid               = $filterId;
        $filterElement->sorting           = $sorting;
        $filterElement->tstamp            = time();
        $filterElement->dateAdded         = time();
        $filterElement->type              = 'parent';
        $filterElement->isInitial         = 1;
        $filterElement->operator          = DatabaseUtil::OPERATOR_IN;
        $filterElement->field             = 'pid';
        $filterElement->initialValueType  = 'array';
        $filterElement->initialValueArray = serialize($initialValueArray);
        $filterElement->published         = 1;
        if (!$this->isDryRun()) {
            $filterElement->save();
        }
        return $filterElement;
    }

    protected function addPublishedFilterConfigElement(int $filterId, $sorting)
    {
        $filterElement                  = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title           = 'Published';
        $filterElement->pid             = $filterId;
        $filterElement->sorting         = $sorting;
        $filterElement->tstamp          = time();
        $filterElement->dateAdded       = time();
        $filterElement->type            = 'visible';
        $filterElement->field           = 'published';
        $filterElement->published       = 1;
        $filterElement->addStartAndStop = 1;
        $filterElement->startField      = 'start';
        $filterElement->stopField       = 'stop';
        if (!$this->isDryRun()) {
            $filterElement->save();
        }
        return $filterElement;
    }

    protected function addFeaturesFilterConfigElement(int $filterId, $sorting, ?string $featured = null)
    {
        if (!$featured || $featured === 'all_items' || !in_array($featured, ['featured','unfeatured'])) {
            return null;
        }

        $filterElement                  = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title           = 'Favorites';
        $filterElement->type            = 'checkbox';
        $filterElement->isInitial       = '1';
        $filterElement->sorting         = $sorting;
        $filterElement->pid             = $filterId;
        $filterElement->tstamp          = time();
        $filterElement->dateAdded       = time();
        $filterElement->published       = 1;

        $filterElement->field           = 'featured';

        if ($featured === 'featured'){
            $filterElement->operator = DatabaseUtil::OPERATOR_LIKE;

        } else {
            $filterElement->operator = DatabaseUtil::OPERATOR_IS_EMPTY;
        }
        $filterElement->initialValueType = AbstractType::VALUE_TYPE_SCALAR;
        $filterElement->initialValue = "1";

        if (!$this->isDryRun()) {
            $filterElement->save();
        }
        return $filterElement;
    }


}