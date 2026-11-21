<?php
namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\Operations;
use App\Modules\IdentityAccess\Services\SiteAccessPolicy;
use Illuminate\Http\Request;

/** Limited staff portal: assigned tasks and checklist only, never student or money records. */
class OwnTasksController extends Controller
{
    public function __construct() {}
    public function complete(Request $request,int $task) {
        (new SiteAccessPolicy())->assertSiteMember($request->user());
        (new Operations())->completeOwnTask((int)$request->user()->id,$task);
        return response()->json(['message'=>'Your assigned task is complete.']);
    }
    public function completeItem(Request $request,int $task,int $item) {
        (new SiteAccessPolicy())->assertSiteMember($request->user());
        (new Operations())->completeOwnTaskItem((int)$request->user()->id,$task,$item);
        return response()->json(['message'=>'Checklist item completed.']);
    }
}
