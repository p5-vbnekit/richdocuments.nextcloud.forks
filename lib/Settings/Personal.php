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

namespace OCA\Richdocuments\Settings;

class Personal implements \OCP\Settings\ISettings {
    public function __construct(
        private readonly ?string $userId,
        private readonly string $appName,
        private readonly \OCA\Richdocuments\Config\User $config,
        private readonly \OCA\Richdocuments\Service\CapabilitiesService $capabilitiesService,
        private readonly \OCA\Richdocuments\Service\InitialStateService $initialStateService

    ) {}

    /** @psalm-suppress InvalidNullableReturnType */
    public function getForm() {
        if (! (
            $this->capabilitiesService->hasTemplateSaveAs() ||
            $this->capabilitiesService->hasTemplateSource()
        )) return null;

        $this->initialStateService->provideCapabilities();

        return new \OCP\AppFramework\Http\TemplateResponse($this->appName, 'personal', [
            'templateFolder' => $this->config->get('templateFolder'),
            'hasZoteroSupport' => $this->capabilitiesService->hasZoteroSupport(),
            'zoteroAPIKey' => $this->config->get('zoteroAPIKey')
        ], 'blank');
    }

    public function getSection() {
        if (! (
            $this->capabilitiesService->hasTemplateSaveAs() ||
            $this->capabilitiesService->hasTemplateSource()
        )) return null;
        return $this->appName;
    }

    public function getPriority() { return 0; }
}
