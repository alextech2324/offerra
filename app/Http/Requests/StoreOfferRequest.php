<?php

namespace App\Http\Requests;

use App\Services\TemplateCatalog;
use App\Support\DomainName;
use App\Support\MarketOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfferRequest extends FormRequest
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

        if ($this->has('domain')) {
            $this->merge([
                'domain' => DomainName::normalize((string) $this->input('domain')),
            ]);
        }

        $normalized = MarketOptions::normalizePhoneFields(
            (string) $this->input('phone', ''),
            $this->input('phone_countries', []),
            (string) $this->input('geo', ''),
        );

        $this->merge([
            'phone' => $normalized['phone'],
            'phone_countries' => $normalized['phone_countries'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $template = (string) $this->input('template');
        $catalog = app(TemplateCatalog::class);
        $currencyCodes = array_column(config('offerra.currencies', []), 'code');

        return [
            'brand' => ['required', 'string', 'max:120'],
            'domain' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/i'],
            'min_deposit' => ['required', 'string', 'max:20'],
            'currency' => ['required', 'string', 'min:2', 'max:8', Rule::in($currencyCodes)],
            'geo' => ['required', 'string', 'size:2', 'alpha:ascii'],
            'lang' => TemplateCatalog::usesMlGeo($template)
                ? ['required', 'string', 'max:8']
                : ['required', 'string', Rule::in($catalog->languageCodesFor($template))],
            'phone' => ['required', 'string', 'regex:/^(ip|[a-zA-Z]{2})$/'],
            'phone_countries' => ['required', 'array', 'min:1'],
            'phone_countries.*' => ['string', 'size:2', 'alpha:ascii'],
            'template' => ['required', 'string', Rule::in($catalog->ids())],
            'vitals_enabled' => ['boolean'],
            'from_search_team' => ['boolean'],
            'provision_infrastructure' => ['boolean'],
            'infra_cloudflare_zone' => ['boolean'],
            'infra_cloudflare_dns' => ['boolean'],
            'infra_dynadot_ns' => ['boolean'],
            'infra_cloudflare_ssl' => ['boolean'],
            'infra_cloudflare_https' => ['boolean'],
            'infra_cloudflare_www_redirect' => ['boolean'],
            'download_zip' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Домен без https://, наприклад example.com (IDN з ń, ö тощо — теж можна)',
            'lang.in' => 'Оберіть мову, доступну для обраного шаблону',
            'currency.in' => 'Оберіть валюту зі списку',
            'geo.size' => 'GEO — 2 літери, наприклад IE, IT, ZA',
        ];
    }
}
