<?php

declare(strict_types=1);

/*
| The 12 system roles from PRD v1.2 §4. Their permissions live here (not in the database),
| so improvements reach every provider automatically. Providers create custom roles from the
| permission catalogue instead of editing these.
|
| 'scope': platform (HQ staff) | tenant (provider staff and learners) | external (QCTO reviewer).
*/
return [

    // ---------------- HQ
    'super_admin' => [
        'name' => 'Super Admin',
        'scope' => 'platform',
        'description' => 'Owns the platform: programmes, content, packages, providers, billing and settings.',
        'permissions' => ['*platform'],
    ],
    'content_manager' => [
        'name' => 'Content Manager',
        'scope' => 'platform',
        'description' => 'Plans programmes and outcomes, manages production and publishes content.',
        'permissions' => ['programmes.view', 'programmes.manage', 'content.author', 'content.review', 'content.publish'],
    ],
    'scenario_designer' => [
        'name' => 'Scenario & Course Designer',
        'scope' => 'platform',
        'description' => 'Builds scenarios and shapes AI-drafted courses.',
        'permissions' => ['programmes.view', 'content.author'],
    ],
    'sme_reviewer' => [
        'name' => 'Subject Matter Expert Reviewer',
        'scope' => 'platform',
        'description' => 'Checks scenarios and lessons for accuracy; approves or returns them.',
        'permissions' => ['programmes.view', 'content.review'],
    ],
    'support_finance' => [
        'name' => 'Support & Finance Officer',
        'scope' => 'platform',
        'description' => 'Helps providers and manages invoices and payments.',
        'permissions' => ['providers.view', 'billing.view', 'billing.manage', 'support.tickets', 'support.login'],
    ],

    // ---------------- Training provider
    'tp_admin' => [
        'name' => 'TP Administrator',
        'scope' => 'tenant',
        'description' => "Runs the provider's account: licences, people, branding, billing and reports.",
        'permissions' => ['*tenant'],
        'exclude' => ['learning.use', 'review_pack.view', 'attendance.confirm_workplace'],
    ],
    'centre_manager' => [
        'name' => 'Centre Manager',
        'scope' => 'tenant',
        'description' => "Manages one centre's learners, staff, classes and licences.",
        'permissions' => ['tenant.dashboard.view', 'reports.view', 'reports.export', 'learners.view', 'learners.manage',
            'staff.manage', 'classes.view', 'classes.manage', 'live_classroom.run', 'attendance.mark', 'attendance.sign', 'licences.manage', 'catalogue.view'],
    ],
    'facilitator' => [
        'name' => 'Facilitator / Instructor',
        'scope' => 'tenant',
        'description' => 'Teaches classes, runs live sessions and marks attendance.',
        'permissions' => ['learners.view', 'classes.view', 'classes.manage', 'live_classroom.run', 'attendance.mark', 'attendance.sign', 'reports.view', 'catalogue.view'],
    ],
    'assessor' => [
        'name' => 'Assessor / Mentor',
        'scope' => 'tenant',
        'description' => 'Reviews assessment attempts, signs off competence and confirms workplace days.',
        'permissions' => ['learners.view', 'classes.view', 'assessments.review', 'assessments.signoff', 'attendance.confirm_workplace', 'reports.view', 'catalogue.view'],
    ],
    'moderator' => [
        'name' => 'Moderator (internal)',
        'scope' => 'tenant',
        'description' => 'Moderates assessor decisions, monitors assessors and confirms EISA readiness.',
        'permissions' => ['learners.view', 'classes.view', 'assessments.review', 'moderation.perform', 'eisa.manage', 'reports.view', 'reports.export', 'catalogue.view'],
    ],
    'learner' => [
        'name' => 'Learner',
        'scope' => 'tenant',
        'description' => 'Uses simulators and courses.',
        'permissions' => ['learning.use'],
    ],

    // ---------------- External
    'qcto_reviewer' => [
        'name' => 'QCTO / Quality Partner Reviewer',
        'scope' => 'external',
        'description' => 'Read-only, time-limited access to a review pack shared by the provider.',
        'permissions' => ['review_pack.view'],
    ],
];
