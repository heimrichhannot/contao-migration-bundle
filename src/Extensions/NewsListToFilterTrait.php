<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle\Extensions;

use Contao\Model;
use Contao\ModuleModel;
use Contao\StringUtil;
use HeimrichHannot\CategoriesBundle\Filter\Type\CategoryChoiceType;
use HeimrichHannot\FilterBundle\Filter\AbstractType;
use HeimrichHannot\FilterBundle\Filter\Type\ParentType;
use HeimrichHannot\FilterBundle\Filter\Type\PublishedType;
use HeimrichHannot\FilterBundle\Filter\Type\SqlType;
use HeimrichHannot\FilterBundle\Model\FilterConfigElementModel;
use HeimrichHannot\FilterBundle\Model\FilterConfigModel;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Trait NewsListToFilterTrait.
 *
 * @method ContainerInterface getContainer
 * @method bool isDryRun
 * @method void addMigrationSql(string $sql)
 * @method void addUpgradeNotices(string $type, string $upgradeNotice)
 * @method void save(Model $model)
 *
 * @property SymfonyStyle $io
 */
trait NewsListToFilterTrait
{
    /**
     * @return array ['config' => FilterConfigModel, 'configElements' => FilterConfigElementModel[]]
     */
    public function createNewsFilter(ModuleModel $module)
    {
        $filterConfig = $this->buildNewsFilter($module);

        $parentFilterConfigElement     = $this->buildNewsParentFilterConfigElement($filterConfig->id, 2, StringUtil::deserialize($module->news_archives, true));
        $dateFilterConfigElement       = $this->buildNewsDateFilterConfigElement($filterConfig->id, 4);
        $categoriesFilterConfigElement = $this->buildNewsCategoriesFilterConfigElement($filterConfig->id, 8, $module);
        $publishedFilterConfigElement  = $this->buildNewsPublishedFilterConfigElement($filterConfig->id, 16);
        $featuredFilterConfigElement   = $this->buildNewsFeaturesFilterConfigElement($filterConfig->id, 24, $module->news_featured);

        return [
            'config'         => $filterConfig,
            'configElements' => [
                'parent'     => $parentFilterConfigElement,
                'date'       => $dateFilterConfigElement,
                'categories' => $categoriesFilterConfigElement,
                'published'  => $publishedFilterConfigElement,
                'featured'   => $featuredFilterConfigElement,
            ],
        ];
    }

    /**
     * Build a filter model out of module.
     *
     * @return FilterConfigModel
     */
    protected function buildNewsFilter(ModuleModel $module)
    {
        $filterConfig                = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigModel());
        $filterConfig->tstamp        = $filterConfig->dateAdded = time();
        $filterConfig->title         = $module->name;
        $filterConfig->name          = preg_replace(['/ä/i', '/ö/i', '/ü/i', '/ß/i'], ['ae', 'oe', 'ue', 'ss'], StringUtil::generateAlias($module->name));
        $filterConfig->dataContainer = 'tl_news';
        $filterConfig->method        = 'GET';
        $filterConfig->template      = 'form_div_layout';
        $filterConfig->published     = 1;

        if (!$this->isDryRun()) {
            $filterConfig->save();
        } else {
            $filterConfig->id = 0;
        }

        return $filterConfig;
    }

    /**
     * @return FilterConfigElementModel|null
     */
    protected function buildNewsParentFilterConfigElement(int $filterId, int $sorting, array $pids)
    {
        if (empty($pids)) {
            return null;
        }

        $initialValueArray = [];

        foreach ($pids as $pid) {
            $initialValueArray[] = ['value' => $pid];
        }

        $filterElement                    = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->pid               = $filterId;
        $filterElement->tstamp            = time();
        $filterElement->dateAdded         = time();
        $filterElement->sorting           = $sorting;
        $filterElement->title             = 'Archiv';
        $filterElement->type              = ParentType::TYPE;
        $filterElement->isInitial         = 1;
        $filterElement->field             = 'pid';
        $filterElement->operator          = DatabaseUtil::OPERATOR_IN;
        $filterElement->initialValueType  = 'array';
        $filterElement->initialValueArray = serialize($initialValueArray);
        $filterElement->published         = 1;

        if (!$this->isDryRun()) {
            $filterElement->save();
        }

        return $filterElement;
    }

    /**
     * @return FilterConfigElementModel|null
     */
    protected function buildNewsDateFilterConfigElement(int $filterId, int $sorting)
    {
        $filterElement            = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->pid       = $filterId;
        $filterElement->tstamp    = time();
        $filterElement->dateAdded = time();
        $filterElement->sorting   = $sorting;
        $filterElement->title     = 'Mindestdatum';
        $filterElement->type      = SqlType::TYPE;
        $filterElement->isInitial = 1;
        $filterElement->field     = 'date';
        $filterElement->whereSql  = 'tl_news.date < {{date::U}}';
        $filterElement->published = 1;

        if (!$this->isDryRun()) {
            $filterElement->save();
        }

        return $filterElement;
    }

    /**
     * @return FilterConfigElementModel|null
     */
    protected function buildNewsCategoriesFilterConfigElement(int $filterId, int $sorting, $module)
    {
        if (!$module->news_filterCategories || !$this->input->hasOption('category-field')) {
            return null;
        }

        $filterCategories = StringUtil::deserialize($module->news_filterDefault, true);

        if (empty($filterCategories)) {
            return null;
        }

        $filterElement                   = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->pid              = $filterId;
        $filterElement->tstamp           = time();
        $filterElement->dateAdded        = time();
        $filterElement->sorting          = $sorting;
        $filterElement->title            = 'Kategorien';
        $filterElement->type             = CategoryChoiceType::TYPE;
        $filterElement->isInitial        = true;
        $filterElement->operator         = DatabaseUtil::OPERATOR_IN;
        $filterElement->initialValueType = 'array';
        $filterElement->field            = $this->input->getOption('category-field');
        $filterElement->published        = 1;

        // try to compute the category ids
        $categoryIds = [];

        foreach ($filterCategories as $filterCategory) {
            if (null === ($category = $this->getContainer()->get('huh.utils.model')->findModelInstanceByPk('tl_news_category', $filterCategory))) {
                continue;
            }

            if (null === ($categoryNew = $this->getContainer()->get('huh.utils.model')->findOneModelInstanceBy('tl_category', ['tl_category.alias=?'], [$category->alias]))) {
                continue;
            }

            $categoryIds[] = ['value' => $categoryNew->id];
        }

        $filterElement->initialValueArray = serialize($categoryIds);

        if (!$this->isDryRun()) {
            $filterElement->save();
        }

        return $filterElement;
    }

    protected function buildNewsPublishedFilterConfigElement(int $filterId, $sorting)
    {
        $filterElement                  = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->pid             = $filterId;
        $filterElement->tstamp          = time();
        $filterElement->dateAdded       = time();
        $filterElement->sorting         = $sorting;
        $filterElement->title           = 'Veröffentlicht';
        $filterElement->type            = PublishedType::TYPE;
        $filterElement->field           = 'published';
        $filterElement->addStartAndStop = 1;
        $filterElement->startField      = 'start';
        $filterElement->stopField       = 'stop';
        $filterElement->published       = 1;

        if (!$this->isDryRun()) {
            $filterElement->save();
        }

        return $filterElement;
    }

    protected function buildNewsFeaturesFilterConfigElement(int $filterId, $sorting, ?string $featured = null)
    {
        if (!$featured || 'all_items' === $featured || !\in_array($featured, ['featured', 'unfeatured'])) {
            return null;
        }

        $filterElement            = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title     = 'Hervorgehoben';
        $filterElement->type      = 'checkbox';
        $filterElement->isInitial = '1';
        $filterElement->sorting   = $sorting;
        $filterElement->pid       = $filterId;
        $filterElement->tstamp    = time();
        $filterElement->dateAdded = time();
        $filterElement->published = 1;

        $filterElement->field = 'featured';

        if ('featured' === $featured) {
            $filterElement->operator = DatabaseUtil::OPERATOR_LIKE;
        } else {
            $filterElement->operator = DatabaseUtil::OPERATOR_IS_EMPTY;
        }
        $filterElement->initialValueType = AbstractType::VALUE_TYPE_SCALAR;
        $filterElement->initialValue     = '1';

        if (!$this->isDryRun()) {
            $filterElement->save();
        }

        return $filterElement;
    }
}
