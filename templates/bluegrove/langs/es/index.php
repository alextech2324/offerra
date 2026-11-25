<?php
declare(strict_types=1);

/** Origin nginx maps /es/ → langs/es/index.php (multilang hub). Bounce into the front controller. */
require dirname(__DIR__, 2).'/index.php';
