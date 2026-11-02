<?php

namespace App\Notifications\Support;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * The Unread tab's pager (UnreadKeys): Laravel's cursor paginator, plus a page that the walk's budget ended early before
 * the unread list index exists (at most a second of work a page). Such a page holds fewer rows than a full one, maybe
 * none, and goes on where the walk stopped ($resume, the last row looked at): its "next" (or, going back, "previous")
 * link starts there. A page without rows links back to where it came from.
 */
final class UnreadPaginator extends CursorPaginator
{
    /** Where the following page in this page's direction goes on (the walk stopped there), or null. */
    private ?Cursor $resume = null;

    public function resumeAt(?Cursor $resume): static
    {
        $this->resume = $resume;

        if ($resume !== null) {
            $this->hasMore = true; // more may follow in this direction (the walk did not reach the end)
        }

        return $this;
    }

    /** Whether the page was cut short by the walk's budget (more may follow; this page may even hold no row). */
    public function isPartial(): bool
    {
        return $this->resume !== null;
    }

    public function nextCursor()
    {
        $forward = $this->cursor === null || $this->cursor->pointsToNextItems();

        if ($this->resume !== null && $forward) {
            return self::at($this->resume, true);
        }

        if ($this->items->isEmpty() && ! $forward && $this->cursor !== null) {
            // a page going back that found no row: forward again from where it started
            return self::at($this->cursor, true);
        }

        return parent::nextCursor();
    }

    public function previousCursor()
    {
        $forward = $this->cursor === null || $this->cursor->pointsToNextItems();

        if ($this->resume !== null && ! $forward) {
            return self::at($this->resume, false);
        }

        if ($this->items->isEmpty() && $forward && $this->cursor !== null) {
            // a page going on that found no row: back from where it started
            return self::at($this->cursor, false);
        }

        return parent::previousCursor();
    }

    /** A cursor at the key of $from (created_at, id), pointing forward (older rows) or back (newer rows). */
    private static function at(Cursor $from, bool $forward): Cursor
    {
        return new Cursor(['created_at' => $from->parameter('created_at'), 'id' => $from->parameter('id')], $forward);
    }
}
