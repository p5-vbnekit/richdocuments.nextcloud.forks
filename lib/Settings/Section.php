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

class Section implements \OCP\Settings\IIconSection {
    public function __construct(
        private readonly string $appName,
        private readonly \OCP\IL10N $l10n,
        private readonly \OCP\IURLGenerator $urlGenerator,
        private readonly \OCA\Richdocuments\Service\CapabilitiesService $capabilitiesService
    ) {}

    public function getID() { return $this->appName; }

    public function getName() {
        if ($this->capabilitiesService->hasNextcloudBranding()) return $this->l10n->t('Office');
        return $this->capabilitiesService->getProductName();
    }

    public function getPriority() { return 75; }

    public function getIcon() { return $this->urlGenerator->imagePath(
        $this->appName, 'app-dark.svg'
    ); }
}
