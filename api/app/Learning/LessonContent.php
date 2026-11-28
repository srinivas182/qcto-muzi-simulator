<?php

declare(strict_types=1);

namespace App\Learning;

use Illuminate\Support\Str;

/**
 * The shape of a lesson: short blocks that work on a small phone screen and on little data.
 *
 *   text       {body}                       short paragraph (simple Markdown: **bold**, lists)
 *   keypoints  {items[]}                    up to 6 key points
 *   image      {asset_id, alt}              picture with a description (read aloud for low-literacy learners)
 *   narration  {script, audio_asset_id?}    what the narrator says; audio is optional
 *   video      {asset_id, low_asset_id?, text}  optional avatar or demo video; text is the alternative on data saver
 *   check      {prompt, options[], answer, explain, hint?, outcome?}  a quick question that feeds progress; a wrong
 *                                       first answer gets the hint and one more try ("Fix it")
 *   try        {activity_code}              "try it in the simulator"
 *
 * Translations are stored per language as {blockId: {field: text}}; only text fields can be translated.
 */
final class LessonContent
{
    public const TYPES = ['text', 'keypoints', 'image', 'narration', 'video', 'check', 'try'];

    /** Translatable fields per block type. */
    public const TEXT_FIELDS = [
        'text' => ['body'], 'keypoints' => ['items'], 'image' => ['alt'], 'narration' => ['script'],
        'video' => ['text'], 'check' => ['prompt', 'options', 'explain', 'hint'], 'try' => [],
    ];

    public const LOCALES = ['zu', 'xh', 'af'];

    public const MAX_BLOCKS = 30;

    /**
     * Normalise blocks and list what is wrong (for saving a draft, problems are allowed; for publishing, not).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public static function clean(mixed $blocks): array
    {
        $out = [];
        $problems = [];
        foreach (array_slice(is_array($blocks) ? array_values($blocks) : [], 0, self::MAX_BLOCKS) as $i => $b) {
            if (! is_array($b) || ! in_array($b['type'] ?? null, self::TYPES, true)) {
                $problems[] = 'Block '.($i + 1).' has an unknown type.';

                continue;
            }
            $n = $i + 1;
            $s = fn (string $k, int $max) => mb_substr(trim((string) ($b[$k] ?? '')), 0, $max);
            $id = preg_match('/^[a-z0-9]{6,12}$/', (string) ($b['id'] ?? '')) === 1 ? (string) $b['id'] : Str::lower(Str::random(8));
            $block = ['id' => $id, 'type' => $b['type']];
            switch ($b['type']) {
                case 'text':
                    $block['body'] = $s('body', 1500);
                    $block['body'] === '' && $problems[] = "Block {$n}: write the text.";
                    break;
                case 'keypoints':
                    $block['items'] = array_values(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 140), array_slice((array) ($b['items'] ?? []), 0, 6)), fn ($x) => $x !== ''));
                    $block['items'] === [] && $problems[] = "Block {$n}: add at least one key point.";
                    break;
                case 'image':
                    $block['asset_id'] = (string) ($b['asset_id'] ?? '');
                    $block['alt'] = $s('alt', 300);
                    ($block['asset_id'] === '' || $block['alt'] === '') && $problems[] = "Block {$n}: choose a picture and describe it.";
                    break;
                case 'narration':
                    $block['script'] = $s('script', 1200);
                    $block['audio_asset_id'] = isset($b['audio_asset_id']) && $b['audio_asset_id'] !== '' ? (string) $b['audio_asset_id'] : null;
                    $block['script'] === '' && $problems[] = "Block {$n}: write what the narrator says.";
                    break;
                case 'video':
                    $block['asset_id'] = (string) ($b['asset_id'] ?? '');
                    $block['low_asset_id'] = isset($b['low_asset_id']) && $b['low_asset_id'] !== '' ? (string) $b['low_asset_id'] : null;
                    $block['text'] = $s('text', 1500);
                    ($block['asset_id'] === '' || $block['text'] === '') && $problems[] = "Block {$n}: upload the video and write the text shown on data saver.";
                    break;
                case 'check':
                    $block['prompt'] = $s('prompt', 300);
                    $block['options'] = array_values(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 200), array_slice((array) ($b['options'] ?? []), 0, 5)));
                    $block['answer'] = (int) ($b['answer'] ?? -1);
                    $block['explain'] = $s('explain', 500);
                    $block['hint'] = $s('hint', 200); // optional: shown after a wrong first answer, before one more try
                    $block['outcome'] = isset($b['outcome']) && $b['outcome'] !== '' ? (string) $b['outcome'] : null;
                    if ($block['prompt'] === '' || count(array_filter($block['options'], fn ($o) => $o !== '')) < 2 || ! array_key_exists($block['answer'], $block['options']) || $block['options'][$block['answer']] === '' || $block['explain'] === '') {
                        $problems[] = "Block {$n}: a check needs a question, at least two answers, the right one marked, and why it is right.";
                    }
                    break;
                case 'try':
                    $block['activity_code'] = $s('activity_code', 30);
                    $block['activity_code'] === '' && $problems[] = "Block {$n}: choose the simulator to try.";
                    break;
            }
            $out[] = $block;
        }
        if ($out === []) {
            $problems[] = 'Add at least one block.';
        }

        return [$out, $problems];
    }

    /**
     * Keep only translations of existing blocks' text fields.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function cleanI18n(mixed $i18n, array $blocks): array
    {
        $byId = [];
        foreach ($blocks as $b) {
            $byId[$b['id']] = self::TEXT_FIELDS[$b['type']];
        }
        $out = [];
        foreach (self::LOCALES as $loc) {
            foreach ((array) (((array) $i18n)[$loc] ?? []) as $id => $fields) {
                foreach ((array) $fields as $field => $value) {
                    if (in_array($field, $byId[$id] ?? [], true)) {
                        $out[$loc][$id][$field] = is_array($value) ? array_values(array_map(fn ($x) => mb_substr((string) $x, 0, 300), $value)) : mb_substr((string) $value, 0, 1500);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The text a learner sees in a language: the translation if a subject expert has reviewed it, otherwise English.
     *
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    public static function localise(array $content, string $locale): array
    {
        $blocks = $content['blocks'] ?? [];
        $tr = isset($content['review'][$locale]['by']) ? ($content['i18n'][$locale] ?? []) : [];

        return array_map(fn (array $b) => array_merge($b, array_intersect_key((array) ($tr[$b['id']] ?? []), array_flip(self::TEXT_FIELDS[$b['type']] ?? []))), $blocks);
    }
}
