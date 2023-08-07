<?php
/**
 * @copyright Copyright (c) 2017 Lukas Reschke <lukas@statuscode.ch>
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

use \OCP\Share\IShare;
use \OCP\Share\IAttributes;

class PermissionManager {
    public function __construct(
        private readonly \OCP\IUserManager $userManager,
        private readonly \OCP\IUserSession $userSession,
        private readonly \OCP\IGroupManager $groupManager,
        private readonly \OCP\SystemTag\ISystemTagObjectMapper $systemTagObjectMapper,
        private readonly Config\Collector $config
    ) {}

    private function userMatchesGroupList(?string $userId = null, ?array $groupList = []): bool {
        if (\is_null($userId)) {
            // Share links set the incognito mode so in order to still get the
            // user information we need to temporarily switch it off to get the current user
            $incognito = \OC_User::isIncognitoMode();
            if ($incognito) \OC_User::setIncognitoMode(false);
            try {
                $user = $this->userSession->getUser();
                $userId = \is_null($user) ? null : $user->getUID();
            }
            finally { if ($incognito) \OC_User::setIncognitoMode(true); }
        }

        // Access for public users will be checked separately based on the share owner
        // when generating the WOPI  token and loading the scripts on public share links
        if (\is_null($userId)) return false;

        if (\is_null($groupList) || empty($groupList)) return true;

        if ($this->groupManager->isAdmin($userId)) return true;

        $userGroups = $this->groupManager->getUserGroupIds($this->userManager->get($userId));

        foreach ($groupList as $group) if (\in_array($group, $userGroups)) return true;

        return false;
    }

    public function isEnabledForUser(?string $userId = null): bool {
        return $this->userMatchesGroupList(
            $userId, $this->config->application->get('use_groups')
        );
    }

    public function userCanEdit(?string $userId = null): bool {
        return $this->userMatchesGroupList(
            $userId, $this->config->application->get('edit_groups')
        );
    }

    public function userIsFeatureLocked(?string $userId = null): bool {
        if (! $this->config->application->get('read_only_feature_lock')) return false;
        return ! $this->userCanEdit($userId);
    }

    public function shouldWatermark(
        \OCP\Files\Node $node,
        ?string $userId = null,
        ?IShare $share = null
    ): bool {
        if (! $this->config->application->get('watermark_enabled')) return false;

        $fileId = $node->getId();

        $isUpdatable = $node->isUpdateable() && (\is_null($share) || (\OCP\Constants::PERMISSION_UPDATE & $share->getPermissions()));

        $hasShareAttributes = (! \is_null($share)) && \method_exists($share, 'getAttributes') && ($share->getAttributes() instanceof IAttributes);
        $isDisabledDownload = $hasShareAttributes && (false === $share->getAttributes()->getAttribute('permissions', 'download'));
        $isHideDownload = (! \is_null($share)) && $share->getHideDownload();
        $isSecureView = $isDisabledDownload || $isHideDownload;
        if ((! \is_null($share)) && (IShare::TYPE_LINK === $share->getShareType())) {
            if ($this->config->application->get('watermark_linkAll')) return true;
            if ((! $isUpdatable) && $this->config->application->get('watermark_linkRead')) return true;
            if ($isSecureView && $this->config->application->get('watermark_linkSecure')) return true;
            if ($this->config->application->get('watermark_linkTags')) {
                $tags = $this->config->application->get('watermark_linkTagsList');
                foreach (\array_map('strval', $this->systemTagObjectMapper->getTagIdsForObjects(
                    [$fileId], 'files'
                )[$fileId]) as $tagId) if (\in_array($tagId, $tags, true)) return true;
            }
        }

        if ($this->config->application->get(
            'watermark_shareAll'
        ) && ($userId !== $node->getOwner()->getUID())) return true;

        if ((! $isUpdatable) && $this->config->application->get(
            'watermark_shareRead'
        )) return true;

        if ($isDisabledDownload && $this->config->application->get(
            'watermark_shareDisabledDownload'
        )) return true;

        if ((! \is_null($userId)) && $this->config->application->get(
            'watermark_allGroups'
        )) foreach ($this->config->application->get(
            'watermark_allGroupsList'
        ) as $group) if ($this->groupManager->isInGroup(
            $userId, $group
        )) return true;

        if ($this->config->application->get('watermark_allTags')) {
            $tags = $this->config->application->get('watermark_allTagsList');
            foreach (\array_map('strval', $this->systemTagObjectMapper->getTagIdsForObjects(
                [$fileId], 'files'
            )[$fileId]) as $tagId) if (\in_array($tagId, $tags, true)) return true;
        }

        return false;
    }
}
