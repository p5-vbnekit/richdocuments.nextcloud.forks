<?php
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
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

namespace OCA\Richdocuments\WOPI;

class DiscoveryManager {
    private \OCP\ICache $cache;

    private ?string $discovery = null;

    public function __construct(
        string $appName,
        \OCP\ICacheFactory $cacheFactory,
        private readonly \OCP\Http\Client\IClientService $clientService,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $endpointResolver,
        private readonly \OCA\Richdocuments\Config\Application $config,
        private readonly \OCA\Richdocuments\Service\CodeService $codeService,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
        $this->cache = $cacheFactory->createDistributed($appName);
    }

    public function get(): ?string {
        if ($this->config->get('code')) {
            $discovery = $this->cache->get('discovery');
            if (! \is_null($discovery)) $this->cache->remove('discovery');
            $this->discovery = $this->codeService->discovery();
        }

        else {
            if (\is_null($this->discovery)) $this->discovery = $this->cache->get('discovery');
            if (\is_null($this->discovery)) {
                $this->discovery = ((function () {
                    $url = $this->endpointResolver->internal();
                    \OCA\Richdocuments\Utils\Common::assert(\is_string($url));
                    $url = \rtrim($url, '/') . '/hosting/discovery';
                    $client = $this->clientService->newClient();
                    $options = ['timeout' => 45, 'nextcloud' => ['allow_local_address' => true]];
                    if ($this->config->get('disable_certificate_verification')) $options['verify'] = false;
                    $start = \microtime(true);
                    $response = $client->get($url, $options);
                    $duration = \round(((microtime(true) - $start)), 3);
                    $this->logger->info('Fetched discovery endpoint from ' . $url . ' in ' . $duration . ' seconds');
                    return $response->getBody();
                }) ());
                $this->cache->set('discovery', $this->discovery, 3600);
            }
        }

        return $this->discovery;
    }

    public function clear(): void {
        $this->cache->remove('discovery');
        $this->discovery = null;
    }
}
