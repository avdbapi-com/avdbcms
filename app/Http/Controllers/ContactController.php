<?php

namespace App\Http\Controllers;

use App\Http\Requests\Contact\ContactRequest;
use App\Notifications\Contact;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function show(): View
    {
        return view('contact.show');
    }

    public function contact (ContactRequest $request): RedirectResponse {

        list('name' => $name, 'email' => $email, 'message' => $message) = $request->only('name', 'email', 'message');

        Notification::route('mail', [
            config('site.admin_email', config('mail.support_mail', config('mail.from.address'))) => config('app.name'),
        ])->notify(new Contact($name, $email, $message));

        // A plain sentence without "!" (member design section 7), saying how the answer comes.
        return redirect()->route('contact.show')
            ->with('success', __('Your message is sent. We will answer you by e-mail.'));

    }
}
