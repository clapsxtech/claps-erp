<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Modules\Erp\Services\Admissions;
use App\Modules\Erp\Services\Access;
use App\Modules\Erp\Services\MoneyInput;
use Illuminate\Http\Request;

class AdmissionsController extends Controller
{
    public function mutate(Request $request, int $unit, string $action)
    {
        (new Access())->unit($request->user(), $unit);
        $service = new Admissions();
        $payload = $request->except('_token', '_method', 'return_page');
        if ($action === 'admission-convert' && array_key_exists('payment_amount_inr', $payload) && $payload['payment_amount_inr'] !== '') {
            $payload['payment_amount_paise'] = MoneyInput::toPaise($payload['payment_amount_inr'], 'payment_amount_inr', 100000000000, false);
            unset($payload['payment_amount_inr']);
        }
        $methods = [
            'admission-create-lead' => 'createLead',
            'admission-update-lead' => 'updateLead',
            'admission-schedule-demo' => 'scheduleDemo',
            'admission-update-demo' => 'updateDemo',
            'admission-followup' => 'recordFollowup',
            'admission-reminder' => 'createReminder',
            'admission-complete-reminder' => 'completeReminder',
            'admission-transfer' => 'requestTransfer',
            'admission-resolve-transfer' => 'resolveTransfer',
            'admission-convert' => 'convertLead',
        ];
        abort_unless(isset($methods[$action]), 404);
        $result = $service->{$methods[$action]}($unit, (int) $request->user()->id, $payload);
        return response()->json(['result' => $result, 'message' => 'Saved. Your CRM is up to date.']);
    }
}
