<?php

namespace App\Enums;

use App\Enums\Traits\Listable;

enum PlaylistStatus : int {

    use Listable;

    case PUBLIC = 0;
    case PRIVATE = 1;
    case UNLISTED = 2;

    public function color(): string
    {
        return match($this)
        {
            self::PUBLIC => 'success',
            self::PRIVATE => 'danger',
            self::UNLISTED => 'info',
        };
    }

    public function icon(): string
    {
        return match($this)
        {
            self::PUBLIC => 'earth-europe',
            self::PRIVATE => 'lock',
            self::UNLISTED => 'eye-slash',
        };
    }
}
