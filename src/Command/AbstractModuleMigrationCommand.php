<?php
/**
 * Copyright (c) 2018 Heimrich & Hannot GmbH
 *
 * @author Rico Kaltofen <r.kaltofen@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */

namespace HeimrichHannot\MigrationBundle\Command;


use Contao\CoreBundle\Command\AbstractLockedCommand;
use Contao\CoreBundle\Framework\FrameworkAwareInterface;
use Contao\CoreBundle\Framework\FrameworkAwareTrait;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractModuleMigrationCommand extends AbstractLockedCommand implements FrameworkAwareInterface
{
    use FrameworkAwareTrait;

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var array
     */
    protected static $types = [];

    /**
     * A collection of models or null if there are no
     *
     * @var array
     */
    protected $modules;

    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * {@inheritdoc}
     */
    protected function executeLocked(InputInterface $input, OutputInterface $output)
    {
        $this->framework->initialize();

        $this->io      = new SymfonyStyle($input, $output);
        $this->input   = $input;
        $this->output  = $output;
        $this->rootDir = $this->getContainer()->getParameter('kernel.project_dir');

        $this->queryBuilder = new QueryBuilder($this->getContainer()->get('doctrine.dbal.default_connection'));

        if (empty(static::$types)) {
            $output->writeln('No valid types provided within command.');

            return 1;
        }

        if (empty($this->modules = $this->collect())) {
            $output->writeln('No modules of types' . implode(",", static::$types) . ' could be found.');

            return 1;
        }

        $this->migrateAll();

        return 0;
    }

    /**
     * Run custom migration on each module
     * @return mixed
     */
    abstract protected function migrate(array $module): int;

    /**
     * Migrate all modules
     */
    protected function migrateAll()
    {
        foreach ($this->modules as $module) {
            $this->migrate($module);
        }
    }

    /**
     * Collect modules
     *
     * @return array
     */
    protected function collect()
    {
        $query = $this->queryBuilder->select('*')->from('tl_module')->where($this->queryBuilder->expr()->in('type', array_map(function ($type) {
            return '"' . addslashes($type) . '"';
        }, static::$types)));

        return $query->execute()->fetchAll();
    }
}