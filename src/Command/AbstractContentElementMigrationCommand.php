<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle\Command;

abstract class AbstractContentElementMigrationCommand extends AbstractMigrationCommand
{
    public static function getTable(): string
    {
        return 'tl_content';
    }

    public static function getElementName(): string
    {
        return 'content element';
    }
}
