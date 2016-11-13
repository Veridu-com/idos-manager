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
     * Command Execution.
     *
     * @param Symfony\Component\Console\Input\InputInterface   $input
     * @param Symfony\Component\Console\Output\OutputInterface $outpput
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $logger = new ThreadSafe\Logger();

        $logger->debug('Initializing idOS Manager Daemon..');

        // Gearman Worker function name setup
        $functionName = $input->getArgument('functionName');
        if ((empty($functionName)) || (! preg_match('/^[a-zA-Z0-9\._-]+$/', $functionName))) {
            $functionName = 'idos-manager';
        }

        $logger->debug(sprintf('Function Name: %s', $functionName));

        // Development mode
        $devMode = ! empty($input->getOption('devMode'));
        if ($devMode) {
            $logger->debug('Running in developer mode');
        }

        // Server List setup
        $servers = $input->getArgument('serverList');

        $gearman = new \GearmanWorker();
        foreach ($servers as $server) {
            if (strpos($server, ':') === false) {
                $logger->debug(sprintf('Adding Gearman Server: %s', $server));
                @$gearman->addServer($server);
            } else {
                $server    = explode(':', $server);
                $server[1] = intval($server[1]);
                $logger->debug(sprintf('Adding Gearman Server: %s:%d', $server[0], $server[1]));
                @$gearman->addServer($server[0], $server[1]);
            }
        }

        // Run the worker in non-blocking mode
        $gearman->addOptions(\GEARMAN_WORKER_NON_BLOCKING);

        // 5 second I/O Timeout
        $gearman->setTimeout(1000);

        $logger->debug('Registering Worker Function');

        $storage = [];
        $request = [];
        $stats   = [
            'first' => null,
            'last'  => null,
            'count' => 0
        ];

        // Register Thread's Worker Function
        $gearman->addFunction(
            $functionName,
            function (\GearmanJob $job) use ($logger, $devMode, &$storage, &$request, &$stats) {
                $logger->debug('Got a new job!');
                $jobData = json_decode($job->workload(), true);
                if ($jobData === null) {
                    $logger->debug('Invalid Job Workload!');
                    $job->sendComplete('invalid');

                    return;
                }

                if ($stats['first'] === null) {
                    $stats['first'] = microtime(true);
                }

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

                $stats['count']++;
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

                // $stream = new Async\Stream('190.98.170.59:80');
                // $stream->setId(1);
                // if ($stream->isOpen()) {
                //     $logger->debug('Async Stream Opened!');
                //     $handler->add($stream);
                //     $job->sendComplete('done');
                // } else {
                //     $logger->debug('Failed to open Async Stream!');
                //     // send job back to queue!
                //     $job->sendFail();
                // }
                $stats['last'] = microtime(true);
            }
        );

        $logger->debug('Registering Ping Function');

        // Register Thread's Ping Function
        $gearman->addFunction(
            'ping',
            function (\GearmanJob $job) use ($logger) {
                $logger->debug('Ping!');

                return 'pong';
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
                            $logger->debug('Stream Not Found!');
                        }

                        $data = fread($stream, 8192);
                        if (feof($stream)) {
                            $logger->debug('Stream EOF, closing..');
                            unset($storage[$index]);
                            fclose($stream);
                            if (count($storage) == 0) {
                                $stats['last'] = microtime(true);
                            }
                        } else {
                            $logger->debug(sprintf('Stream Received %d bytes', strlen($data)));
                        }
                    }
                } else {
                    // $logger->debug(sprintf('Time Spent: %.4f', $stats['last'] - $stats['first']));
                    // $logger->debug(sprintf('Job Count: %d', $stats['count']));
                }
            } while (count($storage) >= self::MAX_STREAMS);

            // if (count($handler)) {
            //     $read = [];
            //     $write = [];
            //     if (!$handler->check($read, $write)) {
            //         $logger->debug('Async Wait Failed!');
            //         // What should be done at this point..?!
            //     }

            //     foreach ($write as &$stream) {
            //         if ($stream->dataSent()) {
            //             continue;
            //         }

            //         $logger->debug('Sending Request..');
            //         $stream->writeToStream("GET / HTTP/1.0\r\nHost: localhost\r\n\r\n");
            //     }

            //     foreach ($read as &$stream) {
            //         $data = $stream->readFromStream();
            //         if (strlen($data) == 0) {
            //             // Stream's EOF
            //             $logger->debug('Stream EOF, closing..');
            //             $handler->del($stream);
            //             unset($stream);
            //         } else {
            //             $logger->debug('Stream Received %d bytes', strlen($data));
            //         }
            //     }
            // }

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
                    continue;
                }
            }
        }

        if ($gearman->returnCode() != \GEARMAN_SUCCESS) {
            $logger->error($gearman->error());
        }

        $logger->debug('Leaving Gearman Worker Loop');
    }
}
