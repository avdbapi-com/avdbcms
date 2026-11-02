<?php

namespace App\Http\Middleware;

use Illuminate\Session\ExistenceAwareInterface;
use SessionHandlerInterface;

/**
 * The session handler of one picture request (ReadOnlySession): it reads through the site's own handler and keeps
 * nothing. Saving, ending (a new id after a sign-in ends the old one) and the clean-up lottery do nothing, unless
 * ReadOnlySession opens it for the one save it allows (a "remember me" sign-in on a session that had ended).
 * found() tells whether the session the browser sent was still stored.
 */
final class ReadOnlySessionHandler implements SessionHandlerInterface, ExistenceAwareInterface
{
    private ?string $readId = null;

    private bool $found = false;

    private bool $writable = false;

    public function __construct(private readonly SessionHandlerInterface $inner)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->inner->read($id);

        // The store reads once, when the session starts: that is the session the browser sent (or a new id).
        if ($this->readId === null) {
            $this->readId = $id;
            $this->found = is_string($data) && $data !== '';
        }

        return $data;
    }

    public function write(string $id, string $data): bool
    {
        return $this->writable ? $this->inner->write($id, $data) : true;
    }

    public function destroy(string $id): bool
    {
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    /**
     * Store::migrate() (a sign-in, a new id) tells the handler the session under the new id does not exist yet: the
     * database handler then inserts a row for the one allowed save instead of updating one.
     */
    public function setExists($value)
    {
        if ($this->inner instanceof ExistenceAwareInterface) {
            $this->inner->setExists($value);
        }

        return $this;
    }

    /** Whether the session the browser sent was still stored when the request read it. */
    public function found(): bool
    {
        return $this->found;
    }

    public function allowWrites(): void
    {
        $this->writable = true;
    }

    public function denyWrites(): void
    {
        $this->writable = false;
    }
}
