<?php
namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Access,Insights};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Management trends for one team: the last six months side by side. */
class InsightsController extends Controller
{
    public function index(Request $request, int $unit)
    {
        (new Access())->unit($request->user(), $unit, false);
        $business = DB::table('erp_units')->find($unit);
        $months = (new Insights())->monthly($unit, 6);
        $page = 'insights';
        return view('erp.insights', compact('business', 'months', 'page'));
    }
}
