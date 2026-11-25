<?php

namespace Tests\Unit;

use Tests\TestCase;

class DictionaryMlI18nTest extends TestCase
{
    /** @var list<string> */
    private const CODES = [
        'cs', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'hr', 'hu', 'id', 'it',
        'ja', 'ka', 'lt', 'lv', 'ms', 'nl', 'no', 'pl', 'pt', 'ro', 'sk', 'sl',
        'sr', 'sv', 'tr',
    ];

    public function test_fortis_and_bluegrove_have_full_language_packs(): void
    {
        foreach (['fortis', 'bluegrove'] as $template) {
            $en = $this->loadPack($template, 'en');
            $enKeys = $this->leafKeys($en);

            foreach (self::CODES as $code) {
                $packFile = base_path('templates/'.$template.'/lang/'.$code.'.php');
                $stubFile = base_path('templates/'.$template.'/langs/'.$code.'/index.php');
                $this->assertFileExists($packFile, $template.'/lang/'.$code.'.php');
                $this->assertFileExists($stubFile, $template.'/langs/'.$code.'/index.php');

                $pack = $this->loadPack($template, $code);
                $this->assertSame($enKeys, $this->leafKeys($pack), $template.'/'.$code.' keys');

                foreach ($enKeys as $path) {
                    $enVal = $this->leaf($en, $path);
                    $trVal = $this->leaf($pack, $path);
                    $this->assertIsString($enVal);
                    $this->assertIsString($trVal);
                    $this->assertSame(
                        $this->placeholders($enVal),
                        $this->placeholders($trVal),
                        $template.'/'.$code.' placeholders at '.$path,
                    );
                    if ($code !== 'en') {
                        $this->assertNotSame('', trim($trVal), $template.'/'.$code.' empty at '.$path);
                    }
                }
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private function loadPack(string $template, string $code): array
    {
        $data = require base_path('templates/'.$template.'/lang/'.$code.'.php');
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function leafKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $keys = array_merge($keys, $this->leafKeys($value, $path));
            } else {
                $keys[] = $path;
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function leaf(array $data, string $path): mixed
    {
        $cur = $data;
        foreach (explode('.', $path) as $part) {
            if (! is_array($cur) || ! array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        preg_match_all('/\{[a-z_]+\}/', $value, $matches);

        return $matches[0];
    }
}
