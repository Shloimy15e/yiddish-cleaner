<?php

namespace App\Services\Google;

use App\Models\User;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception as GoogleServiceException;
use RuntimeException;

class DriveService
{
    protected ?Drive $service = null;

    public function __construct(
        protected GoogleAuthService $authService,
    ) {}

    /**
     * Initialize the Drive service for a user.
     */
    public function forUser(User $user): self
    {
        $client = $this->authService->getClientForUser($user);

        if (! $client) {
            throw new RuntimeException('User does not have valid Google credentials');
        }

        $this->service = new Drive($client);

        return $this;
    }

    /**
     * Download a file by ID.
     */
    public function downloadFile(string $fileId, string $destPath): void
    {
        $this->ensureService();

        try {
            $response = $this->service->files->get($fileId, [
                'alt' => 'media',
                'supportsAllDrives' => true,
            ]);
        } catch (GoogleServiceException $e) {
            throw $this->mapDriveException($e, $fileId);
        }

        file_put_contents($destPath, $response->getBody()->getContents());
    }

    /**
     * Export a Google Doc as DOCX.
     */
    public function exportAsDocx(string $fileId, string $destPath): void
    {
        $this->ensureService();

        try {
            $response = $this->service->files->export($fileId, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', [
                'alt' => 'media',
                'supportsAllDrives' => true,
            ]);
        } catch (GoogleServiceException $e) {
            throw $this->mapDriveException($e, $fileId);
        }

        file_put_contents($destPath, $response->getBody()->getContents());
    }

    /**
     * Get file metadata.
     */
    public function getFile(string $fileId): DriveFile
    {
        $this->ensureService();

        try {
            return $this->service->files->get($fileId, [
                'fields' => 'id,name,mimeType,size,webViewLink',
                'supportsAllDrives' => true,
            ]);
        } catch (GoogleServiceException $e) {
            throw $this->mapDriveException($e, $fileId);
        }
    }

    /**
     * List files in a folder.
     */
    public function listFolder(string $folderId): array
    {
        $this->ensureService();

        $files = [];
        $pageToken = null;

        do {
            $response = $this->service->files->listFiles([
                'q' => "'{$folderId}' in parents and trashed = false",
                'fields' => 'nextPageToken, files(id, name, mimeType, size)',
                'pageSize' => 100,
                'pageToken' => $pageToken,
            ]);

            $files = array_merge($files, $response->getFiles());
            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $files;
    }

    /**
     * Upload a file to Drive.
     */
    public function uploadFile(string $localPath, string $folderId, ?string $name = null): DriveFile
    {
        $this->ensureService();

        $name = $name ?? basename($localPath);
        $mimeType = mime_content_type($localPath);

        $fileMetadata = new DriveFile([
            'name' => $name,
            'parents' => [$folderId],
        ]);

        return $this->service->files->create($fileMetadata, [
            'data' => file_get_contents($localPath),
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id, name, webViewLink',
        ]);
    }

    /**
     * Extract file ID from a Google Drive URL.
     */
    public static function extractFileId(string $url): ?string
    {
        // Match various Google Drive URL formats
        $patterns = [
            '/\/file\/d\/([a-zA-Z0-9_-]+)/',
            '/\/document\/d\/([a-zA-Z0-9_-]+)/',
            '/\/folders\/([a-zA-Z0-9_-]+)/',
            '/id=([a-zA-Z0-9_-]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    protected function ensureService(): void
    {
        if (! $this->service) {
            throw new RuntimeException('Drive service not initialized. Call forUser() first.');
        }
    }

    protected function mapDriveException(GoogleServiceException $exception, ?string $fileId = null): RuntimeException
    {
        $code = (int) $exception->getCode();
        $suffix = $fileId ? " (File ID: {$fileId})" : '';

        if ($code === 404) {
            return new RuntimeException(
                "Drive file not found or you don't have access to it. Share the file with the connected Google account and try again{$suffix}.",
                $code,
                $exception
            );
        }

        if ($code === 403) {
            return new RuntimeException(
                "Access denied to Drive file. Share the file with the connected Google account and try again{$suffix}.",
                $code,
                $exception
            );
        }

        return new RuntimeException('Google Drive error: '.$exception->getMessage(), $code, $exception);
    }
}
