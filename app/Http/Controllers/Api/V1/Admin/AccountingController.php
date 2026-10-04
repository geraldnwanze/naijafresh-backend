<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProfitLossRequest;
use App\Services\Accounting\ProfitLossService;

class AccountingController extends Controller
{
    public function profitLoss(ProfitLossRequest $request, ProfitLossService $profitLoss)
    {
        return response()->json([
            'data' => $profitLoss->report(
                $request->rangeFrom(),
                $request->rangeTo(),
                $request->basis(),
                $request->groupBy(),
            ),
        ]);
    }
}
