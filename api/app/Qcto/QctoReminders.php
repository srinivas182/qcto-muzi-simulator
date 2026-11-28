<?php

declare(strict_types=1);

namespace App\Qcto;

use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\QctoDeadlineReminder;
use App\Tenancy\DomainService;
use Illuminate\Support\Facades\Notification;

/**
 * Daily, inside one provider's context: remind its admins when a cohort's QCTO enrolment upload is due within
 * 5 working days (level 1), and again when it is late (level 2), until the cohort is marked as submitted.
 */
final class QctoReminders
{
    public const SOON = 5;

    public function run(Tenant $tenant): int
    {
        $sent = 0;
        $cohorts = Cohort::query()->whereNull('archived_at')->whereNull('qcto_submitted_at')->where('qcto_type', '<>', 'none')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('enrolments')->whereColumn('enrolments.cohort_id', 'cohorts.id')->whereIn('status', Enrolment::CURRENT))
            ->get();
        foreach ($cohorts as $cohort) {
            $due = QctoRegister::dueOn($cohort);
            if ($due === null) {
                continue;
            }
            $left = WorkingDays::between(today(), $due);
            $level = $left <= 0 ? 2 : ($left <= self::SOON ? 1 : 0);
            if ($level <= $cohort->qcto_reminder_level) {
                continue;
            }
            $cohort->update(['qcto_reminder_level' => $level]);
            $subject = $level === 2 ? "QCTO enrolment upload is due: {$cohort->name}" : "QCTO enrolment upload due in {$left} working days: {$cohort->name}";
            $body = $level === 2
                ? "The learner enrolment file for {$cohort->name} was due on {$due->format('j F Y')} (21 working days after training started). Download it from the cohort page, upload it to QCTO, then mark it as submitted."
                : "The learner enrolment file for {$cohort->name} is due on {$due->format('j F Y')}. Check the cohort's QCTO data now so every learner's details are complete.";
            $url = 'https://'.DomainService::primaryHost($tenant).'/admin/cohorts/'.$cohort->id;
            $admins = User::query()->where('status', User::STATUS_ACTIVE)->get()->filter(fn (User $u) => $u->hasPermission('academy.manage'));
            foreach ($admins as $admin) {
                Notification::send($admin, new QctoDeadlineReminder($subject, $body, $url));
                $sent++;
            }
        }

        return $sent;
    }
}
