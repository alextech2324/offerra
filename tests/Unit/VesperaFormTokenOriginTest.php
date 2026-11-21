<?php

namespace Tests\Unit;

use Tests\TestCase;

class VesperaFormTokenOriginTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('SITE_URL')) {
            define('SITE_URL', 'https://adipexai.pro');
        }

        require_once dirname(__DIR__, 2).'/templates/vespera/integration/FormToken.php';
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_REFERER'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_CF_CONNECTING_IP'],
        );
        parent::tearDown();
    }

    public function test_empty_referer_and_origin_is_not_same_origin(): void
    {
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);

        $this->assertFalse(\FormToken::requestLooksSameOrigin());
    }

    public function test_lander_referer_is_same_origin(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://adipexai.pro/sign-up/';
        unset($_SERVER['HTTP_ORIGIN']);

        $this->assertTrue(\FormToken::requestLooksSameOrigin());
    }

    public function test_www_referer_is_same_origin(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://www.adipexai.pro/';
        unset($_SERVER['HTTP_ORIGIN']);

        $this->assertTrue(\FormToken::requestLooksSameOrigin());
    }

    public function test_foreign_referer_is_rejected(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://truevestoworthen.biz/sign.php';
        unset($_SERVER['HTTP_ORIGIN']);

        $this->assertFalse(\FormToken::requestLooksSameOrigin());
    }

    public function test_origin_header_alone_is_enough(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://adipexai.pro';
        unset($_SERVER['HTTP_REFERER']);

        $this->assertTrue(\FormToken::requestLooksSameOrigin());
    }

    public function test_issue_marks_missing_referer_as_drop(): void
    {
        $src = (string) file_get_contents(base_path('templates/vespera/integration/FormToken.php'));

        $this->assertStringContainsString('! self::requestLooksSameOrigin()', $src);
        $this->assertStringContainsString("'drop' => \$drop", $src);
    }
}
