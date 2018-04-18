<?php

/*
 * Copyright (c) 2018 Heimrich & Hannot GmbH
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle;

use HeimrichHannot\MigrationBundle\DependencyInjection\HeimrichHannotMigrationExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class HeimrichHannotBegBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function getContainerExtension()
    {
        return new HeimrichHannotMigrationExtension();
    }
}
