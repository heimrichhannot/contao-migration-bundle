<?php

namespace HeimrichHannot\MigrationBundle\Command;

use function Clue\StreamFilter\remove;
use Contao\ModuleModel;
use Contao\StringUtil;
use Contao\System;
use HeimrichHannot\FilterBundle\Model\FilterConfigElementModel;
use HeimrichHannot\FilterBundle\Model\FilterConfigModel;
use HeimrichHannot\ReaderBundle\Model\ReaderConfigElementModel;
use HeimrichHannot\ReaderBundle\Model\ReaderConfigModel;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use UAParser\Exception\FileNotFoundException;

class NewsReaderModuleMigrationCommand extends AbstractModuleMigrationCommand
{
    /**
     * @var array
     */
    protected static $types = ['newsreader', 'newsreader_plus'];

    /**
     * @var ReaderConfigModel
     */
    protected $readerConfig;

    /**
     * @var FilterConfigModel
     */
    protected $filterConfig;

    /**
     * @var array
     */
    protected $filterConfigElements;

    /**
     * @var array
     */
    protected $readerConfigElements;

    /**
     * Current module
     * @var ModuleModel
     */
    protected $module;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('migration:module:newsreader')->setDescription(
            'Migration of tl_module type:newsreaser modules to huhreader and creates reader configurations from old tl_module settings.'
        );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function migrate(ModuleModel $module): int
    {
        $this->module = $module;

        $this->readerConfig                             = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ReaderConfigModel());
        $this->readerConfig->tstamp                     = time();
        $this->readerConfig->dateAdded                  = time();
        $this->readerConfig->title                      = $this->module->name;
        $this->readerConfig->dataContainer              = 'tl_news';
        $this->readerConfig->itemRetrievalMode          = 'auto_item';
        $this->readerConfig->itemRetrievalAutoItemField = 'alias';
        $this->readerConfig->hideUnpublishedItems       = 1;
        $this->readerConfig->item                       = 'news_default';
        $this->readerConfig->limitFormattedFields       = 1;
        $this->readerConfig->itemTemplate               = $this->module->news_template;
        $this->readerConfig->formattedFields            = ['headline', 'teaser', 'singleSRC'];
        $this->readerConfig->formattedFields            = ['headline', 'teaser', 'singleSRC'];
        $this->readerConfig->publishedField             = 'published';
        $this->readerConfig->headTags                   = [
            ['service' => 'huh.head.tag.title', 'pattern' => '%headline%'],
            ['service' => 'huh.head.tag.meta_description', 'pattern' => '%teaser%'],
            ['service' => 'huh.head.tag.og_image', 'pattern' => '%singleSRC%'],
            ['service' => 'huh.head.tag.og_type', 'pattern' => 'article'],
            ['service' => 'huh.head.tag.og_description', 'pattern' => '%teaser%'],
        ];

        $this->readerConfig->save();

        if ($this->readerConfig->id > 0) {
            $this->output->writeln('Migrated "' . $this->module->name . '" (Module ID:' . $this->module->id . ') into new reader config with ID: ' . $this->readerConfig->id . '.');

            $this->updateModule();

            $this->copyNewsTemplate();

            $this->attachReaderElements();

            $this->attachFilter();
        }

        return 0;
    }

    protected function attachReaderElements()
    {
        $this->attachReaderImageElement();
        $this->addNavigationElement();
        $this->addSyndicationElement();
    }

    protected function addSyndicationElement()
    {
        if (false == (bool)$this->module->addShare) {
            return 1;
        }

        $readerConfigElement                      = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ReaderConfigElementModel());
        $readerConfigElement->tstamp              = time();
        $readerConfigElement->dateAdded           = time();
        $readerConfigElement->pid                 = $this->readerConfig->id;
        $readerConfigElement->title               = 'Syndikation';
        $readerConfigElement->type                = 'syndication';
        $readerConfigElement->name                = 'syndications';
        $readerConfigElement->syndicationTemplate = 'readersyndication_fontawesome_bootstrap4_button_group';

        $syndications = StringUtil::deserialize($this->module->share_buttons, true);

        if (isset($syndications['pdfButton'])) {
            $readerConfigElement->syndicationPdf         = true;
        }

        if (isset($syndications['printButton'])) {
            $readerConfigElement->syndicationPrint         = true;
            $readerConfigElement->syndicationPrintTemplate = true;
        }

        if (isset($syndications['facebook'])) {
            $readerConfigElement->syndicationFacebook = true;
        }

        if (isset($syndications['twitter'])) {
            $readerConfigElement->syndicationTwitter = true;
        }

        if (isset($syndications['gplus'])) {
            $readerConfigElement->syndicationGooglePlus = true;
        }

        if (isset($syndications['mailto'])) {
            $readerConfigElement->syndicationMail = true;
        }

        if ($readerConfigElement->id > 0) {
            $this->readerConfigElements[] = $readerConfigElement;

            return 0;
        }

        return 1;
    }

    protected function addNavigationElement()
    {
        $readerConfigElement                     = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ReaderConfigElementModel());
        $readerConfigElement->tstamp             = time();
        $readerConfigElement->dateAdded          = time();
        $readerConfigElement->pid                = $this->readerConfig->id;
        $readerConfigElement->title              = 'Navigation';
        $readerConfigElement->type               = 'navigation';
        $readerConfigElement->name               = 'navigation';
        $readerConfigElement->navigationTemplate = 'readernavigation_default';
        $readerConfigElement->nextLabel          = 'huh.reader.element.label.next.default';
        $readerConfigElement->previousLabel      = 'huh.reader.element.label.previous.default';
        $readerConfigElement->sortingDirection   = \HeimrichHannot\ListBundle\Backend\ListConfig::SORTING_DIRECTION_DESC;
        $readerConfigElement->sortingField       = 'date';
        $readerConfigElement->nextTitle          = 'huh.reader.element.title.next.default';
        $readerConfigElement->previousTitle      = 'huh.reader.element.title.previous.default';
        $readerConfigElement->infiniteNavigation = (bool)$this->module->news_navigation_infinite;
        $readerConfigElement->save();

        if ($readerConfigElement->id > 0) {
            $this->readerConfigElements[] = $readerConfigElement;

            return 0;
        }

        return 1;
    }

    protected function attachReaderImageElement()
    {
        $readerConfigElement                     = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ReaderConfigElementModel());
        $readerConfigElement->tstamp             = time();
        $readerConfigElement->dateAdded          = time();
        $readerConfigElement->pid                = $this->readerConfig->id;
        $readerConfigElement->title              = 'Image';
        $readerConfigElement->type               = 'image';
        $readerConfigElement->imageSelectorField = 'addImage';
        $readerConfigElement->imageField         = 'singleSRC';
        $readerConfigElement->imgSize            = $this->module->imgSize;
        $readerConfigElement->save();

        if ($readerConfigElement->id > 0) {
            $this->readerConfigElements[] = $readerConfigElement;

            return 0;
        }

        return 1;
    }


    protected function attachFilter()
    {
        $this->filterConfig                = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigModel());
        $this->filterConfig->tstamp        = time();
        $this->filterConfig->dateAdded     = time();
        $this->filterConfig->title         = $this->module->name;
        $this->filterConfig->name          = \Contao\StringUtil::standardize($this->module->name);
        $this->filterConfig->dataContainer = 'tl_news';
        $this->filterConfig->method        = 'GET';
        $this->filterConfig->template      = 'form_div_layout';
        $this->filterConfig->published     = 1;
        $this->filterConfig->save();

        if ($this->filterConfig->id > 0) {
            $this->output->writeln('Created filter config for module "' . $this->module->name . '" (Module ID:' . $this->module->id . ') with ID: ' . $this->filterConfig->id . '.');


            $this->readerConfig->filter = $this->filterConfig->id;

            if ($this->readerConfig->save()) {
                $this->output->writeln('Updated reader config for "' . $this->module->name . '" (Module ID:' . $this->module->id . ') and set new filter config ID: ' . $this->filterConfig->id . '.');

                $this->attachFilterElements();

                return 0;
            }
        }

        return 1;
    }

    protected function attachFilterElements()
    {
        $sorting = 2;
        $sorting = $this->addParentFilterElement($sorting);
        $sorting = $this->addPublishedFilter($sorting);
    }

    protected function addPublishedFilter($sorting)
    {
        $filterElement                  = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title           = 'Published';
        $filterElement->pid             = $this->filterConfig->id;
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

        if ($filterElement->id > 0) {
            $this->filterConfigElements[] = $filterElement;
            return $sorting * 2;
        }

        return $sorting;
    }

    protected function addParentFilterElement($sorting)
    {
        $pids = \Contao\StringUtil::deserialize($this->module->news_archives, true);

        if (empty($pids)) {
            return $sorting;
        }

        $initialValueArray = [];
        foreach ($pids as $pid) {
            $initialValueArray[] = ['value' => $pid];
        }

        $filterElement                    = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title             = 'Parent';
        $filterElement->pid               = $this->filterConfig->id;
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

        if ($filterElement->id > 0) {
            $this->filterConfigElements[] = $filterElement;
            return $sorting * 2;
        }

        return $sorting;
    }

    protected function copyNewsTemplate()
    {
        $templatePath     = \Controller::getTemplate($this->module->news_template);
        $twigTemplatePath = $this->getContainer()->get('huh.utils.container')->getProjectDir() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $this->module->news_template . '.html.twig';

        if (!file_exists($twigTemplatePath)) {
            $fileSystem = new Filesystem();

            try {
                $fileSystem->copy($templatePath, $twigTemplatePath);
                $this->output->writeln('Created copy of existing template to ' . $this->module->news_template . '.html.twig template, please adjust the template to fit twig syntax in ' . $twigTemplatePath . '.');
            } catch (FileNotFoundException $e) {
                $this->output->writeln('Could not copy news_template: ' . $this->module->news_template . ', which file does not exist.');
                return 1;
            } catch (IOException $e) {
                $this->output->writeln('An error occurred while copy news_template from ' . $templatePath . ' to ' . $twigTemplatePath . '.');
                return 1;
            }
        }

        return 0;
    }

    protected function updateModule()
    {
        $this->module->tstamp       = time();
        $this->module->readerConfig = $this->readerConfig->id;
        $this->module->type         = \HeimrichHannot\ReaderBundle\Backend\Module::MODULE_READER;

        if ($this->module->save()) {
            $this->output->writeln('Updated "' . $this->module->name . '" (Module ID:' . $this->module->id . ') and set new reader config ID: ' . $this->readerConfig->id . '.');

            return 0;
        }

        return 1;
    }
}