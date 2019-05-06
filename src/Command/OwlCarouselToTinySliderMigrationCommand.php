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


use Contao\ModuleModel;
use Contao\StringUtil;
use HeimrichHannot\ListBundle\Module\ModuleList;
use HeimrichHannot\MigrationBundle\Extensions\NewsListToFilterTrait;
use HeimrichHannot\MigrationBundle\Extensions\NewsListToListTrait;
use HeimrichHannot\TinySliderBundle\DataContainer\TinySliderConfigContainer;
use HeimrichHannot\TinySliderBundle\Model\TinySliderConfigModel;

class OwlCarouselToTinySliderMigrationCommand extends AbstractModuleMigrationCommand
{
    use NewsListToFilterTrait;
    use NewsListToListTrait;

    public function isDryRun(): bool
    {
        // Don't save while not ready
        return true;
    }


    protected function configure()
    {
        $this->setName("huh:migration:module:owlcarousel")
            ->setDescription("Migrate owl carousel to tiny slider.")
        ;
        parent::configure();
    }

    /**
     * Run custom migration on each module
     * @return mixed
     */
    protected function migrate(ModuleModel $module): int
    {
        $filterConfigData = $this->createNewsFilter($module);
        $filterConfig = $filterConfigData['config'];
        $listConfigData = $this->createListConfig($module, $filterConfig->id);
        $listConfig = $listConfigData['config'];
        $sliderConfig = $this->createTinySliderConfig($module);
        $this->migrateFrontendModule($module, $listConfig->id);

        $listConfig->addTinySlider = "1";
        $listConfig->tinySliderConfig = $sliderConfig->id;
        if (!$this->isDryRun())
        {
            $listConfig->save();
        }

        // Manuell: Slider Texte

        return 0;
    }

    /**
     * @param ModuleModel $module
     * @param int $listConfigId
     * @return ModuleModel
     */
    protected function migrateFrontendModule(ModuleModel $module, int $listConfigId)
    {
        $module->tstamp     = time();
        $module->type       = ModuleList::TYPE;
        $module->listConfig = $listConfigId;
        if (!$this->isDryRun()) {
            $module->save();
        }

        $this->addMigrationSql("UPDATE tl_module SET type='".$module->type."', listConfig=".$module->listConfig." WHERE id=".$module->id.";");

        return $module;
    }



    protected function createTinySliderConfig(ModuleModel $module): TinySliderConfigModel
    {
        /** @var TinySliderConfigModel $configuration */
        $configuration = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new TinySliderConfigModel());
        $configuration->tstamp = time();
        $configuration->title = $module->name;
        $configuration->type = TinySliderConfigContainer::TYPE_BASE;
        $mapping = [
            'items'              => 'items',
            'margin'             => 'gutter',
            'loop'               => 'loop',
            'mouseDrag'          => 'mouseDrag',
            'touchDrag'          => 'touch',
            'stagePadding'       => 'edgePadding',
            'autoHeight'         => 'autoHeight',
            'autoWidth'          => 'autoWidth',
            'nav'                => 'controls',
            'navRewind'          => 'rewind',
            'slideBy'            => 'slideBy',
            'dots'               => 'nav',
            'lazyload'           => 'lazyload',
            'autoplay'           => 'autoplay',
            'autoplayTimeout'    => 'autoplayTimeout',
            'autoplayHoverPause' => 'autoplayHoverPause',
            'animateIn'          => 'animateIn',
            'animateOut'         => 'animateOut',
            'startPosition'      => [
                'key' => 'startIndex',
                'callable' => function($oldValue) { return is_numeric($oldValue) ? (intval($oldValue) >= 0 ?: 0) : 0; }
            ],
        ];

        $this->map($module, $configuration, $mapping, 'owl_', 'tinySlider_');

        $responsiveConfig = StringUtil::deserialize($module->owl_responsive);

        if (is_array($responsiveConfig))
        {
            $breakpoints = [];
            foreach ($responsiveConfig as $config)
            {
                if (!isset($config['owl_breakpoint']) || $config['owl_breakpoint'] === "" || !isset($config['owl_config']) || empty($config['owl_config']))
                {
                    continue;
                }
                reset($mapping);
                $breakpoint = [];
                $breakpoint['breakpoint'] = $config['owl_breakpoint'];
                $breakpointConfiguration = $configuration->cloneOriginal();
                $breakpointConfiguration->title = $module->name.' - mobile ('.$config['owl_breakpoint'].'px)';
                $breakpointConfiguration->type = TinySliderConfigContainer::TYPE_RESPONSIVE;
                $breakpointConfig = explode(",", $config['owl_config']);
                $breakpointConfig = array_map('trim', $breakpointConfig);
                if (empty($breakpointConfig)) {
                    continue;
                }
                $this->map((object) $breakpointConfig, $breakpointConfiguration, $mapping, 'owl_', 'tinySlider_');
                if (!$this->isDryRun())
                {
                    //$breakpointConfiguration->save();
                }
                $breakpoint['configuration'] = $breakpointConfiguration->id;
                $breakpoints[] = $breakpoint;
            }
            if (!empty($breakpoints))
            {
                $configuration->tinySlider_responsive = serialize($breakpoints);
            }
        }

        if (!$this->isDryRun())
        {
            $configuration->save();
        }
        else {
            $configuration->id = 0;
        }

        if ($module->owl_navText)
        {
            $navText = StringUtil::deserialize($module->owl_navText);

            if (is_array($navText) && isset($navText[0]) && isset($navText[1]))
            {
                if (!empty($navText[0]) || !empty($navText[1]))
                {
                    $this->addUpgradeNotices("You need to manually adjust slider navigation buttons text for Tiny Slider config '".$configuration->title."' (ID: ".$configuration->id."): '".$navText[0]."'', '".$navText[1]."'");
                }
            }
        }

        return $configuration;


//        $a = 'owl_center,
//            owl_pullDrag,owl_freeDrag,
//            owl_merge,owl_mergeFit,
//            owl_URLhashListener,
//            ,owl_dotsEach,owl_dotData,
//            ,owl_lazyContent,
//            owl_smartSpeed,owl_fluidSpeed,owl_autoplaySpeed,owl_navSpeed,owl_dotsSpeed,owl_dragEndSpeed,
//            owl_callbacks,
//            owl_responsive,owl_responsiveRefreshRate,owl_responsiveBaseElement,owl_responsiveClass,
//            owl_video,owl_videoHeight,owl_videoWidth,
//            ,,owl_fallbackEasing,
//            owl_rtl';
    }



    static function getTypes(): array
    {
        return ['owl_newslist'];
    }
}