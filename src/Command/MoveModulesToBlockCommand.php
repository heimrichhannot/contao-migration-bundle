<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2018 Heimrich & Hannot GmbH
 *
 * @author  Thomas Körner <t.koerner@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */


namespace HeimrichHannot\MigrationBundle\Command;


use Contao\ContentModel;
use Contao\CoreBundle\Command\AbstractLockedCommand;
use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\Model;
use Contao\Model\Collection;
use Contao\ModuleModel;
use HeimrichHannot\Blocks\BlockModel;
use HeimrichHannot\Blocks\BlockModuleModel;
use HeimrichHannot\Blocks\ModuleBlock;
use HeimrichHannot\FilterBundle\Module\ModuleFilter;
use HeimrichHannot\ListBundle\Module\ModuleList;
use HeimrichHannot\ReaderBundle\Module\ModuleReader;
use HeimrichHannot\UtilsBundle\Model\ModelUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MoveModulesToBlockCommand extends AbstractLockedCommand
{
    /**
     * @var ContaoFrameworkInterface
     */
    private $framework;
    private $dryRun = false;
    /**
     * @var ModelUtil
     */
    private $modelUtil;
    /**
     * @var InputInterface
     */
    private $input;
    /**
     * @var OutputInterface
     */
    private $output;
    /**
     * @var SymfonyStyle
     */
    private $io;


    public function __construct(ContaoFrameworkInterface $framework, ModelUtil $modelUtil)
    {
        parent::__construct();
        $this->framework = $framework;
        $this->modelUtil = $modelUtil;
    }

    protected function configure()
    {
        $this->setName('huh:migration:movetoblock')
            ->setDescription('Move given modules to a block.')
            ->setHelp("Move the blocks with the given Ids into one block. Module content elements will be replaced with block content element (can be bypassed with no-replace option). By default a new block will be created, a block id is given as option. Block config for autoitem will be set by command for filter, list and reader modules, unless you deactivate this by set ignore-types option")
            ->addArgument('modules', InputArgument::IS_ARRAY | InputArgument::REQUIRED, "Ids of modules should migrated into a block.")
            ->addOption("block", "b", InputOption::VALUE_REQUIRED, "Set a block where module should be added to. If not set, a new block is created.")
            ->addOption("ignore-types", null, InputOption::VALUE_NONE, "Don't set custom module settings for block module like !autoitem for reader module.")
            ->addOption("dry-run", null, InputOption::VALUE_NONE, "Preview command without changing the database.")
            ->addOption("title", "t", InputOption::VALUE_REQUIRED, "Set a block name for new blocks. If not set, name of first module will be used.")
            ->addOption("no-replace", null, InputOption::VALUE_NONE, "Don't replace modules with block.")
        ;
        parent::configure();
    }


    /**
     * Executes the command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function executeLocked(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title("Move modules to block");
        $this->framework->initialize();
        $this->input = $input;
        $this->output = $output;
        $this->io = $io;

        $moduleIds = $input->getArgument("modules");
        $this->dryRun = $input->getOption("dry-run");

        $modules = ModuleModel::findMultipleByIds($moduleIds);
        if (!$modules || $modules->count() < 1)
        {
            $io->error("No modules for given ids found! Aborting...");
            return 1;
        }
        $moduleList = [];
        $moduleIds = [];
        foreach ($modules as $module)
        {
            $moduleList[] = [
                'name' => $module->name,
                'id' => $module->id,
                'type' => $module->type,
            ];
            $moduleIds[] = $module->id;
        }
        $io->writeln("Found following modules for given Ids:");
        $io->table(["Name", "ID", "Type"], $moduleList);
        $block = null;
        $blockModule = null;
        if ($input->hasOption("block") && $input->getOption("block"))
        {
            if (!$block = BlockModel::findByPk($input->getOption("block")))
            {
                $io->error("Given block does could no be found. Please check, if id is correct. Use the block id, not the model id! Aborting...");
                return 1;
            }
            $io->newLine();
            $io->writeln("Use block $block->title (ID: $block->id)");

            $blockModule = ModuleModel::findBy(['pid = ?', 'type = ?', 'block = ?'], [$modules->pid, ModuleBlock::TYPE, $block->id]);
            if ($blockModule)
            {
                $io->newLine();
                $io->writeln("No module found for block. Creating module for block.");
                $blockModule = $this->createBlockModule($block, $modules);
            }
        }
        else {
            /** @var BlockModel $block */
            $block = $this->getContainer()->get('huh.utils.model')->setDefaultsFromDca(new BlockModel());
            $block->tstamp = time();
            $block->pid = $modules->pid;
            if ($input->hasOption("title"))
            {
                $block->title = $input->getOption("title");
            }
            else {
                $block->title = $modules->title;
            }
            if (!$this->dryRun)
            {
                $block->save();
            }
            $io->newLine();
            $io->writeln("Created Block $block->title (ID: $block->id)");
            $blockModule = $this->createBlockModule($block, $modules);
        }
        foreach ($modules as $module)
        {
            /** @var BlockModuleModel $blockElement */
            $blockElement = $this->modelUtil->setDefaultsFromDca(new BlockModuleModel());
            $blockElement->tstamp = time();
            $blockElement->pid = $block->id;
            $blockElement->type = "default";
            $blockElement->module = $module->id;
            if (!$input->getOption('ignore-types'))
            {
                switch($module->type)
                {
                    case ModuleReader::TYPE:
                        $blockElement->keywords = '!autoitem';
                        $blockElement->sorting = "4";
                        break;
                    case ModuleList::TYPE:
                        $blockElement->keywords = 'autoitem';
                        $blockElement->sorting = "2";
                        break;
                    case ModuleFilter::TYPE:
                        $blockElement->keywords = 'autoitem';
                        $blockElement->sorting = "1";
                        break;
                }
            }
            if (!$this->dryRun)
            {
                $blockElement->save();
            }
        }
        if (!$input->getOption("no-replace"))
        {
            $contentElements = ContentModel::findBy([
                'ptable = ?',
                'type = ?',
                'module IN(' . implode(',', array_map('\intval', $moduleIds)) . ')'
            ], [
                'tl_article',
                'module',

            ]);
            if (!$contentElements)
            {
                $io->newLine();
                $io->writeln("No content elements for modules found. Continue...");
            }
            else {
                $replacedArticles = [];
                $io->newLine();
                foreach ($contentElements as $element)
                {
                    if (in_array($element->pid, $replacedArticles))
                    {
                        $elementId = $element->id;
                        if (!$this->dryRun)
                        {
                            $element->delete();
                        }
                        $io->writeln("Deleted content element with id $elementId, cause block is already integrated in article.");
                    }
                    else {
                        $element->module = $blockModule->id;
                        if (!$this->dryRun)
                        {
                            $element->save();
                        }
                        $replacedArticles[] = $element->pid;
                        $io->writeln("Updated content element with id $element->id set module to block model.");
                    }
                }
            }
        }


        $io->success("Successfully moved modules to block.");
        return 0;
    }

    /**
     * @param BlockModel $block
     * @param ModuleModel|Collection|Model $module
     * @return Model|ModuleModel
     */
    protected function createBlockModule(BlockModel $block, $module)
    {
        /** @var ModuleModel|Model $blockModule */
        $blockModule = $this->modelUtil->setDefaultsFromDca(new ModuleModel());
        $blockModule->tstamp = time();
        $blockModule->type = ModuleBlock::TYPE;
        $blockModule->block = $block->id;
        $blockModule->pid = $module->pid;
        if ($this->input->getOption("title"))
        {
            $blockModule->name = $this->input->getOption("title");
        }
        else {
            $blockModule->name = $module->title;
        }
        if (!$this->dryRun)
        {
            $blockModule->save();
            $block->module = $blockModule->id;
            $block->save();
        }
        $this->io->newLine();
        $this->io->writeln("Create block module $blockModule->name (ID: $blockModule->id)");
        return $blockModule;
    }
}