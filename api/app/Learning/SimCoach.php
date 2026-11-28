<?php

declare(strict_types=1);

namespace App\Learning;

use App\Ai\Claude;
use App\Models\Activity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The AI coach inside a simulator (Sprint 4.7): "Why?" after a mistake, and "Help" when stuck.
 *
 * It answers in the learner's language, in plain words, and only about this step of this scenario and its QCTO
 * outcome. It never gives the exact answer, and it is not available in real-job mode. Only the scenario's own
 * text is sent to the AI: no name, no learner data. Answers are cached per step and language, so most
 * questions cost nothing. Without an API key, or if the AI fails, the coach falls back to the hints and the
 * "why it matters" note that the author wrote.
 */
final class SimCoach
{
    private const LANGUAGES = ['en' => 'English', 'zu' => 'isiZulu', 'xh' => 'isiXhosa', 'af' => 'Afrikaans'];

    public function __construct(private readonly Claude $ai) {}

    /** @return array{answer: string, source: 'ai'|'author'} */
    public function ask(Activity $scenario, string $stepId, string $kind, string $locale, ?string $variant): array
    {
        $content = ScenarioContent::localise($scenario->content ?? [], $locale);
        $step = collect(ScenarioContent::stepsFor($content, $variant))->firstWhere('id', $stepId);
        if (! is_array($step)) {
            return ['answer' => '', 'source' => 'author'];
        }
        $fallback = ['answer' => $kind === 'why' ? ($step['why'] !== '' ? $step['why'] : ($step['hints'][0] ?? '')) : implode(' ', $step['hints']), 'source' => 'author'];
        if ((string) config('services.anthropic.key') === '') {
            return $fallback;
        }
        $key = "coach:{$scenario->id}:{$scenario->content_version}:{$stepId}:{$kind}:{$locale}";

        try {
            /** @var array{answer: string, source: 'ai'} */
            return Cache::remember($key, now()->addDays(30), function () use ($scenario, $step, $kind, $locale, $content): array {
                $outcome = $step['outcome'] === null ? null : DB::table('outcomes')->where('programme_version_id', $scenario->programme_version_id)->where('code', $step['outcome'])->value('statement');
                $data = $this->ai->json(
                    'You are a patient workplace coach for South African learners on a QCTO occupational programme, inside a training simulator. '
                    .'Answer in '.self::LANGUAGES[$locale].', in plain everyday words a second-language reader understands, at most 60 words. '
                    .'Talk only about the task below. Never give the exact answer or name the exact object to touch; guide the learner to work it out. '
                    .'Never ask for personal information. Return {"answer": "..."}.',
                    json_encode([
                        'scenario' => $scenario->title, 'customer' => $content['job']['customer'] ?? null, 'step' => $step['prompt'],
                        'choices' => $step['options'] ?? null, 'why_it_matters' => $step['why'], 'author_hints' => $step['hints'], 'qcto_outcome' => $outcome,
                        'question' => $kind === 'why' ? 'The learner just made a mistake on this step. Explain why the right way matters, without giving the answer.' : 'The learner is stuck on this step. Give one helpful nudge that is more than the first hint but not the answer.',
                    ], JSON_UNESCAPED_UNICODE),
                    300,
                );
                $answer = trim(mb_substr((string) ($data['answer'] ?? ''), 0, 600));
                if ($answer === '') {
                    throw new \RuntimeException('Empty coach answer');
                }

                return ['answer' => $answer, 'source' => 'ai'];
            });
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
