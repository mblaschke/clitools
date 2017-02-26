<?php

namespace CliTools\Console\Command\System;

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

use CliTools\Utility\FormatUtility;
use CliTools\Utility\UnixUtility;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BannerCommand extends \CliTools\Console\Command\AbstractCommand implements
    \CliTools\Console\Filter\OnlyRootFilterInterface
{

    /**
     * Enable automatic terminal title
     *
     * @var bool
     */
    protected $automaticTerminalTitle = false;

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName('system:banner')
             ->setDescription('Banner generator for /etc/issue');
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
        $clearScreen = "\033[H" . "\033[2J";
        $normalFont  = "\033[0m";

        $banner = $clearScreen;
        $banner .= $this->generateBannerHeader();
        $banner .= "\n";
        $banner .= "\n";
        $banner .= $this->generateSystemInfo();
        $banner .= "\n";
        $banner .= "\n" . $normalFont;

        echo $banner;

        return 0;
    }

    /**
     * Generate banner header
     *
     * @return string
     */
    protected function generateBannerHeader()
    {
        // INFO: you can use figlet command for generating ascii-art-text

        $logo = '
    ____  _______    __________    ____  ____  __  __________   ________   _    ____  ___
   / __ \\/ ____/ |  / / ____/ /   / __ \\/ __ \\/  |/  / ____/ | / /_  __/  | |  / /  |/  /
  / / / / __/  | | / / __/ / /   / / / / /_/ / /|_/ / __/ /  |/ / / /     | | / / /|_/ /
 / /_/ / /___  | |/ / /___/ /___/ /_/ / ____/ /  / / /___/ /|  / / /      | |/ / /  / /
/_____/_____/  |___/_____/_____/\\____/_/   /_/  /_/_____/_/ |_/ /_/       |___/_/  /_/

    ';

        $subline = '        Development VM :: ' . UnixUtility::lsbSystemDescription();

        // add color
        $lines = explode("\n", $logo);
        foreach ($lines as &$line) {
            $line = "\033[1;32m" . $line;
        }
        $logo = implode("\n", $lines);

        $ret = $logo . "\n\033[1;35m" . $subline;

        return $ret;
    }

    /**
     * Generate system info
     *
     * @return string
     */
    protected function generateSystemInfo()
    {
        $ret = array();

        $leftCol  = array();
        $rightCol = array();

        // ##################
        // Left: System info
        // ##################
        $labelLength  = 12;
        $bytesPadding = 10;

        $cpuCount      = UnixUtility::cpuCount();
        $memSize       = FormatUtility::bytes(UnixUtility::memorySize());
        $kernelVersion = UnixUtility::kernelVersion();
        $dockerVersion = UnixUtility::dockerVersion();
        $mountInfoList = UnixUtility::mountInfoList();
        $mailCount     = $this->getMailCount();

        // Padding
        $memSize = str_pad($memSize, $bytesPadding, ' ', STR_PAD_LEFT);

        // Basic sys informations
        $leftCol[] = str_pad('Linux', $labelLength, ' ', STR_PAD_LEFT) . ': ' . $kernelVersion;
        if (!empty($dockerVersion)) {
            $leftCol[] = str_pad('Docker', $labelLength, ' ', STR_PAD_LEFT) . ': ' . $dockerVersion;
        }
        $leftCol[] = str_pad('CPU', $labelLength, ' ', STR_PAD_LEFT) . ': ' . $cpuCount . ' Cores';
        $leftCol[] = str_pad('Memory', $labelLength, ' ', STR_PAD_LEFT) . ': ' . $memSize;

        // Mount info list
        foreach ($mountInfoList as $mount => $stats) {
            $capacity = FormatUtility::bytes($stats['capacity']);
            $usage    = $stats['usage'];

            if ($mount === '/') {
                $mount = 'root';
            }

            // padding
            $mount    = str_pad($mount, $labelLength, ' ', STR_PAD_LEFT);
            $capacity = str_pad($capacity, $bytesPadding, ' ', STR_PAD_LEFT);

            $leftCol[] = $mount . ': ' . $capacity . ' (' . $usage . ' in use)';
        }

        // ##################
        // Right: Network interfaces
        // ##################
        $labelLength = 6;

        // Network list (but not docker interfaces)
        $netInterfaceList = UnixUtility::networkInterfaceList('/^((?!docker).)*$/i');
        foreach ($netInterfaceList as $netName => $netConf) {
            $netName = str_pad($netName, $labelLength, ' ', STR_PAD_LEFT);

            $rightCol[] = str_pad($netName, $labelLength, ' ', STR_PAD_LEFT) . ': ' . $netConf['ipaddress'];
        }

        $rightCol[] = '';

        if ($mailCount !== null) {
            $rightCol[] = str_pad('Mails', $labelLength, ' ', STR_PAD_LEFT) . ': ' . $mailCount;
        }

        // ##################
        // Build output
        // ##################

        // Get max number of rows
        $maxLines = max(count($leftCol), count($rightCol));

        $colLeftWidth  = 54;
        $colRightWidth = 30;

        for ($i = 0; $i < $maxLines; $i++) {
            $leftColLine  = '';
            $rightColLine = '';

            // Get col-cell if available
            if (isset($leftCol[$i])) {
                $leftColLine = $leftCol[$i];
            }

            // Get col-cell if available
            if (isset($rightCol[$i])) {
                $rightColLine = $rightCol[$i];
            }

            // Fill with required length
            $leftColLine  = str_pad($leftColLine, $colLeftWidth, ' ', STR_PAD_RIGHT);
            $rightColLine = str_pad($rightColLine, $colRightWidth, ' ', STR_PAD_RIGHT);

            // Fix max length
            $leftColLine  = substr($leftColLine, 0, $colLeftWidth);
            $rightColLine = substr($rightColLine, 0, $colRightWidth);

            // Build table row
            $ret[] = $leftColLine . $rightColLine;
        }

        return implode("\n", $ret);
    }

    /**
     * Get mail count
     */
    protected function getMailCount()
    {
        $ret = null;
        $mailboxUri = $this->getApplication()->getConfigValue('banner', 'mailbox');

        if (!empty($mailboxUri) && function_exists('imap_open')) {
            try {
                $mailboxConf = parse_url($mailboxUri);

                $hostname = $this->buildMailboxServerString($mailboxConf);
                $username = $mailboxConf['user'];
                $password = $mailboxConf['pass'];

                $mail = @imap_open($hostname, $username, $password);
                $res = imap_check($mail);
                imap_close($mail);

                // reset imap errors
                imap_errors();
                imap_alerts();

                if (!empty($res)) {
                    $ret = $res->Nmsgs;
                } else {
                    throw new \RuntimeException('Mailcheck failed');
                }
            } catch (\Exception $e) {
                $ret = 'error';
            }
        }

        return $ret;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output); // TODO: Change the autogenerated stub
    }

    /**
     * Build imap connection string
     *
     * @param array $mailboxConf Mailbox configuration
     * @return string
     */
    protected function buildMailboxServerString(array $mailboxConf)
    {
        $hostname = $mailboxConf['host'];
        $path     = ltrim($mailboxConf['path'],'/');

        switch ($mailboxConf['scheme']) {
            case 'imap-insecure':
                $port = !empty($mailboxConf['port']) ? $mailboxConf['port'] : 143;

                $ret = sprintf(
                    '{%s:%s/novalidate-cert/norsh}%s',
                    $hostname,
                    $port,
                    $path
                );
                break;

            case 'imap':
                $port = !empty($mailboxConf['port']) ? $mailboxConf['port'] : 143;

                $ret = sprintf(
                    '{%s:%s}%s',
                    $hostname,
                    $port,
                    $path
                );

                break;

            case 'imap':
                $port = !empty($mailboxConf['port']) ? $mailboxConf['port'] : 993;

                $ret = sprintf(
                    '{%s:%s/imap/ssl}%s',
                    $hostname,
                    $port,
                    $path
                );
                break;


            case 'pop3':
                $port = !empty($mailboxConf['port']) ? $mailboxConf['port'] : 110;

                $ret = sprintf(
                    '{%s:%s/pop3}%s',
                    $hostname,
                    $port,
                    $path
                );
                break;

            default:
                throw new \RuntimeException('Mailbox scheme "' . $mailboxConf['scheme'] . '"" is not supported');
                break;
        }

        return $ret;
    }

    /**
     * Build imap connection options
     *
     * @param array $mailboxConf Mailbox configuration
     * @return integer
     */
    protected function buildMailboxServerOptions(array $mailboxConf)
    {
        $ret = 0;

        switch ($mailboxConf['scheme']) {
            case 'imap-insecure':
                break;
        }

        return $ret;
    }
}

