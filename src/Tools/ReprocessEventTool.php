<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\UserConnectors\Jobs\EnrichMicrosoft365EventJob;
use Platform\UserConnectors\Models\UserConnectorInboundEvent;
use Platform\UserConnectors\Services\InboundEventService;

/**
 * Re-runs correlation for an inbound event. Use case: a Microsoft Graph mail
 * event came in but no MailSession got created (race, transient error, etc.).
 * After fixing the root cause, call this to materialize the session retroactively.
 */
class ReprocessEventTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'user-connectors.events.reprocess';
    }

    public function getDescription(): string
    {
        return 'Stößt die Session-Korrelation für ein bereits gespeichertes Inbound-Event erneut an. Hilft bei Mails/Calls/Meetings/Messages, die das Event erzeugt haben, aber keine Session-Row materialisiert haben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => [
                    'type' => 'integer',
                    'description' => 'ID des UserConnectorInboundEvent.',
                ],
                'force_enrich' => [
                    'type' => 'boolean',
                    'description' => 'Optional: zwingt den Microsoft365-Enrichment-Job neu zu laufen (Graph-API-Refetch). Default: false (nutzt vorhandene meta).',
                    'default' => false,
                ],
            ],
            'required' => ['event_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $event = UserConnectorInboundEvent::find((int) ($arguments['event_id'] ?? 0));
        if (!$event) {
            return ToolResult::error('NOT_FOUND', 'Event nicht gefunden.');
        }

        $forceEnrich = (bool) ($arguments['force_enrich'] ?? false);

        try {
            if ($forceEnrich && $event->connector_key === 'microsoft365') {
                // Synchronously re-run the enrichment job — it will call
                // correlateSession internally, which now marks the event.
                (new EnrichMicrosoft365EventJob($event->id))->handle(
                    app(\Platform\UserConnectors\Services\Microsoft365\Microsoft365ConnectorService::class),
                );
            } else {
                // Just re-run correlation against the already-enriched meta.
                $service = app(InboundEventService::class);
                $eventType = $event->event_type;

                if (str_starts_with($eventType, 'mail.')) {
                    $service->updateMailSession($event);
                } elseif (str_starts_with($eventType, 'calendar.')) {
                    $service->updateMeetingSession($event);
                } elseif (str_starts_with($eventType, 'teams.')) {
                    $service->updateMessageSession($event);
                } elseif (str_starts_with($eventType, 'call.')) {
                    // call sessions get updated as a protected helper; trigger
                    // by re-dispatching the typed event.
                    return ToolResult::error('NOT_SUPPORTED', 'Call-Reprocess noch nicht unterstützt — Call-Events werden direkt beim Empfang korreliert.');
                } else {
                    return ToolResult::error('UNKNOWN_TYPE', "Unbekannter event_type: {$eventType}");
                }

                $event->refresh();
                if (!$event->session_correlated_at) {
                    $event->markSessionCorrelated();
                }
            }

            $event->refresh();

            return ToolResult::success([
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'external_id' => $event->external_id,
                'session_correlated_at' => $event->session_correlated_at?->toIso8601String(),
                'processing_error' => $event->processing_error,
                'message' => $event->session_correlated_at
                    ? 'Session-Korrelation erfolgreich.'
                    : 'Korrelation abgeschlossen, aber Session_correlated_at ist nicht gesetzt — möglicher Fehler, siehe processing_error.',
            ]);
        } catch (\Throwable $e) {
            $event->markCorrelationFailed(get_class($e) . ': ' . $e->getMessage());

            return ToolResult::error(
                'REPROCESS_FAILED',
                $e->getMessage(),
                [
                    'class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace_top' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
                ],
            );
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['user-connectors', 'events', 'reprocess', 'debug'],
            'read_only' => false,
            'requires_auth' => true,
            'risk_level' => 'write',
            'confirmation_required' => false,
            'cost_class' => 'local_db',
        ];
    }
}
