<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @mixin IdeHelperTransaction
 */
class Transaction extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'datetime',
        'amount' => MoneyCast::class,
        'tax' => MoneyCast::class,
        'fee' => MoneyCast::class,
    ];

    public $timestamps = false;

    public const string INVOICE_FOLDER = 'invoices';

    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription() : BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    protected function amountWithoutTax(): Attribute
    {
        return Attribute::make(
            get: fn () => (new MoneyCast())
                ->get($this, 'amount_without_tax',  $this->getRawOriginal('amount') - $this->getRawOriginal('tax'), ['country' => $this->country])
        );
    }

    protected function route(): Attribute
    {
        return Attribute::make(
            get: fn () => route('user.invoices.show', $this)
        );
    }

    /**
     * Invoice PDFs (billing name, address, VAT id; sequential ids) live in a folder named from the site's key, so they
     * cannot be found if a server ever serves storage/ (security plan S03): invoices-<16 hex>/<Y-m>/<invoice name>.
     */
    public static function invoiceFolder(): string
    {
        return self::INVOICE_FOLDER.'-'.substr(hash('sha256', 'invoices|'.(string) config('app.key')), 0, 16);
    }

    /**
     * The invoice folders named from an earlier key (invoices-<16 hex> other than invoiceFolder()): after APP_KEY was
     * changed, the PDFs saved before stay there until App\Support\StorageHider moves them (its daily run).
     *
     * @return list<string>
     */
    public static function earlierInvoiceFolders(): array
    {
        $current = self::invoiceFolder();

        try {
            $folders = Storage::directories();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter($folders, static fn (string $folder): bool => $folder !== $current
            && preg_match('/^'.self::INVOICE_FOLDER.'-[0-9a-f]{16}$/', $folder) === 1));
    }

    /**
     * The PDF's path on the default disk: in the folder named from the site's key; an invoice made before 1.2.3 that is
     * still in invoices/, or one saved before the site's key was changed, answers the path where it is. A new invoice
     * (no file yet) is saved in the current folder.
     */
    protected function invoicePath(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $relative = $this->date->format('Y-m').'/'.$this->invoice_name;
                $path = self::invoiceFolder().'/'.$relative;

                if (Storage::exists($path)) {
                    return $path;
                }

                foreach ([self::INVOICE_FOLDER, ...self::earlierInvoiceFolders()] as $folder) {
                    if (Storage::exists($folder.'/'.$relative)) {
                        return $folder.'/'.$relative;
                    }
                }

                return $path;
            }
        );
    }

    protected function invoiceName(): Attribute
    {
        return Attribute::make(
            get: fn () => 'Invoice' . $this->id . '-' .$this->date->format('Y-m-d').'.pdf'
        );
    }

    protected function invoiceUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::path($this->invoice_path)
        );
    }
}
