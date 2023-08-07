<?php
/**
 * @copyright Copyright (c) 2019 Julius Härtl <jus@bitgrid.net>
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

use \OCP\AutoloadNotAllowedException;
use \OCP\Files\NotFoundException;

use \OCA\Federation\TrustedServers;
use \OCA\Richdocuments\Db;
use \OCA\Richdocuments\Utils;
use \OCA\Files_Sharing\External\Storage as SharingExternalStorage;

use \Psr\Container\ContainerExceptionInterface;
use \Psr\Container\NotFoundExceptionInterface;

class FederationService {
    private readonly \OCP\ICache $cache;
    private readonly ?TrustedServers $trustedServers;

    public function __construct(
        \OCP\ICacheFactory $cacheFactory,
        private readonly string $appName,
        private readonly \OCP\IRequest $request,
        private readonly \OCP\IURLGenerator $urlGenerator,
        private readonly \OCP\Http\Client\IClientService $clientService,
        private readonly \OCA\Richdocuments\TokenManager $tokenManager,
        private readonly \OCA\Richdocuments\Config\Collector $config,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
        $this->cache = $cacheFactory->createDistributed($appName . '_remote/');

        $this->trustedServers = (static function () use($logger) {
            $className = TrustedServers::class;

            try { return \OC::$server->get($className); }
            catch (\Throwable $exception) { if (! (
                    ($exception instanceof NotFoundExceptionInterface) ||
                    ($exception instanceof ContainerExceptionInterface) ||
                    ($exception instanceof AutoloadNotAllowedException)
            )) throw $exception; }

            $logger->warning(
                'failed to resolve ' . $className,
                ['exception' => $exception]
            );

            return null;
        }) ();
    }

    public function getTrustedServers(): array {
        if (! $this->trustedServers) return [];
        return \array_map(
            static fn (array $server) => $server['url'],
            $this->trustedServers->getServers()
        );
    }

    public function getRemoteCollaboraURL(string $remote): string {
        try { (static function () use (&$remote) {
            $remote = \rtrim($remote, '/');
            Utils\Common::assert('' !== $remote);
            $parsed = \parse_url($remote);
            Utils\Common::assert(\is_array($parsed));
            Utils\Common::assert(\array_key_exists('host', $parsed));
            Utils\Common::assert(! \array_key_exists('fragment', $parsed));
            if (\array_key_exists('scheme', $parsed)) Utils\Common::assert(
                \in_array($parsed['scheme'], ['http', 'https'], true
            ));
            else $remote = 'https://' . $remote;
        }) (); }

        catch (\Throwable $e) {
            unset($e);
            throw new \Exception('Unable to determine collabora URL of remote server');
        }

        if (! $this->isTrustedRemote($remote)) throw new \InvalidArgumentException(\implode(' ', [
            'Unable to determine collabora',
            'URL of remote server', $remote,
            '- Remote is not a trusted server'
        ]));

        $cacheKey = $this->appName . '_remote/' . $remote;
        $result = $this->cache->get($cacheKey);
        if (! \is_null($result)) return $result;

        try {
            $result = $this->clientService->newClient()->get(\implode('', [
                $remote, '/ocs/v2.php/apps/',
                $this->appName, '/api/v1/federation?format=json'
            ]), ['timeout' => 30])->getBody();
            Utils\Common::assert(\is_string($result));
            Utils\Common::assert('' !== $result);
            $result = Utils\Common::get_from_tree(
                \json_decode($result, true),
                ['ocs', 'data', 'wopi_url']
            );
            Utils\Common::assert(\is_string($result));
            $result = \rtrim($result, '/');
            (static function () use ($result) {
                Utils\Common::assert('' !== $result);
                $result = \parse_url($result);
                Utils\Common::assert(\is_array($result));
                Utils\Common::assert(\array_key_exists('scheme', $result));
                Utils\Common::assert(\in_array(
                    $result['scheme'], ['http', 'https'], true
                ));
                Utils\Common::assert(\array_key_exists('host', $result));
                Utils\Common::assert(! \array_key_exists('fragment', $result));
            }) ();
            $this->cache->set($cacheKey, $result, 3600);
            return $result;
        }

        catch (\Throwable $e) { $this->logger->info(\implode(' ', [
            'Unable to determine collabora',
            'URL of remote server', $remote,
        ])); }

        $this->cache->set($cacheKey, '', 300);
        return '';
    }

    public function isTrustedRemote(string $domainWithPort): bool {
        $domainWithPort = (static function () use ($domainWithPort) {
            $domainWithPort = \rtrim($domainWithPort, '/');
            if ('' === $domainWithPort) return null;
            $domainWithPort = \parse_url($domainWithPort);
            if (! \is_array($domainWithPort)) return null;
            if (! \array_key_exists('host', $domainWithPort)) return null;
            if ('' === $domainWithPort['host']) return null;
            if (\array_key_exists('port', $domainWithPort)) return \implode(
                ':', [$domainWithPort['host'], $domainWithPort['port']]
            );
            return $domainWithPort['host'];
        });

        if (! \is_string($domainWithPort)) return false;

        if ((function () use ($domainWithPort) {
            if (! $this->config->application->get(
                'federation_use_trusted_domains'
            )) return false;
            if (\is_null($this->trustedServers)) return false;
            return $this->trustedServers->isTrustedServer($domainWithPort);
        }) ()) return true;

        $domain = \parse_url($domainWithPort, \PHP_URL_HOST);

        foreach (\array_merge(
            $this->config->system->get('gs.trustedHosts'),
            [$this->request->getServerHost()]
        ) as $trusted) {
            $regex = '/^' . \implode('[-\.a-zA-Z0-9]*', \array_map(
                static fn (string $v) => \preg_quote($v, '/'),
                \explode('*', $trusted)
            )) . '$/i';
            if (\preg_match($regex, $domain)) return true;
            if (\preg_match($regex, $domainWithPort)) return true;
        }

        return false;
    }

    public function getRemoteFileDetails(string $remote, string $remoteToken): ?Db\Wopi {
        $cacheKey = \md5($remote . $remoteToken);
        $result = $this->cache->get($cacheKey);
        if (! \is_null($result)) return Db\Wopi::fromParams($result);

        if (! $this->isTrustedRemote($remote)) {
            $this->logger->info(\implode(' ', [
                'COOL-Federation-Source: Unable to determine collabora URL of remote server',
                $remote, 'for token', $remoteToken, '- Remote is not a trusted server'
            ]));
            return null;
        }

        try {
            $this->logger->debug(\implode(' ', [
                'COOL-Federation-Source: Fetching remote file details from',
                $remote, 'for token', $remoteToken
            ]));
            $result = $this->clientService->newClient()->post(
                $remote . '/ocs/v2.php/apps/richdocuments/api/v1/federation?format=json',
                ['timeout' => 30, 'body' => ['token' => $remoteToken]]
            )->getBody();
            Utils\Common::assert(\is_string($result));
            Utils\Common::assert('' !== $result);
            $result = Utils\Common::get_from_tree(\json_decode(
                $result, true, 512
            ), ['ocs', 'data']);
            Utils\Common::assert(\is_array($result));
            Utils\Common::assert(! empty($result));
            $this->logger->debug(\implode(' ', [
                'COOL-Federation-Source: Received remote file details for',
                $remoteToken, 'from', $remote . ':', \json_encode($result)
            ]));
        }

        catch (\Throwable $e) {
            $result = null;
            $this->logger->warning(\implode(' ', [
                'COOL-Federation-Source: Unable to fetch remote file details for',
                $remoteToken, 'from' . $remote
            ]), ['exception' => $e]);
        }

        if (\is_null($result)) return null;
        $this->cache->set($cacheKey, $result);
        return Db\Wopi::fromParams($result);
    }

    public function getRemoteRedirectURL(
        \OCP\Files\File $item,
        ?Db\Direct $direct = null,
        ?\OCP\Share\IShare $share = null
    ): ?string {
        if (! $item->getStorage()->instanceOfStorage(
            SharingExternalStorage::class
        )) return null;

        $remote = $item->getStorage()->getRemote();

        if ('' !== $this->getRemoteCollaboraURL($remote)) {
            $shareToken = $share ? $share->getToken() : null;

            $wopi = $this->tokenManager->newInitiatorToken(
                $remote, $item, $shareToken,
                ! \is_null($direct), $direct ? $direct->getUid() : null
            );
            $initiatorServer = $this->urlGenerator->getAbsoluteURL('/');
            $initiatorToken = $wopi->getToken();

            /**
             * If the request to open a file originates from a direct token we might need to fetch the initiator user details when the initiator wopi token is accessed
             * as the user might origin on a 3rd instance
             */
            if ($direct && (! (
                empty($direct->getInitiatorHost()) || empty($direct->getInitiatorToken())
            ))) $this->tokenManager->extendWithInitiatorUserToken(
                $wopi, $direct->getInitiatorHost(), $direct->getInitiatorToken()
            );

            $url = \implode('', [
                \rtrim($remote, '/'),
                '/index.php/apps/richdocuments/remote',
                '?shareToken=' . $item->getStorage()->getToken(),
                '&remoteServer=' . $initiatorServer,
                '&remoteServerToken=' . $initiatorToken
            ]);

            if ('' !== $item->getInternalPath()) $url = $url . '&filePath=' . $item->getInternalPath();

            return $url;
        }

        throw new NotFoundException(\implode(' ', [
            'Failed to connect to remote collabora',
            'instance for', $item->getId()
        ]));
    }
}
