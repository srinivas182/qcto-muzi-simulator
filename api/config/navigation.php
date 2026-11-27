<?php

declare(strict_types=1);

/*
| Menu items and the permission each needs. Drives the role-aware home (Sprint 1.5) and
| "preview as this role". A person sees an item only if they hold its permission.
| `id` is stable: the apps translate the label with the key nav.<id>.
*/
return [

    'tenant' => [
        ['id' => 'admin', 'section' => 'Overview', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'path' => '/admin', 'permission' => 'tenant.dashboard.view'],
        ['id' => 'admin.reports', 'section' => 'Overview', 'label' => 'Reports', 'icon' => 'chart-pie', 'path' => '/admin/reports', 'permission' => 'reports.view'],
        ['id' => 'admin.programmes', 'section' => 'Curriculum', 'label' => 'Programmes', 'icon' => 'graduation-cap', 'path' => '/admin/programmes', 'permission' => 'catalogue.view'],
        ['id' => 'admin.guide', 'section' => 'Curriculum', 'label' => 'Mentor guide', 'icon' => 'book-open', 'path' => '/admin/guide', 'permission' => 'catalogue.view'],
        ['id' => 'admin.learners', 'section' => 'People', 'label' => 'Learners', 'icon' => 'users', 'path' => '/admin/learners', 'permission' => 'learners.view'],
        ['id' => 'admin.staff', 'section' => 'People', 'label' => 'Staff', 'icon' => 'user-cog', 'path' => '/admin/staff', 'permission' => 'staff.manage'],
        ['id' => 'admin.roles', 'section' => 'People', 'label' => 'Roles', 'icon' => 'shield-check', 'path' => '/admin/roles', 'permission' => 'roles.manage_tenant'],
        ['id' => 'admin.centres', 'section' => 'People', 'label' => 'Centres', 'icon' => 'building', 'path' => '/admin/centres', 'permission' => 'centres.manage'],
        ['id' => 'teach', 'section' => 'Teaching', 'label' => 'My classes', 'icon' => 'users-round', 'path' => '/teach', 'permission' => 'classes.view'],
        ['id' => 'teach.live', 'section' => 'Teaching', 'label' => 'Live classroom', 'icon' => 'presentation', 'path' => '/teach/live', 'permission' => 'live_classroom.run'],
        ['id' => 'teach.attendance', 'section' => 'Teaching', 'label' => 'Attendance', 'icon' => 'calendar-check', 'path' => '/teach/attendance', 'permission' => 'attendance.mark'],
        ['id' => 'assess', 'section' => 'Quality assurance', 'label' => 'To review', 'icon' => 'inbox', 'path' => '/assess', 'permission' => 'assessments.review'],
        ['id' => 'moderate', 'section' => 'Quality assurance', 'label' => 'Moderation', 'icon' => 'gavel', 'path' => '/moderate', 'permission' => 'moderation.perform'],
        ['id' => 'qa.eisa', 'section' => 'Quality assurance', 'label' => 'EISA readiness', 'icon' => 'clipboard-check', 'path' => '/qa/eisa', 'permission' => 'eisa.manage'],
        ['id' => 'mentor', 'section' => 'Quality assurance', 'label' => 'Workplace days', 'icon' => 'briefcase', 'path' => '/mentor', 'permission' => 'attendance.confirm_workplace'],
        ['id' => 'admin.licences', 'section' => 'Account', 'label' => 'Licences & packages', 'icon' => 'key-round', 'path' => '/admin/licences', 'permission' => 'licences.manage'],
        ['id' => 'admin.billing', 'section' => 'Account', 'label' => 'Billing', 'icon' => 'receipt', 'path' => '/admin/billing', 'permission' => 'billing.tenant'],
        ['id' => 'admin.branding', 'section' => 'Account', 'label' => 'Branding & website', 'icon' => 'palette', 'path' => '/admin/branding', 'permission' => 'branding.manage'],
        ['id' => 'admin.audit', 'section' => 'Account', 'label' => 'Audit log', 'icon' => 'scroll-text', 'path' => '/admin/audit', 'permission' => 'audit.view_tenant'],
        ['id' => 'learn', 'section' => 'Learn', 'label' => 'Home', 'icon' => 'house', 'path' => '/learn', 'permission' => 'learning.use'],
        ['id' => 'learn.simulate', 'section' => 'Learn', 'label' => 'Simulate', 'icon' => 'box', 'path' => '/learn/simulate', 'permission' => 'learning.use'],
        ['id' => 'learn.courses', 'section' => 'Learn', 'label' => 'Courses', 'icon' => 'play-circle', 'path' => '/learn/courses', 'permission' => 'learning.use'],
        ['id' => 'learn.progress', 'section' => 'Learn', 'label' => 'Progress', 'icon' => 'chart-no-axes-column', 'path' => '/learn/progress', 'permission' => 'learning.use'],
        ['id' => 'review', 'section' => 'Review', 'label' => 'Review packs', 'icon' => 'folder-check', 'path' => '/review', 'permission' => 'review_pack.view'],
    ],

    'platform' => [
        ['id' => 'hq', 'section' => 'Overview', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'path' => '/hq', 'permission' => 'platform.dashboard.view'],
        ['id' => 'hq.programmes', 'section' => 'Content', 'label' => 'Programmes', 'icon' => 'graduation-cap', 'path' => '/hq/programmes', 'permission' => 'programmes.view'],
        ['id' => 'hq.studio.scenarios', 'section' => 'Content', 'label' => 'Scenario Studio', 'icon' => 'box', 'path' => '/hq/studio/scenarios', 'permission' => 'content.author'],
        ['id' => 'hq.studio.courses', 'section' => 'Content', 'label' => 'Course Studio', 'icon' => 'clapperboard', 'path' => '/hq/studio/courses', 'permission' => 'content.author'],
        ['id' => 'hq.review', 'section' => 'Content', 'label' => 'Review queue', 'icon' => 'list-checks', 'path' => '/hq/review', 'permission' => 'content.review'],
        ['id' => 'hq.providers', 'section' => 'Business', 'label' => 'Training providers', 'icon' => 'building-2', 'path' => '/hq/providers', 'permission' => 'providers.view'],
        ['id' => 'hq.packages', 'section' => 'Business', 'label' => 'Packages', 'icon' => 'package', 'path' => '/hq/packages', 'permission' => 'packages.manage'],
        ['id' => 'hq.billing', 'section' => 'Business', 'label' => 'Billing', 'icon' => 'receipt', 'path' => '/hq/billing', 'permission' => 'billing.view'],
        ['id' => 'hq.support', 'section' => 'Business', 'label' => 'Support', 'icon' => 'life-buoy', 'path' => '/hq/support', 'permission' => 'support.tickets'],
        ['id' => 'hq.roles', 'section' => 'System', 'label' => 'Roles & staff', 'icon' => 'shield-check', 'path' => '/hq/roles', 'permission' => 'roles.manage_platform'],
        ['id' => 'hq.settings', 'section' => 'System', 'label' => 'Settings', 'icon' => 'settings', 'path' => '/hq/settings', 'permission' => 'settings.manage'],
        ['id' => 'hq.audit', 'section' => 'System', 'label' => 'Audit log', 'icon' => 'scroll-text', 'path' => '/hq/audit', 'permission' => 'audit.view_all'],
    ],
];
