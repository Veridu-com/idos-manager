<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */

declare(strict_types = 1);

namespace App;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command definition for Daemon.
 */
class ThreadDaemon extends Command {
    /**
     * Command configuration.
     *
     * @return void
     */
    protected function configure() {
        $this
            ->setName('daemon:thread')
            ->setDescription('idOS Manager - Thread-based Daemon [outdated!]')
            ->addOption(
                'devMode',
                'd',
                InputOption::VALUE_NONE,
                'Development mode'
            )
            ->addArgument(
                'poolSize',
                InputArgument::OPTIONAL,
                'Number of Threads in Pool (default: 10 threads; min: 1; max: 100)'
            )
            ->addArgument(
                'functionName',
                InputArgument::OPTIONAL,
                'Gearman Worker Function name (default: idos-delivery)'
            );
    }

    /**
     * Command execution.
     *
     * @param Symfony\Component\Console\Input\InputInterface   $input
     * @param Symfony\Component\Console\Output\OutputInterface $outpput
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $logFile = $input->getOption('logFile') ?? 'php://stdout';
        $logger  = new ThreadSafe\Logger($logFile);
        $config  = [
            'servers' => [
                ['172.17.0.2', 4730]
            ]
        ];

        $logger->debug('Initializing idOS Manager Daemon..');

        // Thread Pool size setup
        $poolSize = $input->getArgument('poolSize');
        if ((empty($poolSize)) || (! is_numeric($poolSize))) {
            $poolSize = 10;
        }

        $poolSize = max(1, $poolSize);
        $poolSize = min($poolSize, 100);

        $logger->debug(sprintf('Pool Size: %d', $poolSize));

        // Gearman Worker function name setup
        $functionName = $input->getArgument('functionName');
        if ((empty($functionName)) || (! preg_match('/^[a-zA-Z_-]+$/', $functionName))) {
            $functionName = 'idos-delivery';
        }

        $logger->debug(sprintf('Function Name: %s', $functionName));

        // Development mode
        $devMode = ! empty($input->getOption('devMode'));
        if ($devMode) {
            $logger->debug('Running in developer mode');
        }

        $threadPool = new \Pool($poolSize, Gearman\Context::class, [$logger, $config]);

        $logger->debug('Starting pool..');

        for ($i = 1; $i <= $poolSize; $i++) {
            $logger->debug(sprintf('Adding thread #%d', $i));

            $threadPool->submit(new Gearman\Thread($functionName, $devMode));
        }

        $logger->debug('Pool started');

        // while ($threadPool->collect(function ($thread) {
        //     return $thread->isGarbage();
        // }));

        $threadPool->shutdown();

        $logger->debug('All threads are done, shutting down the pool..');
    }
}
