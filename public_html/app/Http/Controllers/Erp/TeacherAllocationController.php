<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Admissions,MoneyInput,TeacherAllocation,TeamPermissions};
use Illuminate\Http\Request;

class TeacherAllocationController extends Controller
{
    public function mutate(Request $request,int $unit,string $action)
    {
        $service=new TeacherAllocation();$p=$request->except('_token','return_page');
        $service->authorize($unit,$request->user(),$action);
        $method=match($action){'allocation-qualify'=>'qualify','allocation-window'=>'window','allocation-demo'=>'bookDemo','allocation-feedback'=>'feedback','allocation-regular'=>'regular',default=>null};abort_unless($method,404);
        if($action==='allocation-regular')$p=MoneyInput::forAction($p,'assign');
        $result=$service->$method($unit,$request->user(),$p);
        return response()->json(['result'=>$result,'message'=>$action==='allocation-regular'?'Regular assignment confirmed and dated lessons created.':'Allocation record saved and audited.']);
    }
}
