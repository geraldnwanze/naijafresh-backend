<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Models\Concerns\Auditable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use Auditable, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'category',
        'description',
        'amount_kobo',
        'incurred_on',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount_kobo' => 'integer',
            'incurred_on' => 'date',
        ];
    }
}
