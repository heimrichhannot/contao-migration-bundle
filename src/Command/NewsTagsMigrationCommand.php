<?php

/*
 * Copyright (c) 2020 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\MigrationBundle\Command;

use Contao\CoreBundle\Command\AbstractLockedCommand;
use Doctrine\DBAL\Query\QueryBuilder;
use HeimrichHannot\UtilsBundle\Database\DatabaseUtil;
use HeimrichHannot\UtilsBundle\Driver\DC_Table_Utils;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NewsTagsMigrationCommand extends AbstractLockedCommand
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
     * @var ContainerInterface
     */
    private $container;
    /**
     * @var DatabaseUtil
     */
    private $databaseUtil;

    public function __construct(ContainerInterface $container, DatabaseUtil $databaseUtil)
    {
        parent::__construct();
        $this->container    = $container;
        $this->databaseUtil = $databaseUtil;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('huh:migration:db:news_tags')->setDescription(
            'Migration of database entries from tags module to codefog/tags-bundle.'
        );

        $this->addOption('news-archive-ids', null, InputOption::VALUE_OPTIONAL, 'Restrict the command to news of certain archives; pass in a comma separated list of IDs (default: no restriction)?', '');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function executeLocked(InputInterface $input, OutputInterface $output)
    {
        $this->container->get('contao.framework')->initialize();

        $this->newsArchiveIds = explode(',', $input->getOption('news-archive-ids'));

        $this->io      = new SymfonyStyle($input, $output);
        $this->input   = $input;
        $this->output  = $output;
        $this->rootDir = $this->getContainer()->getParameter('kernel.project_dir');

        $this->queryBuilder = new QueryBuilder($this->getContainer()->get('doctrine.dbal.default_connection'));

        return $this->migrateTags();
    }

    /**
     * Migrate tl_tags.
     */
    protected function migrateTags(): int
    {
        $this->queryBuilder->setParameter('from_table', 'tl_news');

        $this->queryBuilder->select('*')->from('tl_tag')->where($this->queryBuilder->expr()->eq('tl_tag.from_table', ':from_table'));

        // restrict news archive ids
        if (!empty($this->newsArchiveIds)) {
            // retrieve news ids from the archives
            $newsIds = [];

            if (null !== ($news = $this->databaseUtil->findResultsBy('tl_news', ['tl_news.pid IN (' . implode(',', $this->newsArchiveIds) . ')'], [])) &&
                $news->numRows > 0) {
                $newsIds = $news->fetchEach('id');
            }

            // not in the condition above because if the condition is not set, all news_ids would be migrated!
            $this->queryBuilder->andWhere($this->queryBuilder->expr()->in('tid', $newsIds));
        }

        $newsTags = $this->queryBuilder->execute()->fetchAll();

        if (false === $newsTags) {
            return 0;
        }

        $tstamp  = time();
        $newsIds = [];

        foreach ($newsTags as $newsTag) {
            $tag = $this->findTagByName($newsTag['tag']);

            // 1st: create the tag if it not already exists
            if (false === $tag) {
                $this->queryBuilder->setParameters(['tstamp' => $tstamp, 'name' => $newsTag['tag'], 'source' => 'news_tag_manager']);
                $this->queryBuilder->resetQueryParts();
                $this->queryBuilder->insert('tl_cfg_tag')->values(['tstamp' => ':tstamp', 'name' => ':name', 'source' => ':source'])->execute();
            }

            if (false === ($tag = $this->findTagByName($newsTag['tag']))) {
                $this->output->writeln('<error>Error: Could not create news tag with name: "' . $newsTag['tag'] . '"</error>');

                continue;
            }

            // 2nd: create a unique alias
            if (empty($tag['alias'])) {
                $dc               = new DC_Table_Utils('tl_cfg_tag');
                $dc->activeRecord = (object)$tag;

                $this->queryBuilder->setParameters(['alias' => $this->container->get('codefog_tags.listener.data_container.tag')->onAliasSaveCallback('', $dc), 'id' => $tag['id']]);
                $this->queryBuilder->update('tl_cfg_tag')->set('alias', ':alias')->where($this->queryBuilder->expr()->eq('id', ':id'))->execute();
            }

            // 3rd: delete/cleanup news / news tag relations from previous imports
            $this->queryBuilder->resetQueryParts();
            $this->queryBuilder->setParameters(['news_id' => $newsTag['tid'], 'tag_id' => $tag['id']]);
            $this->queryBuilder->delete('tl_cfg_tag_news')->where($this->queryBuilder->expr()->eq('news_id', ':news_id'))->andWhere($this->queryBuilder->expr()->eq('cfg_tag_id', ':tag_id'))->execute();

            // 4th: create news / news tag relations
            $this->queryBuilder->resetQueryParts();
            $this->queryBuilder->setParameters(['news_id' => $newsTag['tid'], 'tag_id' => $tag['id']]);

            if ($this->queryBuilder->insert('tl_cfg_tag_news')->values(['news_id' => ':news_id', 'cfg_tag_id' => ':tag_id'])->execute()) {
                $newsIds[$newsTag['tid']][] = $tag['id'];
            }
        }

        foreach ($newsIds as $id => $tags) {
            $this->queryBuilder->setParameters(['id' => $id, 'tags' => serialize($tags)]);

            if ($this->queryBuilder->update('tl_news')->set('tags', ':tags')->where($this->queryBuilder->expr()->eq('id', ':id'))->execute()) {
                $this->output->writeln('<info>Successfully migrated tags for news with id: "' . $id . '"</info>');
            }
        }

        return 1;
    }

    /**
     * Find a news tag from tl_cfg_tag by a given name.
     *
     * @param $name
     *
     * @return mixed
     */
    protected function findTagByName($name)
    {
        $this->queryBuilder->resetQueryParts();
        $this->queryBuilder->setParameters(['name' => $name, 'source' => 'news_tag_manager']);

        return $this->queryBuilder->select('*')->from('tl_cfg_tag')->where($this->queryBuilder->expr()->eq('name', ':name'))->andWhere($this->queryBuilder->expr()->eq('source', ':source'))->setMaxResults(1)->execute()->fetch();
    }
}
