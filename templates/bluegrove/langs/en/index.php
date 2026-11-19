<?php
declare(strict_types=1);

/** Origin nginx maps /en/ → langs/en/index.php. Router 301s EN to the unprefixed URL. */
require dirname(__DIR__, 2).'/index.php';
