<?php
/**
 * @copyright Copyright (c) 2019, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types = 1);

namespace OCA\Richdocuments\Service;

class CapabilitiesService {
    private readonly \OCP\ICache $cache;
    private ?array $capabilities = null;

    public function __construct(
        string $appName,
        \OCP\ICacheFactory $cacheFactory,
        private readonly \OCP\IL10N $l10n,
        private readonly \OCP\Http\Client\IClientService $clientService,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $endpointResolver,
        private readonly \OCA\Richdocuments\Config\Application $config,
        private readonly \OCA\Richdocuments\Service\CodeService $codeService,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
        $this->cache = $cacheFactory->createDistributed($appName);
    }

    public function getCapabilities() {
        if ($this->config->get('code')) {
            $capabilities = $this->cache->get('capabilities');
            if (! \is_null($capabilities)) $this->cache->remove('capabilities');
            $this->capabilities = $this->codeService->capabilities();
        }

        else {
            if (\is_null($this->capabilities)) $this->capabilities = $this->cache->get('capabilities');
            if (\is_null($this->capabilities) || empty($this->capabilities)) { $this->refetch(); }
        }

        return \is_array($this->capabilities) ? $this->capabilities : [];
    }

    public function hasNextcloudBranding(): bool {
        $productVersion = $this->getCapabilities()['productVersion'] ?? '0.0.0.0';
        return version_compare($productVersion, '21.11', '>=');
    }

    public function hasDrawSupport(): bool {
        $productVersion = $this->getCapabilities()['productVersion'] ?? '0.0.0.0';
        return version_compare($productVersion, '6.4.7', '>=');
    }

    public function hasTemplateSaveAs(): bool {
        return $this->getCapabilities()['hasTemplateSaveAs'] ?? false;
    }

    public function hasTemplateSource(): bool {
        return $this->getCapabilities()['hasTemplateSource'] ?? false;
    }

    public function hasZoteroSupport(): bool {
        return $this->getCapabilities()['hasZoteroSupport'] ?? false;
    }

    public function getProductName(): string {
        $theme = $this->config->get('theme', ['default' => 'nextcloud']);

        $capabilitites = $this->getCapabilities();

        if (isset(
            $capabilitites['productName']
        ) && ('nextcloud' !== $theme)) return $capabilitites['productName'];

        return $this->l10n->t('Nextcloud Office');
    }

    public function clear(): void {
        $this->cache->remove('capabilities');
    }

    public function refetch(): void {
        if ($this->config->get('code')) return;
        $url = $this->endpointResolver->internal();
        if (! \is_string($url)) return;

        $url = rtrim($url, '/') . '/hosting/capabilities';

        $client = $this->clientService->newClient();
        $options = ['timeout' => 45, 'nextcloud' => ['allow_local_address' => true]];

        if ($this->config->get('disable_certificate_verification')) $options['verify'] = false;

        $capabilities = null;

        try {
            $startTime = microtime(true);
            $response = $client->get($url, $options);
            $duration = round(((microtime(true) - $startTime)), 3);
            $this->logger->info('Fetched capabilities endpoint from ' . $url. ' in ' . $duration . ' seconds');
            $responseBody = $response->getBody();
            $capabilities = \json_decode($responseBody, true);
        } catch (\Exception $e) { $this->logger->error(
            'Failed to fetch the Collabora capabilities endpoint: ' . $e->getMessage(),
            [ 'exception' => $e ]
        ); }

        if (! \is_array($capabilities)) $capabilities = [];

        $this->cache->set(
            'capabilities',
            $this->capabilities = $capabilities,
            empty($capabilities) ? 60 : 3600
        );
    }
}
