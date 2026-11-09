<?php
/**
 * JSON-LD schema blocks. Pass $schema_type and optional $schema_data.
 */
function render_schema(string $page = 'home', array $extra = []): void {
    $site = SITE_NAME;
    $url = SITE_URL;
    $platform_image = page_url(platform_image_path());
    $logo_url = page_url('static/img/logo.webp');

    $organization = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $site,
        'url' => $url,
        'logo' => $logo_url,
        'description' => 'Pasiekite kriptovaliutas, Forex ir pasaulinį turtą per vieną platformą.' . $site . 'sujungia tiesioginę analizę, pagalbinį automatizavimą ir ekspertų palaikymą.',
    ];

    $website = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $site,
        'url' => $url,
        'publisher' => ['@type' => 'Organization', 'name' => $site],
    ];

    $software = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => $site,
        'operatingSystem' => 'Web, Android, iOS',
        'applicationCategory' => 'FinanceApplication',
        'description' => $site . 'Dirbtinio intelekto pagrįsta prekybos platforma su tiesiogine analize, pagalbiniu automatizavimu ir kelių rinkų prieiga.',
        'image' => $platform_image,
        'screenshot' => $platform_image,
        'aggregateRating' => [
            '@type' => 'AggregateRating',
            'ratingValue' => '4.7',
            'ratingCount' => '337',
            'bestRating' => '5',
        ],
        'offers' => [
            '@type' => 'Offer',
            'price' => MIN_DEPOSIT,
            'priceCurrency' => CURRENCY,
        ],
    ];

    $faq = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => 'Kokie yra žingsniai norint pradėti prekybą?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Prisiregistruokite, nurodydami savo pagrindinę informaciją, patvirtinkite savo el. pašto adresą ir finansuokite savo sąskaitą mažiausiai' . money_min() . '. Tai atrakina tiesiogines diagramas, prekybos įrankius, rinkos analizę ir specialų palaikymą.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Ar ' . $site . ' patikimas tvarkant mano pinigus ir informaciją?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Seansai yra apsaugoti SSL šifravimu, galimas dviejų veiksnių autentifikavimas, o finansinės operacijos tvarkomos per patikimus partnerius. Svetainėje aprašyta privatumo praktika.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Per kiek laiko galiu atsiimti savo lėšas?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Išėmimo užklausą savo paskyros portale galite pateikti bet kuriuo metu. Apdorojimas paprastai trunka nuo 1 iki 3 darbo dienų, priklausomai nuo metodo. Mokesčiai ir laikas rodomi prieš patvirtinant.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Ar prieš pradedant būtina turėti prekybos patirties?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Ankstesnė prekybos patirtis nebūtina. Įdiegimo palaikymas, mokymo programos ir dirbtinio intelekto patobulinti įrankiai padeda mokytis savo tempu.',
                ],
            ],
        ],
    ];

    $howto = [
        '@context' => 'https://schema.org',
        '@type' => 'HowTo',
        'name' => 'Kaip pradėti prekiauti su' . $site,
        'step' => [
            ['@type' => 'HowToStep', 'position' => 1, 'name' => 'Susikurkite paskyrą', 'text' => 'Prisiregistruokite naudodami pagrindinę informaciją ir gaukite saugią prieigą prie platformos.'],
            ['@type' => 'HowToStep', 'position' => 2, 'name' => 'Patvirtinkite el. pašto adresą', 'text' => 'Patvirtinkite savo el. pašto adresą, kad atrakintumėte visą platformos prieigą.'],
            ['@type' => 'HowToStep', 'position' => 3, 'name' => 'Finansuokite savo sąskaitą', 'text' => 'Įnešti mažiausiai' . money_min() . 'banko pavedimu, kortele arba elektronine pinigine.'],
            ['@type' => 'HowToStep', 'position' => 4, 'name' => 'Nustatykite savo strategiją', 'text' => 'Pasirinkite rizikos lygį ir prekybos nuostatas – rankiniu būdu arba automatizuotai.'],
            ['@type' => 'HowToStep', 'position' => 5, 'name' => 'Pradėkite prekiauti', 'text' => 'Įeikite į rinką užtikrintai, naudodamiesi realaus laiko duomenimis ir AI įžvalgomis.'],
        ],
    ];

    $blocks = [$organization, $website];

    if ($page === 'home') {
        $blocks[] = $software;
        $blocks[] = $faq;
        $blocks[] = $howto;
        $blocks[] = [
            '@context' => 'https://schema.org',
            '@type' => 'ImageObject',
            'name' => $site . 'AI prekybos platforma',
            'description' => $site . 'mobilioji prekybos sąsaja su tiesiogine BTC/USDT kriptovaliutų diagrama ir portfelio įrankiais',
            'contentUrl' => $platform_image,
            'thumbnailUrl' => $platform_image,
            'caption' => $site . '| AI prekybos platforma – mobiliojo diagramos vaizdas',
            'representativeOfPage' => true,
        ];
    }

    if (!empty($extra['breadcrumb'])) {
        $blocks[] = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $extra['breadcrumb'],
        ];
    }

    foreach ($blocks as $block) {
        echo '<script type="application/ld+json">' . json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}
