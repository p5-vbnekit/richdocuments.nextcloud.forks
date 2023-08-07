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

declare(strict_types = 1);

namespace OCA\Richdocuments\Controller;

use \OCP\PreConditionNotMetException;
use \OCP\Files\NotFoundException;
use \OCP\Files\NotPermittedException;
use \OCP\AppFramework\Http;
use \OCA\Richdocuments\UploadException;

class SettingsController extends \OCP\AppFramework\Controller {
    // TODO adapt overview generation if we add more font mimetypes
    public const FONT_MIME_TYPES = [
        'font/ttf',
        'application/font-sfnt',
        'font/sfnt',
        'font/opentype',
        'application/vnd.oasis.opendocument.formula-template',
    ];

    public function __construct(
        string $appName,
        \OCP\IRequest $request,
        private readonly ?string $userId,
        private readonly \OCP\IL10N $l10n,
        private readonly \OCA\Richdocuments\WOPI\Parser $wopiParser,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $wopiEndpointResolver,
        private readonly \OCA\Richdocuments\WOPI\DiscoveryManager $wopiDiscoveryManager,
        private readonly \OCA\Richdocuments\Config\Collector $config,
        private readonly \OCA\Richdocuments\Service\DemoService $demoService,
        private readonly \OCA\Richdocuments\Service\FontService $fontService,
        private readonly \OCA\Richdocuments\Service\CapabilitiesService $capabilitiesService,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @throws \Exception
     */
    public function checkSettings() {
        try {
            $this->wopiDiscoveryManager->clear();
            $this->wopiDiscoveryManager->get();
        } catch (\Throwable $e) {
            $this->logger->error('', ['exception' => $e]);
            return new Http\DataResponse([
                'status' => $e->getCode(),
                'message' => 'Could not fetch discovery details'
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new Http\DataResponse();
    }

    public function demoServers() {
        $demoServers = $this->demoService->fetchDemoServers(true);
        if (count($demoServers) > 0) {
            return new Http\DataResponse($demoServers);
        }
        return new Http\NotFoundResponse([]);
    }

    /**
     * @NoAdminRequired
     *
     * @return Http\JSONResponse
     */
    public function getSettings() { return new Http\JSONResponse([
        'code' => $this->config->application->get('code') ? 'yes' : 'no',
        'wopi_url' => $this->config->application->get('wopi_url'),
        'wopi_allowlist' => \implode(', ', $this->config->application->get('wopi_allowlist')),
        'disable_certificate_verification' => $this->config->application->get('disable_certificate_verification') ? 'yes' : 'no',
        'edit_groups' => \implode('|', $this->config->application->get('edit_groups')),
        'use_groups' => \implode('|', $this->config->application->get('use_groups')),
        'doc_format' => $this->config->application->get('doc_format')
    ]); }

    /**
     * @param bool $code
     * @param string $wopi_url
     * @param string $disable_certificate_verification
     * @param string $edit_groups
     * @param string $use_groups
     * @param string $doc_format
     * @param string $external_apps
     * @param string $canonical_webroot
     * @return Http\JSONResponse
     */
    public function setSettings(
        $code,
        $wopi_url,
        $wopi_allowlist,
        $disable_certificate_verification,
        $edit_groups,
        $use_groups,
        $doc_format,
        $external_apps,
        $canonical_webroot
    ) {
        $message = $this->l10n->t('Saved');

        if (\in_array(
            'public_wopi_url', $this->config->application->keys(), true
        )) $this->config->application->remove('public_wopi_url');


        if (! \is_null($wopi_url)) {
            if (\OCA\Richdocuments\WOPI\EndpointResolver::seems_like_legacy($wopi_url)) { $code = 'yes'; $wopi_url = null; }
            elseif (\is_null($code)) $this->config->application->set('wopi_url', $wopi_url);
        }

        if (! \is_null($code)) {
            if ('yes' === $code) {
                $wopi_url = $this->wopiEndpointResolver->legacy(false);
                $this->config->application->set('code', true);
                $this->config->application->set('wopi_url', $wopi_url);
            }

            else $this->config->application->set('code', false);

            if (\is_null($wopi_url)) $this->config->application->remove('wopi_url');
        }

        if (! \is_null($wopi_allowlist)) $this->config->application->set(
            'wopi_allowlist', \iterator_to_array((function () use ($wopi_allowlist) {
                foreach (\preg_split('/[\s,;\|]/', $wopi_allowlist) as $_item) {
                    $_item = \trim($_item);
                    if ('' !== $_item) yield $_item;
                }
            }) ())
        );

        if (! \is_null($disable_certificate_verification)) $this->config->application->set(
            'disable_certificate_verification', 'yes' === $disable_certificate_verification
        );

        if (! \is_null($edit_groups)) $this->config->application->set(
            'edit_groups', \explode('|', $edit_groups)
        );

        if (! \is_null($use_groups)) $this->config->application->set(
            'use_groups', \explode('|', $use_groups)
        );

        if (! \is_null($doc_format)) $this->config->application->set(
            'doc_format', $doc_format
        );

        if (! \is_null($external_apps)) $this->config->application->set(
            'external_apps', \iterator_to_array((function () use ($external_apps) {
                foreach (\explode(',', $external_apps) as $_item) {
                    $_item = \trim($_item);
                    if ('' !== $_item) yield $_item;
                }
            }) ())
        );

        if (! \is_null($canonical_webroot)) $this->config->application->set(
            'canonical_webroot', $canonical_webroot
        );

        $this->capabilitiesService->clear();
        $this->wopiDiscoveryManager->clear();

        try { $this->wopiParser->getUrlSrc('Capabilities'); }
        catch (\Exception $e) { if (! \is_null($wopi_url)) return new Http\JSONResponse([
            'status' => 'error',
            'data' => ['message' => 'Failed to connect to the remote server']
        ], 500); }

        $this->capabilitiesService->clear();
        $this->capabilitiesService->refetch();

        if (empty($this->capabilitiesService->getCapabilities())) return new Http\JSONResponse([
            'status' => 'error',
            'data' => ['message' => 'Failed to connect to the remote server', 'hint' => 'missing_capabilities']
        ], 500);

        return new Http\JSONResponse([
            'status' => 'success', 'data' => ['message' => $message]
        ]);
    }

    public function updateWatermarkSettings($settings = []) {
        try {
            $settings = \iterator_to_array((function () use ($settings) {
                foreach ($settings['watermark'] as $key => $value) {
                    if (! \is_string($key)) {
                        try { $key = $key . '(not string): ' . $key; }
                        catch (\Throwable $exception) {
                            unset($e);
                            if (! \is_string($exception)) $key = ': not string';
                        }
                        throw new \InvalidArgumentException($this->l10n->t('Invalid config key') . ' ' . $key);
                    }

                    if ('' === $key) throw new \InvalidArgumentException(
                        $this->l10n->t('Invalid config key') . ': emtpy string'
                    );

                    $key = 'watermark_' . $key;
                    $specification = $this->config->application->specification('watermark_' . $key);
                    if (! \array_key_exists('default', $specification)) throw new \InvalidArgumentException(
                        $this->l10n->t('Invalid config key') . '(unknown) : ' . $_key
                    );
                    if (! \is_string($value)) throw new \InvalidArgumentException(
                        $this->l10n->t('Invalid config value') . '(string expected): ' . \json_encode(['key' => $key, 'value' => $value])
                    );

                    $specification = $specification['default'];

                    if (\is_string($specification)) {
                        yield $key => $value;
                        continue;
                    }

                    if (\is_array($specification)) {
                        yield $key => \explode(',', $value);
                        continue;
                    }

                    if (\is_bool($specification)) {
                        if ('' === $value) $value = $specification;
                        elseif ('yes' === $value) $value = true;
                        elseif ('no' === $value) $value = false;
                        else throw new \InvalidArgumentException(
                            $this->l10n->t('Invalid config value') . '(not "yes", "no", or ""): ' . \json_encode(['key' => $key, 'value' => $value])
                        );
                        yield $key => $value;
                        continue;
                    }

                    yield $key => '' . $value;
                }
            }) ());

            foreach ($settings as $key => $value) $this->config->application->set($key, $value);
        }

        catch (\Throwable $exception) {
            $exception = $exception->getMessage();
            if (! \is_string($exception)) $exception = '';
            return new Http\JSONResponse(
                ['status' => 'error', 'data' => ['message' => $exception]],
                Http::STATUS_BAD_REQUEST
            );
        }

        return new Http\JSONResponse([
            'status' => 'success', 'data' => ['message' => $this->l10n->t('Saved')]
        ]);
    }

    /**
     * @NoAdminRequired
     *
     * @param $key
     * @param $value
     * @return Http\JSONResponse
     */
    public function setPersonalSettings(
        $templateFolder, $zoteroAPIKeyInput
    ) {
        $message = $this->l10n->t('Saved');
        $status = 'success';

        if (! \is_null($templateFolder)) {
            try { $this->config->user->set('templateFolder', $templateFolder); }
            catch (PreConditionNotMetException $e) {
                unset($e);
                $status = 'error';
                $message = $this->l10n->t('Error when saving');
            }
        }

        if (! \is_null($zoteroAPIKeyInput)) {
            try { $this->config->user->set('zoteroAPIKey', $zoteroAPIKeyInput); }
            catch (PreConditionNotMetException $e) {
                unset($e);
                $status = 'error';
                $message = $this->l10n->t('Error when saving');
            }
        }

        return new Http\JSONResponse([
            'status' => $status, 'data' => ['message' => $message]
        ]);
    }

    /**
     * @NoAdminRequired
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return Http\JSONResponse|Http\DataResponse
     * @throws \OCP\Files\NotPermittedException
     */
    public function getFontNames() {
        $fileNames = $this->fontService->getFontFileNames();
        $etag = \md5(\implode('/', $fileNames));
        $ifNoneMatchHeader = $this->request->getHeader('If-None-Match');
        if ($ifNoneMatchHeader && (
            $ifNoneMatchHeader === $etag
        )) return new Http\DataResponse([], HTTP::STATUS_NOT_MODIFIED);
        $response = new Http\JSONResponse($fileNames);
        $response->addHeader('Etag', $etag);
        return $response;
    }

    /**
     * @NoAdminRequired
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return Http\JSONResponse|Http\DataResponse
     * @throws \OCP\Files\NotPermittedException
     */
    public function getJsonFontList() {
        $files = $this->fontService->getFontFiles();
        $etags = \array_map(
            static fn (\OCP\Files\SimpleFS\ISimpleFile $f) => $f->getETag(), $files
        );
        $etag = \md5(implode(',', $etags));
        $ifNoneMatchHeader = $this->request->getHeader('If-None-Match');
        if ($ifNoneMatchHeader && (
            $ifNoneMatchHeader === $etag
        )) return new Http\DataResponse([], HTTP::STATUS_NOT_MODIFIED);
        $fontList = $this->fontService->getFontList($files);
        $response = new Http\JSONResponse($fontList);
        $response->addHeader('Etag', $etag);
        return $response;
    }

    /**
     * @NoAdminRequired
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $name
     * @return Http\DataDisplayResponse|Http\DataResponse
     * @throws \OCP\Files\NotPermittedException
     */
    public function getFontFile(string $name) {
        try {
            $file = $this->fontService->getFontFile($name);
            $etag = $file->getETag();
            $ifNoneMatchHeader = $this->request->getHeader('If-None-Match');
            $response = $ifNoneMatchHeader && (
                $ifNoneMatchHeader === $etag
            ) ? new Http\DataResponse(
                [], HTTP::STATUS_NOT_MODIFIED
            ) : new Http\DataDisplayResponse(
                $file->getContent(), Http::STATUS_OK,
                ['Content-Type' => $file->getMimeType(), 'Etag' => $etag]
            );
        }

        catch (NotFoundException $exception) {
            unset($exception);
            $response = new Http\DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }

        return $response;
    }

    /**
     * @NoAdminRequired
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $name
     * @return Http\DataDisplayResponse
     * @throws \OCP\Files\NotPermittedException
     */
    public function getFontFileOverview(string $name): Http\DataDisplayResponse {
        try { $response = new Http\DataDisplayResponse(
            $this->fontService->getFontFileOverview($name),
            Http::STATUS_OK, ['Content-Type' => 'image/png']
        ); }

        catch (NotFoundException $exception) {
            unset($exception);
            $response = new Http\DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }

        return $response;
    }

    /**
     * @param string $name
     * @return Http\DataResponse
     * @throws NotFoundException
     * @throws \OCP\Files\NotPermittedException
     */
    public function deleteFontFile(string $name): Http\DataResponse {
        $this->fontService->deleteFontFile($name);
        return new Http\DataResponse();
    }

    /**
     * @return Http\JSONResponse
     */
    public function uploadFontFile(): Http\JSONResponse {
        try {
            $file = $this->getUploadedFile('fontfile');
            if (isset($file['tmp_name'], $file['name'], $file['type'])) {
                $type = $file['type'];
                if (\function_exists('mime_content_type')) $type = @mime_content_type($file['tmp_name']);
                if (! $type) $type = $file['type'];
                if (! \in_array($type, self::FONT_MIME_TYPES, true)) return new Http\JSONResponse(
                    ['error' => 'Font type not supported: ' . $type], Http::STATUS_BAD_REQUEST
                );
                $resource = \fopen($file['tmp_name'], 'rb');
                if (! \is_resource($resource)) throw new UploadException('Could not read file');
                try { $file = $this->fontService->uploadFontFile($file['name'], $resource); }
                finally { if (\is_resource($resource)) \fclose($resource); }
                return new Http\JSONResponse($file);
            }
        }

        catch (UploadException | NotPermittedException $exception) {
            $this->logger->error('Upload error', ['exception' => $exception]);
            return new Http\JSONResponse(['error' => 'Upload error'], Http::STATUS_BAD_REQUEST);
        }

        return new Http\JSONResponse(['error' => 'No uploaded file'], Http::STATUS_BAD_REQUEST);
    }

    /**
     * @param string $key
     * @return array
     * @throws UploadException
     */
    private function getUploadedFile(string $key): array {
        $file = $this->request->getUploadedFile($key);

        if (empty($file)) throw new UploadException($this->l10n->t(
            'No file uploaded or file size exceeds maximum of %s',
            [\OCP\Util::humanFileSize(\OCP\Util::uploadLimit())]
        ));

        if (\array_key_exists('error', $file)) {
            $file = $file['error'];
            if (UPLOAD_ERR_OK !== $file) {
                static $errors = null;
                if (\is_null($errors)) $errors = [
                    UPLOAD_ERR_OK => 'The file was uploaded',
                    UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
                    UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
                    UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Could not write file to disk',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
                ];
                throw new UploadException(\array_key_exists(
                    $file, $errors
                ) ? $this->l10n->t($errors[$file]) : '');
            }
        }

        return $file;
    }
}
