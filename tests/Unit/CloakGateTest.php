<?php

namespace Tests\Unit;

use Tests\TestCase;

class CloakGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('OFFERRA_CLOAK_TEST')) {
            define('OFFERRA_CLOAK_TEST', true);
        }

        require_once resource_path('cloak/cloak.php');

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cloak-gate-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        copy(resource_path('cloak/googlebot.json'), $this->root.DIRECTORY_SEPARATOR.'googlebot.json');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'cloak.json', json_encode([
            'countries' => ['IT'],
            'panel_ips' => ['38.180.121.50'],
            'entry' => 'index.html',
        ]));

        $GLOBALS['OFFERRA_CLOAK_ROOT'] = $this->root;
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['OFFERRA_CLOAK_ROOT'], $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_URI'] = '/';

        if (is_dir($this->root)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }

        parent::tearDown();
    }

    public function test_googlebot_ip_sees_offer_even_from_other_country(): void
    {
        $_SERVER['REMOTE_ADDR'] = '66.249.64.1';
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';

        $this->assertTrue(offerra_cloak_allows());
    }

    public function test_matching_geo_human_sees_offer_on_cloudflare_headers(): void
    {
        $_SERVER['REMOTE_ADDR'] = '79.12.10.20';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '79.12.10.20';
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'IT';

        $this->assertTrue(offerra_cloak_allows());
    }

    public function test_matching_geo_human_sees_offer_on_proxy_headers(): void
    {
        // Proxy stamps the same CF-* headers the lander already uses.
        $_SERVER['REMOTE_ADDR'] = '2.139.0.40';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '2.139.0.40';
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'IT';

        $this->assertTrue(offerra_cloak_allows());
    }

    public function test_foreign_geo_sees_white(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';

        $this->assertFalse(offerra_cloak_allows());
    }

    public function test_spoofed_googlebot_header_is_ignored_when_remote_addr_differs(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '66.249.64.1';
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';

        $this->assertSame('8.8.8.8', offerra_cloak_visitor_ip());
        $this->assertFalse(offerra_cloak_is_googlebot(offerra_cloak_visitor_ip()));
        $this->assertFalse(offerra_cloak_allows());
    }

    public function test_panel_ip_always_passes(): void
    {
        $_SERVER['REMOTE_ADDR'] = '38.180.121.50';
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);

        $this->assertTrue(offerra_cloak_allows());
    }

    public function test_sitemap_and_gsc_file_always_pass(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';

        $_SERVER['REQUEST_URI'] = '/sitemap.xml';
        $this->assertTrue(offerra_cloak_allows());

        $_SERVER['REQUEST_URI'] = '/googleabc123.html';
        $this->assertTrue(offerra_cloak_allows());

        $_SERVER['REQUEST_URI'] = '/robots.txt';
        $this->assertTrue(offerra_cloak_allows());
    }
}
