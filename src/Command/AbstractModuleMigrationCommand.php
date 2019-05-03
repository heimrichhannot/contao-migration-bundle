<?php
/**
 * Copyright (c) 2018 Heimrich & Hannot GmbH
 *
 * @author Rico Kaltofen <r.kaltofen@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */

namespace HeimrichHannot\MigrationBundle\Command;


use Contao\CoreBundle\Command\AbstractLockedCommand;
use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\Model\Collection;
use Contao\ModuleModel;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractModuleMigrationCommand extends AbstractLockedCommand
{

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * A collection of models or null if there are no
     *
     * @var \Contao\Model\Collection|\Contao\ModuleModel[]|\Contao\ModuleModel|null
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
     * @var ContaoFrameworkInterface
     */
    protected $framework;

    /**
     * @var bool
     */
    protected $dryRun = false;

    public function __construct(ContaoFrameworkInterface $framework)
    {
        $this->framework = $framework;
        parent::__construct();
    }

    /**
     * Returns a list of frontend module types that are supported by this command.
     *
     * @return array
     */
    abstract static function getTypes(): array;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->addOption('id', 'i', InputOption::VALUE_OPTIONAL, 'Provide the id of a single module that should be migrated.');
        $this->addOption('types', 't', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'What module types should be migrated?', static::getTypes());
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, "Performs a run without writing to datebase and copy templates.");
    }

    /**
     * {@inheritdoc}
     */
    protected function executeLocked(InputInterface $input, OutputInterface $output)
    {
        $this->framework->initialize();

        $this->io      = new SymfonyStyle($input, $output);
        $this->input   = $input;
        $this->output  = $output;

        $this->io->newLine();
        $this->output->writeln("<fg=green>Start migration for module of type: ".implode(static::getTypes())."</>");

        if ($input->hasOption('dry-run') && $input->getOption('dry-run'))
        {
            $this->dryRun = true;
            $this->io->note("Dry run enabled, no data will be changed.");
            $this->io->newLine();
        }

        $this->rootDir = $this->getContainer()->getParameter('kernel.project_dir');

        $this->queryBuilder = new QueryBuilder($this->getContainer()->get('doctrine.dbal.default_connection'));

        $types = array_intersect(static::getTypes(), $input->getOption('types'));

        if (empty($types)) {

            $this->io->error('No valid types provided within command.');

            return 1;
        }

        if (empty($this->modules = $this->collect($input->getOption('id')))) {
            $this->io->note('No modules of types' . implode(",", $types) . ' could be found.');

            return 1;
        }

        $this->migrateAll();

        return 0;
    }

    /**
     * Run custom migration on each module
     * @return mixed
     */
    abstract protected function migrate(ModuleModel $module): int;

    /**
     * Migrate all modules
     */
    protected function migrateAll()
    {
        $this->io->section("Migration");
        $this->io->progressStart($this->modules->count());
        foreach ($this->modules as $module) {
            $this->io->progressAdvance();
            $this->migrate($module);
        }
        $this->io->progressFinish();
    }

    /**
     * Collect modules
     * @param int $id
     *
     * @return \Contao\Model\Collection|\Contao\ModuleModel[]|\Contao\ModuleModel|null
     */
    protected function collect(int $id = null): ?Collection
    {
        $options['column'] = [
            'tl_module.type IN (' . implode(',', array_map(function ($type) {
                return '"' . addslashes($type) . '"';
            }, static::getTypes())) . ')'
        ];

        if ($id > 0) {
            $options['column'][] = 'tl_module.id = ?';
            $options['value'][]  = $id;
        }

        return ModuleModel::findAll($options);
    }

    /**
     * @return bool
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * @param bool $dryRun
     */
    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }
}

