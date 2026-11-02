<?php

namespace App\Support\Upload18;

use App\Services\Upload18Service;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a member's browser sends to Upload18 in the apikey field of its direct upload (Upload18 API V2.1, "Browser uploads
 * (safe)"): never the site's key.
 *
 *  1. A short-lived upload token (POST /uploadtoken, 6 hours, enough for every piece of the biggest upload; locked to the
 *     site's category, folder and server when they are set). Upload18's own recommendation ("upload token: best").
 *  2. Only when Upload18 cannot make a token: the key itself, and only when GET /keyinfo says it is a restricted key with
 *     no more than Upload + Read (Upload18's "option 1": such a key can upload and list, never rename or delete).
 *  3. Otherwise nothing: the upload page says Upload18 could not start the upload. A master key, or a key that can edit
 *     or delete, never reaches a browser.
 */
final class BrowserUploadKey
{
    /** Seconds a token lives: Upload18's default, enough for 2 GB at about 1 Mbit/s with every piece. */
    public const TOKEN_SECONDS = 21600;

    public function __construct(private readonly Upload18Service $upload18) {}

    /**
     * @param  array{cid?: mixed, mycid?: mixed, fid?: mixed}  $locks
     * @return array{apikey: string, upload_url: string, kind: string}|null
     */
    public function issue(string $defaultUploadUrl, array $locks): ?array
    {
        if (! $this->upload18->hasKey()) {
            return null;
        }

        try {
            $token = $this->upload18->mintUploadToken($locks, self::TOKEN_SECONDS);

            return [
                'apikey' => $token['token'],
                'upload_url' => $token['upload_url'] ?? $defaultUploadUrl,
                'kind' => 'token',
            ];
        } catch (Throwable $e) {
            // The reason only (HTTP status, Upload18's error code): never the key or a token.
            Log::warning('Upload18 made no upload token: '.($e instanceof Upload18Unavailable ? $e->getMessage() : class_basename($e)));
        }

        $info = KeyInfo::forService($this->upload18);

        if ($info->allowsBrowserFallback()) {
            return [
                'apikey' => $this->upload18->apiKey(),
                'upload_url' => $defaultUploadUrl,
                'kind' => 'restricted_key',
            ];
        }

        Log::warning('Upload18 upload not started: no upload token, and the saved key is not a restricted Upload + Read key ('
            .($info->status === KeyInfo::OK ? $info->type.': '.implode(', ', $info->scopes) : $info->status).').');

        return null;
    }
}
