<?php
/**
 * @copyright Copyright (c) 2022 Julius Härtl <jus@bitgrid.net>
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

namespace OCA\Richdocuments\Listener;

use \OCA\Richdocuments\Utils;

/** @template-implements \OCP\EventDispatcher\IEventListener<\OCP\EventDispatcher\Event|\OCP\Security\CSP\AddContentSecurityPolicyEvent> */
class CSPListener implements \OCP\EventDispatcher\IEventListener {
    public function __construct(
        private readonly \OCP\IRequest $request,
        private readonly \OCP\App\IAppManager $appManager,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $endpointResolver,
        private readonly \OCA\Richdocuments\Config\Collector $config,
        private readonly \OCA\Richdocuments\Service\FederationService $federationService
    ) {}

    public function handle(\OCP\EventDispatcher\Event $event): void {
        if (! ($event instanceof \OCP\Security\CSP\AddContentSecurityPolicyEvent)) return;
        if (! $this->isPageLoad()) return;

        $urls = \iterator_to_array((function () {
            $wopi = $this->endpointResolver->external();
            if (\is_string($wopi)) yield $wopi;
            yield from \array_filter($this->getFederationDomains());
            yield from \array_filter($this->getGSDomains());
        }) (), false);

        $policy = new \OCP\AppFramework\Http\EmptyContentSecurityPolicy();
        $policy->addAllowedFrameDomain("'self'");
        $policy->addAllowedFrameDomain("nc:");

        foreach ($urls as $url) {
            $policy->addAllowedFrameDomain($url);
            $policy->addAllowedFormActionDomain($url);
            $policy->addAllowedFrameAncestorDomain($url);
            $policy->addAllowedImageDomain($url);
        }

        $event->addPolicy($policy);
    }

    private function isPageLoad(): bool {
        $path = $this->request->getScriptName();
        if (! \is_string($path)) return false;
        return ('index.php' === $path) || \str_ends_with($path, '/index.php');
    }

    private function getFederationDomains(): array {
        if (! $this->appManager->isEnabledForUser('federation')) return [];

        $trustedNextcloudDomains = \array_filter(
            $this->federationService->getTrustedServers(),
            fn ($server) => \is_string($server) && ('' !== $server) && $this->federationService->isTrustedRemote($server)
        );

        $trustedCollaboraDomains = \iterator_to_array((function () use ($trustedNextcloudDomains) {
            foreach ($trustedNextcloudDomains as $server) {
                Utils\Common::assert(\is_string($server));
                Utils\Common::assert('' !== $server);
                try {
                    $server = $this->federationService->getRemoteCollaboraURL($server);
                    Utils\Common::assert(\is_string($server));
                    Utils\Common::assert('' !== $server);
                }
                catch (\Throwable $e) {
                    // If there is no remote collabora server we can just skip that
                    unset($e);
                    continue;
                }
                yield $server;
            }
        }) ());

        return \array_map(
            fn ($url) => $this->domainOnly($url),
            \array_merge($trustedNextcloudDomains, $trustedCollaboraDomains)
        );
    }

    private function getGSDomains(): array {
        if (! $this->config->global_scale->isGlobalScaleEnabled()) return [];
        return $this->config->system->get('gs.trustedHosts');
    }

    /**
     * Strips the path and query parameters from the URL.
     */
    private function domainOnly(string $url): string {
        Utils\Common::assert('' !== $url);

        $url = \parse_url($url);
        Utils\Common::assert(\is_array($url));

        if (\array_key_exists('scheme', $url)) {
            $scheme = $url['scheme'];
            Utils\Common::assert(\is_string($scheme));
            if ('' !== $scheme) $scheme = '://' . $scheme;
        } else $scheme = '';

        Utils\Common::assert(\array_key_exists('host', $url));
        $host = $url['host'];
        Utils\Common::assert(\is_string($host));
        Utils\Common::assert('' !== $host);

        if (\array_key_exists('port', $url)) {
            $port = $url['port'];
            Utils\Common::assert(\is_integer($port));
            Utils\Common::assert(0 < $port);
            Utils\Common::assert(65536 > $port);
            $port = ':' . $port;
        } else $port = '';

        return $scheme . $host . $port;
    }
}
