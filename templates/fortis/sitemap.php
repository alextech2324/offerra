<?php
declare(strict_types=1);

/** Origin nginx maps /sitemap.xml → sitemap.php. Bounce into the front controller. */
require __DIR__.'/index.php';
