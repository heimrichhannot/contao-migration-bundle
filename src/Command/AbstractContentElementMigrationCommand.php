<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2019 Heimrich & Hannot GmbH
 *
 * @author  Thomas Körner <t.koerner@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */


namespace HeimrichHannot\MigrationBundle\Command;


abstract class AbstractContentElementMigrationCommand extends AbstractMigrationCommand
{
    static function getTable(): string
    {
        return 'tl_content';
    }

    static function getElementName(): string
    {
        return 'content element';
    }
}