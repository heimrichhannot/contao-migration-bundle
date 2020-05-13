<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

/**
 * Contao Open Source CMS.
 *
 * Copyright (c) 2019 Heimrich & Hannot GmbH
 *
 * @author  Thomas Körner <t.koerner@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */

namespace HeimrichHannot\MigrationBundle\Command;

use Contao\ContentModel;
use Contao\Model;
use HeimrichHannot\TabControlBundle\ContaoTabControlBundle;
use HeimrichHannot\TabControlBundle\ContentElement\TabControlSeperatorElement;
use HeimrichHannot\TabControlBundle\ContentElement\TabControlStartElement;
use HeimrichHannot\TabControlBundle\ContentElement\TabControlStopElement;

class TabsToTabControleMigrationCommand extends AbstractContentElementMigrationCommand
{
    /**
     * Returns a list of types that are supported by this command.
     */
    public static function getTypes(): array
    {
        return [
            'accessible_tabs_start',
            'accessible_tabs_separator',
            'accessible_tabs_stop',
        ];
    }

    protected function configure()
    {
        $this
            ->setName('huh:migration:ce:tab_control_bundle')
            ->setDescription('Migrate the content elements of following tab modules to contao tab control bundle: fry_accessible_tabs');

        parent::configure();
    }

    /**
     * Run custom migration on each element.
     *
     * @param ContentModel|Model $model
     *
     * @return mixed
     */
    protected function migrate(Model $model): int
    {
        if (\in_array($model->type, ['accessible_tabs_start', 'accessible_tabs_separator', 'accessible_tabs_stop'])) {
            return $this->migrateFryAccessibileTabs($model);
        }

        return 1;
    }

    protected function migrateFryAccessibileTabs(ContentModel $model): int
    {
        $data = $this->getContainer()->get('huh.tab_control.helper.structure_tabs')->structureTabsByContentElement($model, '', [
            'startElement' => 'accessible_tabs_start',
            'seperatorElement' => 'accessible_tabs_separator',
            'stopElement' => 'accessible_tabs_stop',
        ]);

        if ($model->id === $data['elements'][1]['id']) {
            if ('accessible_tabs_separator' === $model->type) {
                $this->addMigrationSql('DELETE FROM tl_content WHERE id='.$model->id.';');

                if (!$this->isDryRun()) {
                    $model->delete();
                }

                return 0;
            }
        }

        if ('accessible_tabs_start' === $model->type) {
            if ($data['elements'][0]['id'] !== $model->id) {
                $this->io->error('Element ids not correct. Must be an error! Skipping');

                return 1;
            }

            if ('accessible_tabs_separator' !== $data['elements'][1]['type']) {
                $this->io->error('Second element of accessiblity tab group must be an seperator element. That is not the case. Skipping.');
            }
            $model->type = TabControlStartElement::TYPE;
            $model->tabControlHeadline = $data['elements'][1]['accessible_tabs_title'];
            $model->tabControlRememberLastTab = $model->accessible_tabs_save_state;

            $this->addMigrationSql("UPDATE tl_content SET type='".$model->type."', tabControlHeadline='".$model->tabControlHeadline."' , tabControlRememberLastTab='".$model->tabControlRememberLastTab."' WHERE id=".$model->id.';');
        }

        if ('accessible_tabs_separator' === $model->type) {
            $model->type = TabControlSeperatorElement::TYPE;
            $model->tabControlHeadline = $model->accessible_tabs_title;
            $this->addMigrationSql("UPDATE tl_content SET type='".$model->type."', tabControlHeadline='".$model->tabControlHeadline."' WHERE id=".$model->id.';');
        }

        if ('accessible_tabs_stop' === $model->type) {
            $model->type = TabControlStopElement::TYPE;
            $this->addMigrationSql("UPDATE tl_content SET type='".$model->type."' WHERE id=".$model->id.';');
        }

        if (!$this->dryRun) {
            $model->tstamp = time();
            $model->save();
        }

        return 0;
    }

    protected function beforeMigrationCheck(): bool
    {
        if (!class_exists('\HeimrichHannot\TabControlBundle\ContaoTabControlBundle')) {
            $this->io->error('Class ContaoTabControlBundle could not be found. Maybe the bundle is not installed.');

            return false;
        }

        if ($this->getContainer()->get('huh.utils.container')->isBundleActive(ContaoTabControlBundle::class)) {
            return true;
        }
        $this->io->error('Contao Tab Control Bundle is not installed');

        return false;
    }
}
