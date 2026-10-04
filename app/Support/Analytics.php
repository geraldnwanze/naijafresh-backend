<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Minimal analytics abstraction. For the MVP it logs structured events on the
 * "analytics" channel; a real sink (GA4, Plausible, Segment, …) can be added
 * behind this same method without touching call sites.
 */
class Analytics
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function track(string $event, array $properties = []): void
    {
        Log::channel('analytics')->info($event, $properties);
    }
}
