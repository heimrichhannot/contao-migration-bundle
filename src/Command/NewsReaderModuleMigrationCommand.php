<?php

namespace HeimrichHannot\MigrationBundle\Command;

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
        $readerConfig = [
            'tstamp'                     => time(),
            'dateAdded'                  => time(),
            'title'                      => $module['name'],
            'dataContainer'              => 'tl_news',
            'manager'                    => 'default',
            'item'                       => 'default',
            'itemRetrievalMode'          => 'auto_item',
            'itemRetrievalAutoItemField' => 'alias',
            'hideUnpublishedItems'       => 1,
            'limitFormattedFields'       => 1,
            'formattedFields'            => serialize(['headline', 'teaser', 'singleSRC']),
            'publishedField'             => 'published',
            'itemTemplate'               => $module['news_template'] ? 'templates/' . $module['news_template'] . '.html.twig' : '',
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

        $sql = "INSERT INTO tl_reader_config (" . implode(',', array_keys($readerConfig)) . ") VALUES (" . implode(',', array_map(function ($value) {
                return ':' . $value;
            }, array_keys($readerConfig))) . ")";

        if ($this->queryBuilder->getConnection()->executeUpdate($sql, $readerConfig)) {
            $readerConfigId = $this->queryBuilder->getConnection()->lastInsertId();
            $this->output->writeln('Migrated "' . $module['name'] . '" (Module ID:' . $module['id'] . ') into new reader config with ID: ' . $readerConfigId . '.');

            $updateSql = "UPDATE tl_module SET readerConfig = :readerConfig, `type`=:type WHERE id = :id";

            if ($this->queryBuilder->getConnection()->executeUpdate($updateSql, ['readerConfig' => $readerConfigId, 'id' => $module['id'], 'type' => \HeimrichHannot\ReaderBundle\Backend\Module::MODULE_READER])) {
                $this->output->writeln('Updated "' . $module['name'] . '" (Module ID:' . $module['id'] . ') and set new reader config ID: ' . $readerConfigId . '.');
            }

            $templatePath     = \Controller::getTemplate($module['news_template']);
            $twigTemplatePath = $this->getContainer()->get('huh.utils.container')->getProjectDir() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $module['news_template'] . '.html.twig';

            if (!file_exists($twigTemplatePath)) {
                $fileSystem = new Filesystem();

                try {
                    $fileSystem->copy($templatePath, $twigTemplatePath);
                    $this->output->writeln('Created copy of existing template to ' . $module['news_template'] . '.html.twig template, please adjust the template to fit twig syntax in ' . $twigTemplatePath . '.');
                } catch (FileNotFoundException $e) {
                    $this->output->writeln('Could not copy news_template: ' . $module['news_template'] . ', which file does not exist.');
                    return 1;
                } catch (IOException $e) {
                    $this->output->writeln('An error occurred while copy news_template from ' . $templatePath . ' to ' . $twigTemplatePath . '.');
                    return 1;
                }
            }
        }

        return 0;
    }
}