<?php
namespace Tests\Feature;

use App\Modules\Erp\Services\{Books,Finance,Invoicing};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    private $admin;
    private $unit;
    private $student;
    private $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin=$this->createAdmin();
        $this->unit=DB::table('erp_units')->insertGetId(['code'=>'KT','name'=>'Kochi Tuition','timezone'=>'Asia/Kolkata','phone'=>'+91 98470 10000','legal_name'=>'ClapsLearn Education Pvt Ltd','address'=>"12 MG Road\nKochi 682016",'state'=>'Kerala','tax_mode'=>'gst','tax_id'=>'32ABCDE1234F1Z5']);
        $this->student=DB::table('erp_students')->insertGetId(['unit_id'=>$this->unit,'code'=>'KT-001','name'=>'Aarav Menon','registered_on'=>'2026-01-01','timezone'=>'Asia/Kolkata','status'=>'active','grade'=>'8','syllabus'=>'CBSE']);
        $this->teacher=DB::table('erp_teachers')->insertGetId(['code'=>'KT-T','name'=>'Nisha','timezone'=>'Asia/Kolkata','status'=>'active']);
        DB::table('erp_teacher_units')->insert(['unit_id'=>$this->unit,'teacher_id'=>$this->teacher]);
    }

    private function ledger(string $kind, int $paise, string $reference, array $extra=[]): int
    {
        return DB::table('erp_ledger')->insertGetId(array_merge(['unit_id'=>$this->unit,'student_id'=>in_array($kind,['teacher_accrual','teacher_payment'],true)?null:$this->student,'teacher_id'=>in_array($kind,['teacher_accrual','teacher_payment'],true)?$this->teacher:null,
            'kind'=>$kind,'amount_paise'=>$paise,'currency'=>'INR','reference'=>$reference,'event_key'=>$reference,'occurred_at'=>now()->format('Y-m-d H:i:s'),'created_by'=>$this->admin->id],$extra));
    }

    private function balance(string $key): int
    {
        $books=new Books(); return (int)$books->balances($this->unit,null,now('Asia/Kolkata')->toDateString())->firstWhere('system_key',$key)->balance;
    }

    public function test_every_money_movement_posts_a_balanced_journal_that_agrees_with_balances(): void
    {
        $pay=$this->ledger('payment',360000,'UPI-1');
        $this->ledger('lesson_charge',-45000,'CHG-1');
        $this->ledger('refund',-10000,'REF-1');
        $this->ledger('teacher_accrual',30000,'ACC-1');
        $this->ledger('teacher_payment',-20000,'TP-1');
        $this->ledger('payment',5000,'UPI-2');
        $this->ledger('reversal',-5000,'REV-UPI-2',['reverses_id'=>DB::table('erp_ledger')->where('reference','UPI-2')->value('id')]);
        $this->assertSame(7,(new Books())->sync($this->unit));
        $this->assertSame(0,(new Books())->sync($this->unit),'Posting is idempotent.');
        $trial=(new Books())->trialBalance($this->unit,now('Asia/Kolkata')->toDateString());
        $this->assertSame($trial['debit'],$trial['credit']);
        $this->assertSame(-(int)DB::table('erp_ledger')->whereNotNull('student_id')->sum('amount_paise'),$this->balance('students'),'Student control account equals ledger balances.');
        $this->assertSame([-38136,-3432,-3432,0],[$this->balance('tuition'),$this->balance('cgst'),$this->balance('sgst'),$this->balance('igst')],'₹450 includes ₹68.64 GST split CGST/SGST.');
        $this->assertSame([-10000,30000,360000-10000-20000],[$this->balance('teacher_payable'),$this->balance('teacher_cost'),$this->balance('bank')]);
    }

    public function test_igst_applies_to_out_of_state_students_and_non_gst_teams_charge_no_tax(): void
    {
        DB::table('erp_student_profiles')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'billing_state'=>'Tamil Nadu']);
        $this->ledger('lesson_charge',-45000,'CHG-IGST');
        (new Books())->sync($this->unit);
        $this->assertSame([0,0,-6864],[$this->balance('cgst'),$this->balance('sgst'),$this->balance('igst')]);
        $team=(object)['tax_mode'=>'none','state'=>'Kerala'];
        $this->assertSame(45000,Books::taxSplit($team,45000,null)['taxable']);
    }

    public function test_monthly_tax_invoices_credit_notes_and_parent_access(): void
    {
        $this->actingAs($this->admin);
        $charges=[$this->ledger('lesson_charge',-45000,'CHG-A'),$this->ledger('lesson_charge',-30000,'CHG-B'),$this->ledger('lesson_charge',-45000,'CHG-REVERSED')];
        $this->ledger('reversal',45000,'REV-C',['reverses_id'=>$charges[2]]);
        $month=now('Asia/Kolkata')->format('Y-m');
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$month])->assertOk()->assertJsonPath('message',fn($s)=>str_contains($s,'1 invoice(s) issued for 2 class charge(s)'));
        $invoice=DB::table('erp_invoices')->first();
        $fy=Invoicing::financialYear(now('Asia/Kolkata')->toDateString());
        $this->assertSame(['KT/'.$fy.'/0001','tax_invoice',63560,75000,5720,5720,0],[$invoice->invoice_number,$invoice->document_type,(int)$invoice->subtotal_paise,(int)$invoice->total_paise,(int)$invoice->cgst_paise,(int)$invoice->sgst_paise,(int)$invoice->igst_paise]);
        $this->assertSame((int)$invoice->total_paise,(int)$invoice->subtotal_paise+(int)$invoice->tax_paise);
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$month])->assertOk()->assertJsonPath('message','No new class charges to invoice for that month.');
        $doc=$this->getJson(route('app.api.print.invoice',[$this->unit,$invoice->id]))->assertOk()->assertJsonPath('title','Tax invoice')->assertJsonPath('supplier.taxId','32ABCDE1234F1Z5')
            ->assertJsonPath('lines.0.sac','999293')->assertJsonPath('taxes.1.label','CGST 9%')->assertJsonPath('words','Rupees Seven Hundred Fifty Only')->assertJsonPath('supplier.name','ClapsLearn Education Pvt Ltd')->assertJsonPath('hasGst',true);

        $this->postJson(route('erp.business.mutate',[$this->unit,'books-invoice-cancel']),['invoice_id'=>$invoice->id,'reason'=>'Wrong minutes on one class'])->assertOk();
        $note=DB::table('erp_invoices')->where('document_type','credit_note')->first();
        $this->assertSame(['KT-CN/'.$fy.'/0001',$invoice->id,75000],[$note->invoice_number,(int)$note->credits_invoice_id,(int)$note->total_paise]);
        $this->assertSame('cancelled',DB::table('erp_invoices')->find($invoice->id)->status);
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$month])->assertOk()->assertJsonPath('message',fn($s)=>str_contains($s,'1 invoice(s) issued'));
        $this->assertSame('KT/'.$fy.'/0002',DB::table('erp_invoices')->where('document_type','tax_invoice')->orderByDesc('id')->value('invoice_number'));
        $tax=(new Books())->taxSummary($this->unit,$month);
        $this->assertSame(['taxable'=>63560,'cgst'=>5720],['taxable'=>$tax['issued']['taxable'],'cgst'=>$tax['issued']['cgst']],'Credit note nets off the cancelled invoice.');

        \Illuminate\Support\Facades\Auth::logout();
        DB::table('erp_student_profiles')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'date_of_birth'=>'2013-04-21']);
        $this->postJson(route('parent.login.submit'),['student_code'=>'KT-001','date_of_birth'=>'2013-04-21'])->assertOk();
        $fees=collect($this->getJson(route('parent.api.section',['section'=>'fees']))->assertOk()->json('invoices'));
        $this->assertTrue($fees->contains(fn($i)=>$i['number']==='KT/'.$fy.'/0002'&&!$i['isCreditNote']));
        $this->assertTrue($fees->contains(fn($i)=>$i['number']==='KT-CN/'.$fy.'/0001'&&$i['isCreditNote']));
        $this->getJson(route('parent.api.document',['type'=>'invoice','id'=>$invoice->id]))->assertOk()->assertJsonPath('invoice.isCancelled',true)->assertJsonPath('invoice.number','KT/'.$fy.'/0001');
        $this->getJson(route('parent.api.document',['type'=>'invoice','id'=>$note->id]))->assertOk()->assertJsonPath('invoice.isCreditNote',true)->assertJsonPath('invoice.credited.number','KT/'.$fy.'/0001');
    }

    public function test_invoices_use_the_tax_each_charge_was_booked_with(): void
    {
        $this->actingAs($this->admin);
        DB::table('erp_units')->where('id',$this->unit)->update(['tax_mode'=>'none']);
        $this->ledger('lesson_charge',-118000,'CHG-PRE-GST');
        (new Books())->sync($this->unit);
        DB::table('erp_units')->where('id',$this->unit)->update(['tax_mode'=>'gst']);
        $this->ledger('lesson_charge',-59000,'CHG-GST');
        (new Books())->sync($this->unit);
        // The family moves state after the GST charge was booked; the invoice keeps the booked CGST/SGST.
        DB::table('erp_student_profiles')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'billing_state'=>'Tamil Nadu']);
        $month=now('Asia/Kolkata')->format('Y-m');
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$month])->assertOk()->assertJsonPath('message',fn($s)=>str_contains($s,'2 invoice(s) issued for 2 class charge(s)'));
        $plain=DB::table('erp_invoices')->where('document_type','invoice')->first();
        $taxed=DB::table('erp_invoices')->where('document_type','tax_invoice')->first();
        $this->assertSame([118000,0,null],[(int)$plain->subtotal_paise,(int)$plain->tax_paise,$plain->supplier_tax_id],'Charges booked before GST are not invoiced with GST.');
        $this->assertSame([50000,4500,4500,0,'Kerala'],[(int)$taxed->subtotal_paise,(int)$taxed->cgst_paise,(int)$taxed->sgst_paise,(int)$taxed->igst_paise,$taxed->place_of_supply]);
        $tax=(new Books())->taxSummary($this->unit,$month);
        foreach(['taxable','cgst','sgst','igst'] as $k) $this->assertSame($tax['booked'][$k],$tax['issued'][$k],"Books and invoices agree on $k.");
        $this->getJson(route('app.api.print.invoice',[$this->unit,$plain->id]))->assertOk()->assertJsonPath('title','Invoice')->assertJsonPath('hasGst',false)->assertJsonPath('supplier.taxId',null)->assertJsonPath('taxes',[])->assertJsonPath('taxNote','No tax is charged on this invoice.');
        DB::table('erp_units')->where('id',$this->unit)->update(['tax_mode'=>'none']);
        $this->getJson(route('app.api.print.invoice',[$this->unit,$taxed->id]))->assertOk()->assertJsonPath('title','Tax invoice')->assertJsonPath('supplier.taxId','32ABCDE1234F1Z5')->assertJsonPath('taxes.1.label','CGST 9%');
    }

    public function test_tax_summary_counts_documents_in_the_month_they_bill(): void
    {
        $this->actingAs($this->admin);
        $lastMonth=now('Asia/Kolkata')->subMonthNoOverflow()->startOfMonth();
        $this->ledger('lesson_charge',-59000,'CHG-LAST-MONTH',['occurred_at'=>$lastMonth->addDays(10)->format('Y-m-d H:i:s')]);
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$lastMonth->format('Y-m')])->assertOk()->assertJsonPath('message',fn($s)=>str_contains($s,'1 invoice(s) issued'));
        $last=(new Books())->taxSummary($this->unit,$lastMonth->format('Y-m'));
        $this->assertSame([50000,50000,1],[$last['booked']['taxable'],$last['issued']['taxable'],$last['documents']],'An invoice issued this month for last month counts in last month.');
        $this->assertSame(0,(new Books())->taxSummary($this->unit,now('Asia/Kolkata')->format('Y-m'))['documents']);
    }

    public function test_reversing_an_invoiced_class_charge_issues_a_credit_note(): void
    {
        $this->actingAs($this->admin);
        $subject=DB::table('erp_subjects')->insertGetId(['name'=>'Accounts Maths']);
        $enrollment=DB::table('erp_enrollments')->insertGetId(['unit_id'=>$this->unit,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$subject,'starts_on'=>'2026-01-01','status'=>'active','student_rate_paise'=>45000,'teacher_rate_paise'=>30000,'currency'=>'INR']);
        $at=now('UTC')->subHours(3)->format('Y-m-d H:00:00');
        $lesson=DB::table('erp_lessons')->insertGetId(['unit_id'=>$this->unit,'enrollment_id'=>$enrollment,'student_id'=>$this->student,'teacher_id'=>$this->teacher,'subject_id'=>$subject,'starts_at'=>$at,'ends_at'=>$at,'status'=>'completed','kind'=>'regular','student_rate_paise'=>45000,'teacher_rate_paise'=>30000,'currency'=>'INR']);
        $attendance=DB::table('erp_attendance')->insertGetId(['unit_id'=>$this->unit,'lesson_id'=>$lesson,'actual_minutes'=>60,'status'=>'approved','submitted_by'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
        $charge=$this->ledger('lesson_charge',-45000,'CHG-CLASS',['attendance_id'=>$attendance]);
        $this->ledger('lesson_charge',-30000,'CHG-OTHER');
        $month=now('Asia/Kolkata')->format('Y-m');
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$month])->assertOk()->assertJsonPath('message',fn($s)=>str_contains($s,'1 invoice(s) issued for 2 class charge(s)'));
        $invoice=DB::table('erp_invoices')->first();

        (new Finance())->reverseAttendance($this->unit,$this->admin->id,$attendance,'Class did not happen');
        $this->assertSame('cancelled',DB::table('erp_invoices')->find($invoice->id)->status);
        $note=DB::table('erp_invoices')->where('document_type','credit_note')->first();
        $this->assertSame([(int)$invoice->id,75000],[(int)$note->credits_invoice_id,(int)$note->total_paise]);
        $this->assertStringNotContainsString('Class did not happen',$note->notes,'Staff reasons stay off documents families can open.');
        // The remaining charge is invoiced again on the next run; the reversed one is not.
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$month])->assertOk()->assertJsonPath('message',fn($s)=>str_contains($s,'1 invoice(s) issued for 1 class charge(s)'));
        $this->assertNull(DB::table('erp_ledger')->find($charge)->invoice_id);
        $tax=(new Books())->taxSummary($this->unit,$month);
        $this->assertSame($tax['booked']['taxable'],$tax['issued']['taxable']);
    }

    public function test_states_are_chosen_from_a_list_and_abbreviations_mean_the_same_place(): void
    {
        $team=(object)['tax_mode'=>'gst','state'=>'Kerala'];
        $this->assertFalse(Books::taxSplit($team,11800,'KL')['interstate'],'KL is Kerala, so CGST/SGST.');
        $this->assertFalse(Books::taxSplit($team,11800,' kerala')['interstate']);
        $this->assertTrue(Books::taxSplit($team,11800,'tamil nadu')['interstate']);

        $this->actingAs($this->admin);
        $this->postJson(route('erp.business.mutate',[$this->unit,'student-update']),['student_id'=>$this->student,'billing_state'=>'Kerla'])->assertUnprocessable()->assertJsonValidationErrors('billing_state');
        $this->postJson(route('erp.business.mutate',[$this->unit,'student-update']),['student_id'=>$this->student,'billing_state'=>'tn'])->assertOk();
        $this->assertSame('Tamil Nadu',DB::table('erp_student_profiles')->where('student_id',$this->student)->value('billing_state'));

        $team=['code'=>'KT','name'=>'Kochi Tuition','timezone'=>'Asia/Kolkata','tax_mode'=>'gst','legal_name'=>'ClapsLearn Education Pvt Ltd'];
        $this->postJson(route('erp.teams.update',$this->unit),$team+['state'=>'Tamil Nadu','tax_id'=>'32ABCDE1234F1Z5'])->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->postJson(route('erp.teams.update',$this->unit),$team+['state'=>'KL','tax_id'=>'32ABCDE1234F1Z5'])->assertOk();
        $this->assertSame('Kerala',DB::table('erp_units')->where('id',$this->unit)->value('state'));
    }

    public function test_unit_staff_can_read_the_state_list_and_a_students_billing_state(): void
    {
        $this->actingAs($this->admin);
        $this->postJson(route('erp.business.mutate',[$this->unit,'student-update']),['student_id'=>$this->student,'billing_state'=>'tn'])->assertOk();
        // Administrators get the list for the team form.
        $this->assertContains('Tamil Nadu',$this->getJson(route('app.api.admin.teams'))->assertOk()->json('states'));
        $manager=$this->createMember(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$manager->id,'role'=>'team_manager']);
        $this->actingAs($manager);
        $profile=$this->getJson(route('app.api.student',[$this->unit,$this->student]))->assertOk()
            ->assertJsonPath('student.billingState','Tamil Nadu')->assertJsonPath('can.updateStudent',true);
        $this->assertContains('Kerala',$profile->json('states'));
    }

    public function test_changing_a_billing_state_keeps_the_tax_on_charges_already_made(): void
    {
        $this->actingAs($this->admin);
        // No profile yet: the first billing state set still posts earlier charges under the team's state.
        $this->ledger('lesson_charge',-11800,'CHG-BEFORE-MOVE');
        $this->postJson(route('erp.business.mutate',[$this->unit,'student-update']),['student_id'=>$this->student,'billing_state'=>'Tamil Nadu'])->assertOk();
        $this->ledger('lesson_charge',-11800,'CHG-AFTER-MOVE');
        (new Books())->sync($this->unit);
        $this->assertSame([-900,-900,-1800],[$this->balance('cgst'),$this->balance('sgst'),$this->balance('igst')]);
        $this->ledger('payment',0,'PAY-ZERO');
        $this->assertSame(1,(new Books())->sync($this->unit),'A zero-amount row is marked posted and does not block later syncs.');
        $this->assertSame(0,(new Books())->pendingCount($this->unit));
    }

    public function test_page_loads_post_a_bounded_batch_and_say_what_is_left(): void
    {
        for ($i=0; $i<5; $i++) $this->ledger('payment',1000,'PAY-'.$i);
        $books=new Books();
        $this->assertSame(2,$books->sync($this->unit,2));
        $this->assertSame(3,$books->pendingCount($this->unit));
        $this->assertSame(3,$books->sync($this->unit));
        $this->assertSame(0,$books->pendingCount($this->unit));
        $this->ledger('payment',1000,'PAY-LATE');
        $this->assertSame(1,$books->pendingCount($this->unit));
        $this->actingAs($this->admin)->getJson(route('app.api.accounts.overview',$this->unit))->assertOk()->assertJsonPath('pendingPosts',0);
        $this->assertSame(0,$books->pendingCount($this->unit));
    }

    public function test_tax_settings_are_validated_and_existing_charges_keep_their_treatment(): void
    {
        $this->actingAs($this->admin);
        $team=['code'=>'GO','name'=>'Gulf Online','timezone'=>'Asia/Dubai'];
        $this->postJson(route('erp.teams.create'),$team+['tax_mode'=>'gst','state'=>'Kerala','tax_id'=>'not-a-gstin'])->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->postJson(route('erp.teams.create'),$team+['tax_mode'=>'gst','tax_id'=>'32ABCDE1234F1Z5'])->assertUnprocessable()->assertJsonValidationErrors('state');
        $this->postJson(route('erp.teams.create'),$team)->assertOk();
        $gulf=DB::table('erp_units')->where('code','GO')->value('id');
        DB::table('erp_ledger')->insert(['unit_id'=>$gulf,'student_id'=>$this->student,'kind'=>'lesson_charge','amount_paise'=>-50000,'currency'=>'INR','reference'=>'GO-CHG','event_key'=>'go-chg','occurred_at'=>now(),'created_by'=>$this->admin->id]);
        $this->postJson(route('erp.teams.update',$gulf),$team+['tax_mode'=>'gst','state'=>'Kerala','tax_id'=>'32ABCDE1234F1Z5'])->assertOk();
        $books=new Books();
        $this->assertSame(-50000,(int)$books->balances($gulf,null,now()->toDateString())->firstWhere('system_key','tuition')->balance,'Charges posted before GST was switched on carry no GST.');
    }

    public function test_manual_journals_and_who_can_use_accounts(): void
    {
        $this->actingAs($this->admin)->getJson(route('app.api.accounts.overview',$this->unit))->assertOk()->assertJsonPath('can.books-journal',true);
        $rent=(new Books())->createAccount($this->unit,$this->admin->id,['code'=>'6100','name'=>'Rent','type'=>'expense']);
        $bank=(new Books())->account($this->unit,'bank');
        $finance=$this->createMember(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$finance->id,'role'=>'finance']);
        $journal=['entry_date'=>now('Asia/Kolkata')->toDateString(),'memo'=>'October rent','lines'=>[['account_id'=>$rent->id,'debit_inr'=>'15000'],['account_id'=>$bank,'credit_inr'=>'14000']]];
        $this->actingAs($finance)->postJson(route('erp.business.mutate',[$this->unit,'books-journal']),$journal)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $journal['lines'][1]['credit_inr']='15000';
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-journal']),$journal)->assertOk()->assertJsonPath('message','Journal MJ-00001 posted.');
        $this->assertSame(1500000,$this->balance('bank')*-1);
        $entry=DB::table('erp_journal_entries')->where('reference','MJ-00001')->value('id');
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-journal-reverse']),['entry_id'=>$entry,'reason'=>'Paid by the landlord directly'])->assertOk();
        $this->assertSame(0,$this->balance('bank'));
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-journal']),$journal)->assertOk()->assertJsonPath('message','Journal MJ-00002 posted.');
        $this->assertSame(2,DB::table('erp_journal_entries')->whereIn('reference',['MJ-00001','MJ-00002'])->count(),'Reversals do not use up journal numbers.');
        $views=['overview','journals','ledger','reports','invoices','tax','chart'];
        foreach ($views as $view) $this->getJson(route('app.api.accounts.'.$view,$this->unit))->assertOk();
        $this->getJson(route('app.api.accounts.journals',$this->unit))->assertOk()->assertSee('MJ-00001')->assertSee('MJ-00002');
        $this->getJson(route('app.api.overview.insights',$this->unit))->assertForbidden();
        $readonly=$this->createMember(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$readonly->id,'role'=>'read_only']);
        $this->actingAs($readonly)->getJson(route('app.api.accounts.journals',$this->unit))->assertOk();
        $this->getJson(route('app.api.accounts.overview',$this->unit))->assertOk()->assertJsonPath('can.books-journal',false);
        $this->postJson(route('erp.business.mutate',[$this->unit,'books-journal']),$journal)->assertForbidden();
        $academics=$this->createMember(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$academics->id,'role'=>'academics']);
        $this->actingAs($academics);
        foreach ($views as $view) $this->getJson(route('app.api.accounts.'.$view,$this->unit))->assertForbidden();
    }

    public function test_trends_dashboard_shows_six_months(): void
    {
        $this->ledger('lesson_charge',-45000,'CHG-T'); $this->ledger('payment',100000,'PAY-T');
        DB::table('erp_leads')->insert(['unit_id'=>$this->unit,'name'=>'Lead','phone'=>'9800000099','enquiry_date'=>now('Asia/Kolkata')->toDateString(),'status'=>'LEAD','created_by'=>$this->admin->id]);
        $months=$this->actingAs($this->admin)->getJson(route('app.api.overview.insights',$this->unit))->assertOk()->assertJsonPath('can.seeMoney',true)->json('months');
        $this->assertCount(6,$months);
        $current=collect($months)->firstWhere('month',now('Asia/Kolkata')->format('Y-m'));
        $this->assertSame([45000,100000,1],[$current['billedPaise'],$current['collectedPaise'],$current['enquiries']]);
        $manager=$this->createMember(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$manager->id,'role'=>'team_manager']);
        $this->actingAs($manager)->getJson(route('app.api.overview.insights',$this->unit))->assertOk()->assertJsonCount(6,'months');
    }
}
