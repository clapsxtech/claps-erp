<?php
namespace App\Http\Controllers\Erp;
use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\{Administration,TeamPermissions};
use App\Modules\People\Services\StaffProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $user=$this->safely(fn()=>(new Administration())->onboard($request->user(),$payload),'Unable to create a staff profile.');
        return response()->json(['message'=>'Account created and assigned for '.$user->name.'. Share the staff login URL and password privately.']);
    }
    public function account(Request $request,int $id)
    {
        (new Administration())->account($request->user(),$id,$request->all());
        return response()->json(['message'=>'Account updated. Existing database sessions and remembered logins were revoked.']);
    }

    /** Edit a staff member's details. Bank details arrive flat as bank_<field> and are nested for the service. */
    public function profile(Request $request,int $id,StaffProvisioner $staff)
    {
        $staff->staffQuery($request->user())->findOrFail($id);
        $input=$request->except(array_map(fn($f)=>'bank_'.$f,StaffProvisioner::BANK_FIELDS));
        $bank=[];foreach(StaffProvisioner::BANK_FIELDS as $field)if($request->exists('bank_'.$field))$bank[$field]=$request->input('bank_'.$field);
        if($bank)$input['bank_details']=$bank;
        $user=$this->safely(fn()=>$staff->update($request->user(),$id,$input),'Unable to update a staff profile.');
        return response()->json(['message'=>'Details saved for '.$user->name.'.']);
    }

    /**
     * Database exceptions can contain password hashes and bank details, so an
     * unexpected failure is logged with a fixed message only. Validation and
     * HTTP errors (404, 403) pass through unchanged.
     */
    private function safely(callable $work,string $logMessage)
    {
        try {
            return $work();
        } catch (ValidationException|HttpExceptionInterface|\Illuminate\Auth\Access\AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            throw $exception;
        } catch (\Throwable) {
            Log::error($logMessage);
            abort(500,'The change could not be saved. Nothing was changed.');
        }
    }
}
