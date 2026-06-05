<?php

namespace Platform\UserConnectors\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Core\Services\ContextFileService;
use Platform\UserConnectors\Models\UserConnectorCallSession;
use Platform\UserConnectors\Models\UserConnectorConnection;
use Platform\UserConnectors\Services\RingCentral\RingCentralApiService;
use Platform\UserConnectors\Services\Sipgate\SipgateApiService;

class FetchCallRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        protected int $callSessionId,
    ) {}

    public function handle(): void
    {
        $session = UserConnectorCallSession::with('connection.connector')->find($this->callSessionId);
        if (!$session || $session->status !== 'completed') {
            return;
        }

        $connection = $session->connection;
        if (!$connection) {
            return;
        }

        $settings = $connection->credentials['settings'] ?? [];
        if (!($settings['recordings_enabled'] ?? true)) {
            return;
        }

        // Skip if recording already fetched
        $meta = $session->meta ?? [];
        if (!empty($meta['recording_context_file_id'])) {
            return;
        }

        $connectorKey = $session->connector_key;
        $recordingData = match ($connectorKey) {
            'ringcentral', 'vodafone' => $this->fetchRingCentralRecording($session, $connection),
            'sipgate' => $this->fetchSipgateRecording($session, $connection),
            default => null,
        };

        if (!$recordingData) {
            return;
        }

        $this->storeAsContextFile($session, $connection, $recordingData);
    }

    protected function fetchRingCentralRecording(UserConnectorCallSession $session, UserConnectorConnection $connection): ?array
    {
        $apiService = app(RingCentralApiService::class)->forConnection($connection->id);

        $result = $apiService->getCallRecording($connection, $session->external_call_id);
        if (!$result) {
            return null;
        }

        $extension = match ($result['mime_type']) {
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            default => 'mp3',
        };

        $filename = sprintf(
            'recording_%s_%s.%s',
            $session->started_at?->format('Y-m-d_His') ?? now()->format('Y-m-d_His'),
            $session->id,
            $extension
        );

        return [
            'content' => $result['content'],
            'mime_type' => $result['mime_type'],
            'filename' => $filename,
            'duration' => $result['duration'],
        ];
    }

    protected function fetchSipgateRecording(UserConnectorCallSession $session, UserConnectorConnection $connection): ?array
    {
        $apiService = app(SipgateApiService::class)->forConnection($connection->id);

        $result = $apiService->getCallRecording($connection, $session->external_call_id);
        if (!$result) {
            return null;
        }

        $filename = sprintf(
            'recording_%s_%s.wav',
            $session->started_at?->format('Y-m-d_His') ?? now()->format('Y-m-d_His'),
            $session->id
        );

        return [
            'content' => $result['content'],
            'mime_type' => $result['mime_type'],
            'filename' => $filename,
            'duration' => $result['duration'],
        ];
    }

    protected function storeAsContextFile(
        UserConnectorCallSession $session,
        UserConnectorConnection $connection,
        array $recordingData,
    ): void {
        $tempPath = tempnam(sys_get_temp_dir(), 'call_recording_');
        file_put_contents($tempPath, $recordingData['content']);

        try {
            $uploadedFile = new UploadedFile(
                $tempPath,
                $recordingData['filename'],
                $recordingData['mime_type'],
                null,
                true
            );

            // Resolve team_id via connection owner
            $ownerUser = $connection->ownerUser;
            if (!$ownerUser) {
                Log::warning('FetchCallRecordingJob: Kein Owner-User für Connection', [
                    'connection_id' => $connection->id,
                    'call_session_id' => $session->id,
                ]);
                return;
            }

            $team = $ownerUser->currentTeamRelation;
            if (!$team) {
                Log::warning('FetchCallRecordingJob: Kein Team für Owner-User', [
                    'user_id' => $ownerUser->id,
                    'call_session_id' => $session->id,
                ]);
                return;
            }

            $rootTeam = method_exists($team, 'getRootTeam') ? $team->getRootTeam() : $team;

            $contextFileService = app(ContextFileService::class);
            $result = $contextFileService->uploadForContext(
                $uploadedFile,
                UserConnectorCallSession::class,
                $session->id,
                [
                    'team_id' => $rootTeam->id,
                    'user_id' => $connection->owner_user_id,
                    'generate_variants' => false,
                ]
            );

            // Add file reference to the session
            $session->addFileReference($result['id']);

            // Store reference in meta
            $meta = $session->meta ?? [];
            $meta['recording_context_file_id'] = $result['id'];
            $meta['recording_duration'] = $recordingData['duration'];
            $session->update(['meta' => $meta]);

            Log::info('FetchCallRecordingJob: Recording gespeichert', [
                'call_session_id' => $session->id,
                'context_file_id' => $result['id'],
                'filename' => $recordingData['filename'],
            ]);
        } finally {
            @unlink($tempPath);
        }
    }
}
