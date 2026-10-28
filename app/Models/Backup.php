<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A database backup made by BackupService, and the result of its restore test. */
class Backup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'table_counts' => 'array',
            'verify_detail' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function path(): string
    {
        return rtrim(config('company_os.backups.path'), '/').'/'.$this->filename;
    }
}
