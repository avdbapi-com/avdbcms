<?php

namespace App\Http\Controllers\User;

use App\Models\Transaction;
use App\Services\InvoiceService;
use App\Support\Studio\StudioSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The member's invoices (member design 6.8).
 */
class InvoiceController
{
    public function index(): View|RedirectResponse {

        $invoices = Transaction::where('user_id', Auth::id())
            ->with('subscription.plan')
            ->latest('date')
            ->latest('id')
            ->paginate(StudioSettings::int('per_page_users', 15, 1, 100));

        // 1.2.3 round 1 (R1-108): a page past the end of the list is its last page, not an empty "Showing 0-0 of 4"
        if ($invoices->total() > 0 && $invoices->currentPage() > $invoices->lastPage()) {
            return redirect()->to($invoices->url($invoices->lastPage()));
        }

        $user = Auth::user();
        $subscription = $user?->premium_subscription;

        return view('users.invoices.index', [
            'invoices' => $invoices,
            'premium' => (bool) ($user?->is_premium ?? false),
            // the head line: "Premium ends on 30 Sep 2026", "Premium renews on 24 Nov 2026" or "Premium ended on ..."
            'endsAt' => $subscription?->ends_at,
            'renewsAt' => $subscription?->next_payment,
            'canSell' => $this->premiumCanBeBought(),
        ]);
    }

    /**
     * studio-28 / account-7: an invoice whose PDF is missing (or empty) is made again from its transaction before it is
     * sent; when that is not possible right now the Invoices page says so. Never a 0-byte "PDF".
     * R1-027: the same for a stored PDF that is not this payment's current one (InvoiceService::isCurrent()): one made
     * with an older template (up to 1.2.2: another company and a fixed tax line), or another buyer's PDF left on the
     * disk when a restored database reused the number. Such a file is never sent.
     */
    public function show(Transaction $transaction) : Response|RedirectResponse {

        if (! InvoiceService::isCurrent($transaction)) {
            try {
                app(InvoiceService::class)->generate($transaction);
            } catch (Throwable $e) {
                Log::warning('Invoice PDF could not be made again: '.$e->getMessage(), ['transaction' => $transaction->id]);
            }
        }

        $path = $transaction->invoice_path;

        if (! InvoiceService::isCurrent($transaction, $path)) {
            return redirect()
                ->route('user.invoices.index')
                ->with('info', __('This invoice is being prepared, try again in a minute.'));
        }

        // saved under the number the PDF and the lists print ("INV-2026-0001.pdf", R1-117); the file on the disk keeps its
        // name (Transaction::invoice_name), where every release looks for it
        return Storage::response($path, InvoiceService::number($transaction).'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /** "See Premium" only while Premium can be bought (PremiumAvailability::canSell() when it is there, else the settings). */
    private function premiumCanBeBought(): bool
    {
        if (class_exists(\App\Support\PremiumAvailability::class) && method_exists(\App\Support\PremiumAvailability::class, 'canSell')) {
            try {
                return (bool) \App\Support\PremiumAvailability::canSell();
            } catch (Throwable) {
                return false;
            }
        }

        return StudioSettings::on('premium_enabled', false)
            && (StudioSettings::on('stripe_enabled', false) || StudioSettings::on('paypal_enabled', false));
    }
}
