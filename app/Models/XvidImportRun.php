<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XvidImportRun extends Model
{
    private const MAX_LOG_CHARS = 120000;
    private const MAX_LOG_LINES = 1500;

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'cancel_requested' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function appendLog(string $message): void
    {
        $message = trim(str_replace(["\r\n", "\r"], "\n", $message));
        $line = '[' . now()->format('H:i:s') . '] ' . $message;
        $log = trim((string) $this->log);
        $log = $log === '' ? $line : $log . PHP_EOL . $line;

        $lines = preg_split('/\R/', $log) ?: [];
        if (count($lines) > self::MAX_LOG_LINES) {
            $log = implode(PHP_EOL, array_slice($lines, -self::MAX_LOG_LINES));
        }

        if (strlen($log) > self::MAX_LOG_CHARS) {
            $log = substr($log, -self::MAX_LOG_CHARS);
            $firstNewline = strpos($log, "\n");
            if ($firstNewline !== false) {
                $log = substr($log, $firstNewline + 1);
            }
        }

        $this->forceFill(['log' => $log])->save();
    }
}
