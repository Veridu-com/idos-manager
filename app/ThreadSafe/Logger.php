<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */
declare(strict_types=1);

namespace App\ThreadSafe;

use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;

/**
 * Handles Thread-Safe logging.
 */
class Logger extends \Threaded {
    /**
     * Monolog instance.
     *
     * @var \Monolog\Logger
     */
    private $logger;

    /**
     * Class constructor.
     *
     * @param string $stream
     * @param int $level
     *
     * @return void
     */
    public function __construct(string $stream = 'php://stdout', int $level = Monolog::DEBUG) {
        $this->logger = new Monolog('Manager');
        $this->logger->pushHandler(new StreamHandler($stream, $level));
    }

    /**
     * Synchronized function call
     *
     * @param string $name
     * @param array $arguments
     *
     * @return mixed|null
     */
    public function __call(string $name, array $arguments) {
        $this->synchronized(function ($thread, $name, $arguments) {
            // call_user_func_array([$thread->logger, $name], $arguments);
            echo $arguments[0], PHP_EOL;
        }, $this, $name, $arguments);
    }
}
