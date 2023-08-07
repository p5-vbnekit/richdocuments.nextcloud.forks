<?php
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
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

namespace OCA\Richdocuments\Settings;

class Admin implements \OCP\Settings\ISettings {
    public function __construct(
        private readonly string $appName,
        private readonly \OCA\Richdocuments\TemplateManager $manager,
        private readonly \OCA\Richdocuments\Config\Collector $config,
        private readonly \OCA\Richdocuments\Service\DemoService $demoService,
        private readonly \OCA\Richdocuments\Service\FontService $fontService,
        private readonly \OCA\Richdocuments\Service\InitialStateService $initialStateService,
        private readonly \OCA\Richdocuments\Service\CapabilitiesService $capabilitiesService
    ) {}

    public function getForm() {
        $this->initialStateService->provideCapabilities();

        $_payload = [
            'code' => $this->config->application->get('code'),
            'wopi_url' => $this->config->application->get('wopi_url'),
            'wopi_allowlist' => \implode(', ', $this->config->application->get('wopi_allowlist')),
            'edit_groups' => \implode('|', $this->config->application->get('edit_groups')),
            'use_groups' => \implode('|', $this->config->application->get('use_groups')),
            'doc_format' => $this->config->application->get('doc_format'),
            'external_apps' => \implode(',', $this->config->application->get('external_apps')),
            'canonical_webroot' => $this->config->application->get('canonical_webroot'),
            'disable_certificate_verification' => $this->config->application->get('disable_certificate_verification')
        ];

        $_payload = ['settings' => [
            ...$_payload,
            'templates' => $this->manager->getSystemFormatted(),
            'templatesAvailable' => $this->capabilitiesService->hasTemplateSaveAs() || $this->capabilitiesService->hasTemplateSource(),
            'settings' => \iterator_to_array((function () use ($_payload) {
                yield from $_payload;
                foreach (\iterator_to_array((function () use ($_payload) {
                    yield from \array_filter(
                        $this->config->application->keys(),
                        fn (string $key) => (! \array_key_exists($key, $_payload))
                    );
                    yield from \array_filter(
                        $this->config->application->keys('files'),
                        fn (string $key) => (
                            \str_starts_with($key, 'watermark_') && (! \array_key_exists($key, $_payload))
                        )
                    );
                }) (), true) as $_key) {
                    yield $_key => $this->config->application->get($_key);
                }
            }) (), true),
            'demo_servers' => $this->demoService->fetchDemoServers(),
            'web_server' => \strtolower($_SERVER['SERVER_SOFTWARE']),
            'os_family' => \PHP_VERSION_ID >= 70200 ? \PHP_OS_FAMILY : \PHP_OS,
            'platform' => \php_uname('m'),
            'fonts' => $this->fontService->getFontFileNames()
        ]];

        return new \OCP\AppFramework\Http\TemplateResponse(
            $this->appName, 'admin', $_payload, 'blank'
        );
    }

    public function getSection() { return $this->appName; }

    public function getPriority() { return 0; }
}
