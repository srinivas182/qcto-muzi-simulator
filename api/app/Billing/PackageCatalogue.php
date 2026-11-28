<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditLogger;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\Programme;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HQ's price list. Packages are drafted, published (then frozen by the database) and retired; a change to a
 * published package is made by duplicating it into a new draft. Every step is audited in platform context.
 */
final class PackageCatalogue
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param array<string, mixed> $fields */
    public function create(array $fields, User $actor): Package
    {
        $package = Package::query()->create($this->normalise($fields) + [
            'code' => $this->uniqueCode((string) ($fields['code'] ?? $fields['name'])),
            'status' => Package::DRAFT,
            'created_by' => $actor->id,
        ]);
        $this->log('packages.created', $package, $actor);

        return $package;
    }

    /** @param array<string, mixed> $fields */
    public function update(Package $package, array $fields, User $actor): Package
    {
        $this->assertDraft($package);
        $package->fill($this->normalise($fields + $package->only(['billing', 'term_months', 'includes_simulators', 'includes_courses'])))->save();
        $this->log('packages.changed', $package, $actor, ['fields' => array_keys($fields)]);

        return $package;
    }

    /** @param list<array{from_seats: int, unit_price_cents: int}> $bands */
    public function setPrices(Package $package, array $bands, User $actor): Package
    {
        $this->assertDraft($package);
        $froms = array_column($bands, 'from_seats');
        if (count($froms) !== count(array_unique($froms))) {
            throw ValidationException::withMessages(['prices' => 'Each price band needs a different starting number of seats.']);
        }
        DB::transaction(function () use ($package, $bands): void {
            $package->prices()->delete();
            foreach ($bands as $band) {
                $package->prices()->create(['from_seats' => (int) $band['from_seats'], 'unit_price_cents' => (int) $band['unit_price_cents']]);
            }
        });
        $this->log('packages.prices_changed', $package, $actor, ['bands' => $bands]);

        return $package->load('prices');
    }

    public function publish(Package $package, User $actor): Package
    {
        $this->assertDraft($package);
        $first = $package->prices()->min('from_seats');
        if ($first === null || (int) $first > $package->min_seats) {
            throw ValidationException::withMessages(['prices' => "Add a price that starts at {$package->min_seats} seat(s) or fewer."]);
        }
        $programme = $package->programme;
        if ($programme->status !== 'active' || ! $programme->published()->exists()) {
            throw ValidationException::withMessages(['programme_id' => 'The programme has no published version yet.']);
        }
        $package->forceFill(['status' => Package::PUBLISHED, 'published_at' => now()])->save();
        $this->log('packages.published', $package, $actor);

        return $package;
    }

    public function retire(Package $package, User $actor): Package
    {
        if ($package->status !== Package::PUBLISHED) {
            throw ValidationException::withMessages(['status' => 'Only a published package can be retired.']);
        }
        $package->forceFill(['status' => Package::RETIRED, 'retired_at' => now()])->save();
        $this->log('packages.retired', $package, $actor);

        return $package;
    }

    public function discard(Package $package, User $actor): void
    {
        $this->assertDraft($package);
        $this->log('packages.discarded', $package, $actor);
        $package->delete();
    }

    /** Copy any package into a new draft (the way to change a published package). */
    public function duplicate(Package $package, User $actor): Package
    {
        return DB::transaction(function () use ($package, $actor): Package {
            $copy = Package::query()->create($package->only(['name', 'description', 'programme_id', 'includes_simulators', 'includes_courses', 'billing', 'term_months', 'min_seats']) + [
                'code' => $this->uniqueCode($package->code),
                'status' => Package::DRAFT,
                'created_by' => $actor->id,
            ]);
            foreach ($package->prices as $price) {
                $copy->prices()->create($price->only(['from_seats', 'unit_price_cents']));
            }
            $this->log('packages.duplicated', $copy, $actor, ['from' => $package->code]);

            return $copy->load('prices');
        });
    }

    /** @return array<string, mixed> */
    public function present(Package $package): array
    {
        $package->loadMissing(['prices', 'programme']);

        return [
            'id' => $package->id,
            'code' => $package->code,
            'name' => $package->name,
            'description' => $package->description,
            'programme' => ['code' => $package->programme->code, 'title' => $package->programme->title, 'prefix' => $package->programme->prefix],
            'includes_simulators' => $package->includes_simulators,
            'includes_courses' => $package->includes_courses,
            'billing' => $package->billing,
            'term_months' => $package->term_months,
            'min_seats' => $package->min_seats,
            'status' => $package->status,
            'published_at' => $package->published_at?->toIso8601String(),
            'prices' => $package->prices->map(fn (PackagePrice $p) => ['from_seats' => $p->from_seats, 'unit_price_cents' => $p->unit_price_cents])->values()->all(),
        ];
    }

    /**
     * Published packages on active programmes with a published version: what providers can buy.
     *
     * @return Collection<int, Package>
     */
    public function onSale(): Collection
    {
        return Package::query()->with(['prices', 'programme'])->where('status', Package::PUBLISHED)
            ->whereHas('programme', fn ($q) => $q->where('status', 'active')->whereHas('versions', fn ($v) => $v->where('status', 'published')))
            ->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function normalise(array $fields): array
    {
        if (isset($fields['programme'])) {
            $fields['programme_id'] = Programme::query()->where('code', $fields['programme'])->value('id')
                ?? throw ValidationException::withMessages(['programme' => 'Choose a programme.']);
        }
        if (array_key_exists('includes_simulators', $fields) && ! $fields['includes_simulators'] && ! ($fields['includes_courses'] ?? false)) {
            throw ValidationException::withMessages(['includes_simulators' => 'A package must include simulators, courses or both.']);
        }
        if (($fields['billing'] ?? null) === Package::MONTHLY) {
            $fields['term_months'] = null;
        }
        unset($fields['programme'], $fields['code']);

        return $fields;
    }

    private function uniqueCode(string $base): string
    {
        $slug = Str::slug(preg_replace('/-\d+$/', '', $base) ?: 'package') ?: 'package';
        $code = Str::limit($slug, 50, '');
        for ($i = 2; Package::query()->where('code', $code)->exists(); $i++) {
            $code = Str::limit($slug, 50, '').'-'.$i;
        }

        return $code;
    }

    private function assertDraft(Package $package): void
    {
        if (! $package->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Published packages cannot change. Duplicate it to make a new draft.']);
        }
    }

    /** @param array<string, mixed> $meta */
    private function log(string $action, Package $package, User $actor, array $meta = []): void
    {
        $this->context->runAs(null, fn () => $this->audit->record($action, $package, null, ['code' => $package->code] + $meta, $actor), platform: true);
    }
}
