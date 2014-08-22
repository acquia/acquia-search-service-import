<?php

namespace Acquia\Search\Import;

// Try to find the appropriate autoloader.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
  require __DIR__ . '/../vendor/autoload.php';
} elseif (__DIR__ . '/../../../autoload.php') {
  require __DIR__ . '/../../../autoload.php';
}

use Symfony\Component\Console\Application;
use Acquia\Search\Import\Command\ImportCommand;

$application = new Application();
$application->add(new ImportCommand());
$application->run();