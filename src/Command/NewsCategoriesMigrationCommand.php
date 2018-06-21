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
use Contao\CoreBundle\Framework\FrameworkAwareInterface;
use Contao\CoreBundle\Framework\FrameworkAwareTrait;
use Contao\System;
use Doctrine\DBAL\Query\QueryBuilder;
use HeimrichHannot\CategoriesBundle\Model\CategoryAssociationModel;
use HeimrichHannot\CategoriesBundle\Model\CategoryModel;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class NewsCategoriesMigrationCommand extends AbstractLockedCommand
{

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
     * @var array
     */
    protected $categories;

    /**
     * @var array
     */
    protected $categoryAssociations;

    /**
     * tl_news category field name
     * @var string
     */
    protected $field;
    /**
     * @var ContaoFrameworkInterface
     */
    private $framework;

    public function __construct(ContaoFrameworkInterface $framework)
    {
        parent::__construct();
        $this->framework = $framework;
    }


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('huh:migration:db:news_categories')->setDescription(
            'Migration of database entries from news_categories module to heimrichhannot/contao-categories.'
        );

        $this->addArgument('field', InputArgument::OPTIONAL, 'What is the name of the category field in tl_news (default: categories)?', 'categories');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function executeLocked(InputInterface $input, OutputInterface $output)
    {
        $this->framework->initialize();

        $this->field   = $input->getArgument('field');
        $this->io      = new SymfonyStyle($input, $output);
        $this->input   = $input;
        $this->output  = $output;
        $this->rootDir = $this->getContainer()->getParameter('kernel.project_dir');

        $this->queryBuilder = new QueryBuilder($this->getContainer()->get('doctrine.dbal.default_connection'));

        if ($this->migrateCategories()) {
            $this->migrateAssociations();
        }

        return 0;
    }


    /**
     * Migrate tl_news_category to tl_category
     */
    protected function migrateCategories(): int
    {
        $newsCategories = $this->queryBuilder->select('*')->from('tl_news_category')->execute()->fetchAll();

        if (false === $newsCategories) {
            return 0;
        }


        foreach ($newsCategories as $newsCategory) {
            $categoryModel = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new CategoryModel());
            $categoryModel->setRow($newsCategory);
            $categoryModel->dateAdded = $newsCategory->tstamp;
            $categoryModel->save();

            if ($categoryModel->id > 0) {
                $this->output->writeln('<info>Successfully migrated category: "' . $categoryModel->title . '" (ID: ' . $categoryModel->id . ')</info>');
                $this->categories[$newsCategory['id']] = $categoryModel;
            } else {
                $this->output->writeln('<error>Error: Could not migrate category: "' . $categoryModel->title . '" (ID: ' . $categoryModel->id . ')</error>');
            }
        }

        return 1;
    }

    /**
     * Migrate tl_news_categories to tl_category_association
     */
    protected function migrateAssociations(): int
    {
        $newsCategoryRelations = $this->queryBuilder->select('*')->from('tl_news_categories')->groupBy('category_id,news_id')->execute()->fetchAll();

        if (false === $newsCategoryRelations) {
            return 1;
        }

        foreach ($newsCategoryRelations as $newsCategoryRelation) {

            if (!isset($this->categories[$newsCategoryRelation['category_id']])) {
                $this->output->writeln('<error>Unable to migrate relation for news with ID:' . $newsCategoryRelation['news_id'] . ' and category ID:' . $newsCategoryRelation['category_id'] . ' because category does not exist.</error>');
                continue;
            }

            $categoryAssociationModel                = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new CategoryAssociationModel());
            $categoryAssociationModel->tstamp        = time();
            $categoryAssociationModel->category      = $newsCategoryRelation['category_id'];
            $categoryAssociationModel->parentTable   = 'tl_news';
            $categoryAssociationModel->entity        = $newsCategoryRelation['news_id'];
            $categoryAssociationModel->categoryField = $this->field;
            $categoryAssociationModel->save();

            if ($categoryAssociationModel->id > 0) {
                $this->output->writeln('<info>Successfully migrated category relation for field "' . $this->field . '" : "(news ID:' .$newsCategoryRelation['news_id'] . ')'. $this->categories[$newsCategoryRelation['category_id']]->title . '" (ID: ' . $categoryAssociationModel->category . ')</info>');
                $this->categoryAssociations[] = $categoryAssociationModel;
            } else {
                $this->output->writeln('<error>Error: Could not migrate category relation for field "' . $this->field . '" : "(news ID:' .$newsCategoryRelation['news_id'] . ')'. $this->categories[$newsCategoryRelation['category_id']]->title . '" (ID: ' . $categoryAssociationModel->category . ')</error>');
            }
        }

        return 0;
    }
}