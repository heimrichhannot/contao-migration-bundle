<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle\Extensions;

use Contao\Controller;
use Contao\Model;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Error\LoaderError;

/**
 * Trait MigrateNewsListItemTemplateToListTemplateTrait.
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
     * Add the template of the source entity to the target entity and copy it as twig file (and creates update notices).
     *
     * @param string $templatePrefix If the newly created twig template needs a prefix. Example: "filter_form_"
     */
    protected function moveTemplate(Model $sourceModel, string $sourceModelTemplateField, Model $targetModel, string $targetModelTemplateField, string $templatePrefix = ''): int
    {
        if (!$sourceModel->{$sourceModelTemplateField}) {
            $this->addUpgradeNotices('template', 'No template set for source model (<fg=green>ID: '.$sourceModel->id.'</>). Maybe you need to manually migrate the default theme.');

            return 0;
        }

        $targetFileName = $templatePrefix.$sourceModel->{$sourceModelTemplateField}.'.html.twig';
        $targetModel->{$targetModelTemplateField} = $targetFileName;
        $this->save($targetModel);

        if (\in_array($sourceModel->{$sourceModelTemplateField}, $this->processedTemplate)) {
            if ($this->io->isVeryVerbose()) {
                $this->io->text('Template '.$sourceModel->{$sourceModelTemplateField}.' were already processed. Skipping.');
            }

            return 0;
        }
        $this->processedTemplate[] = $sourceModel->{$sourceModelTemplateField};

        try {
            $sourceTemplatePath = Controller::getTemplate($sourceModel->{$sourceModelTemplateField});
        } catch (\Exception $e) {
            $message = 'Could not copy template: '.$sourceModel->{$sourceModelTemplateField}.', which file does not exist.';
            $this->addUpgradeNotices('template', $message);

            if ($this->io->isVerbose()) {
                $this->io->text($message);
            }

            return 1;
        }

        $templatePath = \dirname($sourceTemplatePath);
        $twigTemplatePath = $templatePath.\DIRECTORY_SEPARATOR.$targetFileName;

        try {
            if ($existingTemplate = $this->getContainer()->get('huh.utils.template')->getTemplate($templatePrefix.$sourceModel->{$sourceModelTemplateField}, 'html.twig')) {
                if ($this->io->isVeryVerbose()) {
                    $this->io->text('Template '.$targetFileName.' does already exist. Skipping.');
                }
            }
        } catch (LoaderError $e) {
        }

        if (!file_exists($twigTemplatePath)) {
            $templateContent = file_get_contents($sourceTemplatePath);
            $fileSystem = new Filesystem();

            try {
                if (!$this->isDryRun()) {
                    $fileSystem->dumpFile($twigTemplatePath,
                        '<p>This template was migrated to twig by '.$this->getName().' command.
                        Please adjust template. You will find it here:
                        <i>'.$twigTemplatePath.'</i>
                        The original php template content is added within a comment to this template.</p>
                        {# '.$templateContent.' #}'
                    );
                    $fileSystem->copy($sourceTemplatePath, $twigTemplatePath);
                }
                $message = 'Created copy of existing template to <fg=green>'.$targetFileName.'</> template, please adjust the template to fit twig syntax in '.$twigTemplatePath.'.';
                $this->addUpgradeNotices('template', $message);

                if ($this->io->isVerbose()) {
                    $this->io->text($message);
                }
            } catch (FileNotFoundException $e) {
                $message = 'Could not copy template: '.$sourceModel->{$sourceModelTemplateField}.', which file does not exist.';
                $this->addUpgradeNotices('template', $message);

                if ($this->io->isVerbose()) {
                    $this->io->text($message);
                }

                return 1;
            } catch (IOException $e) {
                $message = 'An error occurred while copy template from '.$sourceTemplatePath.' to '.$twigTemplatePath.'.';
                $this->addUpgradeNotices('template', 'template', $message);

                if ($this->io->isVerbose()) {
                    $this->io->text($message);
                }

                return 1;
            }
        }

        return 0;
    }
}
