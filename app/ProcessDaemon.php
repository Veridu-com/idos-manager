<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */
declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command definition for Process-based Daemon
 */
class ProcessDaemon extends Command {
    /**
     * Max number of open streams
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
            ->addArgument(
                'functionName',
                InputArgument::OPTIONAL,
                'Gearman Worker Function name (default: idos-delivery)'
            );
    }

    /**
     * Command Execution
     *
     * @param Symfony\Component\Console\Input\InputInterface $input
     * @param Symfony\Component\Console\Output\OutputInterface $outpput
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $logger = new ThreadSafe\Logger();
        $config = [
            'servers' => [
                ['172.17.0.2', 4730]
            ]
        ];

        $logger->debug('Initializing idOS Manager Daemon..');

        // Gearman Worker function name setup
        $functionName = $input->getArgument('functionName');
        if ((empty($functionName)) || (! preg_match('/^[a-zA-Z_-]+$/', $functionName))) {
            $functionName = 'idos-delivery';
        }

        $logger->debug(sprintf('Function Name: %s', $functionName));

        $gearman = new \GearmanWorker();
        foreach ($config['servers'] as $server) {
            if (is_string($server)) {
                $logger->debug(sprintf('Adding Gearman server %s', $server));
                $gearman->addServer($server);
            } else {
                $logger->debug(sprintf('Adding Gearman server %s:%d', $server[0], $server[1]));
                $gearman->addServer($server[0], $server[1]);
            }
        }

        // Run the worker in non-blocking mode
        $gearman->addOptions(\GEARMAN_WORKER_NON_BLOCKING);

        // 5 second I/O Timeout
        $gearman->setTimeout(1000);

        $logger->debug('Registering Worker Function');

        $storage = [];
        $request = [];
        $stats = [
            'first' => null,
            'last' => null,
            'count' => 0
        ];

        // Register Thread's Worker Function
        $gearman->addFunction($functionName, function (\GearmanJob $job) use ($logger, &$storage, &$stats) {
            if ($stats['first'] === null) {
                $stats['first'] = microtime(true);
            }
            $stats['count']++;
            // $logger->debug('Work!');
            $stream = stream_socket_client(
                // '190.98.170.59:80',
                '192.168.0.2:80',
                $errNum,
                $errStr,
                10,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
            );
            if ($stream) {
                // $logger->debug('Async Stream Opened!');
                $storage[] = $stream;
                $job->sendComplete('done');
            } else {
                $logger->debug('Failed to open Async Stream!');
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
        });

        $logger->debug('Registering Ping Function');

        // Register Thread's Ping Function
        $gearman->addFunction('ping', function (\GearmanJob $job) use ($logger) {
            $logger->debug('Ping!');
            return 'pong';
        });

        $logger->debug('Entering Gearman Worker Loop');

        // Gearman's Loop
        while ($gearman->work()
                || ($gearman->returnCode() == \GEARMAN_IO_WAIT)
                || ($gearman->returnCode() == \GEARMAN_NO_JOBS)
                || ($gearman->returnCode() == \GEARMAN_TIMEOUT)
        ) {
            $logger->debug(sprintf('Async Streams: %d', count($storage)));
            do {
                if (count($storage)) {
                    $read = $storage;
                    $write = $storage;
                    $except = null;
                    if (stream_select($read, $write, $except, 0) === false) {
                        $logger->debug('Async Wait Failed!');
                    }

                    // $logger->debug(sprintf('Write: %d', count($write)));
                    foreach ($write as $stream) {
                        $index = array_search($stream, $storage);
                        if ($index === false) {
                            // $logger->debug('Stream Not Found!');
                        }

                        if (! isset($request[$index])) {
                            // $logger->debug('Sending Request..');
                            fwrite($stream, "GET / HTTP/1.0\r\nHost: google.com\r\n\r\n");
                            $request[$index] = true;
                        }
                    }

                    // $logger->debug(sprintf('Read: %d', count($read)));
                    foreach ($read as $stream) {
                        $index = array_search($stream, $storage);
                        if ($index === false) {
                            // $logger->debug('Stream Not Found!');
                        }

                        $data = fread($stream, 8192);
                        if (strlen($data) == 0) {
                            // $logger->debug('Stream EOF, closing..');
                            unset($storage[$index]);
                            unset($request[$index]);
                            fclose($stream);
                            if (count($storage) == 0) {
                                $stats['last'] = microtime(true);
                            }
                        } else {
                            // $logger->debug(sprintf('Stream Received %d bytes', strlen($data)));
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

        $logger->debug('Leaving Gearman Worker Loop');
    }
}
