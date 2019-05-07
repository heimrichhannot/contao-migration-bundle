<?php


namespace HeimrichHannot\MigrationBundle\Extensions;


use Contao\Controller;
use Contao\ModuleModel;
use HeimrichHannot\ListBundle\Model\ListConfigModel;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Trait MigrateNewsListItemTemplateToListTemplateTrait
 * @package HeimrichHannot\MigrationBundle\Extensions
 *
 * @method ContainerInterface getContainer
 * @method bool isDryRun
 * @method void addMigrationSql(string $sql)
 * @method void addUpgradeNotices(string $upgradeNotice)
 *
 * @property SymfonyStyle $io
 */
trait MigrateNewsListItemTemplateToListTemplateTrait
{
    protected $processedNewsListTemplates = [];

    protected function copyNewsItemTemplate(ModuleModel $module, ListConfigModel $listConfigModel)
    {
        $listConfigModel->itemTemplate = $module->news_template;
        if (!$this->isDryRun())
        {
            $listConfigModel->save();
        }

        if (in_array($module->news_template, $this->processedNewsListTemplates))
        {
            if ($this->io->isVeryVerbose())
            {
                $this->io->text("Template ".$module->news_template." were already processed. Skipping.");
            }
            return 0;
        }
        $this->processedNewsListTemplates[] = $module->news_template;

        try {
            $templatePath     = Controller::getTemplate($module->news_template);
        } catch (\Exception $e) {
            $message = 'Could not copy news_template: ' . $module->news_template . ', which file does not exist.';
            $this->addUpgradeNotices($message);
            if ($this->io->isVerbose()) {
                $this->io->text($message);
            }
            return 1;
        }
        $twigTemplatePath = $this->getContainer()->get('huh.utils.container')->getProjectDir() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $module->news_template . '.html.twig';

        if (!file_exists($twigTemplatePath)) {

            $fileSystem = new Filesystem();
            try {

                if (!$this->isDryRun())
                {
                    $fileSystem->copy($templatePath, $twigTemplatePath);
                }
                $message = 'Created copy of existing template to ' . $module->news_template . '.html.twig template, please adjust the template to fit twig syntax in ' . $twigTemplatePath . '.';
                $this->addUpgradeNotices($message);
                if ($this->io->isVerbose()) {
                    $this->io->text($message);
                }
            } catch (FileNotFoundException $e) {
                $message = 'Could not copy news_template: ' . $module->news_template . ', which file does not exist.';
                $this->addUpgradeNotices($message);
                if ($this->io->isVerbose()) {
                    $this->io->text($message);
                }
                return 1;
            } catch (IOException $e) {
                $message = 'An error occurred while copy news_template from ' . $templatePath . ' to ' . $twigTemplatePath . '.';
                $this->addUpgradeNotices($message);
                if ($this->io->isVerbose()) {
                    $this->io->text($message);
                }
                return 1;
            }
        }
        return 0;
    }
}