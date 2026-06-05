<?php

namespace Platform\UserConnectors\Tools;

use Illuminate\Support\Facades\Artisan;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

class RunSnapshotDebugTool implements ToolContract
{
    public function getName(): string
    {
        return 'user-connectors.debug.snapshot.run';
    }

    public function getDescription(): string
    {
        return 'Debug: triggert organization:snapshot-entities und liefert Exit-Code, Artisan-Output und bei Fehler die ganze Exception-Chain (Class + Message + File:Line + Trace) — auch verschachtelte previous Throwables.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'enum' => ['morning', 'evening', 'auto'],
                    'description' => 'Snapshot-Periode (default: auto).',
                    'default' => 'auto',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $period = $arguments['period'] ?? 'auto';

        try {
            $exitCode = Artisan::call('organization:snapshot-entities', ['--period' => $period]);
            $output = Artisan::output();

            return ToolResult::success([
                'exit_code' => $exitCode,
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            $causes = [];
            $current = $e;
            $depth = 0;
            while ($current && $depth < 6) {
                $causes[] = [
                    'depth' => $depth,
                    'class' => get_class($current),
                    'message' => $current->getMessage(),
                    'file' => $current->getFile(),
                    'line' => $current->getLine(),
                ];
                $current = $current->getPrevious();
                $depth++;
            }

            return ToolResult::error(
                'SNAPSHOT_FAILED',
                sprintf('%s: %s', get_class($e), $e->getMessage()),
                [
                    'period' => $period,
                    'causes' => $causes,
                    'trace_top' => array_slice(explode("\n", $e->getTraceAsString()), 0, 30),
                ]
            );
        }
    }
}
