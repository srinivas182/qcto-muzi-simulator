<?php

declare(strict_types=1);

namespace App\Programmes;

use App\Audit\AuditLogger;
use App\Models\DutyArea;
use App\Models\EvidenceRequirement;
use App\Models\Outcome;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * HQ editing of programme content: draft → review → publish.
 *
 *  - A draft starts as a copy of the published version. Only drafts can be edited.
 *  - Duty area numbers and outcome codes never change once given (TS-1.3 stays TS-1.3), so simulators,
 *    lessons and logbook entries can link to them across versions. New items take the next free number.
 *  - Review must be done by someone other than the person who submitted the draft (super admins excepted,
 *    so a one-person HQ can still work). Publishing needs an approved review.
 */
final class ProgrammeStudio
{
    public function __construct(
        private readonly ProgrammeVersions $versions,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function startDraft(Programme $programme, User $actor): ProgrammeVersion
    {
        if ($programme->openDraft()->exists()) {
            throw ValidationException::withMessages(['draft' => 'A draft already exists for this programme.']);
        }
        $published = $programme->published()->firstOrFail();

        $draft = $this->versions->createDraft($programme, ProgrammePresenter::version($published), $published->source ?? [], $actor);
        $this->log('programmes.draft_started', $draft, $actor, ['from_version' => $published->number]);

        return $draft;
    }

    public function draft(Programme $programme, bool $editable = true): ProgrammeVersion
    {
        $draft = $programme->openDraft()->first();
        if ($draft === null) {
            throw ValidationException::withMessages(['draft' => 'Start a draft first.'])->status(404);
        }
        if ($editable && $draft->status !== ProgrammeVersion::DRAFT) {
            throw ValidationException::withMessages(['draft' => 'This draft is in review. Ask the reviewer to send it back before changing it.']);
        }

        return $draft;
    }

    /** @param array<string, mixed> $fields */
    public function updateDraft(Programme $programme, array $fields, User $actor): ProgrammeVersion
    {
        $draft = $this->draft($programme);
        $draft->update($fields);
        $this->log('programmes.draft_edited', $draft, $actor, ['fields' => array_keys($fields)]);

        return $draft;
    }

    public function discard(Programme $programme, User $actor): void
    {
        $draft = $this->draft($programme, editable: false);
        $this->log('programmes.draft_discarded', $draft, $actor);
        $draft->delete();
    }

    public function addDutyArea(Programme $programme, string $title, User $actor): DutyArea
    {
        $draft = $this->draft($programme);
        $area = $draft->dutyAreas()->create(['number' => (int) $draft->dutyAreas()->max('number') + 1, 'title' => $title]);
        $this->log('programmes.draft_edited', $draft, $actor, ['added_duty_area' => $area->number]);

        return $area;
    }

    public function updateDutyArea(Programme $programme, int $number, string $title, User $actor): DutyArea
    {
        $area = $this->dutyArea($this->draft($programme), $number);
        $area->update(['title' => $title]);

        return $area;
    }

    public function removeDutyArea(Programme $programme, int $number, User $actor): void
    {
        $draft = $this->draft($programme);
        $area = $this->dutyArea($draft, $number);
        $this->log('programmes.draft_edited', $draft, $actor, ['removed_duty_area' => $number]);
        $area->delete();
    }

    public function addOutcome(Programme $programme, int $number, string $statement, ?string $plainWords, User $actor): Outcome
    {
        $draft = $this->draft($programme);
        $area = $this->dutyArea($draft, $number);
        $codes = Outcome::query()->where('duty_area_id', $area->id)->pluck('code')->all();
        // Next free code in this duty area, also skipping codes used in earlier versions of the programme.
        $used = Outcome::query()->whereIn('programme_version_id', ProgrammeVersion::query()->where('programme_id', $programme->id)->select('id'))
            ->where('code', 'like', "{$programme->prefix}-{$number}.%")->pluck('code')->all();
        $next = 1 + max([0, ...array_map(fn (string $c) => (int) substr(strrchr($c, '.') ?: '.0', 1), [...$codes, ...$used])]);

        $outcome = $area->outcomes()->create([
            'programme_version_id' => $draft->id,
            'code' => "{$programme->prefix}-{$number}.{$next}",
            'position' => (int) $area->outcomes()->max('position') + 1,
            'statement' => $statement,
            'plain_words' => $plainWords,
        ]);
        $this->log('programmes.draft_edited', $draft, $actor, ['added_outcome' => $outcome->code]);

        return $outcome;
    }

    /** @param array{statement?: string, plain_words?: string|null} $fields */
    public function updateOutcome(Programme $programme, string $code, array $fields, User $actor): Outcome
    {
        $outcome = $this->outcome($this->draft($programme), $code);
        $outcome->update($fields);

        return $outcome;
    }

    public function removeOutcome(Programme $programme, string $code, User $actor): void
    {
        $draft = $this->draft($programme);
        $this->outcome($draft, $code)->delete();
        $this->log('programmes.draft_edited', $draft, $actor, ['removed_outcome' => $code]);
    }

    /** @param array{description: string, type: string, min_count?: int} $fields */
    public function addEvidence(Programme $programme, int $number, array $fields, User $actor): EvidenceRequirement
    {
        $draft = $this->draft($programme);
        $area = $this->dutyArea($draft, $number);

        return $area->evidence()->create($fields + [
            'programme_version_id' => $draft->id, 'position' => (int) $area->evidence()->max('position') + 1, 'min_count' => 1,
        ]);
    }

    /** @param array{description?: string, type?: string, min_count?: int} $fields */
    public function updateEvidence(Programme $programme, string $id, array $fields, User $actor): EvidenceRequirement
    {
        $item = EvidenceRequirement::query()->where('programme_version_id', $this->draft($programme)->id)->findOrFail($id);
        $item->update($fields);

        return $item;
    }

    public function removeEvidence(Programme $programme, string $id, User $actor): void
    {
        EvidenceRequirement::query()->where('programme_version_id', $this->draft($programme)->id)->findOrFail($id)->delete();
    }

    public function submit(Programme $programme, string $notes, User $actor): ProgrammeVersion
    {
        $draft = $this->draft($programme);
        if ($draft->outcomes()->count() === 0) {
            throw ValidationException::withMessages(['draft' => 'Add at least one outcome before sending for review.']);
        }
        $draft->update([
            'status' => ProgrammeVersion::IN_REVIEW, 'change_notes' => $notes, 'submitted_at' => now(), 'submitted_by' => $actor->id,
            'reviewed_by' => null, 'reviewed_at' => null, 'review_comment' => null,
        ]);
        $this->log('programmes.submitted', $draft, $actor, ['notes' => $notes]);

        return $draft;
    }

    public function approve(Programme $programme, ?string $comment, User $actor): ProgrammeVersion
    {
        $draft = $this->inReview($programme);
        $this->assertIndependent($draft, $actor);
        $draft->update(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_comment' => $comment]);
        $this->log('programmes.approved', $draft, $actor, ['comment' => $comment]);

        return $draft;
    }

    public function requestChanges(Programme $programme, string $comment, User $actor): ProgrammeVersion
    {
        $draft = $this->inReview($programme);
        $this->assertIndependent($draft, $actor);
        $draft->update(['status' => ProgrammeVersion::DRAFT, 'reviewed_by' => $actor->id, 'reviewed_at' => null, 'review_comment' => $comment]);
        $this->log('programmes.changes_requested', $draft, $actor, ['comment' => $comment]);

        return $draft;
    }

    public function publish(Programme $programme, User $actor): ProgrammeVersion
    {
        $draft = $this->inReview($programme);
        if ($draft->getAttribute('reviewed_at') === null) {
            throw ValidationException::withMessages(['draft' => 'A reviewer must approve this version before it is published.']);
        }

        return $this->versions->publish($draft, $actor);
    }

    private function inReview(Programme $programme): ProgrammeVersion
    {
        $draft = $this->draft($programme, editable: false);
        if ($draft->status !== ProgrammeVersion::IN_REVIEW) {
            throw ValidationException::withMessages(['draft' => 'This draft has not been sent for review.']);
        }

        return $draft;
    }

    private function assertIndependent(ProgrammeVersion $draft, User $actor): void
    {
        if ($draft->getAttribute('submitted_by') === $actor->id && ! $actor->hasPermission('roles.manage_platform')) {
            throw ValidationException::withMessages(['draft' => 'Someone other than the author must review this version.']);
        }
    }

    private function dutyArea(ProgrammeVersion $draft, int $number): DutyArea
    {
        return DutyArea::query()->where('programme_version_id', $draft->id)->where('number', $number)->firstOrFail();
    }

    private function outcome(ProgrammeVersion $draft, string $code): Outcome
    {
        return Outcome::query()->where('programme_version_id', $draft->id)->where('code', $code)->firstOrFail();
    }

    /** @param array<string, mixed> $meta */
    private function log(string $action, ProgrammeVersion $version, User $actor, array $meta = []): void
    {
        $meta = ['programme' => $version->programme->code, 'version' => $version->number] + $meta;
        $this->context->runAs(null, fn () => $this->audit->record($action, $version, null, $meta, $actor), platform: true);
    }
}
