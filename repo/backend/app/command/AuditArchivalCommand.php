<?php
namespace app\command;

use app\job\AuditArchivalJob;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * Console entrypoint for the AuditArchivalJob.
 *
 * Registered in backend/config/console.php so it can be invoked as:
 *     php think audit:archive
 *
 * This is the hook that the scheduler (cron / supervisord / k8s CronJob)
 * calls on the configured cadence. See backend/config/schedule.php for the
 * declarative schedule and docs/scheduling.md for deployment wiring.
 */
class AuditArchivalCommand extends Command
{
    protected function configure()
    {
        $this->setName('audit:archive')
            ->setDescription('Run the 7-year audit log retention/archival job');
    }

    protected function execute(Input $input, Output $output)
    {
        $result = AuditArchivalJob::run();
        $output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES));
        return 0;
    }
}
