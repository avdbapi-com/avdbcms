<?php

namespace App\Http\Controllers\Admin;

use App\Services\SpamService;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SpamController
{
    public function index(SpamService $spamService): View {
        return view(AdminPreferences::usesNewAdmin() ? 'admin.spams.form' : 'admin.spams.index', [
            'words' => $spamService->getWordsAsString(),
            'emails' => $spamService->getEmailsAsString()
        ]);
    }

    public function update(SpamService $spamService, Request $request): RedirectResponse {

        $data = $request->only('words', 'emails');

        $before = ['words' => $spamService->getWords(), 'emails' => $spamService->getEmails()];

        $spamService->update($data);

        // Tools > Logs > Admin actions (R3-019): which list changed ("words, emails"), never what it holds.
        $changed = array_keys(array_filter([
            'words' => $before['words'] !== $spamService->getWords(),
            'emails' => $before['emails'] !== $spamService->getEmails(),
        ]));

        if ($changed !== []) {
            AuditLogController::log('spam.updated', implode(', ', $changed));
        }

        return redirect()->route('admin.spams.index')
            ->with('success', __('Updated successfully'));
    }
}
