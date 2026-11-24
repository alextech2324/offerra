<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if (! $this->filled('user_id')) {
            return true;
        }

        $requestedUserId = $this->integer('user_id');

        if ($user->isAdmin()) {
            return User::query()->whereKey($requestedUserId)->exists();
        }

        return $requestedUserId === (int) $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'affiliate_tag' => ['nullable', 'string', 'max:50'],
            'crm_api_key' => ['nullable', 'string', 'max:255'],
            'tg_bot_token' => ['nullable', 'string', 'max:255'],
            'tg_chat_id' => ['nullable', 'string', 'max:50'],
            'tg_group_chat_id' => ['nullable', 'string', 'max:50'],
            'dynadot_api_key' => ['nullable', 'string', 'max:255'],
            'dynadot_api_secret' => ['nullable', 'string', 'max:255'],
            'dynadot_contact_id' => ['nullable', 'string', 'max:40'],
            'dynadot_account_name' => ['nullable', 'string', 'max:80'],
            'cloudflare_api_token' => ['nullable', 'string', 'max:255'],
            'cloudflare_account_id' => ['nullable', 'string', 'max:40'],
            'cloudflare_default_proxied' => ['boolean'],
            'cloudflare_account_name' => ['nullable', 'string', 'max:80'],
            'cloudflare_backup_api_token' => ['nullable', 'string', 'max:255'],
            'cloudflare_backup_account_id' => ['nullable', 'string', 'max:40'],
            'cloudflare_backup_account_name' => ['nullable', 'string', 'max:80'],
            'test_domain' => ['nullable', 'string', 'max:120'],
            'cloudflare_slot' => ['nullable', 'string', 'in:primary,backup'],
        ];
    }
}
