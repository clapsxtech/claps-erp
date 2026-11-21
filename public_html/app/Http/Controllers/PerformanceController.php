<?php

namespace App\Http\Controllers;

use App\Modules\Academics\Actions\SubmitPerformance;
use App\Modules\Academics\Services\ResultResponse;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    public function store(Request $request, SubmitPerformance $submit, ResultResponse $response)
    {
        return $response->submit(function () use ($submit, $request) {
            return $submit->execute($request->all());
        });
    }
}
