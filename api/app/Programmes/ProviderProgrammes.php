<?php

declare(strict_types=1);

namespace App\Programmes;

use App\Audit\AuditLogger;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\ProviderEvidenceRequirement;
use App\Models\ProviderOutcome;
use App\Models\ProviderProgrammeCheck;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A provider's own layer on top of HQ's published programme. Everything here is tenant data (row-level
 * security) and never changes HQ's version:
 *
 *  - the "to confirm before use" checklist: one answer per item, recorded for QCTO;
 *  - provider additions: extra outcomes and evidence, allowed only while HQ has switched them on for this
 *    provider (off by default). Existing additions stay visible if the switch is later turned off, but can
 *    no longer be changed.
 */
final class ProviderProgrammes
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function view(Programme $programme, Tenant $tenant): array
    {
        $version = $this->published($programme);
        $answers = ProviderProgrammeCheck::query()->where('programme_id', $programme->id)->get()->keyBy('item_key');
        $names = User::query()->whereIn('id', $answers->pluck('answered_by')->filter())->pluck('name', 'id');

        $checks = [];
        foreach ($version->to_confirm as $text) {
            $key = ProviderProgrammeCheck::keyFor((string) $text);
            $answer = $answers->get($key);
            $checks[] = [
                'key' => $key,
                'text' => $text,
                'status' => $answer?->status,
                'note' => $answer?->note,
                'answered_by' => $answer?->answered_by !== null ? ($names[$answer->answered_by] ?? null) : null,
                'answered_at' => $answer?->answered_at->toIso8601String(),
            ];
        }

        return [
            'checks' => $checks,
            'checks_done' => count(array_filter($checks, fn (array $c) => $c['status'] !== null)),
            'additions' => [
                'enabled' => $tenant->allowsProgrammeAdditions(),
                'outcomes' => ProviderOutcome::query()->where('programme_id', $programme->id)->orderBy('duty_area_number')->orderBy('created_at')->get()
                    ->map(fn (ProviderOutcome $o) => ['id' => $o->id, 'duty_area' => $o->duty_area_number, 'code' => $o->code, 'statement' => $o->statement, 'plain_words' => $o->plain_words])->all(),
                'evidence' => ProviderEvidenceRequirement::query()->where('programme_id', $programme->id)->orderBy('duty_area_number')->orderBy('created_at')->get()
                    ->map(fn (ProviderEvidenceRequirement $e) => ['id' => $e->id, 'duty_area' => $e->duty_area_number, 'description' => $e->description, 'type' => $e->type, 'min_count' => $e->min_count])->all(),
            ],
        ];
    }

    /** Record (or clear, with a null status) the provider's answer to one "to confirm" item. */
    public function answer(Programme $programme, string $key, ?string $status, ?string $note, User $actor): void
    {
        $version = $this->published($programme);
        $text = collect($version->to_confirm)->first(fn ($item) => ProviderProgrammeCheck::keyFor((string) $item) === $key);
        if ($text === null) {
            throw ValidationException::withMessages(['item' => 'This item is not on the programme checklist.'])->status(404);
        }

        $existing = ProviderProgrammeCheck::query()->where(['programme_id' => $programme->id, 'item_key' => $key])->first();
        $before = $existing?->only(['status', 'note']);

        if ($status === null) {
            $existing?->delete();
        } else {
            ($existing ?? new ProviderProgrammeCheck(['programme_id' => $programme->id, 'item_key' => $key]))->fill([
                'item_text' => $text, 'status' => $status, 'note' => $note, 'version_number' => $version->number,
                'answered_by' => $actor->id, 'answered_at' => now(),
            ])->save();
        }

        $this->audit->record('programmes.check_answered', $existing, ['before' => $before, 'after' => $status === null ? null : ['status' => $status, 'note' => $note]], [
            'programme' => $programme->code, 'version' => $version->number, 'item' => $text,
        ], $actor);
    }

    /** @param array{statement: string, plain_words?: string|null} $fields */
    public function addOutcome(Programme $programme, Tenant $tenant, int $area, array $fields, User $actor): ProviderOutcome
    {
        $this->assertEnabled($tenant);
        $this->assertDutyArea($programme, $area);

        return DB::transaction(function () use ($programme, $tenant, $area, $fields, $actor): ProviderOutcome {
            // Serialise code assignment per provider, programme and duty area (the unique index is the backstop).
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["provider-outcome:{$tenant->id}:{$programme->id}:{$area}"]);
            // Codes are never reused: count soft-deleted additions too.
            $used = ProviderOutcome::withTrashed()->where(['programme_id' => $programme->id, 'duty_area_number' => $area])->count();
            $outcome = ProviderOutcome::query()->create([
                'programme_id' => $programme->id, 'duty_area_number' => $area,
                'code' => sprintf('%s-%d.P%d', $programme->prefix, $area, $used + 1),
                'statement' => $fields['statement'], 'plain_words' => $fields['plain_words'] ?? null, 'created_by' => $actor->id,
            ]);
            $this->log('programmes.addition_added', $outcome, $programme, $actor, ['code' => $outcome->code]);

            return $outcome;
        });
    }

    /** @param array{statement?: string, plain_words?: string|null} $fields */
    public function updateOutcome(Programme $programme, Tenant $tenant, string $id, array $fields, User $actor): void
    {
        $this->assertEnabled($tenant);
        $outcome = ProviderOutcome::query()->where('programme_id', $programme->id)->findOrFail($id);
        $before = $outcome->only(array_keys($fields));
        $outcome->fill($fields)->save();
        $this->log('programmes.addition_changed', $outcome, $programme, $actor, ['code' => $outcome->code], ['before' => $before, 'after' => $fields]);
    }

    public function removeOutcome(Programme $programme, Tenant $tenant, string $id, User $actor): void
    {
        $this->assertEnabled($tenant);
        $outcome = ProviderOutcome::query()->where('programme_id', $programme->id)->findOrFail($id);
        $outcome->delete();
        $this->log('programmes.addition_removed', $outcome, $programme, $actor, ['code' => $outcome->code]);
    }

    /** @param array{description: string, type: string, min_count?: int|null} $fields */
    public function addEvidence(Programme $programme, Tenant $tenant, int $area, array $fields, User $actor): ProviderEvidenceRequirement
    {
        $this->assertEnabled($tenant);
        $this->assertDutyArea($programme, $area);
        $evidence = ProviderEvidenceRequirement::query()->create([
            'programme_id' => $programme->id, 'duty_area_number' => $area, 'description' => $fields['description'],
            'type' => $fields['type'], 'min_count' => $fields['min_count'] ?? null, 'created_by' => $actor->id,
        ]);
        $this->log('programmes.addition_added', $evidence, $programme, $actor, ['duty_area' => $area]);

        return $evidence;
    }

    /** @param array{description?: string, type?: string, min_count?: int|null} $fields */
    public function updateEvidence(Programme $programme, Tenant $tenant, string $id, array $fields, User $actor): void
    {
        $this->assertEnabled($tenant);
        $evidence = ProviderEvidenceRequirement::query()->where('programme_id', $programme->id)->findOrFail($id);
        $before = $evidence->only(array_keys($fields));
        $evidence->fill($fields)->save();
        $this->log('programmes.addition_changed', $evidence, $programme, $actor, ['duty_area' => $evidence->duty_area_number], ['before' => $before, 'after' => $fields]);
    }

    public function removeEvidence(Programme $programme, Tenant $tenant, string $id, User $actor): void
    {
        $this->assertEnabled($tenant);
        $evidence = ProviderEvidenceRequirement::query()->where('programme_id', $programme->id)->findOrFail($id);
        $evidence->delete();
        $this->log('programmes.addition_removed', $evidence, $programme, $actor, ['duty_area' => $evidence->duty_area_number]);
    }

    private function published(Programme $programme): ProgrammeVersion
    {
        $version = $programme->status === 'active' ? $programme->published()->first() : null;
        if ($version === null) {
            throw ValidationException::withMessages(['programme' => 'This programme is not published.'])->status(404);
        }

        return $version;
    }

    private function assertEnabled(Tenant $tenant): void
    {
        if (! $tenant->allowsProgrammeAdditions()) {
            throw new AccessDeniedHttpException('Adding your own outcomes is not switched on for your academy. Ask SimuWork HQ to switch it on.');
        }
    }

    private function assertDutyArea(Programme $programme, int $area): void
    {
        if (! $this->published($programme)->dutyAreas()->where('number', $area)->exists()) {
            throw ValidationException::withMessages(['duty_area' => 'This duty area is not in the published programme.']);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $changes
     */
    private function log(string $action, Model $subject, Programme $programme, User $actor, array $meta, ?array $changes = null): void
    {
        $this->audit->record($action, $subject, $changes, ['programme' => $programme->code] + $meta, $actor);
    }
}
