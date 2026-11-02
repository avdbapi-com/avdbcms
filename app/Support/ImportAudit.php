<?php

namespace App\Support;

/**
 * The details of the Tools > Logs > Admin actions rows of Content > Import videos (AVDBAPI and XVIDAPI, R3-019): what an
 * administrator started, stopped, deleted or cleaned there. Written in English like every row's details (codes and
 * counts only, never a provider address, a filter or a key) and shown in the reader's language by App\Support\AuditDetails
 * (its import rules read exactly these forms).
 */
final class ImportAudit
{
    /** "#12", or "#12, dry run" for a run that saves nothing. */
    public static function run(int $id, bool $dryRun = false): string
    {
        return '#'.$id.($dryRun ? ', dry run' : '');
    }

    /**
     * What "Clean All" removed: "videos: 120, tags: 8, imports stopped: 1, queued jobs: 3" (the parts that are not zero;
     * videos always).
     */
    public static function cleaned(int $videos, int $tags = 0, int $stoppedRuns = 0, int $queuedJobs = 0): string
    {
        $parts = ['videos: '.$videos];

        foreach (['tags' => $tags, 'imports stopped' => $stoppedRuns, 'queued jobs' => $queuedJobs] as $name => $count) {
            if ($count > 0) {
                $parts[] = $name.': '.$count;
            }
        }

        return implode(', ', $parts);
    }
}
