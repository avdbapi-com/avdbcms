<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class EmailTestController
{
    public function index(): View
    {
        $config = [
            'Driver' => config('mail.default'),
            'Host' => config('mail.mailers.smtp.host'),
            'Port' => config('mail.mailers.smtp.port'),
            'Encryption' => config('mail.mailers.smtp.encryption'),
            'Username' => config('mail.mailers.smtp.username'),
            'From Address' => config('mail.from.address'),
            'From Name' => config('mail.from.name'),
        ];

        // Get recent queued emails if using database queue
        $queuedEmails = [];
        try {
            $queuedEmails = DB::table('jobs')
                ->where('queue', 'emails')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {}

        return view('admin.email-test.index', compact('config', 'queuedEmails'));
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate([
            'to' => 'required|email',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
        ]);

        try {
            Mail::raw($request->body, function ($message) use ($request) {
                $message->to($request->to)
                    ->subject($request->subject);
            });

            if (config('mail.default') === 'log') {
                // The log transport never delivers anything; say so instead of "sent".
                return redirect()->route('admin.email-test.index')
                    ->withSuccess(__('Mailer is "log": the test email for :to was written to storage/logs/mail.log, not delivered. Configure SMTP in Settings → Email to send real mail.', ['to' => $request->to]));
            }

            return redirect()->route('admin.email-test.index')
                ->withSuccess("Test email sent to {$request->to}!");
        } catch (\Exception $e) {
            return redirect()->route('admin.email-test.index')
                ->withErrors(['email' => 'Failed: ' . $e->getMessage()]);
        }
    }
}
