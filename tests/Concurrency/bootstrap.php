<?php

/*
 * PHPUnit bootstrap for `phpunit.mysql.xml`: the isolated MySQL environment
 * first, before anything can read `.env`, then the autoloader.
 */

require __DIR__.'/environment.php';
require dirname(__DIR__, 2).'/vendor/autoload.php';
