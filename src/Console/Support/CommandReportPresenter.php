<?php

declare(strict_types=1);

namespace Larena\Core\Console\Support;

use Illuminate\Console\Command;

final class CommandReportPresenter
{
    /**
     * @param array<string, mixed> $report
     */
    public static function render(Command $command, string $title, array $report): void
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($command->option('json') === true) {
            $command->line((string) $json);
            return;
        }

        foreach (self::summaryLines($title, $report) as $line) {
            $command->line($line);
        }

        if ($command->option('full') === true) {
            $command->newLine();
            $command->line('Full JSON:');
            $command->line((string) $json);
        }
    }

    /**
     * @param array<string, mixed> $report
     * @return list<string>
     */
    public static function summaryLines(string $title, array $report): array
    {
        $lines = [
            $title,
            'Status: ' . self::statusLabel($report),
        ];

        if (isset($report['evidence_path']) && is_string($report['evidence_path'])) {
            $lines[] = 'Evidence: ' . $report['evidence_path'];
        }

        if (isset($report['reason']) && is_string($report['reason'])) {
            $lines[] = 'Reason: ' . $report['reason'];
        }

        if (isset($report['safe_command']) && is_string($report['safe_command'])) {
            $lines[] = 'Safe command: ' . $report['safe_command'];
        }

        if (isset($report['required_confirmation']) && is_string($report['required_confirmation'])) {
            $lines[] = 'Required confirmation: ' . $report['required_confirmation'];
        }

        if (array_key_exists('provided_confirmation', $report)) {
            $provided = is_string($report['provided_confirmation'])
                ? $report['provided_confirmation']
                : 'none';
            $lines[] = 'Provided confirmation: ' . $provided;
        }

        $checkLines = self::checkLines($report);
        if ($checkLines !== []) {
            $lines[] = '';
            array_push($lines, ...$checkLines);
        }

        $planLines = self::planLines($report);
        if ($planLines !== []) {
            $lines[] = '';
            array_push($lines, ...$planLines);
        }

        if (($report['transition_required'] ?? null) === 'install_apply_launch_record') {
            $lines[] = '';
            $lines[] = 'EXPECTED_GUARD: install apply requires a launch record and explicit confirmation.';
        }

        if (isset($report['next_recommended_command']) && is_string($report['next_recommended_command'])) {
            $lines[] = '';
            $lines[] = 'Next: ' . $report['next_recommended_command'];
        }

        if (isset($report['next_gate']) && is_string($report['next_gate'])) {
            $lines[] = '';
            $lines[] = 'Next gate: ' . $report['next_gate'];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $report
     * @return list<string>
     */
    private static function checkLines(array $report): array
    {
        $checks = $report['checks'] ?? null;
        if (!is_array($checks)) {
            return [];
        }

        $lines = [];
        foreach ($checks as $name => $check) {
            if (!is_array($check)) {
                continue;
            }

            $status = $check['status'] ?? null;
            $label = self::statusLabel(['status' => is_string($status) ? $status : null]);
            $line = sprintf('%-24s %s', $label, (string) $name);

            if (isset($check['reason']) && is_string($check['reason'])) {
                $line .= ' (' . $check['reason'] . ')';
            }

            $lines[] = rtrim($line);

            if (isset($check['safe_message']) && is_string($check['safe_message'])) {
                $lines[] = sprintf('%-24s %s', '', $check['safe_message']);
            }

            if (isset($check['action']) && is_string($check['action']) && $check['action'] !== '') {
                $lines[] = sprintf('%-24s action: %s', '', $check['action']);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $report
     * @return list<string>
     */
    private static function planLines(array $report): array
    {
        $plan = $report['plan'] ?? null;
        if (!is_array($plan)) {
            return [];
        }

        $lines = ['Plan:'];
        foreach ($plan as $step) {
            if (!is_array($step)) {
                continue;
            }

            $name = $step['step'] ?? null;
            $status = $step['status'] ?? null;
            if (!is_string($name) || !is_string($status)) {
                continue;
            }

            $lines[] = sprintf('%-24s %s', self::statusLabel(['status' => $status]), $name);

            if (isset($step['requires_command_confirmation']) && is_string($step['requires_command_confirmation'])) {
                $lines[] = sprintf('%-24s confirmation: %s', '', $step['requires_command_confirmation']);
            }
        }

        return count($lines) > 1 ? $lines : [];
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function statusLabel(array $report): string
    {
        $status = $report['status'] ?? null;
        $reason = $report['reason'] ?? null;

        if ($status === 'passed' || $status === 'ready' || $status === 'ready_for_guarded_install_planning') {
            return 'PASS';
        }

        if ($status === 'blocked' && $reason === 'actual_install_requires_launch_record_and_guarded_transition') {
            return 'EXPECTED_GUARD';
        }

        if (in_array($status, ['degraded', 'missing', 'invalid'], true)) {
            return 'DEGRADED_ACTION_REQUIRED';
        }

        if (in_array($status, ['failed', 'blocked'], true)) {
            return 'ACTION_REQUIRED';
        }

        if (is_string($status) && $status !== '') {
            return strtoupper($status);
        }

        return 'UNKNOWN';
    }
}
