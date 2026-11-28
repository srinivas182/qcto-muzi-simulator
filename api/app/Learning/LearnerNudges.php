<?php

declare(strict_types=1);

namespace App\Learning;

use App\Audit\AuditLogger;
use App\Identity\Messaging\SmsChannel;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\DomainService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gentle reminders for quiet learners (Sprint 4.6b).
 *
 * Daily, inside one provider's context: a learner who is at risk (started, then quiet for 7 days) gets one short
 * message in their own language with a link back to where they left off. At most once a week and at most
 * three times in a row; any activity starts the count again, and after three the facilitator follows up in
 * person. Learners can turn reminders off. Nothing is sent to learners who have finished or withdrawn.
 *
 * Channel: WhatsApp (an approved template) when configured, otherwise SMS, otherwise the log (development).
 * Only the learner's first name, the academy, the programme title and a link are sent.
 */
final class LearnerNudges
{
    public const MAX_IN_A_ROW = 3;

    public const EVERY_DAYS = 7;

    /** Short, warm, and the same meaning in each language. {name} {academy} {programme} {url} */
    public const TEXT = [
        'en' => '{academy}: Hi {name}, your {programme} training is waiting for you. Pick up where you left off: {url} (Turn reminders off in My details.)',
        'zu' => '{academy}: Sawubona {name}, ukuqeqeshwa kwakho kwe-{programme} kusakulindile. Qhubeka lapho ugcine khona: {url} (Vala izikhumbuzo ku-Imininingwane yami.)',
        'xh' => '{academy}: Molo {name}, uqeqesho lwakho lwe-{programme} lusakulindile. Qhubeka apho uyeke khona: {url} (Cima izikhumbuzo kwi-Iinkcukacha zam.)',
        'af' => '{academy}: Hallo {name}, jou {programme}-opleiding wag vir jou. Gaan voort waar jy opgehou het: {url} (Skakel herinneringe af by My besonderhede.)',
    ];

    public function __construct(
        private readonly SmsChannel $sms,
        private readonly AuditLogger $audit,
    ) {}

    /** Send today's reminders for one provider. Returns how many were sent. */
    public function run(Tenant $tenant): int
    {
        $sent = 0;
        $due = Enrolment::query()->with(['learner.user', 'programme'])
            ->whereIn('status', [Enrolment::ENROLLED, Enrolment::ACTIVE])->whereNotNull('started_at')
            ->where('reminders_sent', '<', self::MAX_IN_A_ROW)
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subDays(self::EVERY_DAYS)))
            ->get();
        foreach ($due as $e) {
            if (CohortProgress::atRisk($e) && $this->send($tenant, $e, null)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Send one reminder now (also used by the facilitator's "Nudge" button). */
    public function send(Tenant $tenant, Enrolment $e, ?User $actor): bool
    {
        $learner = $e->learner;
        if ($learner->reminders_off_at !== null || $learner->phone === null) {
            return false;
        }
        $user = $learner->user;
        $locale = $user !== null && in_array($user->locale, ['zu', 'xh', 'af'], true) ? $user->locale : 'en';
        $text = strtr(self::TEXT[$locale], [
            '{academy}' => $tenant->name, '{name}' => strtok($learner->first_names, ' ') ?: $learner->first_names,
            '{programme}' => $e->programme->title, '{url}' => 'https://'.DomainService::primaryHost($tenant).'/learn',
        ]);
        try {
            $channel = $this->deliver($learner->phone, $text, $locale);
        } catch (Throwable $ex) {
            report($ex);

            return false;
        }
        $e->update(['reminded_at' => now(), 'reminders_sent' => $e->reminders_sent + 1]);
        $this->audit->record('learning.reminder_sent', $e, null, ['channel' => $channel, 'locale' => $locale, 'by' => $actor === null ? 'schedule' : 'facilitator'], $actor);

        return true;
    }

    private function deliver(string $phone, string $text, string $locale): string
    {
        $override = config('identity.messaging.driver_override');
        $wa = (array) config('identity.messaging.whatsapp');
        if ($override !== 'log' && filled($wa['token'] ?? null) && filled($wa['phone_number_id'] ?? null) && filled($wa['reminder_template'] ?? null)) {
            $response = Http::withToken((string) $wa['token'])->timeout(8)->post(sprintf('https://graph.facebook.com/%s/%s/messages', $wa['api_version'], $wa['phone_number_id']), [
                'messaging_product' => 'whatsapp', 'to' => ltrim($phone, '+'), 'type' => 'template',
                'template' => ['name' => $wa['reminder_template'], 'language' => ['code' => $locale], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $text]]]]],
            ]);
            if ($response->successful()) {
                return 'whatsapp';
            }
            Log::warning('WhatsApp reminder failed: HTTP '.$response->status());
        }
        if ($override !== 'log' && $this->sms->isConfigured()) {
            $this->sms->sendText($phone, $text);

            return 'sms';
        }
        Log::info('Learner reminder (log channel)', ['to' => substr($phone, 0, 5).'…', 'text' => $text]);

        return 'log';
    }
}
