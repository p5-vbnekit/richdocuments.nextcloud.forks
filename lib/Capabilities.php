<?php
/**
 * @copyright Copyright (c) 2018, Roeland Jago Douma <roeland@famdouma.nl>
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

namespace OCA\Richdocuments;

class Capabilities implements \OCP\Capabilities\ICapability {
    public const MIMETYPES = [
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.graphics',
        'application/vnd.oasis.opendocument.presentation',
        'application/vnd.oasis.opendocument.text-flat-xml',
        'application/vnd.oasis.opendocument.spreadsheet-flat-xml',
        'application/vnd.oasis.opendocument.graphics-flat-xml',
        'application/vnd.oasis.opendocument.presentation-flat-xml',
        'application/vnd.lotus-wordpro',
        'application/vnd.visio',
        'application/vnd.ms-visio.drawing',
        'application/vnd.wordperfect',
        'application/msonenote',
        'application/msword',
        'application/rtf',
        'text/rtf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'application/vnd.ms-word.document.macroEnabled.12',
        'application/vnd.ms-word.template.macroEnabled.12',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
        'application/vnd.ms-excel.sheet.macroEnabled.12',
        'application/vnd.ms-excel.template.macroEnabled.12',
        'application/vnd.ms-excel.addin.macroEnabled.12',
        'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.presentationml.template',
        'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
        'application/vnd.ms-powerpoint.addin.macroEnabled.12',
        'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
        'application/vnd.ms-powerpoint.template.macroEnabled.12',
        'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
        'text/csv'
    ];

    public const MIMETYPES_OPTIONAL = [
        'image/svg+xml',
        'application/pdf',
        'text/plain',
        'text/spreadsheet'
    ];

    private $capabilities = null;

    public function __construct(
        private readonly ?string $userId,
        private readonly string $appName,
        private readonly \OCP\IURLGenerator $urlGenerator,
        private readonly \OCP\App\IAppManager $appManager,
        private readonly PermissionManager $permissionManager,
        private readonly WOPI\EndpointResolver $endpointResolver,
        private readonly Config\Application $config,
        private readonly Service\CapabilitiesService $capabilitiesService
    ) {}

    public function getCapabilities() {
        // Only expose capabilities for users with enabled office or guests (where it depends on the share owner if they have access)
        if (! (\is_null($this->userId) || $this->permissionManager->isEnabledForUser())) return [];

        if (\is_null($this->capabilities)) {
            $collaboraCapabilities = $this->capabilitiesService->getCapabilities();

            $filteredMimetypes = self::MIMETYPES;
            $optionalMimetypes = self::MIMETYPES_OPTIONAL;

            // If version is too old, draw is not supported
            if (! $this->capabilitiesService->hasDrawSupport()) $filteredMimetypes = \array_diff($filteredMimetypes, [
                'application/vnd.oasis.opendocument.graphics',
                'application/vnd.oasis.opendocument.graphics-flat-xml'
            ]);

            if (! $this->appManager->isEnabledForUser('files_pdfviewer')) {
                $filteredMimetypes[] = 'application/pdf';
                $optionalMimetypes = \array_diff($optionalMimetypes, ['application/pdf']);
            }

            $wopiUrl = $this->endpointResolver->external('');

            $this->capabilities = [$this->appName => [
                'version' => $this->appManager->getAppVersion($this->appName),
                'mimetypes' => \array_values($filteredMimetypes),
                'mimetypesNoDefaultOpen' => \array_values($optionalMimetypes),
                'collabora' => $collaboraCapabilities,
                'direct_editing' => isset($collaboraCapabilities['hasMobileSupport']) ?: false,
                'templates' => isset($collaboraCapabilities['hasTemplateSaveAs']) || isset($collaboraCapabilities['hasTemplateSource']) ?: false,
                'productName' => $this->capabilitiesService->getProductName(),
                'editonline_endpoint' => $this->urlGenerator->linkToRouteAbsolute('richdocuments.document.editOnline'),
                'config' => [
                    'code' => $this->config->get('code'),
                    'wopi_url' => $wopiUrl, 'public_wopi_url' => $wopiUrl,
                    'disable_certificate_verification' => $this->config->get('disable_certificate_verification'),
                    'edit_groups' => \implode('|', $this->config->get('edit_groups')),
                    'use_groups' => \implode('|', $this->config->get('use_groups')),
                    'doc_format' => $this->config->get('doc_format'),
                    'timeout' => $this->config->get('timeout')
                ]
            ]];
        }

        return $this->capabilities;
    }
}
