<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\DeliveryWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryWindow extends Model
{
    /** @use HasFactory<DeliveryWindowFactory> */
    use Auditable, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'label',
        'starts_at',
        'ends_at',
        'is_active',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function displayTime(): string
    {
        return "{$this->starts_at} – {$this->ends_at}";
    }

    /**
     * @param  Builder<DeliveryWindow>  $query
     * @return Builder<DeliveryWindow>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position');
    }
}
