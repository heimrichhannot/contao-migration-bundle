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
use HeimrichHannot\ListBundle\Backend\ListConfig;
use HeimrichHannot\ListBundle\Backend\ListConfigElement;
use HeimrichHannot\ListBundle\Model\ListConfigElementModel;
use HeimrichHannot\ListBundle\Model\ListConfigModel;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Trait NewsListToListTrait
 * @package HeimrichHannot\MigrationBundle\Extensions
 *
 * @method ContainerInterface getContainer
 * @method bool isDryRun
 * @method void addMigrationSql(string $sql)
 * @method void addUpgradeNotices(string $upgradeNotice)
 *
 * @property SymfonyStyle $io
 */
trait NewsListToListTrait
{
    /**
     * @param ModuleModel $module
     * @param int $filterConfig
     * @return array ['config' => ListConfigModel, 'configElements' => ListConfigElementModel[]]
     */
    protected function createListConfig(ModuleModel $module, int $filterConfig): array
    {
        /** @var ListConfigModel $listConfig */
        $listConfig                    = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ListConfigModel());
        $listConfig->tstamp            = $listConfig->dateAdded = time();
        $listConfig->title             = $module->name;
        $listConfig->numberOfItems     = $module->numberOfItems;
        $listConfig->perPage           = $module->perPage;
        $listConfig->skipFirst         = $module->skipFirst;
        $listConfig->sortingField      = 'date';
        $listConfig->sortingDirection  = ListConfig::SORTING_DIRECTION_DESC;


//        $listConfig->addDetails    = "1";
//        $listConfig->item = 'news_default';
//        if ($module->news_jumpToCurrent)
//        {
//            $listConfig->jumpToDetails = $module->news_jumpToCurrent;
//        }
//        $listConfig->itemTemplate      = $module->news_template;


        if (!$this->isDryRun()) {
            $listConfig->save();
        }
        else {
            $listConfig->id = 0;
        }

        $imageSizeListConfigElement =$this->addImageSizeListConfigElement($listConfig->id, $module->imgSize);

        return [
            'config' => $listConfig,
            'configElements' => [
                'imageSize' => $imageSizeListConfigElement,
            ]
        ];
    }

    protected function addImageSizeListConfigElement(int $listConfigId, ?string $imgSize = null)
    {
        if (!$imgSize)
        {
            return null;
        }

        $imgSize = StringUtil::deserialize($imgSize);
        if (empty($imgSize[0]) || empty($imgSize[1]) || empty($imgSize[2])) {
            return null;
        }

        $listConfigElement = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ListConfigElementModel());
        $listConfigElement->type = ListConfigElement::TYPE_IMAGE;
        $listConfigElement->pid = $listConfigId;
        $listConfigElement->tstamp = $listConfigElement->dateAdded = time();
        $listConfigElement->title = 'News Image';
        $listConfigElement->templateVariable = 'image';
        $listConfigElement->imageSelectorField = 'addImage';
        $listConfigElement->imageField = 'singleSRC';
        $listConfigElement->imgSize = $imgSize;

        if (!$this->isDryRun()) {
            $listConfigElement->save();
        }
        else {
            $listConfigElement->id = 0;
        }

        return $listConfigElement;
    }
}