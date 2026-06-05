<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RunSnapshotDebugTool implements ToolContract
{
    public function getName(): string
    {
        return 'user-connectors.debug.snapshot.run';
    }

    public function getDescription(): string
    {
        return 'Debug: führt SnapshotEntitiesCommand direkt aus (auch im HTTP/MCP-Kontext, wo Artisan::call den Command sonst nicht findet) und liefert Exit-Code, Output und bei Fehler die ganze Exception-Chain (Class + Message + File:Line + Trace).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'enum' => ['morning', 'evening', 'auto'],
                    'description' => 'Snapshot-Periode (default: evening).',
                    'default' => 'evening',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $period = $arguments['period'] ?? 'evening';

        $commandClass = '\\Platform\\Organization\\Console\\Commands\\SnapshotEntitiesCommand';

        if (!class_exists($commandClass)) {
            return ToolResult::error('NOT_AVAILABLE', "Organization-Modul oder {$commandClass} nicht geladen.");
        }

        try {
            $app = app();
            $command = $app->make($commandClass);

            if (method_exists($command, 'setLaravel')) {
                $command->setLaravel($app);
            }

            $input = new ArrayInput(['--period' => $period]);
            $output = new BufferedOutput();
            $exitCode = $command->run($input, $output);

            return ToolResult::success([
                'exit_code' => $exitCode,
                'output' => $output->fetch(),
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
