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
use Contao\Model;
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

    protected $migrationSql = [];

    protected $upgradeNotices = [];

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
            $this->io->note('No modules of types ' . implode(",", $types) . ' could be found.');


            $this->io->success("Finished migration!");
            return 0;
        }

        $this->migrateAll();

        if ($this->hasMigrationSql())
        {
            $this->io->section("Migration SQL Command");
            $this->io->text("These are the MySql commands, if you want to do a database merge later while keeping newly added settings. Add the following lines to your database migration scripts.");
            $this->io->newLine();
            $this->io->text($this->getMigrationSql());
        }

        if ($this->hasUpgradeNotices())
        {
            $this->io->section("Migration Notices");
            $this->io->listing($this->getUpgradeNotices());
        }

        $this->io->success("Finished migration of ".$this->modules->count()." modules.");

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
     * Map values of an old entitiy to an new entity.
     *
     * Mapping Array:
     * * Key is old entity property name
     * * Value is new entity property name or an array with following options:
     *     * 'key': new entity property key
     *     * 'callable': a mapping function. Gets old entity value as parameter.
     *
     *
     * @param Model|\stdClass $oldEntity
     * @param Model $newEntity
     * @param array $mapping
     * @param string $oldEntityKeySuffix Example: 'owl_'
     * @param string $newEntityKeySuffix Example: 'tinySlider_'
     */
    public function map($oldEntity, &$newEntity, array $mapping, string $oldEntityKeySuffix = '', string $newEntityKeySuffix = ''): void
    {
        foreach ($mapping as $owlIndex => $tinySliderIndex)
        {
            if ($oldEntity->{$oldEntityKeySuffix.$owlIndex})
            {
                if (is_array($tinySliderIndex))
                {
                    if (!isset($tinySliderIndex['key']))
                    {
                        $this->io->note("Missing index 'key' for value of mapping index ".$owlIndex);
                        continue;
                    }
                    if (isset($tinySliderIndex['callable']) && is_callable($tinySliderIndex['callable']))
                    {
                        $value = $tinySliderIndex($oldEntity->{$oldEntityKeySuffix.$owlIndex});
                    }
                    else {
                        $value = $oldEntity->{$oldEntityKeySuffix.$owlIndex};
                    }
                }
                else
                {
                    $value = $oldEntity->{$oldEntityKeySuffix.$owlIndex};
                }
                $newEntity->{$newEntityKeySuffix.$tinySliderIndex} = $value;
            }
        }
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

    protected function addMigrationSql(string $sql): void
    {
        $this->migrationSql[] = $sql;
    }

    protected function getMigrationSql(): array
    {
        return $this->migrationSql;
    }

    public function hasMigrationSql(): bool {
        return !empty($this->migrationSql);
    }

    /**
     * @return array
     */
    public function getUpgradeNotices(): array
    {
        return $this->upgradeNotices;
    }

    /**
     * @param string $upgradeNotices
     */
    public function addUpgradeNotices(string $upgradeNotice): void
    {
        $this->upgradeNotices[] = $upgradeNotice;
    }

    public function hasUpgradeNotices(): bool
    {
        return !empty($this->upgradeNotices);
    }


}


