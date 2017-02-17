<?php

namespace CliTools\Console\Command\Mysql;

/*
 * CliTools Command
 * Copyright (C) 2016 WebDevOps.io
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

use CliTools\Database\DatabaseConnection;
use CliTools\Shell\CommandBuilder\DockerExecCommandBuilder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SlowLogCommand extends AbstractCommand
{

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName('mysql:slowlog')
             ->setDescription('Enable and show slow query log')
             ->addArgument(
                 'grep',
                 InputArgument::OPTIONAL,
                 'Grep'
             )
             ->addOption(
                 'time',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Slow query time (default 1 second)'
             )
             ->addOption(
                 'no-index',
                 'i',
                 InputOption::VALUE_NONE,
                 'Enable log queries without indexes log'
             );
        parent::configure();
    }

    /**
     * Execute command
     *
     * @param  InputInterface  $input  Input instance
     * @param  OutputInterface $output Output instance
     *
     * @return int|null|void
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $slowLogQueryTime     = 1;
        $logNonIndexedQueries = false;

        // Slow log threshold
        if ($input->getOption('time')) {
            $slowLogQueryTime = $input->getOption('time');
        }

        // Also show not using indexes queries
        if ($input->getOption('no-index')) {
            $logNonIndexedQueries = true;
        }

        $debugLogLocation = $this->getApplication()
                                 ->getConfigValue('db', 'debug_log_dir', '/tmp');
        $debugLogDir      = dirname($debugLogLocation);

        $output->writeln('<h2>Starting MySQL slow query log</h2>');

        // Create directory if not exists
        if (!is_dir($debugLogDir)) {
            if (!mkdir($debugLogDir, 0777, true)) {
                $output->writeln('<p-error>Could not create "' . $debugLogDir . '" directory</p-error>');
                throw new \CliTools\Exception\StopException(1);
            }
        }

        $debugLogLocation .= 'mysql_' . getmypid() . '.log';
        $query = 'SET GLOBAL slow_query_log_file = ' . $this->mysqlQuote($debugLogLocation);
        $this->execSqlCommand($query);

        // Enable slow log
        $output->writeln('<p>Enabling slow log</p>');
        $query = 'SET GLOBAL slow_query_log = \'ON\'';
        $this->execSqlCommand($query);

        // Enable slow log
        $output->writeln('<p>Set long_query_time to ' . (int)abs($slowLogQueryTime) . ' seconds</p>');
        $query = 'SET GLOBAL long_query_time = ' . (int)abs($slowLogQueryTime);
        $this->execSqlCommand($query);

        // Enable log queries without indexes log
        if ($logNonIndexedQueries) {
            $output->writeln('<p>Enabling logging of queries without using indexes</p>');
            $query = 'SET GLOBAL log_queries_not_using_indexes = \'ON\'';
            $this->execSqlCommand($query);
        } else {
            $output->writeln('<p>Disabling logging of queries without using indexes</p>');
            $query = 'SET GLOBAL log_queries_not_using_indexes = \'OFF\'';
            $this->execSqlCommand($query);
        }

        // Setup teardown cleanup
        $tearDownFunc = function () use ($output, $logNonIndexedQueries) {
            // Disable general log
            $output->writeln('<p>Disable slow log</p>');
            $query = 'SET GLOBAL slow_query_log = \'OFF\'';
            $this->execSqlCommand($query);

            if ($logNonIndexedQueries) {
                // Disable log queries without indexes log
                $query = 'SET GLOBAL log_queries_not_using_indexes = \'OFF\'';
                $this->execSqlCommand($query);
            }
        };
        $this->getApplication()
             ->registerTearDown($tearDownFunc);

        // Read grep value
        $grep = null;
        if ($input->hasArgument('grep')) {
            $grep = $input->getArgument('grep');
        }

        // Tail logfile
        $logList = array(
            $debugLogLocation,
        );

        $optionList = array(
            '-n 0',
        );

        if ($this->input->getOption('docker-compose') || $this->input->getOption('docker')) {
            $command = new DockerExecCommandBuilder('tail', ['-f']);
            $command->setDockerContainer($this->dockerContainer);
            $command->addArgumentList($logList);
            $command->executeInteractive();
        } else {
            $this->showLog($logList, $input, $output, $grep, $optionList);
        }

        return 0;
    }
}
