<?php

namespace CliTools\Utility;

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

use CliTools\Exception\CommandExecutionException;

/**
 * Class CommandExecutionUtility
 *
 * @package CliTools\Utility
 * @deprecated
 */
class CommandExecutionUtility {

    /**
     * Build raw command
     *
     * @param  string      $command           Command
     * @param  string|null $parameterTemplate Parameter Template
     * @param  array|null  $parameter         Parameter List
     *
     * @return string
     */
    public static function buildCommand($command, $parameterTemplate = null, $parameter = null) {
        // Escape command
        $execCommand = escapeshellcmd($command);

        // Escape args
        if ($parameter !== null && is_array($parameter) && count($parameter) >= 1) {
            // dynamic paramter
            $parameter = array_map('escapeshellarg', $parameter);

            // Just add parameter if template is empty
            if ($parameterTemplate === null) {
                $parameterTemplate = str_repeat('%s ', count($parameter));
            }

            $execCommand .= ' ' . vsprintf($parameterTemplate, $parameter);
        } elseif ($parameterTemplate !== null && $parameter === null) {
            // only template specified, use as static parameter
            $execCommand .= ' ' . $parameterTemplate;
        }

        return $execCommand;
    }


    /**
     * Build argument list as string
     *
     * @param  array  $parameter         Parameter List
     *
     * @return string
     */
    public static function buildArgumentString(array $parameter) {
        $parameter = array_map('escapeshellarg', $parameter);
        $ret = implode(' ', $parameter);
        return $ret;
    }

    /**
     * Exec raw command
     *
     * @param  string $command Command
     * @param  string $output  Output
     *
     * @return integer
     * @throws CommandExecutionException
     */
    public static function execRaw($command, &$output = null) {
        ConsoleUtility::verboseWriteln('EXEC::RAW', $command);

        exec($command, $output, $execStatus);

        if ($execStatus !== 0) {
            $e = new CommandExecutionException('Process ' . $command . ' did not finished successfully');
            $e->setReturnCode($execStatus);
            throw $e;
        }

        return $execStatus;
    }

    /**
     * Exec command
     *
     * @param  string      $command           Command
     * @param  string      $output            Output
     * @param  string|null $parameterTemplate Parameter Template
     * @param  array|null  $parameter         Parameter List
     *
     * @return integer
     * @throws CommandExecutionException
     */
    public static function exec($command, &$output, $parameterTemplate, $parameter = null) {
        $execCommand = self::buildCommand($command, $parameterTemplate, $parameter);

        ConsoleUtility::verboseWriteln('EXEC::EXEC', $execCommand);

        exec($execCommand, $output, $execStatus);

        if ($execStatus !== 0) {
            $e = new CommandExecutionException('Process ' . $execCommand . ' did not finished successfully');
            $e->setReturnCode($execStatus);
            throw $e;
        }

        return $execStatus;
    }

    /**
     * Execute command (via passthru)
     *
     * @param string      $command           Command
     * @param string|null $parameterTemplate Parameter Template
     * @param array|null  $parameter         Parameter List
     *
     * @return integer
     * @throws CommandExecutionException
     */
    public static function execInteractive($command, $parameterTemplate = null, $parameter = null) {
        $execCommand = self::buildCommand($command, $parameterTemplate, $parameter);

        ConsoleUtility::verboseWriteln('EXEC::INTERACTIVE', $execCommand);

        $descriptorSpec = array(
            0 => array('file', 'php://stdin',  'r'),  // stdin is a file that the child will read from
            1 => array('file', 'php://stdout', 'w'),  // stdout is a file that the child will write to
            2 => array('file', 'php://stderr', 'w')   // stderr is a file that the child will write to
        );

        $process = proc_open($execCommand, $descriptorSpec, $pipes);

        if (is_resource($process)) {
            $execStatus = proc_close($process);
            $execStatus = pcntl_wexitstatus($execStatus);

            if ($execStatus !== 0) {
                $e = new CommandExecutionException('Process ' . $execCommand . ' did not finished successfully [return code: ' . $execStatus . ']');
                $e->setReturnCode($execStatus);
                throw $e;
            }
        } else {
            $e = new CommandExecutionException('Process ' . $execCommand . ' could not be started');
            $e->setReturnCode(-1);
            throw $e;
        }

        return $execStatus;
    }
}
