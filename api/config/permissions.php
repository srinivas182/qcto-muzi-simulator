<?php

declare(strict_types=1);

/*
| Permission catalogue. Labels are plain language so providers can build custom roles
| without technical knowledge. 'scope' says where a permission can be used:
|   platform → HQ only;  tenant → inside a training provider.
*/
return [

    'groups' => [
        // ---------------- HQ (platform)
        'HQ overview' => [
            'platform.dashboard.view' => ['scope' => 'platform', 'label' => 'See the HQ business dashboard'],
            'audit.view_all' => ['scope' => 'platform', 'label' => 'See the audit log for every provider'],
        ],
        'Programmes & content' => [
            'programmes.view' => ['scope' => 'platform', 'label' => 'See programmes and workplace outcomes'],
            'programmes.manage' => ['scope' => 'platform', 'label' => 'Create and change programmes and outcomes'],
            'content.author' => ['scope' => 'platform', 'label' => 'Build scenarios and courses in the studios'],
            'content.review' => ['scope' => 'platform', 'label' => 'Review scenarios and lessons as a subject expert'],
            'content.publish' => ['scope' => 'platform', 'label' => 'Publish content to providers'],
        ],
        'Providers & sales' => [
            'providers.view' => ['scope' => 'platform', 'label' => 'See training providers'],
            'providers.manage' => ['scope' => 'platform', 'label' => 'Create, approve and suspend training providers'],
            'packages.manage' => ['scope' => 'platform', 'label' => 'Create and price packages'],
            'billing.view' => ['scope' => 'platform', 'label' => 'See invoices and payments for all providers'],
            'billing.manage' => ['scope' => 'platform', 'label' => 'Manage invoices, payments and overdue accounts'],
        ],
        'Support & system' => [
            'support.tickets' => ['scope' => 'platform', 'label' => 'Answer provider support requests'],
            'support.login' => ['scope' => 'platform', 'label' => 'Open a read-only support view of a provider (always recorded)'],
            'settings.manage' => ['scope' => 'platform', 'label' => 'Change platform settings and integrations'],
            'roles.manage_platform' => ['scope' => 'platform', 'label' => 'Manage HQ roles and staff'],
        ],

        // ---------------- Training provider (tenant)
        'Provider overview' => [
            'tenant.dashboard.view' => ['scope' => 'tenant', 'label' => 'See the provider dashboard'],
            'reports.view' => ['scope' => 'tenant', 'label' => 'See reports'],
            'reports.export' => ['scope' => 'tenant', 'label' => 'Export reports and evidence packs'],
            'audit.view_tenant' => ['scope' => 'tenant', 'label' => "See this provider's audit log"],
            'catalogue.view' => ['scope' => 'tenant', 'label' => 'See programmes, workplace outcomes and the mentor guide'],
        ],
        'People' => [
            'learners.view' => ['scope' => 'tenant', 'label' => 'See learners'],
            'learners.manage' => ['scope' => 'tenant', 'label' => 'Add, invite and change learners'],
            'staff.manage' => ['scope' => 'tenant', 'label' => 'Invite and manage staff'],
            'roles.manage_tenant' => ['scope' => 'tenant', 'label' => 'Create roles and assign them to people'],
            'centres.manage' => ['scope' => 'tenant', 'label' => 'Manage centres and campuses'],
        ],
        'Teaching' => [
            'classes.view' => ['scope' => 'tenant', 'label' => 'See classes and their progress'],
            'classes.manage' => ['scope' => 'tenant', 'label' => 'Create classes and assign activities'],
            'live_classroom.run' => ['scope' => 'tenant', 'label' => 'Run live classroom sessions'],
            'attendance.mark' => ['scope' => 'tenant', 'label' => 'Mark attendance'],
            'attendance.sign' => ['scope' => 'tenant', 'label' => 'Sign and lock attendance registers'],
            'attendance.confirm_workplace' => ['scope' => 'tenant', 'label' => 'Confirm workplace days (mentor)'],
        ],
        'Quality assurance' => [
            'assessments.review' => ['scope' => 'tenant', 'label' => 'Review assessment attempts'],
            'assessments.signoff' => ['scope' => 'tenant', 'label' => 'Sign off competence (assessor)'],
            'moderation.perform' => ['scope' => 'tenant', 'label' => 'Moderate assessor decisions'],
            'eisa.manage' => ['scope' => 'tenant', 'label' => 'Manage EISA readiness and submissions'],
            'review_pack.view' => ['scope' => 'tenant', 'label' => 'Open a shared QCTO review pack (read-only)'],
        ],
        'Account' => [
            'licences.manage' => ['scope' => 'tenant', 'label' => 'Buy packages and assign licences'],
            'billing.tenant' => ['scope' => 'tenant', 'label' => 'See and pay invoices'],
            'branding.manage' => ['scope' => 'tenant', 'label' => 'Change branding, domain and website'],
        ],
        'Learning' => [
            'learning.use' => ['scope' => 'tenant', 'label' => 'Use simulators and courses'],
        ],
    ],
];
