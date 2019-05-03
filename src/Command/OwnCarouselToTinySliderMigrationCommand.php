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
use HeimrichHannot\TinySliderBundle\Model\TinySliderConfigModel;

class OwnCarouselToTinySliderMigrationCommand extends AbstractModuleMigrationCommand
{
    protected function configure()
    {
        $this->setName("huh:migration:module:owncarousel")
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
        $this->createTinySliderConfig($module);
        return;
    }

    protected function createTinySliderConfig(ModuleModel $module)
    {
        $configuration = new TinySliderConfigModel();
        $configuration->tinySlider_mode = 'carousel';
        $configuration->tinySlider_axis = 'horizontal';
        $configuration->tinySlider_items = $module->owl_items;
        $configuration->tinySlider_gutter = $module->owl_margin;
        $configuration->tinySlider_edgePadding = $module->owl_stagePadding;
        $configuration->tinySlider_autoWidth = $module->owl_autoWidth;
        $configuration->tinySlider_slideBy = $module->owl_slideBy;
        $configuration->tinySlider_controls = $module->owl_slideBy;


        $configuration->tinySlider_loop = $module->owl_loop;
        $configuration->tinySlider_loop = $module->owl_nav;

        $navigationText = StringUtil::deserialize($module->owl_navText);



        $a = 'owl_center,
							owl_mouseDrag,owl_touchDrag,owl_pullDrag,owl_freeDrag,
							,owl_merge,owl_mergeFit,
							owl_autoHeight, owl_autoHeightClass,
							,owl_startPosition,owl_URLhashListener,
							,owl_navRewind,,
							owl_slideBy,
							owl_dots,owl_dotsEach,owl_dotData,
							owl_lazyLoad,owl_lazyContent,
							owl_autoplay,owl_autoplayTimeout,owl_autoplayHoverPause,
							owl_smartSpeed,owl_fluidSpeed,owl_autoplaySpeed,owl_navSpeed,owl_dotsSpeed,owl_dragEndSpeed,
							owl_callbacks,
							owl_responsive,owl_responsiveRefreshRate,owl_responsiveBaseElement,owl_responsiveClass,
							owl_video,owl_videoHeight,owl_videoWidth,
							owl_animateOut,owl_animateIn,owl_fallbackEasing,
							owl_rtl';
    }

    static function getTypes(): array
    {
        return ['owl_newslist'];
    }
}