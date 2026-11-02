<?php

namespace App\Models;

use App\Support\FontAwesomeIconRegistry;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * @mixin IdeHelperCategory
 */
class Category extends Model implements Sortable
{
    use SortableTrait;

    protected $guarded = ['id'];

    public const string IMAGE_FOLDER = 'categories';

    public function videos () : HasMany {
        return $this->hasMany(Video::class);
    }

    protected function backgroundUrl(): Attribute
    {
        return Attribute::make(
            // the default picture: the path at a domain's root, the whole address on a site installed in a folder (R2R-05, SB-RV-01)
            get: fn () => $this->background ? Storage::url(self::IMAGE_FOLDER.'/'.$this->background) : site_file_url('/images/default_banner.jpg')
        );
    }

    protected function iconName(): Attribute
    {
        return Attribute::make(
            get: fn () => FontAwesomeIconRegistry::normalize($this->icon)
        );
    }

    protected function route(): Attribute
    {
        return Attribute::make(
            get: fn () => route('pages.category', $this->slug)
        );
    }

    /**
     * 1.2.3 round 3 (R3-080): on the site's own addresses (every one but the admin's pages) a category whose name uses a
     * word that is always blocked (App\Support\ContentSafety) is not found at all: the public API's list of its videos
     * answers it exactly like a number that does not exist (the missing model's answer, at the binding, before the
     * route's middleware run: the same status, body and headers), as its page does. The admin's pages find it (Content >
     * Categories marks it, and renaming it shows it again).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $category = parent::resolveRouteBinding($value, $field);

        return $category !== null && ! request()->routeIs('admin.*') && \App\Support\ContentSafety::hides((string) $category->title)
            ? null
            : $category;
    }
}
