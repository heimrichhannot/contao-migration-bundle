<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle\Command;

use Contao\CoreBundle\Command\AbstractLockedCommand;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Model;
use Contao\Model\Collection;
use stdClass;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractMigrationCommand extends AbstractLockedCommand
{
    /**
     * @var ContaoFramework
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
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    public function __construct(ContaoFramework $framework)
    {
        $this->framework = $framework;
        parent::__construct();
    }

    /**
     * Returns a list of types that are supported by this command.
     */
    abstract public static function getTypes(): array;

    /**
     * Return the table name where the elements are stored.
     */
    abstract public static function getTable(): string;

    /**
     * Return a nice name for the elements, e.g. 'content element' or 'module'.
     */
    abstract public static function getElementName(): string;

    /**
     * Map values of an old entitiy to an new entity.
     *
     * Mapping Array:
     * * Key is old entity property name
     * * Value is new entity property name or an array with following options:
     *     * 'key': new entity property key
     *     * 'callable': a mapping function. Gets old entity value as parameter.
     *
     * @param Model|stdClass $oldEntity
     * @param Model          $newEntity
     * @param string         $oldEntityKeySuffix Example: 'owl_'
     * @param string         $newEntityKeySuffix Example: 'tinySlider_'
     */
    public function map($oldEntity, &$newEntity, array $mapping, string $oldEntityKeySuffix = '', string $newEntityKeySuffix = ''): void
    {
        foreach ($mapping as $owlIndex => $tinySliderIndex) {
            if ($oldEntity->{$oldEntityKeySuffix.$owlIndex}) {
                if (\is_array($tinySliderIndex)) {
                    if (!isset($tinySliderIndex['key'])) {
                        $this->io->note("Missing index 'key' for value of mapping index ".$owlIndex);

                        continue;
                    }

                    if (isset($tinySliderIndex['callable']) && \is_callable($tinySliderIndex['callable'])) {
                        $value = $tinySliderIndex($oldEntity->{$oldEntityKeySuffix.$owlIndex});
                    } else {
                        $value = $oldEntity->{$oldEntityKeySuffix.$owlIndex};
                    }
                } else {
                    $value = $oldEntity->{$oldEntityKeySuffix.$owlIndex};
                }
                $newEntity->{$newEntityKeySuffix.$tinySliderIndex} = $value;
            }
        }
    }

    /**
     * Save method already respecting dry run.
     */
    public function save(Model $model)
    {
        if (!$this->isDryRun()) {
            $model->save();
        } else {
            if (!$model->id) {
                $model->id = 0;
            }
        }
    }

    public function hasMigrationSql(): bool
    {
        return !empty($this->migrationSql);
    }

    public function getUpgradeNotices(): array
    {
        return $this->upgradeNotices;
    }

    public function addUpgradeNotices(string $type, string $upgradeNotice): void
    {
        if (!isset($this->upgradeNotices[$type])) {
            $this->upgradeNotices[$type] = [];
        }
        $this->upgradeNotices[$type][] = $upgradeNotice;
    }

    public function hasUpgradeNotices(): bool
    {
        return !empty($this->upgradeNotices);
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    protected function configure()
    {
        $this->addOption('ids', 'i', InputOption::VALUE_OPTIONAL, 'Provide the id of a single module or a comma separated list of module ids that should be migrated.');
        $this->addOption('types', 't', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'What module types should be migrated?', static::getTypes());
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Performs a run without writing to database and copy templates.');
    }

    /**
     * {@inheritdoc}
     */
    protected function executeLocked(InputInterface $input, OutputInterface $output)
    {
        $this->framework->initialize();

        $this->io = new SymfonyStyle($input, $output);

        $this->input = $input;
        $this->output = $output;

        $this->io->title('Start '.static::getElementName().' migration');
        $this->io->text(ucfirst(static::getElementName()).' types: '.implode(', ', static::getTypes()));

        if ($input->hasOption('dry-run') && $input->getOption('dry-run')) {
            $this->dryRun = true;
            $this->io->note('Dry run enabled, no data will be changed.');
            $this->io->newLine();
        }

        if (!$this->beforeMigrationCheck()) {
            $this->io->error('Migration could not be done, because not all prerequisites are fulfilled!');

            return 1;
        }

        $types = array_intersect(static::getTypes(), $input->getOption('types'));

        if (empty($types)) {
            $this->io->error('No valid types provided within command.');

            return 1;
        }

        if (empty($this->elements = $this->collect($input->getOption('ids')))) {
            $this->io->note('No '.static::getElementName().' of types '.implode(',', $types).' could be found.');

            $this->io->success('Finished migration!');

            return 0;
        }

        $this->io->text('Found '.\count($this->elements).' '.static::getElementName().'.');

        $this->migrateAll();

        if ($this->hasMigrationSql()) {
            $this->io->section('Migration SQL Command');
            $this->io->text('These are the MySql commands, if you want to do a database merge later while keeping newly added settings. Add the following lines to your database migration scripts.');
            $this->io->newLine();
            $this->io->text($this->getMigrationSql());
        }

        if ($this->hasUpgradeNotices()) {
            $this->io->section('Migration Notices');

            foreach ($this->upgradeNotices as $type => $messages) {
                $this->io->text($type);
                $this->io->listing($messages);
            }
        }

        $this->io->success('Finished migration of '.$this->elements->count().' elements.');

        return 0;
    }

    /**
     * This method is used to check, if migration command could be execute.
     * This is the place to check if a needed bundle is installed or database fields exist.
     * Return false, to stop the migration command.
     */
    abstract protected function beforeMigrationCheck(): bool;

    /**
     * Collect modules.
     *
     * @param string $ids
     *
     * @return Collection|Model[]|Model|null
     */
    protected function collect(string $ids = null): ?Collection
    {
        $options['column'] = [
            static::getTable().'.type IN ('.implode(',', array_map(function ($type) {
                return '"'.addslashes($type).'"';
            }, static::getTypes())).')',
        ];

        if ($ids) {
            $ids = explode(',', str_replace(' ', '', $ids));

            // avoid sql injection
            $ids = array_map('intval', $ids);

            $options['column'][] = static::getTable().'.id IN (' . implode(',', $ids) . ')';
        }

        $modelClass = Model::getClassFromTable(static::getTable());

        if (!$modelClass) {
            return null;
        }
        $model = $this->getContainer()->get('contao.framework')->getAdapter($modelClass);

        return $model->findAll($options);
    }

    /**
     * Migrate all elements.
     */
    protected function migrateAll()
    {
        $this->io->section('Migration');
        $this->io->progressStart($this->elements->count());

        foreach ($this->elements as $element) {
            $this->io->progressAdvance();
            $this->migrate($element);
        }
        $this->io->progressFinish();
    }

    /**
     * Run custom migration on each element.
     *
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
}
