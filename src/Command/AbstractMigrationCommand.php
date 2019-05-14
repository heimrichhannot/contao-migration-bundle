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


use Contao\CoreBundle\Command\AbstractLockedCommand;
use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\Model;
use Contao\Model\Collection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractMigrationCommand extends AbstractLockedCommand
{
    /**
     * @var ContaoFrameworkInterface
     */
    protected $framework;
    /**
     * @var SymfonyStyle
     */
    protected $io;
    /**
     * @var Collection|array|Model[]|null
     */
    protected $elements;
    /**
     * @var array
     */
    protected $migrationSql;
    /**
     * @var array
     */
    protected $upgradeNotices;
    /**
     * @var bool
     */
    protected $dryRun = false;
    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;

    public function __construct(ContaoFrameworkInterface $framework)
    {
        $this->framework = $framework;
        parent::__construct();
    }

    /**
     * Returns a list of types that are supported by this command.
     *
     * @return array
     */
    abstract static function getTypes(): array;

    /**
     * Return the table name where the elements are stored
     *
     * @return string
     */
    abstract static function getTable(): string;

    /**
     * Return a nice name for the elements, e.g. 'content element' or 'module'
     *
     * @return string
     */
    abstract static function getElementName(): string;

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

        $this->io->title("Start ".static::getElementName()." migration");
        $this->io->text(ucfirst(static::getElementName())." types: ".implode(", ", static::getTypes()));

        if ($input->hasOption('dry-run') && $input->getOption('dry-run'))
        {
            $this->dryRun = true;
            $this->io->note("Dry run enabled, no data will be changed.");
            $this->io->newLine();
        }

        if (!$this->beforeMigrationCheck())
        {
            $this->io->error('Migration could not be done, cause not all prerequisites are fulfilled!');
            return 1;
        }

        $this->queryBuilder = new QueryBuilder($this->getContainer()->get('doctrine.dbal.default_connection'));

        $types = array_intersect(static::getTypes(), $input->getOption('types'));

        if (empty($types)) {

            $this->io->error('No valid types provided within command.');

            return 1;
        }

        if (empty($this->elements = $this->collect($input->getOption('id')))) {
            $this->io->note('No '.static::getElementName().' of types ' . implode(",", $types) . ' could be found.');


            $this->io->success("Finished migration!");
            return 0;
        }

        $this->io->text("Found ".count($this->elements).' '.static::getElementName().'.');

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

        $this->io->success("Finished migration of ".$this->elements->count()." elements.");

        return 0;
    }

    /**
     * This method is used to check, if migration command could be execute.
     * This is the place to check if a needed bundle is installed or database fields exist.
     * Return false, to stop the migration command.
     *
     * @return bool
     */
    abstract protected function beforeMigrationCheck(): bool;

    /**
     * Collect modules
     * @param int $id
     *
     * @return Collection|Model[]|Model|null
     */
    protected function collect(int $id = null): ?Collection
    {
        $options['column'] = [
            static::getTable().'.type IN (' . implode(',', array_map(function ($type) {
                return '"' . addslashes($type) . '"';
            }, static::getTypes())) . ')'
        ];

        if ($id > 0) {
            $options['column'][] = static::getTable().'.id = ?';
            $options['value'][]  = $id;
        }

        $modelClass = Model::getClassFromTable(static::getTable());
        if (!$modelClass) return null;
        $model = $this->getContainer()->get('contao.framework')->getAdapter($modelClass);
        return $model->findAll($options);
    }

    /**
     * Migrate all elements
     */
    protected function migrateAll()
    {
        $this->io->section("Migration");
        $this->io->progressStart($this->elements->count());
        foreach ($this->elements as $element) {
            $this->io->progressAdvance();
            $this->migrate($element);
        }
        $this->io->progressFinish();
    }

    /**
     * Run custom migration on each element
     * @param Model $model
     * @return mixed
     */
    abstract protected function migrate(Model $model): int;

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
     * @param string $upgradeNotice
     */
    public function addUpgradeNotices(string $upgradeNotice): void
    {
        $this->upgradeNotices[] = $upgradeNotice;
    }

    public function hasUpgradeNotices(): bool
    {
        return !empty($this->upgradeNotices);
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