#!/usr/bin/env php
<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */

require_once __DIR__ . '/vendor/autoload.php';

date_default_timezone_set('UTC');
setlocale(LC_ALL, 'en_US.UTF8');
mb_http_output('UTF-8');
mb_internal_encoding('UTF-8');

$application = new Symfony\Component\Console\Application();
$application->add(new App\ProcessDaemon());
$application->add(new App\ThreadDaemon());
$application->run();
