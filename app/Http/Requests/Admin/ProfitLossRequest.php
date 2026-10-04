<?php

namespace App\Http\Requests\Admin;

use App\Services\Accounting\ProfitLossService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfitLossRequest extends FormRequest
{
    private const MAX_RANGE_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'basis' => ['nullable', Rule::in([ProfitLossService::BASIS_DELIVERED, ProfitLossService::BASIS_PLACED])],
            'group_by' => ['nullable', Rule::in([
                ProfitLossService::GROUP_DAY,
                ProfitLossService::GROUP_WEEK,
                ProfitLossService::GROUP_MONTH,
            ])],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['from', 'to'])) {
                    return;
                }

                $from = CarbonImmutable::parse($this->rangeFrom());
                $to = CarbonImmutable::parse($this->rangeTo());

                if ($to->lt($from)) {
                    $validator->errors()->add('to', 'The end date must be on or after the start date.');
                } elseif ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                    $validator->errors()->add('to', 'Choose a range of one year or less.');
                }
            },
        ];
    }

    /** First local day of the report (defaults to the start of this month). */
    public function rangeFrom(): string
    {
        return $this->input('from') ?? $this->today()->startOfMonth()->toDateString();
    }

    /** Last local day of the report (defaults to today). */
    public function rangeTo(): string
    {
        return $this->input('to') ?? $this->today()->toDateString();
    }

    public function basis(): string
    {
        return $this->input('basis') ?? ProfitLossService::BASIS_DELIVERED;
    }

    /**
     * Trend granularity: as requested, otherwise sized to the range.
     */
    public function groupBy(): string
    {
        if ($this->filled('group_by')) {
            return $this->string('group_by')->toString();
        }

        $days = CarbonImmutable::parse($this->rangeFrom())->diffInDays(CarbonImmutable::parse($this->rangeTo())) + 1;

        return match (true) {
            $days <= 31 => ProfitLossService::GROUP_DAY,
            $days <= 120 => ProfitLossService::GROUP_WEEK,
            default => ProfitLossService::GROUP_MONTH,
        };
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('naijafresh.timezone'));
    }
}
