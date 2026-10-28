<?php

namespace App\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Records that a background process is alive. */
class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    public static function beat(string $name, array $detail = []): void
    {
        DB::table('system_heartbeats')->upsert(
            [['name' => $name, 'last_seen_at' => now(), 'detail' => json_encode($detail), 'created_at' => now(), 'updated_at' => now()]],
            ['name'],
            ['last_seen_at', 'detail', 'updated_at'],
        );
    }

    public static function lastSeen(string $name): ?Carbon
    {
        $at = DB::table('system_heartbeats')->where('name', $name)->value('last_seen_at');

        return $at ? Carbon::parse($at) : null;
    }
}
