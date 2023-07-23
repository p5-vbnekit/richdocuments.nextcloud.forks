<?php

declare(strict_types=1);

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

namespace OCA\Richdocuments\WOPI;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class DiscoveryManager {
	private IClientService $clientService;
	private ICache $cache;
	private IConfig $config;
	private UrlMagic $urlMagic;
	private LoggerInterface $logger;

	private ?string $discovery = null;

	public function __construct(
		IClientService $clientService,
		ICacheFactory $cacheFactory,
		IConfig $config,
		UrlMagic $urlMagic,
		LoggerInterface $logger
	) {
		$this->clientService = $clientService;
		$this->cache = $cacheFactory->createDistributed('richdocuments');
		$this->config = $config;
		$this->urlMagic = $urlMagic;
		$this->logger = $logger;
	}

	public function get(): ?string {
		if ($this->discovery) return $this->discovery;

		$this->discovery = $this->cache->get('discovery');
		if (! $this->discovery) {
			$response = $this->fetchFromRemote();
			$responseBody = $response->getBody();
			$this->discovery = $responseBody;
			$this->cache->set('discovery', $this->discovery, 3600);
		}

		return $this->discovery;
	}

	/**
	 * @throws \Exception if a network error occurs
	 */
	public function fetchFromRemote(): IResponse {
		$wopiDiscovery = rtrim($this->urlMagic->internal(), '/') . '/hosting/discovery';

		$client = $this->clientService->newClient();
		$options = ['timeout' => 45, 'nextcloud' => ['allow_local_address' => true]];

		if ('yes' === $this->config->getAppValue(
			'richdocuments', 'disable_certificate_verification'
		)) $options['verify'] = false;

		if ($this->isProxyStarting()) $options['timeout'] = 180;

		$startTime = microtime(true);
		$response = $client->get($wopiDiscovery, $options);
		$duration = round(((microtime(true) - $startTime)), 3);
		$this->logger->info('Fetched discovery endpoint from ' . $wopiDiscovery . ' in ' . $duration . ' seconds');

		return $response;
	}

	public function refetch(): void {
		$this->cache->remove('discovery');
		$this->discovery = null;
	}

	/**
	 * @return boolean indicating if proxy.php is in initialize or false otherwise
	 */
	private function isProxyStarting(): bool {
		$proxyUrl = $this->urlMagic->code_proxy();

		if ('' !== $proxyUrl) {
			$statusUrl = $proxyUrl . '?status';

			$client = $this->clientService->newClient();
			$options = ['timeout' => 5, 'nextcloud' => ['allow_local_address' => true]];

			if ('yes' === $this->config->getAppValue(
				'richdocuments', 'disable_certificate_verification'
			)) $options['verify'] = false;

			try {
				$response = $client->get($statusUrl, $options);
				if (200 === $response->getStatusCode()) {
					$body = json_decode($response->getBody(), true);
					if ($body['status'] === 'starting'
					|| $body['status'] === 'stopped'
					|| $body['status'] === 'restarting'
					) return true;
				}
			} catch (\Exception) {}
		}

		return false;
	}
}
