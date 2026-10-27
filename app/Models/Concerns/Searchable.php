<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Case-insensitive substring search over the model's $searchable columns.
 * User input is matched literally: LIKE wildcards are escaped.
 */
trait Searchable
{
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // "!" rather than backslash: PDO's pgsql placeholder parser mishandles '\'.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            foreach ($this->searchable as $column) {
                $q->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$like]);
            }
        });
    }
}
