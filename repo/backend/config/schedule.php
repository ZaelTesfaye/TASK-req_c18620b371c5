<?php
/**
 * Declarative scheduler configuration.
 *
 * Each entry describes a recurring job that the deployment cron (or an
 * equivalent k8s CronJob / systemd timer) should invoke. The cron line in
 * backend/crontab is derived from this table so the schedule has exactly
 * one source of truth.
 *
 * cron:         Standard 5-field crontab expression
 * command:      The `php think <name>` console command to run
 * description:  Human-readable purpose for ops dashboards
 */
return [
    'jobs' => [
        [
            'name'        => 'audit_archival',
            'cron'        => '0 3 * * 0',
            'command'     => 'audit:archive',
            'description' => '7-year audit log retention job — runs weekly at 03:00 on Sunday.',
        ],
    ],
];
