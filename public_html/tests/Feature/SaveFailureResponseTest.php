<?php
namespace Tests\Feature;

use App\Infrastructure\Cloudflare\CommitRejected;
use App\Infrastructure\Cloudflare\TransactionTooLarge;
use App\Infrastructure\Cloudflare\UnconfirmedCommit;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SaveFailureResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->post('/__test/save/{kind}', function (string $kind) {
            throw match ($kind) {
                'rejected' => new CommitRejected('D1 did not apply this change. Nothing was saved; retry the request.'),
                'unconfirmed' => new UnconfirmedCommit('D1 commit was not confirmed.'),
                'large' => new TransactionTooLarge('This change needs 1200 database writes; at most 900 can be saved at once. Nothing was saved.'),
            };
        });
    }

    public function test_rejected_save_says_nothing_was_saved_and_skips_the_session_store(): void
    {
        $response = $this->post('/__test/save/rejected', ['note' => 'Synthetic'])->assertStatus(409)
            ->assertSee('This was not saved')->assertSee('submit again')->assertHeader('Cache-Control', 'no-store, private');
        // The working copy cannot be used again in that request, so the session must not write to it.
        $this->assertInstanceOf(NullSessionHandler::class, app('session.store')->getHandler());
        $this->assertStringNotContainsString('Whoops', $response->getContent());
    }

    public function test_unconfirmed_save_tells_staff_to_check_before_resubmitting(): void
    {
        $this->post('/__test/save/unconfirmed')->assertStatus(503)
            ->assertSee('We could not confirm this was saved')->assertSee('check whether it is already there');
        $this->postJson('/__test/save/unconfirmed')->assertStatus(503)
            ->assertJsonFragment(['message' => 'We could not confirm this was saved. The connection to the database was interrupted. The change may or may not have been saved. Before submitting again, open the record and check whether it is already there, so it is not saved twice.']);
    }

    public function test_a_real_d1_conflict_renders_the_page_with_database_sessions(): void
    {
        if (getenv('D1_COMPAT_TEST') !== '1') $this->markTestSkipped('Needs the D1 adapter: run with D1_COMPAT_TEST=1.');
        config(['session.driver' => 'database']);
        Route::middleware('web')->post('/__test/d1-conflict', function () {
            DB::table('erp_units')->count(); // open the working copy at the current revision
            // Another request commits first: the remote revision moves past this working copy.
            app(\Tests\Support\SqliteD1Gateway::class)->remote()->exec('UPDATE erp_d1_revision SET revision = revision + 1');
            DB::table('erp_units')->insert(['code' => 'CONFLICT', 'name' => 'Never saved', 'timezone' => 'UTC']);
        });
        $this->post('/__test/d1-conflict')->assertStatus(409)->assertSee('This was not saved');
        $this->assertSame(0, (int) app(\Tests\Support\SqliteD1Gateway::class)->remote()->query("SELECT COUNT(*) FROM erp_units WHERE code = 'CONFLICT'")->fetchColumn());
    }

    public function test_oversized_save_returns_to_the_form_with_input_and_a_clear_error(): void
    {
        $this->from('/somewhere')->post('/__test/save/large', ['note' => 'Keep me', 'password' => 'never-flash'])
            ->assertRedirect('/somewhere')->assertSessionHasErrors('default')->assertSessionHasInput('note', 'Keep me')
            ->assertSessionMissing('_old_input.password');
        $this->postJson('/__test/save/large')->assertStatus(422)->assertJsonFragment(['message' => 'This change needs 1200 database writes; at most 900 can be saved at once. Nothing was saved. Split it into smaller parts.']);
    }
}
