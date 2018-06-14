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
use HeimrichHannot\ListBundle\Model\ListConfigElementModel;
use HeimrichHannot\ListBundle\Model\ListConfigModel;
use HeimrichHannot\ListBundle\Module\ModuleList;
use HeimrichHannot\ReaderBundle\Model\ReaderConfigElementModel;
use HeimrichHannot\ReaderBundle\Model\ReaderConfigModel;
use HeimrichHannot\ReaderBundle\Module\ModuleReader;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
use HeimrichHannot\UtilsBundle\Model\ModelUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\TranslatorInterface;

class NewsPlusModuleMigrationCommand extends AbstractLockedCommand
{
    const NEWS_PLUS_READER = 'newsreader_plus';
    const NEWS_PLUS_LIST = 'newslist_plus';
    const NEWS_PLUS_HIGHLIGHT = 'newslist_highlight';

    const LIST_MODULES = [
        self::NEWS_PLUS_LIST,
        self::NEWS_PLUS_HIGHLIGHT
    ];

    const READER_MODULES = [
        self::NEWS_PLUS_READER
    ];

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
    /**
     * @var array
     */
    protected $processedTemplates = [];
    protected $dryRun = false;
    protected $count = [];

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
        /** @var ListConfigModel $listConfig */
        $listConfig                    = $this->modelUtil->setDefaultsFromDca(new ListConfigModel());
        $listConfig->tstamp            = $listConfig->dateAdded = time();
        $listConfig->title             = $module->name;
        $listConfig->numberOfItems     = $module->numberOfItems;
        $listConfig->perPage           = $module->perPage;
        $listConfig->skipFirst         = $module->skipFirst;
        $listConfig->addDetails        = "1";
        $listConfig->jumpToDetails     = $module->jumpToDetails;
        $listConfig->useModal          = $module->news_showInModal;
        $listConfig->addInfiniteScroll = $module->news_useInfiniteScroll;
        $listConfig->itemTemplate      = $module->news_template;
        $listConfig->sortingField      = 'date';
        $listConfig->sortingDirection  = ListConfig::SORTING_DIRECTION_DESC;
        if (!$this->dryRun) {
            $listConfig->save();
        }
        return $listConfig;
    }

    protected function configure()
    {
        $this->setName("huh:migration:module:newsplus")
            ->setDescription("A migration script for newsplus modules")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Performs a run without writing to datebase and copy templates.")
            ->addOption('module', 'm', InputOption::VALUE_REQUIRED, "Convert a single module instead of all modules.")
        ;
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
        $io->title("Migration for News Plus module");
        $this->io = $io;
        $this->framework->initialize();

        if ($input->hasOption('dry-run') && $input->getOption('dry-run'))
        {
            $this->dryRun = true;
            $io->note("Dry run enabled, no data will be changed.");
            $io->newLine();
        }
        if ($input->hasOption('module') && $input->getOption('module'))
        {
            if (!$module = ModuleModel::findById($input->getOption('module')))
            {
                $io->error("No module with given id found.");
                return 1;
            }
            switch ($module->type) {
                case static::NEWS_PLUS_LIST:
                case static::NEWS_PLUS_HIGHLIGHT:
                    $io->writeln("Start migration ".$module->type.' module "'.$module->name.'" (ID: '.$module->id.')');
                    $this->migrateList($module);
                    break;
                case static::NEWS_PLUS_READER:
                    $io->writeln("Start migration ".$module->type.' module "'.$module->name.'" (ID: '.$module->id.')');
                    $this->migrateReader($module);
                    break;
                default:
                    $io->error("Modules of type ".$module->type.' are not supported.');
                    return 1;
            }
        }
        else
        {
            $this->migrateListModules();
            $this->migrateReaderModules();
        }



        $io->success("Migration news plus to filter/list/reader finished!");
        return 0;
    }

    /**
     * @return bool
     */
    public function migrateListModules()
    {
        $this->io->section("List module migration");
        $listModules = $this->findModules(static::LIST_MODULES);
        if (!$listModules)
        {
            $this->io->writeln("No list modules found.");
            return true;
        }
        $this->count = [
            'list' => 0,
            'reader' => 0,
            'filter' => 0,
            'readerConverted' => 0,
            'filterConverted' => 0,
        ];
        $this->io->progressStart($listModules->count());
        foreach ($listModules as $module)
        {
            $this->io->progressAdvance();
            $this->migrateList($module);
        }
        $listCount                   = $this->count['list'];
        $readerCount                 = $this->count['reader'];
        $filterCount                 = $this->count['filter'];
        $readerAlreadyConvertedCount = $this->count['readerConverted'];
        $filterAlreadyConvertedCount = $this->count['filterConverted'];
        $this->io->progressFinish();
        $this->io->writeln("Finished migration of $listCount list modules. Also migrated $readerCount reader modules and $filterCount filter modules linked with list modules.");
        if ($readerAlreadyConvertedCount > 0)
        {
            $this->io->writeln("$readerAlreadyConvertedCount reader modules were already converted.");
        }
        if ($filterAlreadyConvertedCount > 0)
        {
            $this->io->writeln("$filterAlreadyConvertedCount filter modules were already converted.");
        }
        $this->io->newLine();
        return true;
    }

    public function migrateList(ModuleModel $module)
    {
        $filters    = [];
        $listConfig = $this->createListConfig($module);
        $this->count['list']++;
        $filters = $this->migrateListModule($module, $listConfig, $filters);

        $filterConfig = null;
        if ($module->news_readerModule && $module->news_readerModule > 0)
        {
            if ($readerModule = ModuleModel::findById($module->news_readerModule))
            {
                if (ModuleReader::TYPE !== $readerModule->type || !$readerConfig = ReaderConfigModel::findByPk($readerModule->readerConfig || $this->dryRun))
                {
                    $readerConfig = $this->createReaderConfig($readerModule);
                    $this->count['reader']++;
                } else
                {
                    $filterConfig = FilterConfigModel::findByIdOrAlias($readerConfig->filter);
                    $this->count['readerConverted']++;
                }
                $readerConfig                   = $this->migrateReaderModule($readerModule, $readerConfig);
                $this->processedReaderModules[] = $readerModule->id;
            }
        }

        if ($module->news_filterModule && $module->news_filterModule > 0)
        {
            if ($filterModule = ModuleModel::findById($module->news_filterModule))
            {
                if (ModuleFilter::TYPE !== $filterModule->type)
                {
                    $filters                        = $this->migrateFilterModule($filterModule, $filters);
                    $this->processedFilterModules[] = $filterModule->id;
                    $this->count['filter']++;
                } else
                {
                    $this->count['filterConverted']++;
                }
            }
        }

        if (!$filterConfig)
        {
            $filterConfig = $this->attachFilter($module);
        }
        $listConfig->filter = $filterConfig->id;
        if (!$this->dryRun) {
            $listConfig->save();
        }
        if ($readerConfig)
        {
            $readerConfig->filter = $filterConfig->id;
            if (!$this->dryRun) {
                $readerConfig->save();
            }

        }
        if ($filterModule)
        {
            $filterModule->filter = $filterConfig->id;
            if (!$this->dryRun) {
                $filterModule->save();
            }

        }
        $this->attachFilterElements($filterConfig, $filters);
    }

    /**
     * @return bool
     */
    public function migrateReaderModules()
    {
        $this->io->section("Reader modules migration");
        $readerModules = $this->findModules(static::READER_MODULES);
        if (!$readerModules)
        {
            $this->io->writeln("No reader modules found.");
            return true;
        }
        $this->count['skipped'] = 0;
        $this->count['reader'] = 0;
        $this->io->progressStart($readerModules->count());
        foreach ($readerModules as $module)
        {
            $this->io->progressAdvance();
            $this->migrateReader($module);
        }
        $modulesCount = $this->count['reader'];
        $skippedCount = $this->count['skipped'];
        $this->io->progressFinish();
        $this->io->writeln("Finished migration of $modulesCount reader modules. $skippedCount modules were already migrated.");
        $this->io->newLine();
        return true;
    }

    /**
     * @param ModuleModel $module
     */
    public function migrateReader(ModuleModel $module)
    {
        if (in_array($module->id, $this->processedReaderModules))
        {
            $this->count['skipped']++;
            return;
        }
        $readerConfig = $this->createReaderConfig($module);
        $this->migrateReaderModule($module, $readerConfig);
        $this->count['reader']++;

        $filterConfig             = $this->attachFilter($module);
        $filters                  = [];
        $filters["news_archives"] = StringUtil::deserialize($module->news_archives, true);
        $this->attachFilterElements($filterConfig, $filters);
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

        if (!$this->copyNewsTemplate($module))
        {
            $this->io->note("Due error while importig the template, the item template will be reset to default template.");
            $listConfig->itemTemplate = "default";
            if (!$this->dryRun) {
                $listConfig->save();
            }

        }

        $module->tstamp     = time();
        $module->type       = ModuleList::TYPE;
        $module->listConfig = $listConfig->id;
        if (!$this->dryRun) {
            $module->save();
        }
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
        if (!$this->dryRun) {
            $module->save();
        }


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
        if (!$this->dryRun) {
            $module->save();
        }
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
        if (!$this->dryRun) {
            $readerConfig->save();
        }
        return $readerConfig;
    }

    /**
     * @param ModuleModel $module
     * @return bool
     */
    protected function copyNewsTemplate(ModuleModel $module)
    {
        if (in_array($module->news_template, $this->processedTemplates))
        {
            return true;
        }
        $this->processedTemplates[] = $module->news_template;

        if (ModuleList::TYPE == $module->type || in_array($module->type, static::LIST_MODULES))
        {
            $listTemplates = $this->getContainer()->getParameter('huh.list')['list']['templates']['item'];
            if (array_search($module->news_template, array_column($listTemplates, 'name')))
            {
                return true;
            }
        }
        if (ModuleReader::TYPE == $module->type || in_array($module->type, static::READER_MODULES))
        {
            $readerTemplates = $this->getContainer()->getParameter('huh.reader')['reader']['templates']['item'];
            if (array_search($module->news_template, array_column($readerTemplates, 'name')))
            {
                return true;
            }
        }

        try
        {
            $templatePath = Controller::getTemplate($module->news_template);
        } catch (\Exception $e) {
            $this->io->newLine(2);
            $this->io->error('Template ' . $module->news_template . ' does not exist and therefore could not be copied.');
            return false;

        }
        $twigTemplatePath = $this->getContainer()->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $module->news_template . '.html.twig';

        if (!file_exists($twigTemplatePath))
        {
            if ($this->dryRun) {
                $this->io->newLine(2);
                $this->io->writeln('Created no copy of existing template to ' . $module->news_template . '.html.twig template');
                return true;
            }
            $fileSystem = new Filesystem();

            try
            {
                $fileSystem->copy($templatePath, $twigTemplatePath);
                $this->io->newLine(2);
                $this->io->writeln('Created copy of existing template to ' . $module->news_template . '.html.twig template, please adjust the template to fit twig syntax in ' . $twigTemplatePath . '.');
                return true;
            } catch (FileNotFoundException $e)
            {
                $this->io->newLine(2);
                $this->io->error('Could not copy news_template: ' . $module->news_template . ', which file does not exist.');
                return false;
            } catch (IOException $e)
            {
                $this->io->newLine(2);
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
        if (!$this->dryRun) {
            $listConfigElement->save();
        }
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
        if (!$this->dryRun) {
            $readerConfigElement->save();
        }
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
        if (!$this->dryRun) {
            $readerConfigElement->save();
        }
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
        if (!$this->dryRun) {
            $readerConfigElement->save();
        }
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
        if (!$this->dryRun) {
            $filterConfig->save();
        }
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
        if (!$this->dryRun) {
            $filterElement->save();
        }
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
        if (!$this->dryRun) {
            $filterElement->save();
        }

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
        if (!$this->dryRun) {
            $startElement                    = $filterElement->save();
        }

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
        if (!$this->dryRun) {
            $stopElement                     = $filterElement->save();
        }

        $filterElement               = $this->modelUtil->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title        = 'Date range';
        $filterElement->pid          = $filterConfig->id;
        $filterElement->sorting      = $sorting;
        $filterElement->tstamp       = time();
        $filterElement->dateAdded    = time();
        $filterElement->type         = DateRangeType::TYPE;
        $filterElement->name         = 'date_range';
        $filterElement->published    = 1;
        if (!$this->dryRun) {
            $filterElement->startElement = $startElement->id;
            $filterElement->stopElement  = $stopElement->id;
            $filterElement->save();
        }
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
        if (!$this->dryRun) {
            $filterElement->save();
        }
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
        if (!$this->dryRun) {
            $filterElement->save();
        }
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
        if (!$this->dryRun) {
            $filterElement->save();
        }
        return $sorting * 2;
    }
}