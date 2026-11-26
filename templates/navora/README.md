# Navora — шаблон ленда

Темний Webflow-ленд про AI-аналіз ринків (акції, forex, крипто). Звичайний GEO-шаблон,
як audax / lumen: мова з гео, не ML.

Джерело — `langs/pt/` (португальський ленд PNAF Plataforma). Бренд, домен, депозит і GEO
підставляються з `includes/config.php`.

## Мови

```
node scripts/build-navora-langs.mjs       # усі мови
node scripts/build-navora-langs.mjs it    # одна мова
```

Не правити згенерований `langs/{code}/` вручну — наступний білд його перезапише.
Правки лише в `langs/pt/` + `scripts/navora-i18n/`.

## Прев’ю

`/preview/navora/`
`/preview/navora/?lang=en`

## Структура

- `langs/pt/` — джерело
- `includes/` — helpers / head / form (синхронізуються в офер)
- `integration/` — lead pipeline
- `static/` — css, js, images, fonts
