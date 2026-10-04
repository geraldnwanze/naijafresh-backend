<?php

namespace Database\Seeders;

use App\Models\DeliveryWindow;
use Illuminate\Database\Seeder;

class DeliveryWindowSeeder extends Seeder
{
    public function run(): void
    {
        $windows = [
            ['label' => 'Morning', 'starts_at' => '8am', 'ends_at' => '11am'],
            ['label' => 'Afternoon', 'starts_at' => '12pm', 'ends_at' => '3pm'],
            ['label' => 'Evening', 'starts_at' => '4pm', 'ends_at' => '7pm'],
        ];

        foreach ($windows as $index => $window) {
            DeliveryWindow::updateOrCreate(
                ['label' => $window['label']],
                [
                    'starts_at' => $window['starts_at'],
                    'ends_at' => $window['ends_at'],
                    'is_active' => true,
                    'position' => $index,
                ],
            );
        }
    }
}
