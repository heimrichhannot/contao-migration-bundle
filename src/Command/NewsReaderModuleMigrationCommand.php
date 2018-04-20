<?php

namespace HeimrichHannot\MigrationBundle\Command;

use Contao\System;
use HeimrichHannot\FilterBundle\Model\FilterConfigElementModel;
use HeimrichHannot\FilterBundle\Model\FilterConfigModel;
use HeimrichHannot\Haste\Util\StringUtil;
use HeimrichHannot\ReaderBundle\Model\ReaderConfigModel;
use HeimrichHannot\UtilsBundle\Model\ModelUtil;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use UAParser\Exception\FileNotFoundException;

class NewsReaderModuleMigrationCommand extends AbstractModuleMigrationCommand
{
    /**
     * @var array
     */
    protected static $types = ['newsreader'];

    /**
     * @var array
     */
    protected $readerConfig;

    /**
     * @var array
     */
    protected $filterConfig;

    /**
     * Current module
     * @var array
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
    }

    /**
     * @inheritDoc
     */
    protected function migrate(array $module): int
    {
        $this->module = $module;

        $this->readerConfig = [
            'tstamp'                     => time(),
            'dateAdded'                  => time(),
            'title'                      => $this->module['name'],
            'dataContainer'              => 'tl_news',
            'manager'                    => 'default',
            'item'                       => 'default',
            'itemRetrievalMode'          => 'auto_item',
            'itemRetrievalAutoItemField' => 'alias',
            'hideUnpublishedItems'       => 1,
            'limitFormattedFields'       => 1,
            'formattedFields'            => serialize(['headline', 'teaser', 'singleSRC']),
            'publishedField'             => 'published',
            'itemTemplate'               => $this->module['news_template'] ? 'templates/' . $this->module['news_template'] . '.html.twig' : '',
            'headTags'                   => serialize(
                [
                    ['service' => 'huh.head.tag.title', 'pattern' => '%headline%'],
                    ['service' => 'huh.head.tag.meta_description', 'pattern' => '%teaser%'],
                    ['service' => 'huh.head.tag.og_image', 'pattern' => '%singleSRC%'],
                    ['service' => 'huh.head.tag.og_type', 'pattern' => 'article'],
                    ['service' => 'huh.head.tag.og_description', 'pattern' => '%teaser%'],
                ]
            )
        ];

        $sql = "INSERT INTO tl_reader_config (" . implode(',', array_keys($this->readerConfig)) . ") VALUES (" . implode(',', array_map(function ($value) {
                return ':' . $value;
            }, array_keys($this->readerConfig))) . ")";

        if ($this->queryBuilder->getConnection()->executeUpdate($sql, $this->readerConfig)) {
            $this->readerConfig['id'] = $this->queryBuilder->getConnection()->lastInsertId();
            $this->output->writeln('Migrated "' . $this->module['name'] . '" (Module ID:' . $this->module['id'] . ') into new reader config with ID: ' . $this->readerConfig['id'] . '.');

            $this->updateModule();

            $this->copyNewsTemplate();

            $this->attachFilter();
        }

        return 0;
    }

    protected function attachFilter()
    {
        $this->filterConfig = [
            'tstamp'        => time(),
            'dateAdded'     => time(),
            'title'         => $this->module['name'],
            'name'          => \Contao\StringUtil::standardize($this->module['name']),
            'dataContainer' => 'tl_news',
            'method'        => 'GET',
            'template'      => 'form_div_layout',
            'published'     => 1
        ];

        $sql = "INSERT INTO tl_filter_config (" . implode(',', array_keys($this->filterConfig)) . ") VALUES (" . implode(',', array_map(function ($value) {
                return ':' . $value;
            }, array_keys($this->filterConfig))) . ")";

        if ($this->queryBuilder->getConnection()->executeUpdate($sql, $this->readerConfig)) {
            $this->filterConfig['id'] = $this->queryBuilder->getConnection()->lastInsertId();
            $this->output->writeln('Created filter config for module "' . $this->module['name'] . '" (Module ID:' . $this->module['id'] . ') with ID: ' . $this->filterConfig['id'] . '.');


            $updateSql = "UPDATE tl_reader_config SET filter = :filter WHERE id = :id";

            if ($this->queryBuilder->getConnection()->executeUpdate($updateSql, ['filter' => $this->filterConfig['id'], 'id' => $this->readerConfig['id']])) {
                $this->output->writeln('Updated reader config for "' . $this->module['name'] . '" (Module ID:' . $this->module['id'] . ') and set new filter config ID: ' . $this->filterConfig['id'] . '.');

                $this->attachFilterElements();

                return 0;
            }
        }

        return 1;
    }

    protected function attachFilterElements()
    {
        $sorting = 2;
        $pids    = \Contao\StringUtil::deserialize($this->module['news_archives'], true);

        if (!empty($pids)) {
            $initialValueArray = [];
            foreach ($pids as $pid) {
                $initialValueArray[] = ['value' => $pid];
            }

            $filterElement                    = new FilterConfigElementModel();
            $filterElement->pid               = $this->filterConfig['id'];
            $filterElement->sorting           = $sorting;
            $filterElement->tstamp            = time();
            $filterElement->dateAdded         = time();
            $filterElement->type              = 'parent';
            $filterElement->isInitial         = 1;
            $filterElement->field             = 'pid';
            $filterElement->initialValueType  = 'array';
            $filterElement->initialValueArray = serialize($initialValueArray);
            $filterElement->published         = 1;
            $filterElement->save();

            $sorting = $sorting * 2;
        }
    }

    protected function copyNewsTemplate()
    {
        $templatePath     = \Controller::getTemplate($this->module['news_template']);
        $twigTemplatePath = $this->getContainer()->get('huh.utils.container')->getProjectDir() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $this->module['news_template'] . '.html.twig';

        if (!file_exists($twigTemplatePath)) {
            $fileSystem = new Filesystem();

            try {
                $fileSystem->copy($templatePath, $twigTemplatePath);
                $this->output->writeln('Created copy of existing template to ' . $this->module['news_template'] . '.html.twig template, please adjust the template to fit twig syntax in ' . $twigTemplatePath . '.');
            } catch (FileNotFoundException $e) {
                $this->output->writeln('Could not copy news_template: ' . $this->module['news_template'] . ', which file does not exist.');
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
        $updateSql = "UPDATE tl_module SET readerConfig = :readerConfig, `type`=:type WHERE id = :id";

        if ($this->queryBuilder->getConnection()->executeUpdate($updateSql, ['readerConfig' => $this->readerConfig['id'], 'id' => $this->module['id'], 'type' => \HeimrichHannot\ReaderBundle\Backend\Module::MODULE_READER])) {
            $this->output->writeln('Updated "' . $this->module['name'] . '" (Module ID:' . $this->module['id'] . ') and set new reader config ID: ' . $this->readerConfig['id'] . '.');

            return 0;
        }

        return 1;
    }
}