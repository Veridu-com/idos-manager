<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */
declare(strict_types = 1);

namespace App\Gearman;

use App\Async;

/**
 * Registers a Thread as a Worker on Gearman Daemon.
 */
class Thread extends \Threaded {
    /**
     * Max number of open streams.
     *
     * @const MAX_STREAMS
     */
    const MAX_STREAMS = 500;
    /**
     * Worker Function Name.
     *
     * @var string
     */
    private $functionName;
    /**
     * Development Mode.
     *
     * @var bool
     */
    private $devMode;

    /**
     * Class constructor.
     *
     * @param string $functionName
     *
     * @return void
     */
    public function __construct(string $functionName, bool $devMode) {
        $this->functionName = $functionName;
        $this->devMode      = $devMode;
    }

    /**
     * Thread's run function.
     *
     * @return void
     */
    public function run() {
        $logger   = $this->worker->getLogger();
        $gearman  = $this->worker->getGearman();
        $threadId = $this->worker->getThreadId();
        $storage  = [];
        $request  = [];
        $stats    = [
            'first' => null,
            'last'  => null,
            'count' => 0
        ];

        $logger->debug(sprintf('[%lu] Registering Worker Function', $threadId));

        // Register Thread's Worker Function
        $gearman->addFunction(
            $this->functionName, function (\GearmanJob $job) use ($logger, $threadId, &$storage, &$stats) {
                if ($stats['first'] === null) {
                    $stats['first'] = microtime(true);
                }

                $stats['count']++;
                $logger->debug(sprintf('[%lu] Work!', $threadId));
                $stream = stream_socket_client(
                    '190.98.170.59:80',
                    // '192.168.0.2:80',
                    $errNum,
                    $errStr,
                    10,
                    STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
                );
                if ($stream) {
                    $logger->debug('Async Stream Opened!');
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
            }
        );

        $logger->debug(sprintf('[%lu] Registering Ping Function', $threadId));

        // Register Thread's Ping Function
        $gearman->addFunction(
            'ping', function (\GearmanJob $job) use ($logger, $threadId) {
                $logger->debug(sprintf('[%lu] Ping!', $threadId));

                return 'pong';
            }
        );

        $logger->debug(sprintf('[%lu] Entering Gearman Worker Loop', $threadId));

        // Gearman's Loop
        while ($gearman->work()
                || ($gearman->returnCode() == \GEARMAN_IO_WAIT)
                || ($gearman->returnCode() == \GEARMAN_NO_JOBS)
                || ($gearman->returnCode() == \GEARMAN_TIMEOUT)
        ) {
            $logger->debug(sprintf('Async Streams: %d', count($storage)));
            do {
                if (count($storage)) {
                    $read  = $storage;
                    $write = $storage;
                    // $read = [];
                    // $write = [];
                    // foreach ($storage as $stream) {
                    //     $read[] = $stream;
                    //     $write[] = $stream;
                    // }
                    $except = null;
                    if (stream_select($read, $write, $except, 0) === false) {
                        $logger->debug('Async Wait Failed!');
                    }

                    $logger->debug(sprintf('Write: %d', count($write)));
                    foreach ($write as $stream) {
                        $index = array_search($stream, $storage);
                        if ($index === false) {
                            $logger->debug('Stream Not Found!');
                        }

                        if (! isset($request[$index])) {
                            $logger->debug('Sending Request..');
                            fwrite($stream, "GET / HTTP/1.0\r\nHost: google.com\r\n\r\n");
                            $request[$index] = true;
                        }
                    }

                    $logger->debug(sprintf('Read: %d', count($read)));
                    foreach ($read as $stream) {
                        $index = array_search($stream, $storage);
                        if ($index === false) {
                            $logger->debug('Stream Not Found!');
                        }

                        $data = fread($stream, 8192);
                        if (strlen($data) == 0) {
                            $logger->debug('Stream EOF, closing..');
                            unset($storage[$index]);
                            unset($request[$index]);
                            fclose($stream);
                            if (count($storage) == 0) {
                                $stats['last'] = microtime(true);
                            }
                        } else {
                            $logger->debug(sprintf('Stream Received %d bytes', strlen($data)));
                        }
                    }
                } else {
                    $logger->debug(sprintf('Time Spent: %.4f', $stats['last'] - $stats['first']));
                    $logger->debug(sprintf('Job Count: %d', $stats['count']));
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
                    $logger->debug(sprintf('[%lu] No active server, sleep before retry', $threadId));
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

        $logger->debug(sprintf('[%lu] Leaving Gearman Worker Loop', $threadId));
    }
}
