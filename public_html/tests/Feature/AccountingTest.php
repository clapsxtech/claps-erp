<?php
namespace Tests\Feature;

use App\Modules\Erp\Services\{Books,Invoicing};
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
        $this->post(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$month])->assertRedirect()->assertSessionHas('status',fn($s)=>str_contains($s,'1 invoice(s) issued for 2 class charge(s)'));
        $invoice=DB::table('erp_invoices')->first();
        $fy=Invoicing::financialYear(now('Asia/Kolkata')->toDateString());
        $this->assertSame(['KT/'.$fy.'/0001','tax_invoice',63560,75000,5720,5720,0],[$invoice->invoice_number,$invoice->document_type,(int)$invoice->subtotal_paise,(int)$invoice->total_paise,(int)$invoice->cgst_paise,(int)$invoice->sgst_paise,(int)$invoice->igst_paise]);
        $this->assertSame((int)$invoice->total_paise,(int)$invoice->subtotal_paise+(int)$invoice->tax_paise);
        $this->post(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$month])->assertSessionHas('status','No new class charges to invoice for that month.');
        $this->get(route('erp.invoice',[$this->unit,$invoice->id]))->assertOk()->assertSee('Tax invoice')->assertSee('GSTIN: 32ABCDE1234F1Z5')->assertSee('999293')->assertSee('CGST 9%')->assertSee('Rupees Seven Hundred Fifty Only')->assertSee('ClapsLearn Education Pvt Ltd');

        $this->post(route('erp.business.mutate',[$this->unit,'books-invoice-cancel']),['invoice_id'=>$invoice->id,'reason'=>'Wrong minutes on one class'])->assertRedirect();
        $note=DB::table('erp_invoices')->where('document_type','credit_note')->first();
        $this->assertSame(['KT-CN/'.$fy.'/0001',$invoice->id,75000],[$note->invoice_number,(int)$note->credits_invoice_id,(int)$note->total_paise]);
        $this->assertSame('cancelled',DB::table('erp_invoices')->find($invoice->id)->status);
        $this->post(route('erp.business.mutate',[$this->unit,'books-invoice-run']),['month'=>$month])->assertSessionHas('status',fn($s)=>str_contains($s,'1 invoice(s) issued'));
        $this->assertSame('KT/'.$fy.'/0002',DB::table('erp_invoices')->where('document_type','tax_invoice')->orderByDesc('id')->value('invoice_number'));
        $tax=(new Books())->taxSummary($this->unit,$month);
        $this->assertSame(['taxable'=>63560,'cgst'=>5720],['taxable'=>$tax['issued']['taxable'],'cgst'=>$tax['issued']['cgst']],'Credit note nets off the cancelled invoice.');

        \Illuminate\Support\Facades\Auth::logout();
        DB::table('erp_student_profiles')->insert(['unit_id'=>$this->unit,'student_id'=>$this->student,'date_of_birth'=>'2013-04-21']);
        $this->post(route('parent.login.submit'),['student_code'=>'KT-001','date_of_birth'=>'2013-04-21']);
        $this->get(route('parent.home',['section'=>'fees']))->assertSee('KT/'.$fy.'/0002')->assertSee('(credit note)');
        $this->get(route('parent.document',['type'=>'invoice','id'=>$invoice->id]))->assertOk()->assertSee('CANCELLED')->assertSee('Back to the parent portal');
    }

    public function test_tax_settings_are_validated_and_existing_charges_keep_their_treatment(): void
    {
        $this->actingAs($this->admin);
        $team=['code'=>'GO','name'=>'Gulf Online','timezone'=>'Asia/Dubai'];
        $this->post(route('erp.teams.create'),$team+['tax_mode'=>'gst','state'=>'Kerala','tax_id'=>'not-a-gstin'])->assertSessionHasErrors('tax_id');
        $this->post(route('erp.teams.create'),$team+['tax_mode'=>'gst','tax_id'=>'32ABCDE1234F1Z5'])->assertSessionHasErrors('state');
        $this->post(route('erp.teams.create'),$team)->assertRedirect();
        $gulf=DB::table('erp_units')->where('code','GO')->value('id');
        DB::table('erp_ledger')->insert(['unit_id'=>$gulf,'student_id'=>$this->student,'kind'=>'lesson_charge','amount_paise'=>-50000,'currency'=>'INR','reference'=>'GO-CHG','event_key'=>'go-chg','occurred_at'=>now(),'created_by'=>$this->admin->id]);
        $this->post(route('erp.teams.update',$gulf),$team+['tax_mode'=>'gst','state'=>'Kerala','tax_id'=>'32ABCDE1234F1Z5'])->assertRedirect();
        $books=new Books();
        $this->assertSame(-50000,(int)$books->balances($gulf,null,now()->toDateString())->firstWhere('system_key','tuition')->balance,'Charges posted before GST was switched on carry no GST.');
    }

    public function test_manual_journals_and_who_can_use_accounts(): void
    {
        $this->actingAs($this->admin)->get(route('erp.business',[$this->unit,'accounts']))->assertOk();
        $rent=(new Books())->createAccount($this->unit,$this->admin->id,['code'=>'6100','name'=>'Rent','type'=>'expense']);
        $bank=(new Books())->account($this->unit,'bank');
        $finance=$this->createMember(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$finance->id,'role'=>'finance']);
        $journal=['entry_date'=>now('Asia/Kolkata')->toDateString(),'memo'=>'October rent','lines'=>[['account_id'=>$rent->id,'debit_inr'=>'15000'],['account_id'=>$bank,'credit_inr'=>'14000']]];
        $this->actingAs($finance)->post(route('erp.business.mutate',[$this->unit,'books-journal']),$journal)->assertSessionHasErrors('lines');
        $journal['lines'][1]['credit_inr']='15000';
        $this->post(route('erp.business.mutate',[$this->unit,'books-journal']),$journal)->assertRedirect()->assertSessionHas('status','Journal MJ-00001 posted.');
        $this->assertSame(1500000,$this->balance('bank')*-1);
        $entry=DB::table('erp_journal_entries')->where('reference','MJ-00001')->value('id');
        $this->post(route('erp.business.mutate',[$this->unit,'books-journal-reverse']),['entry_id'=>$entry,'reason'=>'Paid by the landlord directly'])->assertRedirect();
        $this->assertSame(0,$this->balance('bank'));
        foreach (['overview','journals','ledger','reports','invoices','tax','chart'] as $view) $this->get(route('erp.business',[$this->unit,'accounts','view'=>$view]))->assertOk();
        $this->get(route('erp.business',[$this->unit,'insights']))->assertForbidden();
        $readonly=$this->createMember(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$readonly->id,'role'=>'read_only']);
        $this->actingAs($readonly)->get(route('erp.business',[$this->unit,'accounts','view'=>'journals']))->assertOk()->assertDontSee('Post journal');
        $this->post(route('erp.business.mutate',[$this->unit,'books-journal']),$journal)->assertForbidden();
        $academics=$this->createMember(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$academics->id,'role'=>'academics']);
        $this->actingAs($academics)->get(route('erp.business',[$this->unit,'accounts']))->assertForbidden();
    }

    public function test_trends_dashboard_shows_six_months(): void
    {
        $this->ledger('lesson_charge',-45000,'CHG-T'); $this->ledger('payment',100000,'PAY-T');
        DB::table('erp_leads')->insert(['unit_id'=>$this->unit,'name'=>'Lead','phone'=>'9800000099','enquiry_date'=>now('Asia/Kolkata')->toDateString(),'status'=>'LEAD','created_by'=>$this->admin->id]);
        $page=$this->actingAs($this->admin)->get(route('erp.business',[$this->unit,'insights']))->assertOk()->assertSee('Trends')->assertSee('INR 450.00')->assertSee('INR 1,000.00');
        $this->assertSame(6,substr_count($page->getContent(),'<svg class="trend-chart"')/2);
        $manager=$this->createMember(); DB::table('erp_memberships')->insert(['unit_id'=>$this->unit,'user_id'=>$manager->id,'role'=>'team_manager']);
        $this->actingAs($manager)->get(route('erp.business',[$this->unit,'insights']))->assertOk();
    }
}
