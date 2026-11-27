<?php

declare(strict_types=1);

/*
| Menu items and the permission each needs. Drives the role-aware home (Sprint 1.5) and
| "preview as this role". A person sees an item only if they hold its permission.
*/
return [

    'tenant' => [
        ['section' => 'Overview', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'path' => '/admin', 'permission' => 'tenant.dashboard.view'],
        ['section' => 'Overview', 'label' => 'Reports', 'icon' => 'chart-pie', 'path' => '/admin/reports', 'permission' => 'reports.view'],
        ['section' => 'People', 'label' => 'Learners', 'icon' => 'users', 'path' => '/admin/learners', 'permission' => 'learners.view'],
        ['section' => 'People', 'label' => 'Staff', 'icon' => 'user-cog', 'path' => '/admin/staff', 'permission' => 'staff.manage'],
        ['section' => 'People', 'label' => 'Roles', 'icon' => 'shield-check', 'path' => '/admin/roles', 'permission' => 'roles.manage_tenant'],
        ['section' => 'People', 'label' => 'Centres', 'icon' => 'building', 'path' => '/admin/centres', 'permission' => 'centres.manage'],
        ['section' => 'Teaching', 'label' => 'My classes', 'icon' => 'users-round', 'path' => '/teach', 'permission' => 'classes.view'],
        ['section' => 'Teaching', 'label' => 'Live classroom', 'icon' => 'presentation', 'path' => '/teach/live', 'permission' => 'live_classroom.run'],
        ['section' => 'Teaching', 'label' => 'Attendance', 'icon' => 'calendar-check', 'path' => '/teach/attendance', 'permission' => 'attendance.mark'],
        ['section' => 'Quality assurance', 'label' => 'To review', 'icon' => 'inbox', 'path' => '/assess', 'permission' => 'assessments.review'],
        ['section' => 'Quality assurance', 'label' => 'Moderation', 'icon' => 'gavel', 'path' => '/moderate', 'permission' => 'moderation.perform'],
        ['section' => 'Quality assurance', 'label' => 'EISA readiness', 'icon' => 'clipboard-check', 'path' => '/qa/eisa', 'permission' => 'eisa.manage'],
        ['section' => 'Quality assurance', 'label' => 'Workplace days', 'icon' => 'briefcase', 'path' => '/mentor', 'permission' => 'attendance.confirm_workplace'],
        ['section' => 'Account', 'label' => 'Licences & packages', 'icon' => 'key-round', 'path' => '/admin/licences', 'permission' => 'licences.manage'],
        ['section' => 'Account', 'label' => 'Billing', 'icon' => 'receipt', 'path' => '/admin/billing', 'permission' => 'billing.tenant'],
        ['section' => 'Account', 'label' => 'Branding & website', 'icon' => 'palette', 'path' => '/admin/branding', 'permission' => 'branding.manage'],
        ['section' => 'Account', 'label' => 'Audit log', 'icon' => 'scroll-text', 'path' => '/admin/audit', 'permission' => 'audit.view_tenant'],
        ['section' => 'Learn', 'label' => 'Home', 'icon' => 'house', 'path' => '/learn', 'permission' => 'learning.use'],
        ['section' => 'Learn', 'label' => 'Simulate', 'icon' => 'box', 'path' => '/learn/simulate', 'permission' => 'learning.use'],
        ['section' => 'Learn', 'label' => 'Courses', 'icon' => 'play-circle', 'path' => '/learn/courses', 'permission' => 'learning.use'],
        ['section' => 'Learn', 'label' => 'Progress', 'icon' => 'chart-no-axes-column', 'path' => '/learn/progress', 'permission' => 'learning.use'],
        ['section' => 'Review', 'label' => 'Review packs', 'icon' => 'folder-check', 'path' => '/review', 'permission' => 'review_pack.view'],
    ],

    'platform' => [
        ['section' => 'Overview', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'path' => '/hq', 'permission' => 'platform.dashboard.view'],
        ['section' => 'Content', 'label' => 'Programmes', 'icon' => 'graduation-cap', 'path' => '/hq/programmes', 'permission' => 'programmes.view'],
        ['section' => 'Content', 'label' => 'Scenario Studio', 'icon' => 'box', 'path' => '/hq/studio/scenarios', 'permission' => 'content.author'],
        ['section' => 'Content', 'label' => 'Course Studio', 'icon' => 'clapperboard', 'path' => '/hq/studio/courses', 'permission' => 'content.author'],
        ['section' => 'Content', 'label' => 'Review queue', 'icon' => 'list-checks', 'path' => '/hq/review', 'permission' => 'content.review'],
        ['section' => 'Business', 'label' => 'Training providers', 'icon' => 'building-2', 'path' => '/hq/providers', 'permission' => 'providers.view'],
        ['section' => 'Business', 'label' => 'Packages', 'icon' => 'package', 'path' => '/hq/packages', 'permission' => 'packages.manage'],
        ['section' => 'Business', 'label' => 'Billing', 'icon' => 'receipt', 'path' => '/hq/billing', 'permission' => 'billing.view'],
        ['section' => 'Business', 'label' => 'Support', 'icon' => 'life-buoy', 'path' => '/hq/support', 'permission' => 'support.tickets'],
        ['section' => 'System', 'label' => 'Roles & staff', 'icon' => 'shield-check', 'path' => '/hq/roles', 'permission' => 'roles.manage_platform'],
        ['section' => 'System', 'label' => 'Settings', 'icon' => 'settings', 'path' => '/hq/settings', 'permission' => 'settings.manage'],
        ['section' => 'System', 'label' => 'Audit log', 'icon' => 'scroll-text', 'path' => '/hq/audit', 'permission' => 'audit.view_all'],
    ],
];
