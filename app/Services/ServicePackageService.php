<?php

namespace App\Services;

use App\Models\ServicePackage;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class ServicePackageService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input  validated form input
     */
    public function create(array $input, User $actor): ServicePackage
    {
        return DB::transaction(function () use ($input, $actor) {
            $package = ServicePackage::query()->create($this->attributes($input));
            $this->audit->record('service_package.created', $package, [
                'base_price_minor' => $package->base_price_minor,
                'currency' => $package->currency,
            ], $actor);

            return $package;
        });
    }

    /**
     * @param  array<string, mixed>  $input  validated form input
     */
    public function update(ServicePackage $package, array $input, User $actor): ServicePackage
    {
        return DB::transaction(function () use ($package, $input, $actor) {
            $package->fill($this->attributes($input));

            $changes = [];
            foreach ($package->getDirty() as $field => $new) {
                $changes[$field] = ['from' => $package->getOriginal($field), 'to' => $package->{$field}];
            }

            if ($changes === []) {
                return $package;
            }

            $package->save();
            $this->audit->record('service_package.updated', $package, ['changes' => $changes], $actor);

            return $package;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input): array
    {
        return [
            'name' => $input['name'],
            'slug' => $input['slug'],
            'description' => $input['description'] ?? null,
            'scope' => self::lines($input['scope']),
            'exclusions' => self::lines($input['exclusions'] ?? ''),
            'delivery_template' => self::template($input['delivery_template'] ?? null),
            'base_price_minor' => Money::toMinor($input['base_price']),
            'currency' => $input['currency'],
            'estimated_days' => (int) $input['estimated_days'],
            'revision_limit' => (int) $input['revision_limit'],
            'is_active' => (bool) ($input['is_active'] ?? false),
        ];
    }

    /**
     * Parses the delivery template text: "# Milestone" lines start a
     * milestone, other lines are its tasks. Blank means the default template.
     *
     * @return list<array{name: string, tasks: list<string>}>|null
     */
    public static function template(?string $text): ?array
    {
        $milestones = [];
        foreach (self::lines($text) as $line) {
            if (str_starts_with($line, '#')) {
                $milestones[] = ['name' => trim(ltrim($line, '#')), 'tasks' => []];

                continue;
            }
            if ($milestones === []) {
                $milestones[] = ['name' => 'Delivery', 'tasks' => []];
            }
            $milestones[array_key_last($milestones)]['tasks'][] = ltrim($line, "-* \t");
        }

        return $milestones === [] ? null : array_values(array_filter($milestones, fn ($m) => $m['name'] !== ''));
    }

    /**
     * @param  list<array{name: string, tasks: list<string>}>|null  $template
     */
    public static function templateText(?array $template): string
    {
        return collect($template ?? [])->map(fn ($m) => '# '.$m['name'].collect($m['tasks'])->map(fn ($t) => "\n- {$t}")->implode(''))->implode("\n");
    }

    /**
     * @return list<string>
     */
    public static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text)), fn ($l) => $l !== ''));
    }
}
