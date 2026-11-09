<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class TemplatePhpSyntaxTest extends TestCase
{
    public function test_lithuanian_template_php_parses(): void
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'templates';
        $broken = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (! preg_match('/[\/\\\\]langs[\/\\\\]lt[\/\\\\]/', $path)) {
                continue;
            }

            $src = file_get_contents($path);
            if ($src === false) {
                $broken[] = str_replace($root.DIRECTORY_SEPARATOR, '', $path).': unreadable';

                continue;
            }

            try {
                token_get_all($src, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $rel = str_replace($root.DIRECTORY_SEPARATOR, '', $path);
                $broken[] = $rel.':'.$e->getLine().' '.$e->getMessage();
            }
        }

        $this->assertSame([], $broken);
    }
}
