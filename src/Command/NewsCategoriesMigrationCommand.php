<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle\Command;

use Contao\CoreBundle\Command\AbstractLockedCommand;
use Contao\CoreBundle\Framework\ContaoFrameworkInterface;
use Contao\Database;
use Contao\System;
use Doctrine\DBAL\Query\QueryBuilder;
use HeimrichHannot\CategoriesBundle\Model\CategoryAssociationModel;
use HeimrichHannot\CategoriesBundle\Model\CategoryModel;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class NewsCategoriesMigrationCommand extends AbstractLockedCommand
{
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
     * @var array
     */
    protected $categoryIdMapping;

    /**
     * tl_news category field name.
     *
     * @var string
     */
    protected $field;

    /**
     * @var array
     */
    protected $newsArchiveIds;

    /**
     * @var array
     */
    protected $categoryIds;

    /**
     * @var string
     */
    protected $primaryCategoryField;

    /**
     * @var ContaoFrameworkInterface
     */
    private $framework;
    /**
     * @var DatabaseUtil
     */
    private $databaseUtil;

    public function __construct(ContaoFrameworkInterface $framework, DatabaseUtil $databaseUtil)
    {
        parent::__construct();
        $this->framework = $framework;
        $this->databaseUtil = $databaseUtil;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('huh:migration:db:news_categories')->setDescription(
            'Migration of database entries from news_categories module to heimrichhannot/contao-categories.'
        );

        $this->addArgument('field', InputArgument::OPTIONAL, 'What is the name of the *target* category field in tl_news (default: categories)?', 'categories');
        $this->addOption('category-ids', null, InputOption::VALUE_OPTIONAL, 'Restrict the command to legacy news categories of certain IDs **and their children** (default: no restriction)?', '');
        $this->addOption('news-archive-ids', null, InputOption::VALUE_OPTIONAL, 'Restrict the command to news of certain archives; pass in a comma separated list of IDs (default: no restriction)?', '');
        $this->addOption('primary-category-field', null, InputOption::VALUE_OPTIONAL, 'Pass in the name of the *source* field in tl_news holding the ID of the primary category.', '');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function executeLocked(InputInterface $input, OutputInterface $output)
    {
        $this->framework->initialize();

        $this->field = $input->getArgument('field');
        $this->categoryIds = explode(',', $input->getOption('category-ids'));
        $this->newsArchiveIds = explode(',', $input->getOption('news-archive-ids'));
        $this->primaryCategoryField = $input->getOption('primary-category-field');

        $this->io = new SymfonyStyle($input, $output);
        $this->input = $input;
        $this->output = $output;
        $this->rootDir = $this->getContainer()->getParameter('kernel.project_dir');

        if ($this->migrateCategories()) {
            $this->migrateAssociations();
        }

        return 0;
    }

    /**
     * Migrate tl_news_category to tl_category.
     */
    protected function migrateCategories(): int
    {
        $queryBuilder = new QueryBuilder($this->getContainer()->get('doctrine.dbal.default_connection'));

        $queryBuilder->select('*')->from('tl_news_category');

        // restrict category ids
        if (!empty($this->categoryIds)) {
            // retrieve children of category ids
            $children = Database::getInstance()->getChildRecords($this->categoryIds, 'tl_news_category');

            $queryBuilder->andWhere($queryBuilder->expr()->in('tl_news_category.id', array_merge($this->categoryIds, $children)));
        }

        $newsCategories = $queryBuilder->execute()->fetchAll();

        if (false === $newsCategories) {
            return 0;
        }

        foreach ($newsCategories as $newsCategory) {
            $categoryModel = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new CategoryModel());

            // unset the old ID since it may also be available in the target table
            $legacyId = $newsCategory['id'];
            unset($newsCategory['id']);

            $categoryModel->setRow($newsCategory);
            $categoryModel->dateAdded = $newsCategory['tstamp'];

            $categoryModel->save();

            if ($categoryModel->id > 0) {
                $this->output->writeln('<info>Successfully migrated category: "'.$categoryModel->title.'" (Legacy-ID: '.$legacyId.')</info>');
                $this->categories[$legacyId] = $categoryModel;

                // store the id mapping
                $this->categoryIdMapping[$legacyId] = $categoryModel->id;
            } else {
                $this->output->writeln('<error>Error: Could not migrate category: "'.$categoryModel->title.'" (Legacy-ID: '.$legacyId.')</error>');
            }
        }

        // set the correct pid
        foreach ($this->categoryIdMapping as $legacyId => $id) {
            if (null === ($categoryModel = System::getContainer()->get('huh.utils.model')->findModelInstanceByPk('tl_category', $id))) {
                continue;
            }

            $categoryModel->pid = $this->categoryIdMapping[$categoryModel->pid] ?? 0;

            if ($categoryModel->jumpTo && $categoryModel->pid) {
                $categoryModel->overrideJumpTo = true;
            }

            $categoryModel->save();
        }

        return 1;
    }

    /**
     * Migrate tl_news_categories to tl_category_association.
     */
    protected function migrateAssociations(): int
    {
        $queryBuilder = new QueryBuilder($this->getContainer()->get('doctrine.dbal.default_connection'));

        $queryBuilder->select('*')->from('tl_news_categories')->groupBy('category_id,news_id');

        // restrict category ids
        if (!empty($this->categoryIds)) {
            // retrieve children of category ids
            $children = Database::getInstance()->getChildRecords($this->categoryIds, 'tl_news_category');

            $queryBuilder->andWhere($queryBuilder->expr()->in('category_id', array_merge($this->categoryIds, $children)));
        }

        // restrict news archive ids
        if (!empty($this->newsArchiveIds)) {
            // retrieve news ids from the archives
            $newsIds = [];

            if (null !== ($news = $this->databaseUtil->findResultsBy('tl_news', ['tl_news.pid IN ('.implode(',', $this->newsArchiveIds).')'], [])) &&
                $news->numRows > 0) {
                $newsIds = $news->fetchEach('id');
            }

            // not in the condition above because if the condition is not set, all news_ids would be migrated!
            $queryBuilder->andWhere($queryBuilder->expr()->in('news_id', $newsIds));
        }

        $newsCategoryRelations = $queryBuilder->execute()->fetchAll();

        if (false === $newsCategoryRelations) {
            return 1;
        }

        foreach ($newsCategoryRelations as $newsCategoryRelation) {
            if (!isset($this->categories[$newsCategoryRelation['category_id']]) || !isset($this->categoryIdMapping[$newsCategoryRelation['category_id']])) {
                $this->output->writeln('<error>Unable to migrate relation for news with ID:'.$newsCategoryRelation['news_id'].' and category ID:'.$newsCategoryRelation['category_id'].' because category does not exist.</error>');

                continue;
            }

            $categoryAssociationModel = System::getContainer()->get('huh.utils.model')->setDefaultsFromDca(new CategoryAssociationModel());
            $categoryAssociationModel->tstamp = time();
            $categoryAssociationModel->category = $this->categoryIdMapping[$newsCategoryRelation['category_id']];
            $categoryAssociationModel->parentTable = 'tl_news';
            $categoryAssociationModel->entity = $newsCategoryRelation['news_id'];
            $categoryAssociationModel->categoryField = $this->field;
            $categoryAssociationModel->save();

            if ($categoryAssociationModel->id > 0) {
                $this->output->writeln('<info>Successfully migrated category relation for field "'.$this->field.'" : "(news ID:'.$newsCategoryRelation['news_id'].')'.$this->categories[$newsCategoryRelation['category_id']]->title.'" (ID: '.$categoryAssociationModel->category.')</info>');
                $this->categoryAssociations[] = $categoryAssociationModel;
            } else {
                $this->output->writeln('<error>Error: Could not migrate category relation for field "'.$this->field.'" : "(news ID:'.$newsCategoryRelation['news_id'].')'.$this->categories[$newsCategoryRelation['category_id']]->title.'" (ID: '.$categoryAssociationModel->category.')</error>');
            }
        }

        // set the primary category in the news
        if ($this->primaryCategoryField) {
            $newsIds = [];
            $targetField = $this->field.'_primary';

            if (!empty($this->newsArchiveIds)) {
                // retrieve news ids from the archives
                if (null !== ($news = $this->databaseUtil->findResultsBy('tl_news', ['tl_news.pid IN ('.implode(',', $this->newsArchiveIds).')'], [])) &&
                    $news->numRows > 0) {
                    $newsIds = $news->fetchEach('id');

                    if (empty($newsIds)) {
                        throw new \Exception('No news found in passed in news archive ids.');
                    }
                }
            }

            $columns = empty($newsIds) ? [] : ['tl_news.id IN ('.implode(',', $newsIds).')'];

            if (null !== ($news = $this->databaseUtil->findResultsBy('tl_news', $columns, [])) && $news->numRows > 0) {
                while ($news->next()) {
                    if (!$news->{$this->primaryCategoryField} || !isset($this->categoryIdMapping[$news->{$this->primaryCategoryField}])) {
                        continue;
                    }

                    $this->databaseUtil->update('tl_news', [
                        $targetField => $this->categoryIdMapping[$news->{$this->primaryCategoryField}],
                    ], 'tl_news.id=?', [$news->id]);

                    $this->output->writeln('<info>Successfully migrated primary category from field "'.$this->primaryCategoryField.'" to field "'.$targetField.'": "(news ID:'.$news->id.')</info>');
                }
            }
        }

        return 0;
    }
}
