<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// One test boots the kernel without debug mode, and such a kernel never notices
// changed code. Start every run from a fresh cache so it cannot serve stale services.
(new Filesystem())->remove(dirname(__DIR__).'/var/cache/test');
