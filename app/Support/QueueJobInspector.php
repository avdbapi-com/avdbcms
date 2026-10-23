<?php

namespace App\Support;

use App\Models\AvdbImportRun;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class QueueJobInspector
{
    /**
     * @var array<string, array{0:string,1:string}>
     */
    private const PROTECTED_JOBS = [
        'App\\Jobs\\ProcessVideo' => ['Video processing', 'This job finalizes uploaded video records and should be run, paused, or retried instead of deleted.'],
        'App\\Jobs\\UploadToUpload18' => ['Upload transfer', 'This job sends video media to the remote upload provider and should not be deleted.'],
        'App\\Jobs\\PollUpload18Embed' => ['Embed polling', 'This job waits for the remote embed URL and should not be deleted.'],
        'App\\Jobs\\GenerateThumbnail' => ['Thumbnail generation', 'This job creates video thumbnails and should not be deleted.'],
        'App\\Jobs\\GenerateThumbnails' => ['Thumbnail generation', 'This job creates video thumbnails and should not be deleted.'],
        'App\\Jobs\\Export' => ['Export', 'This job creates a user export and should not be deleted.'],
        'App\\Jobs\\DeleteUser' => ['Account deletion', 'This job completes account deletion work and should not be deleted.'],
    ];

    /**
     * @param  array<string, mixed>|string|null  $payload
     * @return array{risk_level:string,risk_label:string,risk_description:string,delete_protected:bool,cleanup_group:string}
     */
    public static function inspectPayload(array|string|null $payload): array
    {
        $payload = self::normalizePayload($payload);
        $material = self::payloadSearchText($payload);

        if (self::contains($material, 'App\\Jobs\\RunAvdbImport')) {
            $runId = self::runIdFromCommand((string) data_get($payload, 'data.command', ''));
            $active = self::activeImportRun($runId);

            return [
                'risk_level' => $active ? 'protected' : 'low',
                'risk_label' => $active ? __('Protected') : __('Old import stub'),
                'risk_description' => $active
                    ? __('This AVDB import job is still active and should be cancelled from AVDB Import before deleting.')
                    : __('This AVDB import queue row no longer controls an active import run.'),
                'delete_protected' => $active,
                'cleanup_group' => $active ? 'protected' : 'obsolete_import',
            ];
        }

        foreach (self::PROTECTED_JOBS as $class => [$label, $description]) {
            if (self::contains($material, $class)) {
                return [
                    'risk_level' => 'protected',
                    'risk_label' => __($label),
                    'risk_description' => __($description),
                    'delete_protected' => true,
                    'cleanup_group' => 'protected',
                ];
            }
        }

        if (self::contains($material, 'Illuminate\\Broadcasting\\BroadcastEvent')
            || self::contains($material, 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated')) {
            return [
                'risk_level' => 'low',
                'risk_label' => __('Realtime only'),
                'risk_description' => __('Deleting skips a live browser broadcast/notification only; it does not remove videos or main records.'),
                'delete_protected' => false,
                'cleanup_group' => 'realtime',
            ];
        }

        return [
            'risk_level' => 'protected',
            'risk_label' => __('Unknown job'),
            'risk_description' => __('This job type is not recognized, so delete is blocked until it is reviewed.'),
            'delete_protected' => true,
            'cleanup_group' => 'protected',
        ];
    }

    /**
     * @param  array<string, mixed>|string|null  $payload
     * @return array<string, mixed>
     */
    private static function normalizePayload(array|string|null $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function payloadSearchText(array $payload): string
    {
        return implode("\n", array_filter([
            (string) ($payload['displayName'] ?? ''),
            (string) ($payload['job'] ?? ''),
            (string) data_get($payload, 'data.commandName', ''),
            (string) data_get($payload, 'data.command', ''),
        ]));
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $needle);
    }

    private static function runIdFromCommand(string $command): ?int
    {
        if ($command === '') {
            return null;
        }

        if (preg_match('/s:5:"runId";i:(\d+)/', $command, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private static function activeImportRun(?int $runId): bool
    {
        if ($runId === null) {
            return true;
        }

        try {
            if (! Schema::hasTable('avdb_import_runs')) {
                return true;
            }

            $status = AvdbImportRun::query()->whereKey($runId)->value('status');
        } catch (Throwable) {
            return true;
        }

        return in_array($status, ['queued', 'starting', 'running'], true);
    }
}
