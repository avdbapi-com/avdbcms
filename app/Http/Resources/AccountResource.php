<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AccountResource extends JsonResource
{

    public static $wrap = null;

    private ?string $token = null;

    public function withToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'token' => $this->token,
            'avatar_url' => $this->avatar_url,
            'is_premium' => $this->is_premium,
            'language' => $this->language,
            'created_at' => $this->created_at,
        ];
    }
}
