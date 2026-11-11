<?php

namespace Tests\Unit;

use Tests\TestCase;

class OriginLangNginxTest extends TestCase
{
    public function test_ubuntu_bootstrap_uses_named_lang_captures(): void
    {
        $src = (string) file_get_contents(base_path('server/bootstrap-origin.sh'));

        $this->assertStringContainsString('offerra_lang_index', $src);
        $this->assertStringContainsString('(?<offerra_lang>[a-z][a-z])', $src);
        $this->assertStringNotContainsString('langs/$1/index.php', $src);
    }

    public function test_el_bootstrap_uses_named_lang_captures(): void
    {
        $src = (string) file_get_contents(base_path('server/bootstrap-origin-el.sh'));

        $this->assertStringContainsString('offerra_lang_index', $src);
        $this->assertStringContainsString('(?<offerra_lang>[a-z][a-z])', $src);
        $this->assertStringNotContainsString('langs/$1/index.php', $src);
    }
}
