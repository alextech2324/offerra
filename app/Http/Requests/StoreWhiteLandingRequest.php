<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWhiteLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:80'],
            'zip' => ['required', 'file', 'max:25600'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zip.required' => 'Завантаж ZIP з вайтом.',
            'zip.file' => 'Завантаж ZIP з вайтом.',
            'zip.max' => 'ZIP має бути до 25 МБ.',
        ];
    }
}
