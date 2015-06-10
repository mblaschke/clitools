<?php

namespace CliTools\Console\Command\Sync;

/*
 * CliTools Command
 * Copyright (C) 2015 Markus Blaschke <markus@familie-blaschke.net>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

use CliTools\Utility\FilterUtility;
use CliTools\Console\Shell\CommandBuilder\CommandBuilder;
use CliTools\Console\Shell\CommandBuilder\RemoteCommandBuilder;
use CliTools\Console\Shell\CommandBuilder\OutputCombineCommandBuilder;
use CliTools\Console\Shell\CommandBuilder\CommandBuilderInterface;
use CliTools\Database\DatabaseConnection;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;


class ServerCommand extends AbstractSyncCommand {

    /**
     * Config area
     *
     * @var string
     */
    protected $confArea = 'sync';

    /**
     * Server configuration name
     * @var string
     */
    protected $contextName;

    /**
     * Configure command
     */
    protected function configure() {
        $this
            ->setName('sync:server')
            ->setDescription('Sync files and database from server')
            ->addArgument(
                'context',
                InputArgument::OPTIONAL,
                'Configuration name for server'
            )
            ->addOption(
                'mysql',
                null,
                InputOption::VALUE_NONE,
                'Run only mysql'
            )
            ->addOption(
                'rsync',
                null,
                InputOption::VALUE_NONE,
                'Run only rsync'
            );
    }

    /**
     * Startup task
     */
    protected function startup() {
        $this->output->writeln('<h2>Starting server synchronization</h2>');
        parent::startup();
    }

    /**
     * Backup task
     */
    protected function runMain() {
        // ##################
        // Option specific runners
        // ##################
        $runRsync = true;
        $runMysql = true;

        if ($this->input->getOption('mysql') || $this->input->getOption('rsync')) {
            // don't run rsync if not specifiecd
            $runRsync = $this->input->getOption('rsync');

            // don't run mysql if not specifiecd
            $runMysql = $this->input->getOption('mysql');
        }

        // ##################
        // Run tasks
        // ##################

        // Check database connection
        if ($runMysql && $this->config->exists('mysql')) {
            DatabaseConnection::ping();
        }

        // Sync files with rsync to local storage
        if ($runRsync && $this->config->exists('rsync')) {
            $this->output->writeln('<h1>Starting FILE sync</h1>');
            $this->runTaskRsync();
        }

        // Sync database to local server
        if ($runMysql && $this->config->exists('mysql')) {
            $this->output->writeln('<h1>Starting MYSQL sync</h1>');
            $this->runTaskDatabase();
        }
    }

    /**
     * Sync files with rsync
     */
    protected function runTaskRsync() {
        // ##################
        // Restore dirs
        // ##################
        $source = $this->getRsyncPathFromConfig();
        $target = $this->getRsyncWorkingPath();
        $command = $this->createRsyncCommand($source, $target);

        $command->executeInteractive();
    }

    /**
     * Sync database
     */
    protected function runTaskDatabase() {
        // ##################
        // Sync databases
        // ##################
        foreach ($this->contextConfig->getArray('mysql.database') as $databaseConf) {
            if (strpos($databaseConf, ':') !== false) {
                // local and foreign database in one string
                list($localDatabase, $foreignDatabase) = explode(':', $databaseConf, 2);
            } else {
                // database equal
                $localDatabase   = $databaseConf;
                $foreignDatabase = $databaseConf;
            }

            // make sure we don't have any leading whitespaces
            $localDatabase   = trim($localDatabase);
            $foreignDatabase = trim($foreignDatabase);

            $dumpFile = $this->tempDir . '/' . $localDatabase . '.sql.dump';

            // ##########
            // Dump from server
            // ##########
            $this->output->writeln('<p>Fetching foreign database "' . $foreignDatabase . '"</p>');

            $mysqldump = $this->createRemoteMySqlDumpCommand($foreignDatabase);

            if ($this->contextConfig['mysql']['filter']) {
                $mysqldump = $this->addFilterArguments($mysqldump, $foreignDatabase, $this->contextConfig['mysql']['filter']);
            }

            $command = $this->wrapRemoteCommand($mysqldump);
            $command->setOutputRedirectToFile($dumpFile);

            $command->executeInteractive();

            // ##########
            // Restore local
            // ##########
            $this->output->writeln('<p>Restoring database "' . $localDatabase . '"</p>');

            $this->createMysqlRestoreCommand($localDatabase, $dumpFile)->executeInteractive();
        }
    }


    /**
     * Create rsync command for share sync
     *
     * @param string     $source    Source directory
     * @param string     $target    Target directory
     * @param array|null $filelist  List of files (patterns)
     * @param array|null $exclude   List of excludes (patterns)
     *
     * @return CommandBuilder
     */
    protected function createRsyncCommand($source, $target, array $filelist = null, array $exclude = null) {
        // Add file list (external file with --files-from option)
        if (!$filelist && $this->contextConfig->exists('rsync.directory')) {
            $filelist = $this->contextConfig->get('rsync.directory');
        }

        // Add exclude (external file with --exclude-from option)
        if (!$exclude && $this->contextConfig->exists('rsync.exclude')) {
            $exclude = $this->contextConfig->get('rsync.exclude');
        }

        return parent::createRsyncCommand($source, $target, $filelist, $exclude);
    }

    /**
     * Add filter to command
     *
     * @param CommandBuilderInterface $commandDump  Command
     * @param string                  $database     Database
     * @param string                  $filter       Filter name
     *
     * @return CommandBuilderInterface
     */
    protected function addFilterArguments(CommandBuilderInterface $commandDump, $database, $filter) {
        $command = $commandDump;

        // get filter
        if (is_array($filter)) {
            $filterList = (array)$filter;
            $filter     = 'custom table filter';
        } else {
            $filterList = $this->getApplication()->getConfigValue('mysql-backup-filter', $filter);
        }

        if (empty($filterList)) {
            throw new \RuntimeException('MySQL dump filters "' . $filter . '" not available"');
        }

        $this->output->writeln('<p>Using filter "' . $filter . '"</p>');

        // Get table list (from cloned mysqldump command)
        $tableListDumper = $this->createRemoteMySqlCommand($database);
        $tableListDumper->addArgumentTemplate('-e %s', 'show tables;');

        $tableListDumper = $this->wrapRemoteCommand($tableListDumper);
        $tableList       = $tableListDumper->execute()->getOutput();

        // Filter table list
        $ignoredTableList = FilterUtility::mysqlIgnoredTableFilter($tableList, $filterList, $database);

        // Dump only structure
        $commandStructure = clone $command;
        $commandStructure
            ->addArgument('--no-data')
            ->clearPipes();

        // Dump only data (only filtered tables)
        $commandData = clone $command;
        $commandData
            ->addArgument('--no-create-info')
            ->clearPipes();

        if (!empty($ignoredTableList)) {
            $commandData->addArgumentTemplateMultiple('--ignore-table=%s', $ignoredTableList);
        }

        $commandPipeList = $command->getPipeList();

        // Combine both commands to one
        $command = new OutputCombineCommandBuilder();
        $command
            ->addCommandForCombinedOutput($commandStructure)
            ->addCommandForCombinedOutput($commandData);

        // Readd compression pipe
        if (!empty($commandPipeList)) {
            $command->setPipeList($commandPipeList);
        }

        return $command;
    }

}
