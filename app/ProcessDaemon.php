<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */

declare(strict_types = 1);

namespace App;

use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\UidProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command definition for Process-based Daemon.
 */
class ProcessDaemon extends Command {
    /**
     * Max number of open streams.
     *
     * @const MAX_STREAMS
     */
    const MAX_STREAMS = 500;

    /**
     * Command Configuration.
     *
     * @return void
     */
    protected function configure() {
        $this
            ->setName('daemon:process')
            ->setDescription('idOS Manager - Process-based Daemon')
            ->addOption(
                'devMode',
                'd',
                InputOption::VALUE_NONE,
                'Development mode'
            )
            ->addOption(
                'healthCheck',
                'h',
                InputOption::VALUE_NONE,
                'Enable queue health check'
            )
            ->addOption(
                'logFile',
                'l',
                InputOption::VALUE_REQUIRED,
                'Path to log file'
            )
            ->addArgument(
                'functionName',
                InputArgument::REQUIRED,
                'Gearman Worker Function name'
            )
            ->addArgument(
                'serverList',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'Gearman server host list (separate values by space)'
            );
    }

    /**
     * Command execution.
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $outpput
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $logFile = $input->getOption('logFile') ?? 'php://stdout';
        $logger  = new Monolog('Manager');
        $logger
            ->pushProcessor(new ProcessIdProcessor())
            ->pushProcessor(new UidProcessor())
            ->pushHandler(new StreamHandler($logFile, Monolog::DEBUG));

        $logger->debug('Initializing idOS Manager Daemon..');

        $bootTime = time();

        // Development mode
        $devMode = ! empty($input->getOption('devMode'));
        if ($devMode) {
            $logger->debug('Running in developer mode');
            ini_set('display_errors', 'On');
            error_reporting(-1);
        }

        // Health check
        $healthCheck = ! empty($input->getOption('healthCheck'));
        if ($healthCheck) {
            $logger->debug('Enabling health check');
        }

        // Gearman Worker function name setup
        $functionName = $input->getArgument('functionName');
        if ((empty($functionName)) || (! preg_match('/^[a-zA-Z0-9\._-]+$/', $functionName))) {
            $functionName = 'manager';
        }

        $logger->debug(sprintf('Function Name: %s', $functionName));

        // Server List setup
        $servers = $input->getArgument('serverList');

        $gearman = new \GearmanWorker();
        foreach ($servers as $server) {
            if (strpos($server, ':') === false) {
                $logger->debug(sprintf('Adding Gearman Server: %s', $server));
                @$gearman->addServer($server);
                continue;
            }

            $server    = explode(':', $server);
            $server[1] = intval($server[1]);
            $logger->debug(sprintf('Adding Gearman Server: %s:%d', $server[0], $server[1]));
            @$gearman->addServer($server[0], $server[1]);
        }

        // Run the worker in non-blocking mode
        $gearman->addOptions(\GEARMAN_WORKER_NON_BLOCKING);

        // 1 second I/O timeout
        $gearman->setTimeout(1000);

        $logger->debug('Registering Worker Function', ['function' => $functionName]);

        $storage = [];
        $request = [];

        $jobCount = 0;
        $lastJob  = 0;

        // Register Thread's Worker Function
        $gearman->addFunction(
            $functionName,
            function (\GearmanJob $job) use ($logger, $devMode, &$storage, &$request, &$jobCount, &$lastJob) {
                $logger->debug('Got a new job!');
                $jobData = json_decode($job->workload(), true);
                if ($jobData === null) {
                    $logger->debug('Invalid Job Workload!');
                    $job->sendComplete('invalid');

                    return;
                }

                $jobCount++;

                $url = parse_url($jobData['url']);

                if (! isset($url['port'])) {
                    $url['port'] = 443;
                }

                $host = sprintf('ssl://%s:%d', $url['host'], $url['port']);

                $uri = '/';
                if (isset($url['path'])) {
                    $uri = $url['path'];
                }

                if (isset($url['query'])) {
                    $uri = sprintf('%s?%s', $uri, $url['query']);
                }

                if (isset($url['fragment'])) {
                    $uri = sprintf('%s#%s', $uri, $url['fragment']);
                }

                $logger->info(sprintf('Host: %s', $host));
                $logger->info(sprintf('Path: %s', $uri));

                $authorization = base64_encode(sprintf('%s:%s', $jobData['user'], $jobData['pass']));

                $body = json_encode($jobData['handler']);
                $header = implode(
                    "\r\n",
                    [
                        sprintf('POST %s HTTP/1.1', $uri),
                        'Accept-Language: en-US,en;q=0.8',
                        'Upgrade-Insecure-Requests: 1',
                        'User-Agent: idOS-Manager/1.0',
                        'Accept: application/json;q=0.9,*/*;q=0.8',
                        'Accept-Encoding: gzip, deflate, sdch, br',
                        sprintf('Authorization: Basic %s', $authorization),
                        sprintf('Host: %s', $url['host']),
                        'Connection: close',
                        sprintf('Content-Length: %d', strlen($body)),
                        'Content-Type: application/json; charset=utf-8',
                        'Cache-Control: no-store,no-cache'
                    ]
                );

                $context = stream_context_create();
                // development mode: disable ssl check
                if ($devMode) {
                    stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
                    stream_context_set_option($context, 'ssl', 'verify_peer', false);
                    stream_context_set_option($context, 'ssl', 'verify_peer_name', false);
                }

                // FIXME loop while ($errNum === 115) + timeout control
                $stream = stream_socket_client(
                    $host,
                    $errNum,
                    $errStr,
                    10,
                    STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
                    $context
                );
                if ($stream) {
                    $logger->debug('Async Stream Opened!');
                    $storage[] = $stream;
                    $request[] = implode(
                        "\r\n",
                        [
                            $header,
                            '',
                            $body
                        ]
                    );
                    $job->sendComplete('done');
                } else {
                    $logger->debug('Failed to open Async Stream!');
                    $logger->error($errStr);
                    $job->sendFail();
                }

                $lastJob = time();
            }
        );

        $logger->debug('Entering Gearman Worker Loop');

        // Gearman's Loop
        while (@$gearman->work()
                || ($gearman->returnCode() == \GEARMAN_IO_WAIT)
                || ($gearman->returnCode() == \GEARMAN_NO_JOBS)
                || ($gearman->returnCode() == \GEARMAN_TIMEOUT)
        ) {
            do {
                if (count($storage)) {
                    $logger->debug(sprintf('Async Streams: %d', count($storage)));
                    $read   = $storage;
                    $write  = $storage;
                    $except = null;
                    if (stream_select($read, $write, $except, 0) === false) {
                        $logger->debug('Async Wait Failed!');
                    }

                    $logger->debug(sprintf('Write: %d streams', count($write)));
                    foreach ($write as $stream) {
                        $index = array_search($stream, $storage);
                        if ($index === false) {
                            $logger->debug('Stream Not Found!');
                        }

                        if (isset($request[$index])) {
                            $logger->debug('Sending Request..');
                            fwrite($stream, $request[$index]);
                            $logger->debug(sprintf('Stream Sent %d bytes', strlen($request[$index])));
                            unset($request[$index]);
                        }
                    }

                    $logger->debug(sprintf('Read: %d streams', count($read)));
                    foreach ($read as $stream) {
                        $index = array_search($stream, $storage);
                        if ($index === false) {
                            $logger->error('Stream Not Found!');
                        }

                        $data = fread($stream, 8192);
                        if (feof($stream)) {
                            $logger->debug('Stream EOF, closing..');
                            unset($storage[$index]);
                            fclose($stream);
                        } else {
                            $logger->debug(sprintf('Stream Received %d bytes', strlen($data)));
                        }
                    }
                }
            } while (count($storage) >= self::MAX_STREAMS);

            if ($gearman->returnCode() == \GEARMAN_SUCCESS) {
                continue;
            }

            if (! @$gearman->wait()) {
                if ($gearman->returnCode() == \GEARMAN_NO_ACTIVE_FDS) {
                    // No server connection, sleep before reconnect
                    $logger->debug('No active server, sleep before retry');
                    sleep(5);
                    continue;
                }

                if ($gearman->returnCode() == \GEARMAN_TIMEOUT) {
                    // Job wait timeout, sleep before retry
                    sleep(1);
                    if (! @$gearman->echo('ping')) {
                        $logger->debug('Invalid server state, restarting');
                        exit;
                    }

                    if (($healthCheck) && ((time() - $bootTime) > 10) && ((time() - $lastJob) > 10)) {
                        $logger->info(
                            'Inactivity detected, restarting',
                            [
                                'runtime' => time() - $bootTime,
                                'jobs'    => $jobCount
                            ]
                        );
                        exit;
                    }

                    continue;
                }
            }
        }

        if ($gearman->returnCode() != \GEARMAN_SUCCESS) {
            $logger->error($gearman->error());
        }

        $logger->debug('Leaving Gearman Worker Loop', ['runtime' => time() - $bootTime, 'jobs' => $jobCount]);
    }
}
