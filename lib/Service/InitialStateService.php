<?php
/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
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

class InitialStateService {
    private bool $hasProvidedCapabilities = false;

    public function __construct(
        private readonly ?string $userId,
        private readonly \OCP\IURLGenerator $urlGenerator,
        private readonly \OCP\AppFramework\Services\IInitialState $initialState,
        private readonly CapabilitiesService $capabilitiesService,
        private readonly \OCA\Richdocuments\Config\Collector $config,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {}

    public function provideCapabilities(): void {
        if ($this->hasProvidedCapabilities) return;
        $this->initialState->provideInitialState('productName', $this->capabilitiesService->getProductName());
        $this->initialState->provideInitialState('hasDrawSupport', $this->capabilitiesService->hasDrawSupport());
        $this->initialState->provideInitialState('hasNextcloudBranding', $this->capabilitiesService->hasNextcloudBranding());
        $this->hasProvidedCapabilities = true;
    }

    public function provideDocument(
        \OCA\Richdocuments\Db\Wopi $wopi, array $params
    ): void {
        $this->provideCapabilities();

        $this->initialState->provideInitialState('document', $this->prepareParams($params));

        $this->initialState->provideInitialState('wopi', $wopi);
        $this->initialState->provideInitialState('theme', $this->config->application->get(
            'theme', ['default' => 'nextcloud']
        ));
        $this->initialState->provideInitialState('uiDefaults', [
            'UIMode' => $this->config->application->get(
                'uiDefaults-UIMode', ['default' => 'notebookbar']
            )
        ]);

        $logo = '' !== $this->config->application->get(
            'logoheaderMime', ['default' => '', 'application' => 'theming']
        );
        if (! $logo) $logo = '' !== $this->config->application->get(
            'logoMime', ['default' => '', 'application' => 'theming']
        );
        if ($logo) try { $logo = $this->urlGenerator->getAbsoluteURL(
            \OC::$server->get(\OCA\Theming\ThemingDefaults::class)->getLogo()
        ); } catch (\Throwable $exception) { $this->logger->error(
            'failed to resolve theming logo', ['exception' => $exception]
        ); }

        $this->initialState->provideInitialState('theming-customLogo', $logo);
    }

    public function prepareParams(array $params): array { return \array_merge([
        'instanceId' => $this->config->system->get('instanceid'),
        'canonical_webroot' => $this->config->application->get('canonical_webroot'),
        'userId' => $this->userId,
        'token' => '',
        'token_ttl' => 0,
        'directEdit' => false,
        'directGuest' => false,
        'path' => '',
        'urlsrc' => '',
        'fileId' => '',
        'title' => '',
        'permissions' => '',
        'isPublicShare' => false,
    ], $params); }
}
