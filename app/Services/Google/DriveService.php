<?php

namespace App\Services\Google;

use App\Models\User;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
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

        if (!$client) {
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

        $response = $this->service->files->get($fileId, [
            'alt' => 'media',
        ]);

        file_put_contents($destPath, $response->getBody()->getContents());
    }

    /**
     * Export a Google Doc as DOCX.
     */
    public function exportAsDocx(string $fileId, string $destPath): void
    {
        $this->ensureService();

        $response = $this->service->files->export($fileId, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', [
            'alt' => 'media',
        ]);

        file_put_contents($destPath, $response->getBody()->getContents());
    }

    /**
     * Get file metadata.
     */
    public function getFile(string $fileId): DriveFile
    {
        $this->ensureService();

        return $this->service->files->get($fileId, [
            'fields' => 'id,name,mimeType,size,webViewLink',
        ]);
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
        if (!$this->service) {
            throw new RuntimeException('Drive service not initialized. Call forUser() first.');
        }
    }
}
