<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle\Command;

use Contao\Controller;
use Contao\Model;
use Contao\ModuleModel;
use Contao\StringUtil;
use HeimrichHannot\FilterBundle\Model\FilterConfigElementModel;
use HeimrichHannot\FilterBundle\Model\FilterConfigModel;
use HeimrichHannot\ListBundle\Backend\ListConfig;
use HeimrichHannot\ReaderBundle\Model\ReaderConfigElementModel;
use HeimrichHannot\ReaderBundle\Model\ReaderConfigModel;
use HeimrichHannot\ReaderBundle\Module\ModuleReader;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
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
     * Current module.
     *
     * @var ModuleModel
     */
    protected $module;

    /**
     * Returns a list of frontend module types that are supported by this command.
     */
    public static function getTypes(): array
    {
        return static::$types;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('migration:module:newsreader')->setDescription(
            'Migration of tl_module type:newsreader modules to huhreader and creates reader configurations from old tl_module settings.'
        );

        $this->addOption('add-navigation', null, InputOption::VALUE_NONE, 'Skips creation of navigation elements.');

        parent::configure();
    }

    /**
     * @param ModuleModel $module
     */
    protected function migrate(Model $module): int
    {
        $this->module = $module;

        $this->readerConfig = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ReaderConfigModel());
        $this->readerConfig->tstamp = time();
        $this->readerConfig->dateAdded = time();
        $this->readerConfig->title = $this->module->name;
        $this->readerConfig->dataContainer = 'tl_news';
        $this->readerConfig->itemRetrievalMode = 'auto_item';
        $this->readerConfig->itemRetrievalAutoItemField = 'alias';
        $this->readerConfig->hideUnpublishedItems = 1;
        $this->readerConfig->item = 'news_default';
        $this->readerConfig->limitFormattedFields = 1;
        $this->readerConfig->itemTemplate = $this->module->news_template;
        $this->readerConfig->formattedFields = ['headline', 'teaser', 'singleSRC'];
        $this->readerConfig->formattedFields = ['headline', 'teaser', 'singleSRC'];
        $this->readerConfig->publishedField = 'published';
        $this->readerConfig->headTags = [
            ['service' => 'huh.head.tag.title', 'pattern' => '%headline%'],
            ['service' => 'huh.head.tag.meta_description', 'pattern' => '%teaser%'],
            ['service' => 'huh.head.tag.og_image', 'pattern' => '%singleSRC%'],
            ['service' => 'huh.head.tag.og_type', 'pattern' => 'article'],
            ['service' => 'huh.head.tag.og_description', 'pattern' => '%teaser%'],
        ];

        if (!$this->isDryRun()) {
            $this->readerConfig->save();
        }

        if ($this->readerConfig->id > 0) {
            $this->io->writeln('Migrated "'.$this->module->name.'" (Module ID:'.$this->module->id.') into new reader config with ID: '.$this->readerConfig->id.'.');

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
        if (false == (bool) $this->module->addShare) {
            return 1;
        }

        $readerConfigElement = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ReaderConfigElementModel());
        $readerConfigElement->tstamp = time();
        $readerConfigElement->dateAdded = time();
        $readerConfigElement->pid = $this->readerConfig->id;
        $readerConfigElement->title = 'Syndikation';
        $readerConfigElement->type = 'syndication';
        $readerConfigElement->name = 'syndications';
        $readerConfigElement->syndicationTemplate = 'readersyndication_fontawesome_bootstrap4_button_group';

        $syndications = StringUtil::deserialize($this->module->share_buttons, true);

        if (\in_array('pdfButton', $syndications)) {
            $readerConfigElement->syndicationPdf = true;
        }

        if (\in_array('printButton', $syndications)) {
            $readerConfigElement->syndicationPrint = true;
            $readerConfigElement->syndicationPrintTemplate = 'readerprint_default';
        }

        if (\in_array('facebook', $syndications)) {
            $readerConfigElement->syndicationFacebook = true;
        }

        if (\in_array('twitter', $syndications)) {
            $readerConfigElement->syndicationTwitter = true;
        }

        if (\in_array('gplus', $syndications)) {
            $readerConfigElement->syndicationGooglePlus = true;
        }

        if (\in_array('mailto', $syndications)) {
            $readerConfigElement->syndicationMail = true;
        }

        if (!$this->isDryRun()) {
            $readerConfigElement->save();
        }

        if ($readerConfigElement->id > 0) {
            $this->readerConfigElements[] = $readerConfigElement;

            return 0;
        }

        return 1;
    }

    protected function addNavigationElement()
    {
        if (!$this->input->getOption('add-navigation')) {
            return 0;
        }

        $readerConfigElement = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ReaderConfigElementModel());
        $readerConfigElement->tstamp = time();
        $readerConfigElement->dateAdded = time();
        $readerConfigElement->pid = $this->readerConfig->id;
        $readerConfigElement->title = 'Navigation';
        $readerConfigElement->type = 'navigation';
        $readerConfigElement->name = 'navigation';
        $readerConfigElement->navigationTemplate = 'readernavigation_default';
        $readerConfigElement->nextLabel = 'huh.reader.element.label.next.default';
        $readerConfigElement->previousLabel = 'huh.reader.element.label.previous.default';
        $readerConfigElement->sortingDirection = ListConfig::SORTING_DIRECTION_DESC;
        $readerConfigElement->sortingField = 'date';
        $readerConfigElement->nextTitle = 'huh.reader.element.title.next.default';
        $readerConfigElement->previousTitle = 'huh.reader.element.title.previous.default';
        $readerConfigElement->infiniteNavigation = (bool) $this->module->news_navigation_infinite;

        if (!$this->isDryRun()) {
            $readerConfigElement->save();
        }

        if ($readerConfigElement->id > 0 || $this->isDryRun()) {
            $this->readerConfigElements[] = $readerConfigElement;

            return 0;
        }

        return 1;
    }

    protected function attachReaderImageElement()
    {
        $readerConfigElement = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new ReaderConfigElementModel());
        $readerConfigElement->tstamp = time();
        $readerConfigElement->dateAdded = time();
        $readerConfigElement->pid = $this->readerConfig->id;
        $readerConfigElement->title = 'Image';
        $readerConfigElement->type = 'image';
        $readerConfigElement->imageSelectorField = 'addImage';
        $readerConfigElement->imageField = 'singleSRC';
        $readerConfigElement->imgSize = $this->module->imgSize;

        if (!$this->isDryRun()) {
            $readerConfigElement->save();
        }

        if ($readerConfigElement->id > 0 || $this->isDryRun()) {
            $this->readerConfigElements[] = $readerConfigElement;

            return 0;
        }

        return 1;
    }

    protected function attachFilter()
    {
        $this->filterConfig = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigModel());
        $this->filterConfig->tstamp = time();
        $this->filterConfig->dateAdded = time();
        $this->filterConfig->title = $this->module->name;
        $this->filterConfig->name = StringUtil::standardize($this->module->name);
        $this->filterConfig->dataContainer = 'tl_news';
        $this->filterConfig->method = 'GET';
        $this->filterConfig->template = 'form_div_layout';
        $this->filterConfig->published = 1;

        if (!$this->isDryRun()) {
            $this->filterConfig->save();

            if ($this->filterConfig->id > 0) {
                $this->io->writeln('Created filter config for module "'.$this->module->name.'" (Module ID:'.$this->module->id.') with ID: '.$this->filterConfig->id.'.');

                $this->readerConfig->filter = $this->filterConfig->id;

                if ($this->readerConfig->save()) {
                    $this->io->writeln('Updated reader config for "'.$this->module->name.'" (Module ID:'.$this->module->id.') and set new filter config ID: '.$this->filterConfig->id.'.');

                    $this->attachFilterElements();

                    return 0;
                }
            }
        } else {
            return 0;
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
        $filterElement = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title = 'Published';
        $filterElement->pid = $this->filterConfig->id;
        $filterElement->sorting = $sorting;
        $filterElement->tstamp = time();
        $filterElement->dateAdded = time();
        $filterElement->type = 'visible';
        $filterElement->field = 'published';
        $filterElement->published = 1;
        $filterElement->addStartAndStop = 1;
        $filterElement->startField = 'start';
        $filterElement->stopField = 'stop';
        $filterElement->save();

        if ($filterElement->id > 0) {
            $this->filterConfigElements[] = $filterElement;

            return $sorting * 2;
        }

        return $sorting;
    }

    protected function addParentFilterElement($sorting)
    {
        $pids = StringUtil::deserialize($this->module->news_archives, true);

        if (empty($pids)) {
            return $sorting;
        }

        $initialValueArray = [];

        foreach ($pids as $pid) {
            $initialValueArray[] = ['value' => $pid];
        }

        $filterElement = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new FilterConfigElementModel());
        $filterElement->title = 'Parent';
        $filterElement->pid = $this->filterConfig->id;
        $filterElement->sorting = $sorting;
        $filterElement->tstamp = time();
        $filterElement->dateAdded = time();
        $filterElement->type = 'parent';
        $filterElement->isInitial = 1;
        $filterElement->operator = DatabaseUtil::OPERATOR_IN;
        $filterElement->field = 'pid';
        $filterElement->initialValueType = 'array';
        $filterElement->initialValueArray = serialize($initialValueArray);
        $filterElement->published = 1;
        $filterElement->save();

        if ($filterElement->id > 0) {
            $this->filterConfigElements[] = $filterElement;

            return $sorting * 2;
        }

        return $sorting;
    }

    protected function copyNewsTemplate()
    {
        $templatePath = Controller::getTemplate($this->module->news_template);
        $twigTemplatePath = $this->getContainer()->get('huh.utils.container')->getProjectDir().\DIRECTORY_SEPARATOR.'templates'.\DIRECTORY_SEPARATOR.$this->module->news_template.'.html.twig';

        if (!file_exists($twigTemplatePath)) {
            $fileSystem = new Filesystem();

            try {
                $fileSystem->copy($templatePath, $twigTemplatePath);
                $this->io->writeln('Created copy of existing template to '.$this->module->news_template.'.html.twig template, please adjust the template to fit twig syntax in '.$twigTemplatePath.'.');
            } catch (FileNotFoundException $e) {
                $this->io->writeln('Could not copy news_template: '.$this->module->news_template.', which file does not exist.');

                return 1;
            } catch (IOException $e) {
                $this->io->writeln('An error occurred while copy news_template from '.$templatePath.' to '.$twigTemplatePath.'.');

                return 1;
            }
        }

        return 0;
    }

    protected function updateModule()
    {
        $this->module->tstamp = time();
        $this->module->readerConfig = $this->readerConfig->id;
        $this->module->type = ModuleReader::TYPE;

        if ($this->module->save()) {
            $this->io->writeln('Updated "'.$this->module->name.'" (Module ID:'.$this->module->id.') and set new reader config ID: '.$this->readerConfig->id.'.');

            return 0;
        }

        return 1;
    }

    /**
     * This method is used to check, if migration command could be execute.
     * This is the place to check if a needed bundle is installed or database fields exist.
     * Return false, to stop the migration command.
     */
    protected function beforeMigrationCheck(): bool
    {
        return true;
    }
}
