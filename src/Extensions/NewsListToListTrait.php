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
use HeimrichHannot\ListBundle\Backend\ListConfig;
use HeimrichHannot\ListBundle\Backend\ListConfigElement;
use HeimrichHannot\ListBundle\Model\ListConfigElementModel;
use HeimrichHannot\ListBundle\Model\ListConfigModel;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Trait NewsListToListTrait.
 *
 * @method ContainerInterface getContainer
 * @method bool isDryRun
 * @method void addMigrationSql(string $sql)
 * @method void addUpgradeNotices(string $type, string $upgradeNotice)
 * @method void save(Model $model)
 *
 * @property SymfonyStyle $io
 */
trait NewsListToListTrait
{
    /**
     * @return array ['config' => ListConfigModel, 'configElements' => ListConfigElementModel[]]
     */
    protected function createListConfig(ModuleModel $module, int $filterConfigId): array
    {
        /** @var ListConfigModel $listConfig */
        $listConfig = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ListConfigModel());
        $listConfig->filter = $filterConfigId;
        $listConfig->tstamp = $listConfig->dateAdded = time();
        $listConfig->title = $module->name;
        $listConfig->numberOfItems = $module->numberOfItems;
        $listConfig->perPage = $module->perPage;
        $listConfig->skipFirst = $module->skipFirst;
        $listConfig->sortingField = 'date';
        $listConfig->sortingDirection = ListConfig::SORTING_DIRECTION_DESC;
        $listConfig->limitFormattedFields = true;
        $listConfig->useAlias = true;
        $listConfig->aliasField = 'alias';

        if ($module->jumpToDetails) {
            $listConfig->addDetails = true;
            $listConfig->jumpToDetails = $module->jumpToDetails;
        }

        if (!$this->isDryRun()) {
            $listConfig->save();
        } else {
            $listConfig->id = 0;
        }

        $imageSizeListConfigElement = $this->addImageSizeListConfigElement($listConfig->id, $module->imgSize);

        $configElements = [
            'imageSize' => $imageSizeListConfigElement,
        ];

        // category filter


        // news related
        // related_numberOfItems, related_match (array mit "tags", "categories"), image config element -> create new list config and link to list config element

        return [
            'config' => $listConfig,
            'configElements' => $configElements
        ];
    }

    protected function addImageSizeListConfigElement(int $listConfigId, ?string $imgSize = null)
    {
        if (!$imgSize) {
            return null;
        }

        $imgSize = StringUtil::deserialize($imgSize);

        if (empty(array_filter($imgSize))) {
            return null;
        }

        $listConfigElement = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ListConfigElementModel());
        $listConfigElement->type = ListConfigElement::TYPE_IMAGE;
        $listConfigElement->pid = $listConfigId;
        $listConfigElement->tstamp = $listConfigElement->dateAdded = time();
        $listConfigElement->title = 'News Image';
        $listConfigElement->imageSelectorField = 'addImage';
        $listConfigElement->imageField = 'singleSRC';
        $listConfigElement->imgSize = serialize($imgSize);

        if (!$this->isDryRun()) {
            $listConfigElement->save();
        } else {
            $listConfigElement->id = 0;
        }

        return $listConfigElement;
    }
}
