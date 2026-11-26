<?php

namespace App\Http\Controllers;

use App\Models\Master;
use App\Models\Topic;
use App\Modules\Academics\Actions\SubmitPerformance;
use App\Modules\Academics\Services\ResultResponse;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    public function create()
    {
        // Public page: teachers type their name; never publish the teacher directory.
        $topics = Topic::select('id', 'name')->get();

        return view('performenceform', compact('topics'));
    }

    public function store(Request $request, SubmitPerformance $submit, ResultResponse $response)
    {
        return $response->submit(function () use ($submit, $request) {
            return $submit->execute($request->all());
        });
    }
}
