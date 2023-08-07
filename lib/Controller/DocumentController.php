<?php
/**
 * ownCloud - Richdocuments App
 *
 * @author Victor Dubiniuk
 * @copyright 2014 Victor Dubiniuk victor.dubiniuk@gmail.com
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\Richdocuments\Controller;

use \OC\User\NoUserException;

use \OCP\Constants;
use \OCP\Files\Node;
use \OCP\Files\File;
use \OCP\Files\Folder;
use \OCP\Files\NotFoundException;
use \OCP\Files\NotPermittedException;
use \OCP\AppFramework\Http;
use \OCP\Share\Exceptions\ShareNotFound;

use \OCA\Richdocuments\Utils;

class DocumentController extends \OCP\AppFramework\Controller {
    use DocumentTrait;

    public const SESSION_FILE_TARGET = 'richdocuments_openfile_target';

    public function __construct(
        string $appName,
        \OCP\IRequest $request,
        private readonly ?string $userId,
        private readonly \OCP\ISession $session,
        private readonly \OCP\IURLGenerator $urlGenerator,
        private readonly \OCP\Share\IManager $shareManager,
        private readonly \OCP\Files\IRootFolder $rootFolder,
        private readonly \OCP\EventDispatcher\IEventDispatcher $eventDispatcher,
        private readonly \OCA\Richdocuments\TokenManager $tokenManager,
        private readonly \OCA\Richdocuments\TemplateManager $templateManager,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $endpointResolver,
        private readonly \OCA\Richdocuments\Config\Collector $config,
        private readonly \OCA\Richdocuments\Service\FederationService $federationService,
        private readonly \OCA\Richdocuments\Service\InitialStateService $initialStateService,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * Returns the access_token and urlsrc for WOPI access for given $fileId
     * Requests is accepted only when a secret_token is provided set by admin in
     * settings page
     *
     * @return array access_token, urlsrc
     */
    public function extAppGetData(int $fileId) {
        $secretToken = $this->request->getParam('secret_token');
        $apps = $this->config->application->get('external_apps');
        foreach ($apps as $app) {
            if ('' === $app) continue;
            if ($secretToken !== $app) continue;
            $appName = explode(':', $app);
            $this->logger->debug(\implode(' ', [
                'External app', $appName[0],
                'authenticated; issuing access token for fileId', $fileId
            ]));
            try {
                $folder = $this->rootFolder->getUserFolder($this->userId);
                $item = $folder->getById($fileId)[0];
                Utils\Common::assert($item instanceof Node);
                list($urlSrc, $token) = $this->tokenManager->getToken($item->getId());
                return ['status' => 'success', 'urlsrc' => $urlSrc, 'token' => $token];
            }
            catch (\Throwable $e) { $this->logger->error('', ['exception' => $e]); }
        }
        return ['status' => 'error', 'message' => 'Permission denied'];
    }

    /**
     * @NoAdminRequired
     * @UseSession
     *
     * @param string $fileId
     * @param string|null $path
     * @return Http\RedirectResponse|Http\TemplateResponse
     */
    public function index($fileId, ?string $path = null) {
        try {
            $folder = $this->rootFolder->getUserFolder($this->userId);

            if (\is_null($path)) $item = $folder->getById($fileId)[0];
            else $item = $folder->get($path);

            Utils\Common::assert($item instanceof File);

            /**
             * Open file on source instance if it is originating from a federated share
             * The generated url will result in {@link remote()}
             */
            $federatedUrl = $this->federationService->getRemoteRedirectURL($item);
            if (! \is_null($federatedUrl)) {
                $response = new Http\RedirectResponse($federatedUrl);
                $response->addHeader('X-Frame-Options', 'ALLOW');
                return $response;
            }

            $templateFile = $this->templateManager->getTemplateSource($item->getId());
            if (\is_null($templateFile)) list($urlSrc, $token, $wopi) = $this->tokenManager->getToken($item->getId());
            else {
                list($urlSrc, $wopi) = $this->tokenManager->getTokenForTemplate(
                    $templateFile, $this->userId, $item->getId()
                );
                $token = $wopi->getToken();
            }

            $params = [
                'permissions' => $item->getPermissions(),
                'title' => $item->getName(),
                'fileId' => $item->getId() . '_' . $this->config->system->get('instanceid'),
                'token' => $token,
                'token_ttl' => $wopi->getExpiry(),
                'urlsrc' => $urlSrc,
                'path' => $folder->getRelativePath($item->getPath()),
            ];

            $targetData = $this->session->get(self::SESSION_FILE_TARGET);
            if ($targetData) {
                $this->session->remove(self::SESSION_FILE_TARGET);
                if ($targetData['fileId'] === $item->getId()) $params['target'] = $targetData['target'];
            }

            $encryptionManager = \OC::$server->getEncryptionManager();
            if ($encryptionManager->isEnabled()) {
                // Update the current file to be accessible with system public shared key
                $owner = $item->getOwner()->getUID();
                $absPath = '/' . $owner . '/' .  $item->getInternalPath();
                $accessList = \OC::$server->getEncryptionFilesHelper()->getAccessList($absPath);
                $accessList['public'] = true;
                $encryptionManager->getEncryptionModule()->update($absPath, $owner, $accessList);
            }

            return $this->documentTemplateResponse($wopi, $params);
        }

        catch (\Throwable $e) {
            $this->logger->error('', ['exception' => $e]);
            return $this->renderErrorPage('Failed to open the requested file.');
        }
    }

    /**
     * @NoAdminRequired
     *
     * Create a new file from a template
     *
     * @param int $templateId
     * @param string $fileName
     * @param string $dir
     * @return Http\TemplateResponse
     * @throws NotFoundException
     * @throws NotPermittedException
     * @throws \OCP\Files\InvalidPathException
     */
    public function createFromTemplate($templateId, $fileName, $dir) {
        if (! $this->templateManager->isTemplate($templateId)) return new Http\TemplateResponse(
            'core', '403', [], 'guest'
        );

        $userFolder = $this->rootFolder->getUserFolder($this->userId);
        try { $folder = $userFolder->get($dir); }
        catch (NotFoundException $e) {
            unset($e);
            return new Http\TemplateResponse('core', '403', [], 'guest');
        }

        if (! ($folder instanceof Folder)) return new Http\TemplateResponse(
            'core', '403', [], 'guest'
        );

        $file = $folder->newFile($fileName);

        $template = $this->templateManager->get($templateId);
        list($urlSrc, $wopi) = $this->tokenManager->getTokenForTemplate(
            $template, $this->userId, $file->getId()
        );

        return $this->documentTemplateResponse($wopi, [
            'permissions' => $template->getPermissions(),
            'title' => $fileName,
            'fileId' => $wopi->getFileid() . '_' . $this->config->system->get('instanceid'),
            'token' => $wopi->getToken(),
            'token_ttl' => $wopi->getExpiry(),
            'urlsrc' => $urlSrc,
            'path' => $userFolder->getRelativePath($file->getPath()),
        ]);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $shareToken
     * @param string $fileName
     * @return Http\TemplateResponse|Http\RedirectResponse
     * @throws \Exception
     */
    public function publicPage($shareToken, $fileName, $fileId) {
        try {
            $share = $this->shareManager->getShareByToken($shareToken);

            // not authenticated ?
            if ((function () use ($share) {
                if (! $share->getPassword()) return false;
                if (! $this->session->exists('public_link_authenticated')) return true;
                return (string)($share->getId()) !== $this->session->get('public_link_authenticated');
            }) ()) throw new \Exception('Invalid password');

            if (0 === (Constants::PERMISSION_READ & $share->getPermissions())) return new Http\TemplateResponse(
                'core', '403', [], 'guest'
            );

            $node = $share->getNode();
            if ($node instanceof Folder) $item = $node->getById($fileId)[0];
            else $item = $node;

            $federatedUrl = $this->federationService->getRemoteRedirectURL($item, null, $share);
            if (! \is_null($federatedUrl)) {
                $response = new Http\RedirectResponse($federatedUrl);
                $response->addHeader('X-Frame-Options', 'ALLOW');
                return $response;
            }

            if ($item instanceof Node) {
                $params = [
                    'permissions' => $share->getPermissions(),
                    'title' => $item->getName(),
                    'fileId' => $item->getId() . '_' . $this->config->system->get('instanceid'),
                    'path' => '/',
                    'isPublicShare' => true,
                ];

                $templateFile = $this->templateManager->getTemplateSource($item->getId());
                if (\is_null($templateFile)) list($urlSrc, $token, $wopi) = $this->tokenManager->getToken(
                    $item->getId(), $shareToken, $this->userId
                );
                else list($urlSrc, $wopi) = $this->tokenManager->getTokenForTemplate(
                    $templateFile, $share->getShareOwner(), $item->getId()
                );

                $params['token'] = $wopi->getToken();
                $params['token_ttl'] = $wopi->getExpiry();
                $params['urlsrc'] = $urlSrc;
                $params['hideCloseButton'] = $node instanceof File && $wopi->getHideDownload();

                return $this->documentTemplateResponse($wopi, $params);
            }
        }

        catch (\Throwable $e) {
            $this->logger->error('', ['exception' => $e]);
            return $this->renderErrorPage('Failed to open the requested file.');
        }

        return new Http\TemplateResponse('core', '403', [], 'guest');
    }

    /**
     * Open file on Source instance with token from Initiator instance
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $shareToken
     * @param $remoteServer
     * @param $remoteServerToken
     * @param null $filePath
     * @return Http\TemplateResponse
     */
    public function remote($shareToken, $remoteServer, $remoteServerToken, $filePath = null) {
        try {
            $share = $this->shareManager->getShareByToken($shareToken);

            // not authenticated ?
            if ((function () use ($share) {
                if (! $share->getPassword()) return false;
                if (! $this->session->exists('public_link_authenticated')) return true;
                return (string)($share->getId()) !== $this->session->get('public_link_authenticated');
            }) ()) throw new \Exception('Invalid password');

            if (0 === (Constants::PERMISSION_READ & $share->getPermissions())) return new Http\TemplateResponse(
                'core', '403', [], 'guest'
            );

            $node = $share->getNode();
            if (! \is_null($filePath)) $node = $node->get($filePath);

            if ($node instanceof Node) {
                list($urlSrc, $token, $wopi) = $this->tokenManager->getToken($node->getId(), $shareToken, $this->userId);

                $remoteWopi = $this->federationService->getRemoteFileDetails($remoteServer, $remoteServerToken);
                if (\is_null($remoteWopi)) throw new \Exception(
                    'Invalid remote file details for ' . $remoteServerToken
                );
                $this->tokenManager->upgradeToRemoteToken(
                    $wopi, $remoteWopi, $shareToken, $remoteServer, $remoteServerToken
                );

                $permissions = $share->getPermissions();
                if (! $remoteWopi->getCanwrite()) $permissions = (~Constants::PERMISSION_UPDATE) & $permissions;

                return $this->documentTemplateResponse($wopi, [
                    'permissions' => $permissions,
                    'title' => $node->getName(),
                    'fileId' => $node->getId() . '_' . $this->config->system->get('instanceid'),
                    'token' => $token,
                    'token_ttl' => $wopi->getExpiry(),
                    'urlsrc' => $urlSrc,
                    'path' => '/',
                    'userId' => $remoteWopi->getEditorUid() ? ($remoteWopi->getEditorUid() . '@' . $remoteServer) : null,
                ]);
            }
        }

        catch (ShareNotFound $e) {
            unset($e);
            return new Http\TemplateResponse('core', '404', [], 'guest');
        }

        catch (\Throwable $e) {
            $this->logger->error('', ['exception' => $e]);
            return $this->renderErrorPage('Failed to open the requested file.');
        }

        return new Http\TemplateResponse('core', '403', [], 'guest');
    }

    private function renderErrorPage(string $message, $status = Http::STATUS_INTERNAL_SERVER_ERROR) {
        $response = new Http\TemplateResponse(
            'core', 'error', ['errors' => [['error' => $message]]], 'guest'
        );
        $response->setStatus($status);
        return $response;
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @UseSession
     */
    public function editOnline(string $path = null, ?string $userId = null, ?string $target = null) {
        if (\is_null($path)) return $this->renderErrorPage('No path provided');

        if (\is_null($userId)) $userId = $this->userId;

        if (! (\is_null($userId) || ($userId === $this->userId))) return $this->renderErrorPage(
            'You are trying to open a file from another user account than the one you are currently logged in with.'
        );

        if (\is_null($userId)) return new Http\RedirectResponse(
            $this->urlGenerator->linkToRoute('core.login.showLoginForm', [
                'user' => $userId, 'redirect_url' => $this->request->getRequestUri(),
            ])
        );

        try {
            $file = $this->rootFolder->getUserFolder($userId)->get($path);
            if (! \is_null($target)) $this->session->set(self::SESSION_FILE_TARGET, [
                'fileId' => $file->getId(), 'target' => $target,
            ]);
            return new Http\RedirectResponse($this->urlGenerator->getAbsoluteURL(
                '/index.php/f/' . $file->getId())
            );
        }

        catch (NotFoundException $e) { unset($e); }
        catch (NotPermittedException $e) { unset($e); }
        catch (NoUserException $e) { unset($e); }

        return $this->renderErrorPage('File not found', Http::STATUS_NOT_FOUND);
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @UseSession
     */
    public function editOnlineTarget(int $fileId, ?string $target = null) {
        if (\is_null($this->userId)) return $this->renderErrorPage(
            'File not found', Http::STATUS_NOT_FOUND
        );

        try {
            $file = \array_shift($this->rootFolder->getUserFolder($this->userId)->getById($fileId));
            if (\is_null($file)) return $this->renderErrorPage(
                'File not found', Http::STATUS_NOT_FOUND
            );
            if (! \is_null($target)) $this->session->set(self::SESSION_FILE_TARGET, [
                'fileId' => $file->getId(), 'target' => $target
            ]);
            return new Http\RedirectResponse($this->urlGenerator->getAbsoluteURL(
                '/index.php/f/' . $file->getId()
            ));
        }

        catch (NotFoundException $e) { unset ($e); }
        catch (NotPermittedException $e) { unset ($e); }
        catch (NoUserException $e) { unset ($e); }

        return $this->renderErrorPage('File not found', Http::STATUS_NOT_FOUND);
    }
}
