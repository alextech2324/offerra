<?php

namespace App\Http\Requests;

use App\Services\TemplateCatalog;
use App\Support\DomainName;
use App\Support\MarketOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBulkOffersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        if ($this->has('geo')) {
            $this->merge([
                'geo' => MarketOptions::normalizeGeo((string) $this->input('geo')),
            ]);
        }

        $normalized = MarketOptions::normalizePhoneFields(
            (string) $this->input('phone', ''),
            $this->input('phone_countries', []),
            (string) $this->input('geo', ''),
        );

        $items = collect($this->input('items', []))
            ->filter(static fn ($item) => is_array($item))
            ->map(static function (array $item): array {
                return [
                    'domain' => DomainName::normalize((string) ($item['domain'] ?? '')),
                    'template' => trim((string) ($item['template'] ?? '')),
                ];
            })
            ->filter(static fn (array $item) => $item['domain'] !== '' && $item['template'] !== '')
            ->unique('domain')
            ->values()
            ->take(10)
            ->all();

        $this->merge([
            'phone' => $normalized['phone'],
            'phone_countries' => $normalized['phone_countries'],
            'items' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $catalog = app(TemplateCatalog::class);
        $currencyCodes = array_column(config('offerra.currencies', []), 'code');

        return [
            'brand' => ['required', 'string', 'max:120'],
            'min_deposit' => ['required', 'string', 'max:20'],
            'currency' => ['required', 'string', 'min:2', 'max:8', Rule::in($currencyCodes)],
            'geo' => ['required', 'string', 'size:2', 'alpha:ascii'],
            'lang' => ['required', 'string', 'max:8'],
            'phone' => ['required', 'string', 'regex:/^(ip|[a-zA-Z]{2})$/'],
            'phone_countries' => ['required', 'array', 'min:1'],
            'phone_countries.*' => ['string', 'size:2', 'alpha:ascii'],
            'vitals_enabled' => ['boolean'],
            'from_search_team' => ['boolean'],
            'infra_cloudflare_zone' => ['boolean'],
            'infra_cloudflare_dns' => ['boolean'],
            'infra_dynadot_ns' => ['boolean'],
            'infra_cloudflare_ssl' => ['boolean'],
            'infra_cloudflare_https' => ['boolean'],
            'infra_cloudflare_www_redirect' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.domain' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/i', 'distinct'],
            'items.*.template' => ['required', 'string', Rule::in($catalog->enabledIds())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $catalog = app(TemplateCatalog::class);
            $lang = strtolower((string) $this->input('lang'));
            $items = $this->input('items', []);

            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $template = (string) ($item['template'] ?? '');
                $domain = (string) ($item['domain'] ?? '');

                if (TemplateCatalog::usesMlGeo($template)) {
                    continue;
                }

                $codes = $catalog->languageCodesFor($template);

                if ($template !== '' && $lang !== '' && ! in_array($lang, $codes, true)) {
                    $validator->errors()->add(
                        "items.{$index}.template",
                        "Мова «{$lang}» недоступна для шаблону «{$template}» (домен {$domain}).",
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.template.in' => 'Цей шаблон вимкнено',
            'items.required' => 'Додайте хоча б один домен до пакету.',
            'items.max' => 'За раз можна згенерувати максимум 10 оферів.',
            'items.*.domain.regex' => 'Домен без https://, наприклад example.com',
            'items.*.domain.distinct' => 'Домени в пакеті мають бути унікальними.',
            'currency.in' => 'Оберіть валюту зі списку',
            'geo.size' => 'GEO — 2 літери, наприклад IE, IT, ZA',
        ];
    }
}
