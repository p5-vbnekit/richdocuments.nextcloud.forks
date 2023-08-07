<?php
/**
 * @copyright Copyright (c) 2016-2017 Lukas Reschke <lukas@statuscode.ch>
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

use \OCP\PreConditionNotMetException;
use \OCP\Lock\LockedException;
use \OCP\Files\File;
use \OCP\Files\Node;
use \OCP\Files\Folder;
use \OCP\Files\NotFoundException;
use \OCP\Files\GenericFileException;
use \OCP\Files\InvalidPathException;
use \OCP\Files\NotPermittedException;
use \OCP\AppFramework\Http;
use \OCP\Files\Lock\ILock;
use \OCP\Files\Lock\LockContext;
use \OCP\Files\Lock\NoLockProviderException;
use \OCP\Files\Lock\OwnerLockedException;
use \OCP\Share\Exceptions\ShareNotFound;
use \OCP\AppFramework\Db\DoesNotExistException;
use \OCA\Richdocuments\Helper;
use \OCA\Richdocuments\Db\Wopi;
use \OCA\Richdocuments\Events\DocumentOpenedEvent;
use \OCA\Richdocuments\Exceptions\ExpiredTokenException;
use \OCA\Richdocuments\Exceptions\UnknownTokenException;
use \Psr\Container\NotFoundExceptionInterface;
use \Psr\Container\ContainerExceptionInterface;

class WopiController extends \OCP\AppFramework\Controller {
    // Signifies LOOL that document has been changed externally in this storage
    public const LOOL_STATUS_DOC_CHANGED = 1010;

    public const WOPI_AVATAR_SIZE = 64;

    public function __construct(
        string $appName,
        \OCP\IRequest $request,
        private readonly \OCP\IUserManager $userManager,
        private readonly \OCP\IGroupManager $groupManager,
        private readonly \OCP\IURLGenerator $urlGenerator,
        private readonly \OCP\Share\IManager $shareManager,
        private readonly \OCP\Files\IRootFolder $rootFolder,
        private readonly \OCP\Encryption\IManager $encryptionManager,
        private readonly \OCP\EventDispatcher\IEventDispatcher $eventDispatcher,
        private readonly \OCP\Files\Lock\ILockManager $lockManager,
        private readonly \OCA\Richdocuments\TokenManager $tokenManager,
        private readonly \OCA\Richdocuments\TemplateManager $templateManager,
        private readonly \OCA\Richdocuments\PermissionManager $permissionManager,
        private readonly \OCA\Richdocuments\Db\WopiMapper $wopiMapper,
        private readonly \OCA\Richdocuments\Config\Collector $config,
        private readonly \OCA\Richdocuments\Service\UserScopeService $userScopeService,
        private readonly \OCA\Richdocuments\Service\FederationService $federationService,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Returns general info about a file.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @param string $fileId
     * @param string $access_token
     * @return Http\JSONResponse
     * @throws InvalidPathException
     * @throws NotFoundException
     */
    public function checkFileInfo($fileId, $access_token) {
        try {
            list($fileId, , $version) = Helper::parseFileId($fileId);
            $wopi = $this->wopiMapper->getWopiForToken($access_token);
            if ($wopi->isTemplateToken()) {
                $this->templateManager->setUserId($wopi->getOwnerUid());
                $file = $this->templateManager->get($wopi->getFileid());
            } else $file = $this->getFileForWopiToken($wopi);
            if (! ($file instanceof File)) throw new NotFoundException('No valid file found for ' . $fileId);
        }

        catch (NotFoundException $exception) {
            $this->logger->debug('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        } catch (UnknownTokenException $exception) {
            $this->logger->debug('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        } catch (ExpiredTokenException $exception) {
            $this->logger->debug('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_UNAUTHORIZED);
        } catch (\Throwable $exception) {
            $this->logger->error('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        $isPublic = empty($wopi->getEditorUid());
        $guestUserId = 'Guest-' . \OC::$server->getSecureRandom()->generate(8);
        $user = $this->userManager->get($wopi->getEditorUid());
        $userDisplayName = $user !== null && !$isPublic ? $user->getDisplayName() : $wopi->getGuestDisplayname();
        $isVersion = $version !== '0';
        $response = [
            'BaseFileName' => $file->getName(),
            'Size' => $file->getSize(),
            'Version' => $version,
            'UserId' => !$isPublic ? $wopi->getEditorUid() : $guestUserId,
            'OwnerId' => $wopi->getOwnerUid(),
            'UserFriendlyName' => $userDisplayName,
            'UserExtraInfo' => [],
            'UserPrivateInfo' => [],
            'UserCanWrite' => (bool)$wopi->getCanwrite(),
            'UserCanNotWriteRelative' => $isPublic || $this->encryptionManager->isEnabled() || $wopi->getHideDownload(),
            'PostMessageOrigin' => $wopi->getServerHost(),
            'LastModifiedTime' => Helper::toISO8601($file->getMTime()),
            'SupportsRename' => !$isVersion,
            'UserCanRename' => !$isPublic && !$isVersion,
            'EnableInsertRemoteImage' => !$isPublic,
            'EnableShare' => $file->isShareable() && !$isVersion && !$isPublic,
            'HideUserList' => '',
            'DisablePrint' => $wopi->getHideDownload(),
            'DisableExport' => $wopi->getHideDownload(),
            'DisableCopy' => $wopi->getHideDownload(),
            'HideExportOption' => $wopi->getHideDownload(),
            'HidePrintOption' => $wopi->getHideDownload(),
            'DownloadAsPostMessage' => $wopi->getDirect(),
            'SupportsLocks' => $this->lockManager->isLockProviderAvailable(),
            'IsUserLocked' => $this->permissionManager->userIsFeatureLocked($wopi->getEditorUid()),
            'EnableRemoteLinkPicker' => (bool)$wopi->getCanwrite() && !$isPublic && !$wopi->getDirect(),
        ];

        $enableZotero = $this->config->application->get('zoteroEnabled');
        if (!$isPublic && $enableZotero) {
            $zoteroAPIKey = $this->config->user->get('zoteroAPIKey');
            $response['UserPrivateInfo']['ZoteroAPIKey'] = $zoteroAPIKey;
        }
        if ($wopi->hasTemplateId()) {
            $templateUrl = 'index.php/apps/richdocuments/wopi/template/' . $wopi->getTemplateId() . '?access_token=' . $wopi->getToken();
            $templateUrl = $this->urlGenerator->getAbsoluteURL($templateUrl);
            $response['TemplateSource'] = $templateUrl;
        } elseif ($wopi->isTemplateToken()) {
            // FIXME: Remove backward compatibility layer once TemplateSource is available in all supported Collabora versions
            $userFolder = $this->rootFolder->getUserFolder($wopi->getOwnerUid());
            $file = $userFolder->getById($wopi->getTemplateDestination())[0];
            $response['TemplateSaveAs'] = $file->getName();
        }

        $share = $this->getShareForWopiToken($wopi);
        if ($this->permissionManager->shouldWatermark($file, $wopi->getEditorUid(), $share)) {
            $email = $user !== null && !$isPublic ? $user->getEMailAddress() : "";
            $replacements = [
                'userId' => $wopi->getEditorUid(),
                'date' => (new \DateTime())->format('Y-m-d H:i:s'),
                'themingName' => \OC::$server->getThemingDefaults()->getName(),
                'userDisplayName' => $userDisplayName,
                'email' => $email,
            ];
            $watermarkTemplate = $this->config->application->get('watermark_text');
            $response['WatermarkText'] = preg_replace_callback('/{(.+?)}/', function ($matches) use ($replacements) {
                return $replacements[$matches[1]];
            }, $watermarkTemplate);
        }

        $user = $this->userManager->get($wopi->getEditorUid());
        if ($user !== null) {
            $response['UserExtraInfo']['avatar'] = $this->urlGenerator->linkToRouteAbsolute('core.avatar.getAvatar', ['userId' => $wopi->getEditorUid(), 'size' => self::WOPI_AVATAR_SIZE]);
            if ($this->groupManager->isAdmin($wopi->getEditorUid())) {
                $response['UserExtraInfo']['is_admin'] = true;
            }
        } else {
            $response['UserExtraInfo']['avatar'] = $this->urlGenerator->linkToRouteAbsolute('core.GuestAvatar.getAvatar', ['guestName' => urlencode($wopi->getGuestDisplayname()), 'size' => self::WOPI_AVATAR_SIZE]);
        }

        if ($isPublic) $response['UserExtraInfo']['is_guest'] = true;

        if ($wopi->isRemoteToken()) $response = $this->setFederationFileInfo($wopi, $response);

        $response = \array_merge($response, $this->config->application->get('wopi_override'));

        $this->eventDispatcher->dispatchTyped(new DocumentOpenedEvent(
            $user ? $user->getUID() : null, $file
        ));

        return new Http\JSONResponse($response);
    }


    private function setFederationFileInfo(Wopi $wopi, $response) {
        $response['UserId'] = 'Guest-' . \OC::$server->getSecureRandom()->generate(8);

        if ($wopi->getTokenType() === Wopi::TOKEN_TYPE_REMOTE_USER) {
            $remoteUserId = $wopi->getGuestDisplayname();
            $cloudID = \OC::$server->getCloudIdManager()->resolveCloudId($remoteUserId);
            $response['UserId'] = $cloudID->getDisplayId();
            $response['UserFriendlyName'] = $cloudID->getDisplayId();
            $response['UserExtraInfo']['avatar'] = $this->urlGenerator->linkToRouteAbsolute('core.avatar.getAvatar', ['userId' => explode('@', $remoteUserId)[0], 'size' => self::WOPI_AVATAR_SIZE]);
            $cleanCloudId = str_replace(['http://', 'https://'], '', $cloudID->getId());
            $addressBookEntries = \OC::$server->getContactsManager()->search($cleanCloudId, ['CLOUD']);
            foreach ($addressBookEntries as $entry) {
                if (isset($entry['CLOUD'])) {
                    foreach ($entry['CLOUD'] as $cloudID) {
                        if ($cloudID === $cleanCloudId) {
                            $response['UserFriendlyName'] = $entry['FN'];
                            break;
                        }
                    }
                }
            }
        }

        $initiator = $this->federationService->getRemoteFileDetails($wopi->getRemoteServer(), $wopi->getRemoteServerToken());
        if ($initiator === null) {
            return $response;
        }

        $response['UserFriendlyName'] = $this->tokenManager->prepareGuestName($initiator->getGuestDisplayname());
        if ($initiator->hasTemplateId()) {
            $templateUrl = $wopi->getRemoteServer() . '/index.php/apps/richdocuments/wopi/template/' . $initiator->getTemplateId() . '?access_token=' . $initiator->getToken();
            $response['TemplateSource'] = $templateUrl;
        }
        if ($wopi->getTokenType() === Wopi::TOKEN_TYPE_REMOTE_USER || ($wopi->getTokenType() === Wopi::TOKEN_TYPE_REMOTE_GUEST && $initiator->getEditorUid())) {
            $response['UserExtraInfo']['avatar'] = $wopi->getRemoteServer() . '/index.php/avatar/' . $initiator->getEditorUid() . '/'. self::WOPI_AVATAR_SIZE;
        }

        return $response;
    }

    /**
     * Given an access token and a fileId, returns the contents of the file.
     * Expects a valid token in access_token parameter.
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $fileId
     * @param string $access_token
     * @return Http\Response
     * @throws NotFoundException
     * @throws NotPermittedException
     * @throws LockedException
     */
    public function getFile($fileId,
        $access_token) {
        list($fileId, , $version) = Helper::parseFileId($fileId);

        try { $wopi = $this->wopiMapper->getWopiForToken($access_token); }
        catch (UnknownTokenException $exception) {
            $this->logger->debug('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        } catch (ExpiredTokenException $exception) {
            $this->logger->debug('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_UNAUTHORIZED);
        } catch (\Throwable $exception) {
            $this->logger->error('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        if ((int)$fileId !== $wopi->getFileid()) return new Http\JSONResponse(
            [], Http::STATUS_FORBIDDEN
        );

        // Template is just returned as there is no version logic
        if ($wopi->isTemplateToken()) {
            $this->templateManager->setUserId($wopi->getOwnerUid());
            $file = $this->templateManager->get($wopi->getFileid());
            $response = new Http\StreamResponse($file->fopen('rb'));
            $response->addHeader('Content-Disposition', 'attachment');
            $response->addHeader('Content-Type', 'application/octet-stream');
            return $response;
        }

        try {
            /** @var File $file */
            $file = $this->getFileForWopiToken($wopi);
            \OC_User::setIncognitoMode(true);
            if ('0' !== $version) $file = \OC::$server->get(
                \OCA\Files_Versions\Versions\IVersionManager::class
            )->getVersionFile($this->rootFolder->getUserFolder(
                $wopi->getOwnerUid())->getOwner(), $file, $version
            );
            if (0 === $file->getSize()) $response = new Http\Response();
            else $response = new Http\StreamResponse($file->fopen('rb'));
            $response->addHeader('Content-Disposition', 'attachment');
            $response->addHeader('Content-Type', 'application/octet-stream');
        }

        catch (NotFoundExceptionInterface|ContainerExceptionInterface $exception) {
            $this->logger->error(
                'Version manager could not be found when trying to restore file. Versioning app disabled?',
                ['exception' => $exception]
            );
            $response = new Http\JSONResponse([], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $exception) {
            $this->logger->error('getFile failed', ['exception' => $exception]);
            $response = new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        return $response;
    }

    /**
     * Given an access token and a fileId, replaces the files with the request body.
     * Expects a valid token in access_token parameter.
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $fileId
     * @param string $access_token
     * @return Http\JSONResponse
     */
    public function putFile($fileId,
        $access_token) {
        list($fileId, , ) = Helper::parseFileId($fileId);
        $isPutRelative = ($this->request->getHeader('X-WOPI-Override') === 'PUT_RELATIVE');

        try { $wopi = $this->wopiMapper->getWopiForToken($access_token); }
        catch (UnknownTokenException $exception) {
            $this->logger->debug('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        } catch (ExpiredTokenException $exception) {
            $this->logger->debug('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_UNAUTHORIZED);
        } catch (\Throwable $exception) {
            $this->logger->error('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        if (! $wopi->getCanwrite()) return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);

        if ((! $this->encryptionManager->isEnabled()) || $this->isMasterKeyEnabled()) {
            // Set the user to register the change under his name
            $this->userScopeService->setUserScope($wopi->getUserForFileAccess());
            $this->userScopeService->setFilesystemScope($isPutRelative ? $wopi->getEditorUid() : $wopi->getUserForFileAccess());
        } else {
            // Per-user encryption is enabled so that collabora isn't able to store the file by using the
            // user's private key. Because of that we have to use the incognito mode for writing the file.
            \OC_User::setIncognitoMode(true);
        }

        try {
            if ($isPutRelative) {
                // the new file needs to be installed in the current user dir
                $userFolder = $this->rootFolder->getUserFolder($wopi->getEditorUid());
                $file = $userFolder->getById($fileId);
                if (empty($file)) return new Http\JSONResponse([], Http::STATUS_NOT_FOUND);
                $file = $file[0];

                $suggested = \mb_convert_encoding($this->request->getHeader(
                    'X-WOPI-SuggestedTarget'
                ), 'utf-8', 'utf-7');

                if (\str_starts_with($suggested, '.')) $path = \dirname($file->getPath()) . '/New File' . $suggested;
                elseif (! \str_starts_with($suggested, '/')) $path = \dirname($file->getPath()) . '/' . $suggested;
                else $path = $userFolder->getPath() . $suggested;

                if ('' === $path) return new Http\JSONResponse([
                    'status' => 'error', 'message' => 'Cannot create the file'
                ], Http::STATUS_INTERNAL_SERVER_ERROR);

                (function () use ($path) {
                    // create the folder first
                    $directory = \dirname($path);
                    if (! $this->rootFolder->nodeExists($directory)) $this->rootFolder->newFolder($directory);
                }) ();

                // create a unique new file
                $path = $this->rootFolder->getNonExistingName($path);
                $this->rootFolder->newFile($path);
                $file = $this->rootFolder->get($path);
            }

            else {
                $file = $this->getFileForWopiToken($wopi);
                $headerTime = $this->request->getHeader('X-LOOL-WOPI-Timestamp');
                if (! empty($headerTime)) {
                    $storageTime = Helper::toISO8601($file->getMTime() ?? 0);
                    if ($headerTime !== $storageTime) {
                        $this->logger->debug(\implode(' ', [
                            'Document timestamp mismatch ! WOPI client says mtime' . $headerTime,
                            'but storage says' . $storageTime
                        ]));
                        // Tell WOPI client about this conflict.
                        return new Http\JSONResponse([
                            'LOOLStatusCode' => self::LOOL_STATUS_DOC_CHANGED
                        ], Http::STATUS_CONFLICT);
                    }
                }
            }

            $content = \fopen('php://input', 'rb');

            try { $this->wrappedFilesystemOperation(
                $wopi, static function () use ($file, $content) { return $file->putContent($content); }
            ); }

            catch (LockedException $exception) {
                $this->logger->error('', ['exception' => $exception]);
                return new Http\JSONResponse(['message' => 'File locked'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            if ($isPutRelative) {
                // generate a token for the new file (the user still has to be logged in)
                list(, $wopiToken) = $this->tokenManager->getToken((string)$file->getId(), null, $wopi->getEditorUid(), $wopi->getDirect());
                $wopi = 'index.php/apps/richdocuments/wopi/files/' . $file->getId() . '_' . $this->config->system->get('instanceid') . '?access_token=' . $wopiToken;
                $url = $this->urlGenerator->getAbsoluteURL($wopi);
                return new Http\JSONResponse([ 'Name' => $file->getName(), 'Url' => $url ], Http::STATUS_OK);
            }

            if ($wopi->hasTemplateId()) {
                $wopi->setTemplateId(null);
                $this->wopiMapper->update($wopi);
            }

            $response = new Http\JSONResponse(['LastModifiedTime' => Helper::toISO8601($file->getMTime() ?? 0)]);
        }

        catch (NotFoundException $exception) {
            $this->logger->info('File not found', ['exception' => $exception]);
            $response = new Http\JSONResponse([], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $exception) {
            $this->logger->error('getFile failed', ['exception' => $exception]);
            $response = new Http\JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return $response;
    }

    /**
     * Given an access token and a fileId, replaces the files with the request body.
     * Expects a valid token in access_token parameter.
     * Just actually routes to the PutFile, the implementation of PutFile
     * handles both saving and saving as.* Given an access token and a fileId, replaces the files with the request body.
     *
     * FIXME Cleanup this code as is a lot of shared logic between putFile and putRelativeFile
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $fileId
     * @param string $access_token
     * @return Http\JSONResponse
     * @throws DoesNotExistException
     */
    public function postFile(string $fileId, string $access_token): Http\JSONResponse {
        try {
            $wopiOverride = $this->request->getHeader('X-WOPI-Override');
            $wopiLock = $this->request->getHeader('X-WOPI-Lock');
            list($fileId, , ) = Helper::parseFileId($fileId);
            $wopi = $this->wopiMapper->getWopiForToken($access_token);
            if ((int) $fileId !== $wopi->getFileid()) return new Http\JSONResponse(
                [], Http::STATUS_FORBIDDEN
            );
        } catch (UnknownTokenException $exception) {
            $this->logger->debug('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        } catch (ExpiredTokenException $exception) {
            $this->logger->debug('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_UNAUTHORIZED);
        } catch (\Throwable $exception) {
            $this->logger->error('', ['exception' => $exception]);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        switch ($wopiOverride) {
            case 'LOCK': return $this->lock($wopi, $wopiLock);
            case 'UNLOCK': return $this->unlock($wopi, $wopiLock);
            case 'REFRESH_LOCK': return $this->refreshLock($wopi, $wopiLock);
            case 'GET_LOCK': return $this->getLock($wopi, $wopiLock);
            case 'RENAME_FILE': break; //FIXME: Move to function
            default: break; //FIXME: Move to function and add error for unsupported method
        }

        $isRenameFile = ($this->request->getHeader('X-WOPI-Override') === 'RENAME_FILE');

        if (! $wopi->getCanwrite()) return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);

        // Unless the editor is empty (public link) we modify the files as the current editor
        $editor = $wopi->getEditorUid();
        if (\is_null($editor) && (! $wopi->isRemoteToken())) $editor = $wopi->getOwnerUid();

        try {
            // the new file needs to be installed in the current user dir
            $userFolder = $this->rootFolder->getUserFolder($editor);

            if ($wopi->isTemplateToken()) {
                $this->templateManager->setUserId($wopi->getOwnerUid());
                $file = $userFolder->getById($wopi->getTemplateDestination())[0];
            } elseif ($isRenameFile) {
                // the new file needs to be installed in the current user dir
                $file = $this->getFileForWopiToken($wopi);

                $suggested = \mb_convert_encoding(
                    $this->request->getHeader('X-WOPI-RequestedName'),
                    'utf-8', 'utf-7'
                ) . '.' . $file->getExtension();

                if (\str_starts_with($suggested, '.')) $path = \dirname($file->getPath()) . '/New File' . $suggested;
                elseif (! \str_starts_with($suggested, '/')) $path = \dirname($file->getPath()) . '/' . $suggested;
                else $path = $userFolder->getPath() . $suggested;

                if ('' === $path) return new Http\JSONResponse([
                    'status' => 'error', 'message' => 'Cannot rename the file'
                ], Http::STATUS_INTERNAL_SERVER_ERROR);

                (function () use ($path) {
                    // create the folder first
                    $directory = \dirname($path);
                    if (! $this->rootFolder->nodeExists($directory)) $this->rootFolder->newFolder($directory);
                }) ();

                // create a unique new file
                $path = $this->rootFolder->getNonExistingName($path);
                $file = $file->move($path);
            }

            else {
                $file = $this->getFileForWopiToken($wopi);
                $suggested = mb_convert_encoding($this->request->getHeader(
                    'X-WOPI-SuggestedTarget'
                ), 'utf-8', 'utf-7');

                if (\str_starts_with($suggested, '.')) $path = \dirname($file->getPath()) . '/New File' . $suggested;
                elseif (! \str_starts_with($suggested, '/')) $path = \dirname($file->getPath()) . '/' . $suggested;
                else $path = $userFolder->getPath() . $suggested;

                if ('' === $path) return new Http\JSONResponse([
                    'status' => 'error', 'message' => 'Cannot create the file'
                ], Http::STATUS_INTERNAL_SERVER_ERROR);

                (function () use ($path) {
                    // create the folder first
                    $directory = \dirname($path);
                    if (! $this->rootFolder->nodeExists($directory)) $this->rootFolder->newFolder($directory);
                }) ();

                // create a unique new file
                $path = $this->rootFolder->getNonExistingName($path);
                $file = $this->rootFolder->newFile($path);
            }

            $content = \fopen('php://input', 'rb');
            // Set the user to register the change under his name
            $this->userScopeService->setUserScope($wopi->getEditorUid());
            $this->userScopeService->setFilesystemScope($wopi->getEditorUid());

            try { $this->wrappedFilesystemOperation($wopi, static function () use ($file, $content) {
                return $file->putContent($content);
            }); }

            catch (LockedException $exception) {
                unset($exception);
                return new Http\JSONResponse(
                    ['message' => 'File locked'],
                    Http::STATUS_INTERNAL_SERVER_ERROR
                );
            }

            // epub is exception (can be uploaded but not opened so don't try to get access token)
            if ('application/epub+zip' === $file->getMimeType()) return new Http\JSONResponse(
                [ 'Name' => $file->getName() ], Http::STATUS_OK
            );

            // generate a token for the new file (the user still has to be
            // logged in)
            list(, $wopiToken) = $this->tokenManager->getToken(
                (string)$file->getId(), null,
                $wopi->getEditorUid(), $wopi->getDirect()
            );

            $response = new Http\JSONResponse([
                'Name' => $file->getName(),
                'Url' => $this->urlGenerator->getAbsoluteURL(\implode('', [
                    'index.php/apps/richdocuments/wopi/files/',
                    $file->getId() . '_' . $this->config->system->get('instanceid'),
                    '?access_token=' . $wopiToken
                ]))
            ], Http::STATUS_OK);
        }

        catch (NotFoundException $exception) {
            $this->logger->info('File not found', ['exception' => $exception]);
            $response = new Http\JSONResponse([], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $exception) {
            $this->logger->error('putRelativeFile failed', ['exception' => $exception]);
            $response = new Http\JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return $response;
    }

    private function lock(Wopi $wopi, string $lock): Http\JSONResponse {
        try { $this->lockManager->lock(new LockContext(
            $this->getFileForWopiToken($wopi),
            ILock::TYPE_APP, $this->appName
        )); }

        catch (NoLockProviderException|PreConditionNotMetException $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_BAD_REQUEST);
        } catch (OwnerLockedException $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_LOCKED);
        } catch (\Throwable $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new Http\JSONResponse();
    }

    private function unlock(Wopi $wopi, string $lock): Http\JSONResponse {
        try { $this->lockManager->unlock(new LockContext(
            $this->getFileForWopiToken($wopi),
            ILock::TYPE_APP, $this->appName
        )); }

        catch (NoLockProviderException|PreConditionNotMetException $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new Http\JSONResponse();
    }

    private function refreshLock(Wopi $wopi, string $lock): Http\JSONResponse {
        try { $this->lockManager->lock(new LockContext(
            $this->getFileForWopiToken($wopi),
            ILock::TYPE_APP, $this->appName
        )); }

        catch (NoLockProviderException|PreConditionNotMetException $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_BAD_REQUEST);
        } catch (OwnerLockedException $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_LOCKED);
        } catch (\Throwable $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new Http\JSONResponse();
    }

    private function getLock(Wopi $wopi, string $lock): Http\JSONResponse {
        $this->lockManager->getLocks($wopi->getFileid());
        return new Http\JSONResponse();
    }

    /**
     * @throws NotFoundException
     * @throws GenericFileException
     * @throws LockedException
     * @throws ShareNotFound
     */
    protected function wrappedFilesystemOperation(Wopi $wopi, callable $filesystemOperation): void {
        $retry = function () use ($filesystemOperation) {
            $this->retryOperation($filesystemOperation);
        };

        try { $this->lockManager->runInScope(new LockContext(
            $this->getFileForWopiToken($wopi),
            ILock::TYPE_APP, $this->appName
        ), $retry); }

        catch (NoLockProviderException $exception) {
            unset($exception);
            $retry();
        }
    }

    /**
     * Retry operation if a LockedException occurred
     * Other exceptions will still be thrown
     * @param callable $operation
     * @throws LockedException
     * @throws GenericFileException
     */
    private function retryOperation(callable $operation) {
        for ($i = 0; $i < 5; $i++) try { if (false !== $operation()) return; }
        catch (LockedException $e) { if (4 > $i) \usleep(500000); else throw $e; }
        throw new GenericFileException('Operation failed after multiple retries');
    }

    /**
     * @param Wopi $wopi
     * @return File|Folder|Node|null
     * @throws NotFoundException
     * @throws ShareNotFound
     */
    private function getFileForWopiToken(Wopi $wopi) {
        $share = $wopi->getShare();

        if (! empty($share)) {
            $share = $this->shareManager->getShareByToken($share)->getNode();
            if ($share instanceof File) return $share;
            return \array_shift($share->getById($wopi->getFileid()));
        }

        // Group folders requires an active user to be set in order to apply the proper acl permissions as for anonymous requests it requires share permissions for read access
        // https://github.com/nextcloud/groupfolders/blob/e281b1e4514cf7ef4fb2513fb8d8e433b1727eb6/lib/Mount/MountProvider.php#L169
        $this->userScopeService->setUserScope($wopi->getEditorUid());
        // Unless the editor is empty (public link) we modify the files as the current editor
        // TODO: add related share token to the wopi table so we can obtain the
        $files = $this->rootFolder->getUserFolder(
            $wopi->getUserForFileAccess()
        )->getById($wopi->getFileid());

        if ((! \is_array($files)) || empty($files)) throw new NotFoundException(
            'No valid file found for wopi token'
        );

        // Workaround to always open files with edit permissions if multiple occurrences of
        // the same file id are in the user home, ideally we should also track the path of the file when opening
        \usort($files, static fn (Node $a, Node $b) => (
            \OCP\Constants::PERMISSION_UPDATE & $b->getPermissions()
        ) <=> (
            \OCP\Constants::PERMISSION_UPDATE & $a->getPermissions()
        ));

        return \array_shift($files);
    }

    private function getShareForWopiToken(Wopi $wopi): ?\OCP\Share\IShare {
        try {
            $share = $wopi->getShare();
            if ($share) return $this->shareManager->getShareByToken($share);
        }

        catch (ShareNotFound $exception) { unset($exception); }

        return null;
    }

    /**
     * Endpoint to return the template file that is requested by collabora to create a new document
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param $fileId
     * @param $access_token
     * @return Http\JSONResponse|Http\StreamResponse
     */
    public function getTemplate($fileId, $access_token) {
        try { $wopi = $this->wopiMapper->getPathForToken($access_token); }

        catch (UnknownTokenException $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
        } catch (ExpiredTokenException $exception) {
            unset($exception);
            return new Http\JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }

        if ((int)$fileId !== $wopi->getTemplateId()) return new Http\JSONResponse(
            [], Http::STATUS_FORBIDDEN
        );

        try {
            $this->templateManager->setUserId($wopi->getOwnerUid());
            $file = $this->templateManager->get($wopi->getTemplateId());
            $response = new Http\StreamResponse($file->fopen('rb'));
            $response->addHeader('Content-Disposition', 'attachment');
            $response->addHeader('Content-Type', 'application/octet-stream');
        }

        catch (\Throwable $exception) {
            $this->logger->error('getTemplate failed', ['exception' => $exception]);
            $response = new Http\JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return $response;
    }

    /**
     * Check if the encryption module uses a master key.
     */
    private function isMasterKeyEnabled(): bool {
        try { return \OC::$server->get(\OCA\Encryption\Util::class)->isMasterKeyEnabled(); }
        catch (\Throwable $e) { unset($e); }
        return false;
    }
}
