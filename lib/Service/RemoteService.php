<?php

declare(strict_types = 1);

namespace OCA\Richdocuments\Service;

use \OCP\Files\File;
use \OCP\Files\NotFoundException;
use \OCA\Richdocuments\Utils;

class RemoteService {
    public const REMOTE_TIMEOUT_DEFAULT = 25;

    public function __construct(
        private string $appName,
        private readonly \OCP\Http\Client\IClientService $clientService,
        private readonly \OCA\Richdocuments\Config\Application $config,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $endpointResolver,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {}

    public function fetchTargets($file): array {
        $client = $this->clientService->newClient();

        try {
            $url = $this->endpointResolver->internal();
            Utils\Common::assert(\is_string($url));
            $url = $url . '/cool/extract-link-targets';
            $response = $client->put($url, $this->getRequestOptionsForFile($file));
        }

        catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to fetch extract-link-targets',
                ['exception' => $e]
            );
            return [];
        }

        $response = \str_replace(
            ['", }', "\r\n", "\t"],
            ['" }', '\r\n', '\t'],
            \trim($response->getBody())
        );

        try { $response = \json_decode($response, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) {
            $this->logger->warning(
                'Failed to parse extract-link-targets response',
                ['exception' => $e]
            );
            return [];
        }

        return $response;
    }

    public function fetchTargetThumbnail(File $file, string $target): ?string {
        $client = $this->clientService->newClient();

        try {
            $url = $this->endpointResolver->internal();
            Utils\Common::assert(\is_string($url));
            $url = $url . '/cool/get-thumbnail';
            $response = $client->put($url, $this->getRequestOptionsForFile($file, $target));
            return (string)($response->getBody());
        }

        catch (\Throwable $e) { $this->logger->info(
            'Failed to fetch target thumbnail',
            ['exception' => $e]
        ); }

        return null;
    }

    private function getRequestOptionsForFile(File $file, ?string $target = null): array {
        if ($file->isEncrypted() || (! $file->getStorage()->isLocal())) {
            $localFile = $file->getStorage()->getLocalFile($file->getInternalPath());
            if (! \is_string($localFile)) throw new NotFoundException(
                'Could not get local file'
            );
            $stream = \fopen($localFile, 'rb');
        }

        else $stream = $file->fopen('rb');

        $options = ['timeout' => self::REMOTE_TIMEOUT_DEFAULT, 'multipart' => [
            ['name' => $file->getName(), 'contents' => $stream],
            ['name' => 'target', 'contents' => $target]
        ]];

        if ($this->config->get('disable_certificate_verification')) $options['verify'] = false;

        $options['headers'] = [
            'User-Agent' => 'Nextcloud Server / ' . $this->appName,
            'Accept' => 'application/json'
        ];

        return $options;
    }
}
