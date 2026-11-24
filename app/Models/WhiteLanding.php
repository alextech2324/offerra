<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhiteLanding extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'entry_file',
        'file_count',
        'size_bytes',
        'original_filename',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'file_count' => 'integer',
            'size_bytes' => 'integer',
            'is_demo' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function previewUrl(): string
    {
        $entry = ltrim((string) ($this->entry_file ?: 'index.html'), '/');

        return url('/landings/'.$this->id.'/preview/'.$entry);
    }

    public function toPanelArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'entry_file' => $this->entry_file,
            'file_count' => $this->file_count,
            'size_bytes' => $this->size_bytes,
            'original_filename' => $this->original_filename,
            'is_demo' => (bool) $this->is_demo,
            'preview_url' => $this->previewUrl(),
            'created_at' => $this->created_at?->timezone('Europe/Kyiv')->format('Y-m-d H:i'),
        ];
    }

    /**
     * Compact row for the create-offer cloak picker.
     *
     * @return array<string, mixed>
     */
    public function toPickerArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_count' => $this->file_count,
            'is_demo' => (bool) $this->is_demo,
            'preview_url' => $this->previewUrl(),
        ];
    }
}
