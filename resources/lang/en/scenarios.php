<?php

declare(strict_types=1);

// Site scenarios (owner decision 2026-11-25): what to switch on, starter settings and demo pages.
return [
    'company_site' => [
        'title' => 'Company website',
        'description' => 'Pages about the company, its services and contacts, site search and files to download.',
    ],
    'online_store' => [
        'title' => 'Online store',
        'description' => 'A product catalogue with tables and search. Order handling comes with the paid automation.',
    ],
    'blog' => [
        'title' => 'Blog',
        'description' => 'Posts, search, short links and notifications to readers.',
    ],
    'landing' => [
        'title' => 'Landing page',
        'description' => 'One selling page made of ready blocks.',
    ],
    'staff_portal' => [
        'title' => 'Staff portal',
        'description' => 'Sign-in for staff, permissions, news, search and notifications. Chat and workflows are paid functions.',
    ],
    // Functions in plain words: how the wizard names what a scenario needs.
    'capabilities' => [
        'access' => ['base' => 'Permissions'],
        'auth' => ['base' => 'Sign-in and accounts'],
        'automation' => ['base' => 'Automation'],
        'chat' => ['base' => 'Chat'],
        'content' => ['base' => 'Pages and articles'],
        'dataview' => ['base' => 'Data tables'],
        'file_manager' => ['base' => 'Files'],
        'layout' => ['base' => 'Page design'],
        'link' => ['base' => 'Links'],
        'notification' => ['base' => 'Notifications'],
        'search' => ['base' => 'Site search'],
        'storage' => ['base' => 'Data storages'],
        'workflow' => ['base' => 'Workflows and approvals'],
    ],
];
