<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryWindowResource;
use App\Models\DeliveryWindow;

class DeliveryWindowController extends Controller
{
    public function index()
    {
        return DeliveryWindowResource::collection(
            DeliveryWindow::query()->active()->get()
        );
    }
}
