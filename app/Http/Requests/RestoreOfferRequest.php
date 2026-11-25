<?php

namespace App\Http\Requests;

use App\Services\TemplateCatalog;
use App\Support\MarketOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $offer = $this->route('offer');

        return is_object($offer) && (bool) $this->user()?->canManageOffer($offer);
    }

    public function prepareForValidation(): void
    {
        if ($this->has('template')) {
            $this->merge([
                'template' => trim((string) $this->input('template')),
            ]);
        }

        if ($this->has('geo')) {
            $this->merge([
                'geo' => MarketOptions::normalizeGeo((string) $this->input('geo')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $offer = $this->route('offer');
        $currentTemplate = is_object($offer) ? (string) $offer->template : null;
        $catalog = app(TemplateCatalog::class);

        return [
            'template' => ['sometimes', 'nullable', 'string', Rule::in($catalog->selectableIds($currentTemplate))],
            'geo' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha:ascii'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template.in' => 'Цей шаблон вимкнено',
            'geo.size' => 'GEO — 2 літери, наприклад IE, IT, ZA',
        ];
    }

    /**
     * @return array{template?: string, geo?: string}
     */
    public function restoreChoices(): array
    {
        $out = [];

        if ($this->filled('template')) {
            $out['template'] = trim((string) $this->input('template'));
        }

        if ($this->filled('geo')) {
            $out['geo'] = MarketOptions::normalizeGeo((string) $this->input('geo'));
        }

        return $out;
    }
}
