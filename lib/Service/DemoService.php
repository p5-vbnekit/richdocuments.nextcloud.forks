<?php
/**
 * @copyright Copyright (c) 2020 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types = 1);

namespace OCA\Richdocuments\Service;

use \OCA\Richdocuments\Utils;

class DemoService {
    private readonly \OCP\ICache $cache;

    public function __construct(
        \OCP\ICacheFactory $cacheFactory,
        private readonly string $appName,
        private readonly \OCP\Http\Client\IClientService $clientService,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
        $this->cache = $cacheFactory->createDistributed($appName);
    }

    public function fetchDemoServers(bool $refresh = false): array {
        $cacheKey = $this->appName . '-demo';

        if ($refresh) {
            static $url = 'https://col.la/nextclouddemoservers';

            try {
                $servers = $this->clientService->newClient()->get($url)->getBody();
                Utils\Common::assert(\is_string($servers));
                Utils\Common::assert('' !== $servers);
                $servers = \json_decode($servers, true);
                Utils\Common::assert(\is_array($servers));
                Utils\Common::assert(\array_key_exists('servers', $servers));
                $servers = $servers['servers'];
                Utils\Common::assert(! empty($servers));
            }

            catch (\Exception $exception) {
                $servers = [];
                $this->logger->warning(\implode(' ', [
                    'failed to fetch',
                    'demoservers from', $url
                ]), ['exception' => $exception]);
            }

            $this->cache->set($cacheKey, \json_encode($servers));
        }

        else {
            $servers = $this->cache->get($cacheKey);
            if (\is_string($servers) && ('' !== $servers)) {
                $servers = \json_decode($servers, true);
                if (! \is_array($servers)) $servers = [];
            } else $servers = [];
        }

        return $servers;
    }
}
