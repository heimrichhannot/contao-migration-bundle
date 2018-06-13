<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2018 Heimrich & Hannot GmbH
 *
 * @author  Thomas Körner <t.koerner@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */


namespace HeimrichHannot\MigrationBundle\Command;


use Contao\Controller;
use Contao\CoreBundle\Command\AbstractLockedCommand;
use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\Model;
use Contao\Model\Collection;
use Contao\ModuleModel;
use Contao\NewsArchiveModel;
use Contao\StringUtil;
use HeimrichHannot\CategoriesBundle\CategoriesBundle;
use HeimrichHannot\FilterBundle\Filter\Type\ChoiceType;
use HeimrichHannot\FilterBundle\Filter\Type\DateRangeType;
use HeimrichHannot\FilterBundle\Filter\Type\DateType;
use HeimrichHannot\FilterBundle\Filter\Type\SubmitType;
use HeimrichHannot\FilterBundle\Filter\Type\TextConcatType;
use HeimrichHannot\FilterBundle\Model\FilterConfigElementModel;
use HeimrichHannot\FilterBundle\Model\FilterConfigModel;
use HeimrichHannot\FilterBundle\Module\ModuleFilter;
use HeimrichHannot\ListBundle\Backend\ListConfig;
use HeimrichHannot\ListBundle\Backend\ListConfigElement;
use HeimrichHannot\ListBundle\Backend\Module;
use HeimrichHannot\ListBundle\Model\ListConfigElementModel;
use HeimrichHannot\ListBundle\Model\ListConfigModel;
use HeimrichHannot\ReaderBundle\Model\ReaderConfigElementModel;
use HeimrichHannot\ReaderBundle\Model\ReaderConfigModel;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
use HeimrichHannot\UtilsBundle\Model\ModelUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\TranslatorInterface;

class NewsPlusModuleMigrationCommand extends AbstractLockedCommand
{
    /**
     * @var ContaoFrameworkInterface
     */
    protected $framework;
    /**
     * @var ModelUtil
     */
    protected $modelUtil;
    /**
     * @var array
     */
    protected $processedReaderModules = [];
    /**
     * @var array
     */
    protected $processedFilterModules = [];
    /**
     * @var SymfonyStyle
     */
    protected $io;
    /**
     * @var TranslatorInterface
     */
    protected $translator;

    public function __construct(ContaoFrameworkInterface $framework, ModelUtil $modelUtil, TranslatorInterface $translator)
    {
        parent::__construct();
        $this->framework  = $framework;
        $this->modelUtil  = $modelUtil;
        $this->translator = $translator;
    }

    /**
     * @param $module
     * @return \Model
     */
    public function createListConfig(ModuleModel $module): Model
    {
        $this->io->progressAdvance();
        $listConfig                    = $this->modelUtil->setDefaultsFromDca(new ListConfigModel());
        $listConfig->tstamp            = $listConfig->dateAdded = time();
        $listConfig->title             = $module->name;
        $listConfig->numberOfItems     = $module->numberOfItems;
        $listConfig->perPage           = $module->perPage;
        $listConfig->skipFirst         = $module->skipFirst;
        $listConfig->jumpToDetails     = $module->jumpToDetails;
        $listConfig->useModal          = $module->news_showInModal;
        $listConfig->addInfiniteScroll = $module->news_useInfiniteScroll;
        $listConfig->itemTemplate      = $module->news_template;
        $listConfig->save();
        return $listConfig;
    }

    protected function configure()
    {
        $this->setName("huh:migration:module:newsplus")
            ->setDescription("A migration script for newsplus modules");
        parent::configure();
    }


    /**
     * Executes the command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function executeLocked(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title("Migration for News_Plus module");
        $this->io = $io;
        $this->framework->initialize();

        $this->migrateListModules();
        $this->migrateReaderModules();

        $io->success("Migration news plus to filter/list/reader finished!");
        return 0;
    }

    /**
     * @return bool
     */
    public function migrateListModules()
    {
        $this->io->section("List module migration");
        $listModules = $this->findModules(['newslist_plus', 'newslist_highlight']);
        if (!$listModules)
        {
            $this->io->note("No list modules found.");
            return true;
        }
        $listCount   = 0;
        $errorCount  = 0;
        $readerCount = 0;
        $filterCount = 0;
        $this->io->progressStart($listModules->count());
        foreach ($listModules as $module)
        {
            $filters    = [];
            $listConfig = $this->createListConfig($module);
            $listCount++;
            $filters = $this->migrateListModule($module, $listConfig, $filters);

            $readerConfig = null;
            if ($module->news_readerModule && $module->news_readerModule > 0)
            {
                if ($readerModule = ModuleModel::findById($module->news_readerModule))
                {
                    $readerConfig                   = $this->createReaderConfig($readerModule);
                    $readerConfig                   = $this->migrateReaderModule($readerModule, $readerConfig);
                    $this->processedReaderModules[] = $readerModule->id;
                }
            }

            if ($module->news_filterModule && $module->news_filterModule > 0)
            {
                if ($filterModule = ModuleModel::findById($module->news_filterModule))
                {
                    $filters                        = $this->migrateFilterModule($filterModule, $filters);
                    $this->processedFilterModules[] = $filterModule->id;
                }
            }

            $filterConfig       = $this->attachFilter($module);
            $listConfig->filter = $filterConfig->id;
            $listConfig->save();

            if ($readerConfig)
            {
                $readerConfig->filter = $filterConfig->id;
                $readerConfig->save();
                $readerCount++;
            }
            if ($filterModule)
            {
                $filterModule->filter = $filterConfig->id;
                $filterModule->save();
                $filterCount++;
            }

            $this->attachFilterElements($filterConfig, $filters);
        }
        $this->io->progressFinish();
        $this->io->writeln("Finished migration of $listCount list modules. Also migrated $readerCount reader modules and $filterCount filter modules linked with list modules.");
        $this->io->newLine();
        return true;
    }

    /**
     * @return bool
     */
    public function migrateReaderModules()
    {
        $this->io->section("Reader modules migration");
        $readerModules = $this->findModules(["newsreader_plus"]);
        if (!$readerModules)
        {
            $this->io->note("No reader modules found.");
            return true;
        }
        $modulesCount = 0;
        $skippedCount = 0;
        $this->io->progressStart($readerModules->count());
        foreach ($readerModules as $module)
        {
            $this->io->progressAdvance();
            if (in_array($module->id, $this->processedReaderModules))
            {
                $skippedCount++;
                continue;
            }
            $readerConfig = $this->createReaderConfig($module);
            $this->migrateReaderModule($module, $readerConfig);
            $modulesCount++;

            $filterConfig             = $this->attachFilter($module);
            $filters                  = [];
            $filters["news_archives"] = StringUtil::deserialize($module->news_archives, true);
            $this->attachFilterElements($filterConfig, $filters);


        }
        $this->io->progressFinish();
        $this->io->writeln("Finished migration of $modulesCount reader modules. $skippedCount modules were already migrated.");
        $this->io->newLine();
        return true;
    }

    /**
     * @param array $types
     * @return Collection|ModuleModel|ModuleModel[]|null
     */
    public function findModules(array $types)
    {
        $options['column'] = [
            'tl_module.type IN (' . implode(',', array_map(function ($type) {
                return '"' . addslashes($type) . '"';
            }, $types)) . ')'
        ];
        return ModuleModel::findAll($options);
    }

    /**
     * @param ModuleModel $module
     * @param ListConfigModel|Model $listConfig
     * @param array $filters
     * @return array
     */
    public function migrateListModule(ModuleModel $module, Model $listConfig, array $filters)
    {
        $filters["news_archives"] = StringUtil::deserialize($module->news_archives, true);
        $this->attachListImageElement($module, $listConfig);

        $this->copyNewsTemplate($module);

        $module->tstamp     = time();
        $module->type       = Module::MODULE_LIST;
        $module->listConfig = $listConfig->id;
        return $filters;
    }

    /**
     * @param ModuleModel $module
     *
     * @param ReaderConfigModel $readerConfig
     * @return ReaderConfigModel
     */
    public function migrateReaderModule(ModuleModel $module, ReaderConfigModel $readerConfig)
    {

        if (!$readerConfig || $readerConfig->id < 1)
        {
            return null;
        }

        $module->tstamp       = time();
        $module->readerConfig = $readerConfig->id;
        $module->type         = \HeimrichHannot\ReaderBundle\Backend\Module::MODULE_READER;
        $module->save();

        $this->copyNewsTemplate($module);

        $this->attachReaderImageElement($module, $readerConfig);
        $this->addNavigationElement($module, $readerConfig);
        $this->addSyndicationElement($module, $readerConfig);
        return $readerConfig;
    }

    /**
     * @param ModuleModel $module
     * @param array $filters
     * @return array
     */
    protected function migrateFilterModule(ModuleModel $module, array $filters)
    {
        $filters["date_range"] = true;
        $filters["submit"]     = true;
        $filters["search"]     = $module->news_filterShowSearch ? true : false;
        if ($module->news_archives && !empty($archives = StringUtil::deserialize($module->news_archives, true)))
        {
            $pids = [];
            foreach ($archives as $id)
            {
                $archive = NewsArchiveModel::findById($id);
                if ($archive)
                {
                    $categories = StringUtil::deserialize($archives->categories, true);
                    if (!empty($categories))
                    {
                        $pids = array_merge($pids, $categories);
                    }
                }
            }
            if (!empty($pids))
            {
                $filters['categories'] = $pids;
            }
        }

        $module->tstamp = time();
        $module->type   = ModuleFilter::TYPE;
        $module->save();

        return $filters;
    }

    /**
     * @param ModuleModel $module
     * @return ReaderConfigModel|Model
     */
    protected function createReaderConfig(ModuleModel $module)
    {
        $readerConfig                             = $this->modelUtil->setDefaultsFromDca(new ReaderConfigModel());
        $readerConfig->tstamp                     = $readerConfig->dateAdded = time();
        $readerConfig->dateAdded                  = time();
        $readerConfig->title                      = $module->name;
        $readerConfig->dataContainer              = 'tl_news';
        $readerConfig->itemRetrievalMode          = 'auto_item';
        $readerConfig->itemRetrievalAutoItemField = 'alias';
        $readerConfig->hideUnpublishedItems       = 1;
        $readerConfig->item                       = 'news_default';
        $readerConfig->limitFormattedFields       = 1;
        $readerConfig->itemTemplate               = $module->news_template;
        $readerConfig->formattedFields            = ['headline', 'teaser', 'singleSRC'];
        $readerConfig->formattedFields            = ['headline', 'teaser', 'singleSRC'];
        $readerConfig->publishedField             = 'published';
        $readerConfig->headTags                   = [
            ['service' => 'huh.head.tag.title', 'pattern' => '%headline%'],
            ['service' => 'huh.head.tag.meta_description', 'pattern' => '%teaser%'],
            ['service' => 'huh.head.tag.og_image', 'pattern' => '%singleSRC%'],
            ['service' => 'huh.head.tag.og_type', 'pattern' => 'article'],
            ['service' => 'huh.head.tag.og_description', 'pattern' => '%teaser%'],
        ];
        return $readerConfig->save();
    }

    /**
     * @param ModuleModel $module
     * @return bool
     */
    protected function copyNewsTemplate(ModuleModel $module)
    {
        $templatePath     = Controller::getTemplate($module->news_template);
        $twigTemplatePath = $this->getContainer()->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $module->news_template . '.html.twig';

        if (!file_exists($twigTemplatePath))
        {
            $fileSystem = new Filesystem();

            try
            {
                $fileSystem->copy($templatePath, $twigTemplatePath);
                $this->io->writeln('Created copy of existing template to ' . $module->news_template . '.html.twig template, please adjust the template to fit twig syntax in ' . $twigTemplatePath . '.');
            } catch (FileNotFoundException $e)
            {
                $this->io->error('Could not copy news_template: ' . $module->news_template . ', which file does not exist.');
                return false;
            } catch (IOException $e)
            {
                $this->io->error('An error occurred while copy news_template from ' . $templatePath . ' to ' . $twigTemplatePath . '.');
                return false;
            }
        }
        return true;
    }

    /**
     * @param ModuleModel $module
     * @param ListConfigModel|Model $listConfig
     * @return bool
     */
    protected function attachListImageElement(ModuleModel $module, Model $listConfig)
    {
        $listConfigElement                     = $this->modelUtil->setDefaultsFromDca(new ListConfigElementModel());
        $listConfigElement->tstamp             = time();
        $listConfigElement->dateAdded          = time();
        $listConfigElement->title              = "News Image";
        $listConfigElement->type               = ListConfigElement::TYPE_IMAGE;
        $listConfigElement->imageSelectorField = 'addImage';
        $listConfigElement->imageField         = 'singleSRC';
        $listConfigElement->imgSize            = $module->imgSize;
        $listConfigElement->pid                = $listConfig->id;
        $listConfigElement->save();
        return true;
    }

    /**
     * @param ModuleModel $module News plus reader module
     * @param ReaderConfigModel $readerConfig
     * @return bool
     */
    protected function attachReaderImageElement(ModuleModel $module, ReaderConfigModel $readerConfig)
    {
        $readerConfigElement                     = $this->modelUtil->setDefaultsFromDca(new ReaderConfigElementModel());
        $readerConfigElement->tstamp             = $readerConfigElement->dateAdded = time();
        $readerConfigElement->pid                = $readerConfig->id;
        $readerConfigElement->title              = 'Image';
        $readerConfigElement->type               = 'image';
        $readerConfigElement->imageSelectorField = 'addImage';
        $readerConfigElement->imageField         = 'singleSRC';
        $readerConfigElement->imgSize            = $module->imgSize;
        $readerConfigElement->save();
        return true;
    }

    /**
     * @param ModuleModel $module
     * @param ReaderConfigModel $readerConfig
     * @return bool
     */
    protected function addNavigationElement(ModuleModel $module, ReaderConfigModel $readerConfig)
    {
        $readerConfigElement                     = $this->modelUtil->setDefaultsFromDca(new ReaderConfigElementModel());
        $readerConfigElement->tstamp             = $readerConfigElement->dateAdded = time();
        $readerConfigElement->pid                = $readerConfig->id;
        $readerConfigElement->title              = 'Navigation';
        $readerConfigElement->type               = 'navigation';
        $readerConfigElement->name               = 'navigation';
        $readerConfigElement->navigationTemplate = 'readernavigation_default';
        $readerConfigElement->nextLabel          = 'huh.reader.element.label.next.default';
        $readerConfigElement->previousLabel      = 'huh.reader.element.label.previous.default';
        $readerConfigElement->sortingDirection   = ListConfig::SORTING_DIRECTION_DESC;
        $readerConfigElement->sortingField       = 'date';
        $readerConfigElement->nextTitle          = 'huh.reader.element.title.next.default';
        $readerConfigElement->previousTitle      = 'huh.reader.element.title.previous.default';
        $readerConfigElement->infiniteNavigation = (bool)$module->news_navigation_infinite;
        $readerConfigElement->save();
        return true;
    }

    /**
     * @param ModuleModel $module
     * @param ReaderConfigModel $readerConfig
     * @return bool
     */
    protected function addSyndicationElement(ModuleModel $module, ReaderConfigModel $readerConfig)
    {
        if (false === (bool)$module->addShare)
        {
            return true;
        }

        $readerConfigElement                      = $this->modelUtil->setDefaultsFromDca(new ReaderConfigElementModel());
        $readerConfigElement->tstamp              = time();
        $readerConfigElement->dateAdded           = time();
        $readerConfigElement->pid                 = $readerConfig->id;
        $readerConfigElement->title               = 'Syndikation';
        $readerConfigElement->type                = 'syndication';
        $readerConfigElement->name                = 'syndications';
        $readerConfigElement->syndicationTemplate = 'readersyndication_fontawesome_bootstrap4_button_group';

        $syndications = StringUtil::deserialize($module->share_buttons, true);

        if (isset($syndications['pdfButton']))
        {
            $readerConfigElement->syndicationPdf = true;
        }

        if (isset($syndications['printButton']))
        {
            $readerConfigElement->syndicationPrint         = true;
            $readerConfigElement->syndicationPrintTemplate = true;
        }

        if (isset($syndications['facebook']))
        {
            $readerConfigElement->syndicationFacebook = true;
        }

        if (isset($syndications['twitter']))
        {
            $readerConfigElement->syndicationTwitter = true;
        }

        if (isset($syndications['gplus']))
        {
            $readerConfigElement->syndicationGooglePlus = true;
        }

        if (isset($syndications['mailto']))
        {
            $readerConfigElement->syndicationMail = true;
        }
        $readerConfigElement->save();
        return true;
    }

    /**
     * @param ModuleModel $module
     * @return FilterConfigModel|Model
     */
    protected function attachFilter(ModuleModel $module)
    {
        $filterConfig                = $this->modelUtil->setDefaultsFromDca(new FilterConfigModel());
        $filterConfig->tstamp        = $filterConfig->dateAdded = time();
        $filterConfig->title         = $module->name;
        $filterConfig->name          = StringUtil::standardize($module->name);
        $filterConfig->dataContainer = 'tl_news';
        $filterConfig->method        = 'GET';
        $filterConfig->template      = 'form_div_layout';
        $filterConfig->published     = 1;
        $filterConfig->save();
        return $filterConfig;
    }

    /**
     * @param FilterConfigModel|Model $filterConfig
     */
    protected function attachFilterElements(Model $filterConfig, array $filters = [])
    {
        $sorting = 2;
        if (isset($filters["news_archives"]) && !empty($filters["news_archives"]))
        {
            $sorting = $this->addParentFilterElement($filterConfig, $sorting, $filters["news_archives"]);
        }
        if (isset($filters["date_range"]) && $filters["date_range"] === true)
        {
            $sorting = $this->addDateRangeFilter($filterConfig, $sorting);
        }
        $sorting = $this->addPublishedFilter($filterConfig, $sorting);
        if (isset($filters["search"]) && $filters["search"] === true)
        {
            $this->addSearchFilter($filterConfig, $sorting);
        }
        if (isset($filters["categories"]) && is_array($filters["categories"]))
        {
            $this->addCategoriesFilter($filterConfig, $sorting, $filters['categories']);
        }
        if (isset($filters["submit"]) && $filters["submit"] === true)
        {
            $this->addSubmitFilter($filterConfig, $sorting);
        }
    }

    /**
     * @param FilterConfigModel|Model $filterConfig
     * @param $sorting
     * @param $module
     * @return int
     */
    protected function addParentFilterElement(Model $filterConfig, int $sorting, array $pids)
    {
        if (empty($pids))
        {
            return $sorting;
        }

        $initialValueArray = [];
        foreach ($pids as $pid)
        {
            $initialValueArray[] = ['value' => $pid];
        }

        $filterElement                    = $this->modelUtil->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title             = 'Parent';
        $filterElement->pid               = $filterConfig->id;
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
        $filterElement->save();

        if ($filterElement->id > 0)
        {
            return $sorting * 2;
        }
        return $sorting;
    }

    protected function addPublishedFilter($filterConfig, $sorting)
    {
        $filterElement                  = $this->modelUtil->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title           = 'Published';
        $filterElement->pid             = $filterConfig->id;
        $filterElement->sorting         = $sorting;
        $filterElement->tstamp          = time();
        $filterElement->dateAdded       = time();
        $filterElement->type            = 'visible';
        $filterElement->field           = 'published';
        $filterElement->published       = 1;
        $filterElement->addStartAndStop = 1;
        $filterElement->startField      = 'start';
        $filterElement->stopField       = 'stop';
        $filterElement->save();

        if ($filterElement->id > 0)
        {
            return $sorting * 2;
        }
        return $sorting;
    }

    /**
     * @param $filterConfig
     * @param $sorting
     * @return int
     */
    protected function addDateRangeFilter($filterConfig, $sorting)
    {
        $filterElement                   = $this->modelUtil->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title            = 'Start date';
        $filterElement->pid              = $filterConfig->id;
        $filterElement->sorting          = $sorting * 2;
        $filterElement->tstamp           = time();
        $filterElement->dateAdded        = time();
        $filterElement->type             = DateType::TYPE;
        $filterElement->name             = 'startDate';
        $filterElement->field            = 'date';
        $filterElement->inputGroup       = '1';
        $filterElement->inputGroupAppend = 'huh.filter.input_group_text.button.fa_calendar';
        $filterElement->addPlaceholder   = '1';
        $filterElement->placeholder      = $this->translator->trans('huh.filter.placeholder.from');
        $filterElement->published        = 1;
        $startElement                    = $filterElement->save();

        $filterElement                   = $this->modelUtil->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title            = 'End date';
        $filterElement->pid              = $filterConfig->id;
        $filterElement->sorting          = $sorting * 4;
        $filterElement->tstamp           = time();
        $filterElement->dateAdded        = time();
        $filterElement->type             = DateType::TYPE;
        $filterElement->name             = 'endDate';
        $filterElement->field            = 'date';
        $filterElement->inputGroup       = '1';
        $filterElement->inputGroupAppend = 'huh.filter.input_group_text.button.fa_calendar';
        $filterElement->addPlaceholder   = '1';
        $filterElement->placeholder      = $this->translator->trans('huh.filter.placeholder.to');
        $filterElement->published        = 1;
        $stopElement                     = $filterElement->save();

        $filterElement               = $this->modelUtil->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title        = 'Date range';
        $filterElement->pid          = $filterConfig->id;
        $filterElement->sorting      = $sorting;
        $filterElement->tstamp       = time();
        $filterElement->dateAdded    = time();
        $filterElement->type         = DateRangeType::TYPE;
        $filterElement->name         = 'date_range';
        $filterElement->startElement = $startElement->id;
        $filterElement->stopElement  = $stopElement->id;
        $filterElement->published    = 1;
        $filterElement->save();

        return $sorting * 8;
    }

    /**
     * @param $filterConfig
     * @param $sorting
     * @return int
     */
    protected function addSubmitFilter($filterConfig, $sorting)
    {
        $filterElement            = $this->modelUtil->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title     = 'Date range';
        $filterElement->pid       = $filterConfig->id;
        $filterElement->sorting   = $sorting;
        $filterElement->tstamp    = time();
        $filterElement->dateAdded = time();
        $filterElement->type      = SubmitType::TYPE;
        $filterElement->published = 1;
        $filterElement->save();
        return $sorting * 2;
    }

    /**
     * @param $filterConfig
     * @param $sorting
     * @return int
     */
    protected function addSearchFilter(FilterConfigModel $filterConfig, int $sorting)
    {
        $filterElement            = $this->modelUtil->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title     = 'Search';
        $filterElement->pid       = $filterConfig->id;
        $filterElement->sorting   = $sorting;
        $filterElement->tstamp    = time();
        $filterElement->dateAdded = time();
        $filterElement->type      = TextConcatType::TYPE;
        $filterElement->fields    = serialize(['headline', 'teaser']);
        $filterElement->name      = 'search';
        $filterElement->published = 1;
        $filterElement->save();
        return $sorting * 2;
    }

    /**
     * @param FilterConfigModel $filterConfig
     * @param int $sorting
     * @param array $categories
     * @return int
     */
    protected function addCategoriesFilter(FilterConfigModel $filterConfig, int $sorting, array $categories = [])
    {
        if (empty($categories))
        {
            return $sorting;
        }

        if (!$this->getContainer()->get('huh.utils.container')->isBundleActive('HeimrichHannot\CategoriesBundle\CategoriesBundle'))
        {
            return $sorting;
        }

        $filterElement                   = $this->modelUtil->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title            = 'Categories';
        $filterElement->pid              = $filterConfig->id;
        $filterElement->sorting          = $sorting;
        $filterElement->tstamp           = time();
        $filterElement->dateAdded        = time();
        $filterElement->type             = ChoiceType::TYPE;
        $filterElement->field            = 'categories';
        $filterElement->name             = 'categories';
        $filterElement->published        = 1;
        $filterElement->parentCategories = serialize($categories);
        $filterElement->save();
        return $sorting * 2;
    }
}