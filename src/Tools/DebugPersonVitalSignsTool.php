<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Services\PersonActivityRegistry;

class DebugPersonVitalSignsTool implements ToolContract
{
    public function getName(): string
    {
        return 'user-connectors.debug.person-vital-signs.GET';
    }

    public function getDescription(): string
    {
        return 'Debug: liefert die Vital Signs aller PersonActivityProvider für eine Entity inkl. PHP-Typ pro Wert, und prüft ob das Resultat sich sauber JSON-encoden lässt. Hilft beim Aufspüren von Non-Scalar-Werten, die den Entity-Snapshot Upsert sprengen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entity_id' => [
                    'type' => 'integer',
                    'description' => 'Entity-ID einer Person-Entity mit gesetztem linked_user_id.',
                ],
            ],
            'required' => ['entity_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $entity = OrganizationEntity::find((int) ($arguments['entity_id'] ?? 0));

            if (!$entity) {
                return ToolResult::error('NOT_FOUND', 'Entity nicht gefunden.');
            }

            $base = [
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'team_id' => $entity->team_id,
                'linked_user_id' => $entity->linked_user_id,
            ];

            if (!$entity->linked_user_id) {
                return ToolResult::success($base + [
                    'note' => 'Kein linked_user_id — kein Provider wird aufgerufen.',
                ]);
            }

            $registry = resolve(PersonActivityRegistry::class);
            $signs = $registry->allVitalSigns($entity->linked_user_id, $entity->team_id);

            $flat = [];
            $typeDiagnostic = [];
            $offenders = [];

            foreach ($signs as $sectionKey => $sectionSigns) {
                foreach ($sectionSigns as $sign) {
                    $key = "person_{$sectionKey}_" . ($sign['key'] ?? '?');
                    $value = $sign['value'] ?? null;
                    $flat[$key] = $value;

                    $typeDiagnostic[$key] = [
                        'type' => gettype($value),
                        'is_scalar' => is_scalar($value),
                        'preview' => is_scalar($value) ? (string) $value : json_encode($value),
                    ];

                    if (!is_scalar($value) && $value !== null) {
                        $offenders[$key] = $value;
                    }
                }
            }

            $encoded = json_encode($flat);
            $encodingInfo = [
                'status' => $encoded === false ? 'FAILED' : 'OK',
                'json_last_error' => json_last_error_msg(),
                'length' => is_string($encoded) ? strlen($encoded) : null,
                'sample' => is_string($encoded) ? substr($encoded, 0, 500) : null,
            ];

            return ToolResult::success($base + [
                'sections_returned' => array_keys($signs),
                'sign_counts' => array_map(fn ($s) => count($s), $signs),
                'flat_keys' => array_keys($flat),
                'type_diagnostic' => $typeDiagnostic,
                'non_scalar_offenders' => $offenders,
                'json_encode' => $encodingInfo,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error(
                'EXCEPTION',
                sprintf('%s: %s', get_class($e), $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 25),
                ]
            );
        }
    }
}
