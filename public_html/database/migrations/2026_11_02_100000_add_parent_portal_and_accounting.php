<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Parent portal requests, team tax settings, double-entry books and tax invoice fields. */
class AddParentPortalAndAccounting extends Migration
{
    public function up()
    {
        Schema::table('erp_student_profiles', function (Blueprint $t) {
            $t->string('billing_state', 60)->nullable();
        });
        Schema::table('erp_units', function (Blueprint $t) {
            $t->string('legal_name', 200)->nullable();
            $t->text('address')->nullable();
            $t->string('state', 60)->nullable();
            $t->string('tax_mode', 20)->default('none'); // none | gst
            $t->string('tax_id', 30)->nullable(); // GSTIN for GST teams
            $t->boolean('prices_include_tax')->default(true);
            $t->string('invoice_prefix', 20)->nullable();
        });
        Schema::create('erp_parent_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('unit_id')->index();
            $t->unsignedBigInteger('student_id')->index();
            $t->unsignedBigInteger('attendance_id')->nullable()->index();
            $t->string('kind', 20)->default('dispute');
            $t->text('note');
            $t->string('status', 20)->default('open');
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('erp_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('unit_id')->index();
            $t->string('code', 20);
            $t->string('name', 120);
            $t->string('type', 20); // asset | liability | equity | income | expense
            $t->string('system_key', 40)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['unit_id', 'code']);
            $t->unique(['unit_id', 'system_key']);
        });
        Schema::create('erp_journal_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('unit_id')->index();
            $t->date('entry_date');
            $t->string('reference', 120);
            $t->string('memo', 500)->nullable();
            $t->string('source_type', 30); // ledger | manual
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedBigInteger('reverses_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['source_type', 'source_id']);
            $t->index(['unit_id', 'entry_date']);
        });
        Schema::create('erp_journal_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('entry_id')->index();
            $t->unsignedBigInteger('unit_id')->index();
            $t->unsignedBigInteger('account_id')->index();
            $t->bigInteger('debit_paise')->default(0);
            $t->bigInteger('credit_paise')->default(0);
            $t->unsignedBigInteger('student_id')->nullable()->index();
            $t->unsignedBigInteger('teacher_id')->nullable()->index();
            $t->timestamps();
        });
        Schema::table('erp_invoices', function (Blueprint $t) {
            $t->string('document_type', 20)->default('invoice'); // invoice | tax_invoice | credit_note
            $t->unsignedBigInteger('credits_invoice_id')->nullable();
            $t->string('billing_period', 7)->nullable(); // YYYY-MM
            $t->string('place_of_supply', 60)->nullable();
            $t->bigInteger('cgst_paise')->default(0);
            $t->bigInteger('sgst_paise')->default(0);
            $t->bigInteger('igst_paise')->default(0);
            $t->string('supplier_tax_id', 30)->nullable();
        });
        Schema::table('erp_invoice_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('ledger_id')->nullable()->index();
            $t->bigInteger('taxable_paise')->default(0);
            $t->bigInteger('tax_paise')->default(0);
            $t->string('sac', 10)->nullable();
        });
    }

    public function down()
    {
        Schema::table('erp_invoice_lines', function (Blueprint $t) { $t->dropIndex(['ledger_id']); $t->dropColumn(['ledger_id', 'taxable_paise', 'tax_paise', 'sac']); });
        Schema::table('erp_invoices', fn (Blueprint $t) => $t->dropColumn(['document_type', 'credits_invoice_id', 'billing_period', 'place_of_supply', 'cgst_paise', 'sgst_paise', 'igst_paise', 'supplier_tax_id']));
        Schema::dropIfExists('erp_journal_lines');
        Schema::dropIfExists('erp_journal_entries');
        Schema::dropIfExists('erp_accounts');
        Schema::dropIfExists('erp_parent_requests');
        Schema::table('erp_units', fn (Blueprint $t) => $t->dropColumn(['legal_name', 'address', 'state', 'tax_mode', 'tax_id', 'prices_include_tax', 'invoice_prefix']));
        Schema::table('erp_student_profiles', fn (Blueprint $t) => $t->dropColumn(['billing_state']));
    }
}
