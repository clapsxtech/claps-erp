<?php
namespace App\Http\Controllers\Erp;
use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Administration,OperationalHealth,TeamPermissions};
use Illuminate\Http\Request;

class AdministrationController extends Controller
{
    public function team(Request $request,?int $id=null)
    {
        (new Administration())->team($request->user(),$request->all(),$id);
        return response()->json(['message'=>'Team saved.']);
    }
    public function archive(Request $request,int $id)
    {
        $v=$request->validate(['archived'=>'required|boolean']);
        (new Administration())->archive($request->user(),$id,(bool)$v['archived']);
        return response()->json(['message'=>$v['archived']?'Team archived. Records are preserved.':'Team restored.']);
    }
    public function onboard(Request $request)
    {
        $request->validate(['team_ids'=>'required|array|min:1|max:30','team_ids.*'=>'required|integer|distinct','initial_role'=>['required',\Illuminate\Validation\Rule::in(TeamPermissions::ROLES)]]);
        $payload=$request->except('team_ids','initial_role');
        $payload['assignments']=array_map(fn($id)=>['unit_id'=>$id,'role'=>$request->initial_role],$request->team_ids);
        $user=(new Administration())->onboard($request->user(),$payload);
        return response()->json(['message'=>'Account created and assigned for '.$user->name.'. Share the staff login URL and password privately.']);
    }
    public function account(Request $request,int $id)
    {
        (new Administration())->account($request->user(),$id,$request->all());
        return response()->json(['message'=>'Account updated. Existing database sessions and remembered logins were revoked.']);
    }
}
