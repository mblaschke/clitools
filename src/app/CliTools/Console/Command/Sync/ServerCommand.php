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
use Symfony\Component\Console\Input\InputArgument;

class ServerCommand extends AbstractSyncCommand {

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
                InputArgument::REQUIRED,
                'Configuration name for server'
            );
    }

    /**
     * Read and validate configuration
     */
    protected function readConfiguration() {
        parent::readConfiguration();

        $this->contextName = $this->input->getArgument('context');

        if (empty($this->contextName) || $this->contextName === '_' || empty($this->config[$this->contextName])) {
            throw new \RuntimeException('No valid configuration found for context "' . $this->contextName . '"');
        }

        // Use server specific configuration
        $this->output->writeln('<info>Syncing from "' . $this->contextName . '" server');

        $fullConfig = $this->config;

        if (!empty($fullConfig['_'])) {
            // Merge global config with specific config
            $this->config = array_replace_recursive($fullConfig['_'], $this->config[$this->contextName]);
        } else {
            $this->config = $this->config[$this->contextName];
        }
    }

    /**
     * Backup task
     */
    protected function runTask() {
        // Check database connection
        if (!empty($this->config['mysql'])) {
            DatabaseConnection::ping();
        }

        // Sync files with rsync to local storage
        if (!empty($this->config['rsync'])) {
            $this->runTaskRsync();
        }

        // Sync database to local server
        if (!empty($this->config['mysql'])) {
            // Full mysql transfer
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
        $mysqlConf = $this->config['mysql'];

        foreach ($mysqlConf['database'] as $databaseConf) {
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

            $dumpFile = $this->tempDir . '/' . $localDatabase . '.sql.bz2';

            // ##########
            // Dump from server
            // ##########
            $this->output->writeln('<info>Fetching foreign database "' . $foreignDatabase . '"</info>');

            $mysqldump = $this->createMySqlDumpCommand($foreignDatabase);

            if ($this->config['mysql']['filter']) {
                $mysqldump = $this->addFilterArguments($mysqldump, $foreignDatabase, $this->config['mysql']['filter']);
            }

            $command = $this->wrapCommand($mysqldump);
            $command->setOutputRedirectToFile($dumpFile);

            $command->executeInteractive();

            // ##########
            // Restore local
            // ##########
            $this->output->writeln('<info>Restoring database "' . $localDatabase . '"</info>');

            $this->createMysqlRestoreCommand($localDatabase, $dumpFile)->executeInteractive();
        }
    }

    /**
     * Create new mysql command
     *
     * @param null|string $database Database name
     *
     * @return RemoteCommandBuilder
     */
    protected function createMySqlCommand($database = null) {
        $command = new RemoteCommandBuilder('mysql');
        $command
              // batch mode
            ->addArgument('-B')
              // skip column names
            ->addArgument('-N');

        // Add username
        if (!empty($this->config['mysql']['username'])) {
            $command->addArgumentTemplate('-u%s', $this->config['mysql']['username']);
        }

        // Add password
        if (!empty($this->config['mysql']['password'])) {
            $command->addArgumentTemplate('-p%s', $this->config['mysql']['password']);
        }

        // Add hostname
        if (!empty($this->config['mysql']['hostname'])) {
            $command->addArgumentTemplate('-h%s', $this->config['mysql']['hostname']);
        }

        if ($database !== null) {
            $command->addArgument($database);
        }

        return $command;
    }

    /**
     * Create new mysql command
     *
     * @param null|string $database Database name
     *
     * @return RemoteCommandBuilder
     */
    protected function createMySqlDumpCommand($database = null) {
        $command = new RemoteCommandBuilder('mysqldump');


        // Add username
        if (!empty($this->config['mysql']['username'])) {
            $command->addArgumentTemplate('-u%s', $this->config['mysql']['username']);
        }

        // Add password
        if (!empty($this->config['mysql']['password'])) {
            $command->addArgumentTemplate('-p%s', $this->config['mysql']['password']);
        }

        // Add hostname
        if (!empty($this->config['mysql']['hostname'])) {
            $command->addArgumentTemplate('-h%s', $this->config['mysql']['hostname']);
        }

        // Add custom options
        if (!empty($this->config['mysqldump']['option'])) {
            $command->addArgumentRaw($this->config['mysqldump']['option']);
        }

        // Add pipe compressor (bzip2 compressed transfer via ssh)
        $command->addPipeCommand( new CommandBuilder('bzip2', '--compress --stdout') );

        if ($database !== null) {
            $command->addArgument($database);
        }

        return $command;
    }

    /**
     * Wrap command with ssh if needed
     *
     * @param  CommandBuilderInterface $command
     * @return CommandBuilderInterface
     */
    protected function wrapCommand(CommandBuilderInterface $command) {
        // Wrap in ssh if needed
        if (!empty($this->config['ssh']['hostname'])) {
            $sshCommand = new CommandBuilder('ssh', '-o BatchMode=yes');
            $sshCommand->addArgument($this->config['ssh']['hostname'])
                ->append($command, true);

            $command = $sshCommand;
        }

        return $command;
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
        if (!$filelist && !empty($this->config['rsync']['directory'])) {
            $filelist = $this->config['rsync']['directory'];
        }

        // Add exclude (external file with --exclude-from option)
        if (!$exclude && !empty($this->config['rsync']['exclude'])) {
            $exclude = $this->config['rsync']['exclude'];
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
        $filterList = $this->getApplication()->getConfigValue('mysql-backup-filter', $filter);

        if (empty($filterList)) {
            throw new \RuntimeException('MySQL dump filters "' . $filter . '" not available"');
        }

        $this->output->writeln('<comment>Using filter "' . $filter . '"</comment>');

        // Get table list (from cloned mysqldump command)
        $tableListDumper = $this->createMySqlCommand($database);
        $tableListDumper->addArgumentTemplate('-e %s', 'show tables;');

        $tableListDumper = $this->wrapCommand($tableListDumper);
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
