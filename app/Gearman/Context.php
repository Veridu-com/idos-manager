<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */
declare(strict_types=1);

namespace App\Gearman;

use App\Async\Handler;
use App\ThreadSafe\Logger;

/**
 * Handles Thread's context.
 */
class Context extends \Worker {
    /**
     * Thread-safe Logger instance.
     *
     * @var App\ThreadSafe\Logger
     */
    private $logger;
    /**
     * General settings.
     *
     * @var array
     */
    private $config;
    /**
     * Thread-local instance of GearmanWorker.
     *
     * @var \GearmanWorker
     */
    private static $gearman;

    /**
     * Class constructor.
     *
     * @param App\ThreadSafe\Logger $logger
     * @param array $config
     *
     * @return void
     */
    public function __construct(Logger $logger = null, array $config = []) {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Worker's run function.
     *
     * Ensures autoloading works on thread's context.
     *
     * @return void
     */
    public function run() {
        require __DIR__ . '/../../vendor/autoload.php';

        if (! empty($this->config['servers'])) {
            $this->logger->debug('Gearman setup start');

            self::$gearman = new \GearmanWorker();
            foreach ($this->config['servers'] as $server) {
                if (is_string($server)) {
                    $this->logger->debug(sprintf('Adding Gearman server %s', $server));
                    self::$gearman->addServer($server);
                } else {
                    $this->logger->debug(sprintf('Adding Gearman server %s:%d', $server[0], $server[1]));
                    self::$gearman->addServer($server[0], $server[1]);
                }
            }

            // Run the worker in non-blocking mode
            self::$gearman->addOptions(\GEARMAN_WORKER_NON_BLOCKING);

            // 5 second I/O Timeout
            self::$gearman->setTimeout(1000);

            $this->logger->debug('Gearman setup complete');
        }
    }

    /**
     * Returns the Logger instance.
     *
     * @return App\ThreadSafe\Logger
     */
    public function getLogger() : Logger {
        return $this->logger;
    }

    /**
     * Returns a static instance of GearmanWorker
     *
     * @return \GearmanWorker
     */
    public function getGearman() : \GearmanWorker {
        return self::$gearman;
    }
}
