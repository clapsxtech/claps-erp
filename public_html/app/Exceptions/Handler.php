<?php

namespace App\Exceptions;

use App\Infrastructure\Cloudflare\CommitRejected;
use App\Infrastructure\Cloudflare\TransactionTooLarge;
use App\Infrastructure\Cloudflare\UnconfirmedCommit;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
        'confirm_password',
        'current_password',
        'code',
        'dob',
        'teacher_dob',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Too many writes for one D1 commit. Nothing was sent and the connection is still
        // usable, so return to the form with the input and a clear message.
        $this->renderable(function (TransactionTooLarge $e, Request $request) {
            $message = $e->getMessage().' Split it into smaller parts.';
            if ($request->expectsJson()) return response()->json(['message' => $message], 422);
            return back()->withInput($request->except($this->dontFlash))->withErrors(['default' => $message]);
        });

        // After a rejected or unconfirmed commit the working copy cannot be used again in this
        // request, so the session cannot be saved either: answer without touching the database.
        $this->renderable(function (CommitRejected $e, Request $request) {
            return $this->saveFailed($request, 409, 'This was not saved', [
                'Someone else changed the same records at the same moment, so nothing from this form was saved.',
                'Go back, check the details and submit again.',
            ]);
        });
        $this->renderable(function (UnconfirmedCommit $e, Request $request) {
            return $this->saveFailed($request, 503, 'We could not confirm this was saved', [
                'The connection to the database was interrupted. The change may or may not have been saved.',
                'Before submitting again, open the record and check whether it is already there, so it is not saved twice.',
            ]);
        });
    }

    private function saveFailed(Request $request, int $status, string $title, array $lines)
    {
        if ($request->hasSession()) $request->session()->setHandler(new NullSessionHandler());
        if ($request->expectsJson()) return response()->json(['message' => $title.'. '.implode(' ', $lines)], $status);
        return response()->view('errors.save-failed', compact('title', 'lines'), $status)->header('Cache-Control', 'no-store');
    }
}
