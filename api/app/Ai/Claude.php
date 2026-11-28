<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A small client for Anthropic's Messages API, used by Course Studio to draft lessons and translations.
 * Only programme content (outcomes, lesson text) is ever sent: never learner or provider data.
 */
final class Claude
{
    public function configured(): bool
    {
        return (string) config('services.anthropic.key') !== '';
    }

    /**
     * Ask for a JSON answer and decode it.
     *
     * @return array<string, mixed>
     */
    public function json(string $system, string $prompt, int $maxTokens = 4000): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('AI drafting is not set up: add ANTHROPIC_API_KEY.');
        }
        $res = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(120)->retry(2, 2000, throw: false)->post(rtrim((string) config('services.anthropic.url'), '/').'/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => $system."\nAnswer with one JSON object only, no other text.",
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('The AI service did not answer (HTTP '.$res->status().'). Try again.');
        }
        $text = collect((array) $res->json('content'))->where('type', 'text')->pluck('text')->implode('');
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (! is_array($data)) {
            throw new RuntimeException('The AI answer could not be read. Try again.');
        }

        return $data;
    }
}
