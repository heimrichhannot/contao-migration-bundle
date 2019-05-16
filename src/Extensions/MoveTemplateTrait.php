<?php


namespace HeimrichHannot\MigrationBundle\Extensions;


use Contao\Controller;
use Contao\Model;
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
 * @method void addUpgradeNotices(string $type, string $upgradeNotice)
 * @method void save(Model $model)
 *
 * @property SymfonyStyle $io
 */
trait MoveTemplateTrait
{
    protected $processedTemplate = [];

    /**
     * Add the template of the source entity to the target entity and copy it as twig file (and creates update notices)
     *
     * @param Model $sourceModel
     * @param string $sourceModelTemplateField
     * @param Model $targetModel
     * @param string $targetModelTemplateField
     * @param string $templatePrefix If the newly created twig template needs a prefix. Example: "filter_form_"
     * @return int
     */
    protected function moveTemplate(Model $sourceModel, string $sourceModelTemplateField, Model $targetModel, string $targetModelTemplateField, string $templatePrefix = ''): int
    {
        if (!$sourceModel->{$sourceModelTemplateField})
        {
            $this->addUpgradeNotices("template", "No template set for source model (<fg=green>ID: ".$sourceModel->id."</>). Maybe you need to manually migrate the default theme.");
            return 0;
        }


        $targetModel->{$targetModelTemplateField} = $sourceModel->{$sourceModelTemplateField};
        $this->save($targetModel);

        if (in_array($sourceModel->{$sourceModelTemplateField}, $this->processedTemplate))
        {
            if ($this->io->isVeryVerbose())
            {
                $this->io->text("Template ".$sourceModel->{$sourceModelTemplateField}." were already processed. Skipping.");
            }
            return 0;
        }
        $this->processedTemplate[] = $sourceModel->{$sourceModelTemplateField};

        try {
            $templatePath     = Controller::getTemplate($sourceModel->{$sourceModelTemplateField});
        } catch (\Exception $e) {
            $message = 'Could not copy template: ' . $sourceModel->{$sourceModelTemplateField} . ', which file does not exist.';
            $this->addUpgradeNotices("template", $message);
            if ($this->io->isVerbose()) {
                $this->io->text($message);
            }
            return 1;
        }
        $targetFileName = $templatePrefix.$sourceModel->{$sourceModelTemplateField} . '.html.twig';
        $twigTemplatePath = $this->getContainer()->get('huh.utils.container')->getProjectDir()
            . DIRECTORY_SEPARATOR . 'templates'
            . DIRECTORY_SEPARATOR . $targetFileName;

        if (!file_exists($twigTemplatePath)) {

            $fileSystem = new Filesystem();
            try {

                if (!$this->isDryRun())
                {
                    $fileSystem->copy($templatePath, $twigTemplatePath);
                }
                $message = 'Created copy of existing template to <fg=green>' . $targetFileName . '</> template, please adjust the template to fit twig syntax in ' . $twigTemplatePath . '.';
                $this->addUpgradeNotices("template", $message);
                if ($this->io->isVerbose()) {
                    $this->io->text($message);
                }
            } catch (FileNotFoundException $e) {
                $message = 'Could not copy template: ' . $sourceModel->{$sourceModelTemplateField} . ', which file does not exist.';
                $this->addUpgradeNotices("template", $message);
                if ($this->io->isVerbose()) {
                    $this->io->text($message);
                }
                return 1;
            } catch (IOException $e) {
                $message = 'An error occurred while copy template from ' . $templatePath . ' to ' . $twigTemplatePath . '.';
                $this->addUpgradeNotices("template", "template", $message);
                if ($this->io->isVerbose()) {
                    $this->io->text($message);
                }
                return 1;
            }
        }
        return 0;
    }
}