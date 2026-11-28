<?php

declare(strict_types=1);

namespace App\Learning;

use App\Ai\Claude;
use App\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\ProgrammeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Course Studio (HQ). A lesson is edited as a draft:
 *   1. AI drafts it from the programme's outcomes (or an author writes it);
 *   2. AI drafts isiZulu, isiXhosa and Afrikaans translations;
 *   3. a subject expert marks each language as reviewed (content.review);
 *   4. it is published (content.publish): English must be reviewed; learners see only reviewed translations.
 * Any change to the text of a language clears that language's review.
 */
final class LessonStudio
{
    private const LANGUAGE_NAMES = ['zu' => 'isiZulu', 'xh' => 'isiXhosa', 'af' => 'Afrikaans'];

    public function __construct(
        private readonly Claude $ai,
        private readonly ActivityCatalogue $catalogue,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{blocks: list<array<string, mixed>>, i18n: array<string, mixed>, review: array<string, mixed>} */
    public static function draftOf(Activity $a): array
    {
        $d = $a->draft ?? $a->content;

        return ['blocks' => (array) ($d['blocks'] ?? []), 'i18n' => (array) ($d['i18n'] ?? []), 'review' => (array) ($d['review'] ?? [])];
    }

    /** @param array<string, mixed> $input */
    public function save(Activity $a, array $input, User $actor): Activity
    {
        $draft = self::draftOf($a);
        if (array_key_exists('blocks', $input)) {
            [$blocks] = LessonContent::clean($input['blocks']);
            if ($blocks !== $draft['blocks']) {
                $draft['review'] = []; // the English text changed: every language needs another look
            }
            $draft['blocks'] = $blocks;
            $draft['i18n'] = LessonContent::cleanI18n($draft['i18n'], $blocks);
        }
        if (array_key_exists('i18n', $input)) {
            $new = LessonContent::cleanI18n($input['i18n'], $draft['blocks']);
            foreach (LessonContent::LOCALES as $loc) {
                if (($new[$loc] ?? []) !== ($draft['i18n'][$loc] ?? [])) {
                    unset($draft['review'][$loc]);
                }
            }
            $draft['i18n'] = $new;
        }
        $meta = array_intersect_key($input, array_flip(['title', 'summary', 'minutes', 'weight', 'required']));
        $a->fill($meta + ['draft' => $draft, 'draft_updated_at' => now(), 'updated_by' => $actor->id])->save();

        return $a;
    }

    /** Draft the lesson's English blocks from its linked outcomes. Only programme content is sent to the AI. */
    public function aiDraft(Activity $a, User $actor): Activity
    {
        $outcomes = DB::table('outcomes')->where('programme_version_id', $a->programme_version_id)->whereIn('code', $a->outcomeCodes())
            ->orderBy('position')->get(['code', 'statement', 'plain_words']);
        $programme = $a->version->programme;
        $codes = Activity::query()->where('programme_version_id', $a->programme_version_id)->where('kind', Activity::SCENARIO)->pluck('title', 'code');
        $system = 'You write short, practical lessons for South African learners on QCTO occupational programmes. Learners use cheap phones, '
            .'often on little data, and many read English as a second language: use plain words, short sentences, and real workplace examples from South Africa. '
            .'Never invent facts about laws or standards. Each lesson takes 3 to 5 minutes.';
        $prompt = "Programme: {$programme->title}\nLesson title: {$a->title}\nLesson aim: ".($a->summary ?? '')."\nOutcomes it must teach:\n"
            .$outcomes->map(fn ($o) => "- {$o->code}: {$o->statement}")->implode("\n")
            ."\nSimulators learners can try afterwards (code: title):\n".$codes->map(fn ($t, $c) => "- {$c}: {$t}")->implode("\n")
            ."\n\nReturn {\"blocks\": [...]} with 5 to 9 blocks, in this order: one narration (a friendly opening, 2-3 sentences), text and keypoints blocks that teach, "
            .'at least two check blocks (multiple choice, 3 or 4 options, answer = index of the correct option, explain = why), and, if a listed simulator fits, a final try block. '
            .'Block shapes: {"type":"text","body":"..."} {"type":"keypoints","items":["..."]} {"type":"narration","script":"..."} '
            .'{"type":"check","prompt":"...","options":["..."],"answer":0,"explain":"...","outcome":"code"} {"type":"try","activity_code":"..."}. Do not use image or video blocks.';
        $data = $this->ask($system, $prompt);
        [$blocks] = LessonContent::clean(array_values(array_filter((array) ($data['blocks'] ?? []), fn ($b) => is_array($b) && in_array($b['type'] ?? '', ['text', 'keypoints', 'narration', 'check', 'try'], true))));
        if ($blocks === []) {
            throw ValidationException::withMessages(['ai' => 'The AI draft was empty. Try again.']);
        }
        $a = $this->save($a, ['blocks' => $blocks], $actor);
        $draft = self::draftOf($a);
        $draft['review']['en'] = ['ai' => true];
        $a->update(['draft' => $draft]);
        $this->audit->record('activities.ai_drafted', $a, null, ['blocks' => count($blocks)], $actor);

        return $a;
    }

    /**
     * Draft translations of the current English text. They are marked as AI drafts until reviewed.
     *
     * @param  list<string>  $locales
     */
    public function aiTranslate(Activity $a, array $locales, User $actor): Activity
    {
        $draft = self::draftOf($a);
        $source = [];
        foreach ($draft['blocks'] as $b) {
            $fields = array_intersect_key($b, array_flip(LessonContent::TEXT_FIELDS[$b['type']]));
            if ($fields !== []) {
                $source[$b['id']] = $fields;
            }
        }
        if ($source === []) {
            throw ValidationException::withMessages(['ai' => 'Write the English lesson first.']);
        }
        foreach (array_intersect($locales, LessonContent::LOCALES) as $loc) {
            $data = $this->ask(
                'You translate short workplace training lessons for South African learners. Use everyday, natural '.self::LANGUAGE_NAMES[$loc]
                .' as spoken today, not formal or archaic language. Keep technical terms that workers really use in English (for example RAM, Wi-Fi, ticket) in English. Keep the same meaning and the same number of list items.',
                'Translate every value into '.self::LANGUAGE_NAMES[$loc].". Keep the keys and structure exactly.\n".json_encode(['blocks' => $source], JSON_UNESCAPED_UNICODE),
            );
            $draft['i18n'][$loc] = (array) ($data['blocks'] ?? []);
            $draft['i18n'] = LessonContent::cleanI18n($draft['i18n'], $draft['blocks']);
            $draft['review'][$loc] = ['ai' => true];
        }
        $a->update(['draft' => $draft, 'draft_updated_at' => now()]);
        $this->audit->record('activities.ai_translated', $a, null, ['locales' => $locales], $actor);

        return $a;
    }

    /** A subject expert marks a language as reviewed (or takes it back). */
    public function review(Activity $a, string $locale, bool $reviewed, User $actor): Activity
    {
        $draft = self::draftOf($a);
        if ($locale !== 'en' && ($draft['i18n'][$locale] ?? []) === []) {
            throw ValidationException::withMessages(['locale' => 'There is no translation to review yet.']);
        }
        if ($reviewed) {
            $draft['review'][$locale] = ['by' => $actor->name, 'at' => now()->toIso8601String()];
        } else {
            unset($draft['review'][$locale]);
        }
        $a->update(['draft' => $draft]);
        $this->audit->record($reviewed ? 'activities.reviewed' : 'activities.review_withdrawn', $a, null, ['locale' => $locale], $actor);

        return $a;
    }

    /** Publish the draft. Learners get only the languages a subject expert has reviewed. */
    public function publish(Activity $a, User $actor): Activity
    {
        $draft = self::draftOf($a);
        [$blocks, $problems] = LessonContent::clean($draft['blocks']);
        $reviewed = fn (string $l) => isset($draft['review'][$l]['by']);
        if (! $reviewed('en')) {
            $problems[] = 'A subject expert must review the English lesson before it is published.';
        }
        foreach ($blocks as $b) {
            if ($b['type'] === 'try' && ! Activity::query()->where('programme_version_id', $a->programme_version_id)->where('code', $b['activity_code'])->where('kind', Activity::SCENARIO)->exists()) {
                $problems[] = "The simulator {$b['activity_code']} does not exist in this programme.";
            }
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['publish' => $problems]);
        }
        // Every translation is kept (so review can continue); learners see only reviewed ones (LessonContent::localise).
        $content = ['blocks' => $blocks, 'i18n' => $draft['i18n'], 'review' => $draft['review']];
        $changed = $content !== $a->content;
        $a->fill(['content' => $content, 'draft' => null, 'content_version' => $a->status === Activity::PUBLISHED && $changed ? $a->content_version + 1 : $a->content_version])->save();
        $this->catalogue->publish($a, $actor);

        return $a;
    }

    /** @return array<string, mixed> */
    private function ask(string $system, string $prompt): array
    {
        try {
            return $this->ai->json($system, $prompt);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['ai' => $e->getMessage()]);
        }
    }

    /**
     * When a new programme version is published, its lessons and scenarios carry forward, keeping links only to
     * outcomes that still exist. Learners on the old version keep the old copies.
     */
    public static function carryForward(ProgrammeVersion $from, ProgrammeVersion $to): int
    {
        $codes = DB::table('outcomes')->where('programme_version_id', $to->id)->pluck('code')->flip();
        $n = 0;
        foreach (Activity::query()->where('programme_version_id', $from->id)->where('status', '<>', 'retired')->get() as $old) {
            if (Activity::query()->where('programme_version_id', $to->id)->where('code', $old->code)->exists()) {
                continue;
            }
            $copy = $old->replicate();
            $copy->programme_version_id = $to->id;
            $copy->save();
            $links = array_values(array_filter($old->outcomeCodes(), fn ($c) => $codes->has($c)));
            DB::table('activity_outcomes')->insert(array_map(fn ($c) => ['activity_id' => $copy->id, 'outcome_code' => $c], $links));
            $n++;
        }

        return $n;
    }
}
