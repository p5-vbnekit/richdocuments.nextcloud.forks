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

namespace OCA\Richdocuments\Controller;

use \OCP\Files;
use \OCP\AppFramework\Http;
use \OCA\Richdocuments\Utils;

class DirectViewController extends \OCP\AppFramework\Controller {
    use DocumentTrait;

    public function __construct(
        string $appName,
        \OCP\IRequest $request,
        private readonly \OCP\Share\IManager $shareManager,
        private readonly \OCP\Files\IRootFolder $rootFolder,
        private readonly \OCP\EventDispatcher\IEventDispatcher $eventDispatcher,
        private readonly \OCA\Richdocuments\TokenManager $tokenManager,
        private readonly \OCA\Richdocuments\TemplateManager $templateManager,
        private readonly \OCA\Richdocuments\Db\DirectMapper $directMapper,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $endpointResolver,
        private readonly \OCA\Richdocuments\Config\Collector $config,
        private readonly \OCA\Richdocuments\Service\FederationService $federationService,
        private readonly \OCA\Richdocuments\Service\InitialStateService $initialStateService,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @param string $token
     * @return Http\JSONResponse|Http\RedirectResponse|Http\TemplateResponse
     * @throws Files\NotFoundException
     */
    public function show($token) {
        try { $direct = $this->directMapper->getByToken($token); }
        catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            unset($e);
            $response = $this->renderErrorPage('Failed to open the requested file.');
            $response->setStatus(Http::STATUS_FORBIDDEN);
            return $response;
        }

        // Delete the token. They are for 1 time use only
        $this->directMapper->delete($direct);

        // Direct token for share link
        if (! empty($direct->getShare())) return $this->showPublicShare($direct);

        $folder = $this->rootFolder->getUserFolder($direct->getUid());
        if ($this->templateManager->isTemplate($direct->getFileid())) {
            $item = $this->templateManager->get($direct->getFileid());
            $templateDestination = $direct->getTemplateDestination();

            if (\in_array(
                $templateDestination, [0, null], true
            )) return new Http\JSONResponse([], Http::STATUS_BAD_REQUEST);

            try {
                list($urlSrc, $wopi) = $this->tokenManager->getTokenForTemplate(
                    $item, $direct->getUid(), $templateDestination, true
                );
                $targetFile = $folder->getById($templateDestination)[0];
                $relativePath = $folder->getRelativePath($targetFile->getPath());
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to generate token for new file on direct editing',
                    ['exception' => $e]
                );
                return new Http\JSONResponse([], Http::STATUS_BAD_REQUEST);
            }
        } else {
            try {
                $item = $folder->getById($direct->getFileid())[0];
                Utils\Common::assert($item instanceof Files\Node);

                /** Open file from remote collabora */
                $federatedUrl = $this->federationService->getRemoteRedirectURL($item, $direct);
                if (! \is_null($federatedUrl)) {
                    $response = new Http\RedirectResponse($federatedUrl);
                    $response->addHeader('X-Frame-Options', 'ALLOW');
                    return $response;
                }

                list($urlSrc, $token, $wopi) = $this->tokenManager->getToken(
                    $item->getId(), null, $direct->getUid(), true
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to generate token for existing file on direct editing',
                    ['exception' => $e]
                );
                return $this->renderErrorPage('Failed to open the requested file.');
            }

            $relativePath = $folder->getRelativePath($item->getPath());
        }

        try { return $this->documentTemplateResponse($wopi, [
                'permissions' => $item->getPermissions(),
                'title' => basename($relativePath),
                'fileId' => \implode('_', [
                    $wopi->getFileid(), $this->config->system->get('instanceid')
                ]),
                'token' => $wopi->getToken(),
                'token_ttl' => $wopi->getExpiry(),
                'urlsrc' => $urlSrc,
                'path' => $relativePath,
                'direct' => true,
        ]); } catch (\Throwable $e) { $this->logger->error(
            'Failed to open the requested file',
            ['exception' => $e]
        ); }

        return  $this->renderErrorPage('Failed to open the requested file.');
    }

    public function showPublicShare(
        \OCA\Richdocuments\Db\Direct $direct
    ) {
        try {
            $share = $this->shareManager->getShareByToken($direct->getShare());

            $node = $share->getNode();
            if ($node instanceof Files\Folder) {
                $node = \array_shift($node->getById($direct->getFileid()));
                if (\is_null($node)) throw new Files\NotFoundException();
            }

            // Handle opening a share link that originates from a remote instance
            $federatedUrl = $this->federationService->getRemoteRedirectURL($node, $direct, $share);
            if (! \is_null($federatedUrl)) {
                $response = new Http\RedirectResponse($federatedUrl);
                $response->addHeader('X-Frame-Options', 'ALLOW');
                return $response;
            }

            if ($node instanceof Files\Node) {
                $params = [
                    'permissions' => $share->getPermissions(),
                    'title' => $node->getName(),
                    'fileId' => \implode('_', [
                        $node->getId(), $this->config->system->get('instanceid')
                    ]),
                    'path' => '/',
                    'userId' => null,
                    'direct' => true,
                    'directGuest' => empty($direct->getUid()),
                ];

                list($urlSrc, $token, $wopi) = $this->tokenManager->getToken(
                    $node->getId(), $direct->getShare(), $direct->getUid(), true
                );

                if (! empty($direct->getInitiatorHost())) $this->tokenManager->upgradeFromDirectInitiator(
                    $direct, $wopi
                );

                $params['token'] = $token;
                $params['token_ttl'] = $wopi->getExpiry();
                $params['urlsrc'] = $urlSrc;

                return $this->documentTemplateResponse($wopi, $params);
            }
        }

        catch (\Throwable $e) {
            $this->logger->error('Failed to open the requested file', ['exception' => $e]);
            return $this->renderErrorPage('Failed to open the requested file.');
        }

        return new Http\TemplateResponse('core', '403', [], 'guest');
    }

    private function renderErrorPage($message) { return new Http\TemplateResponse(
        'core', 'error', ['errors' => [['error' => $message]]], 'guest'
    ); }
}
