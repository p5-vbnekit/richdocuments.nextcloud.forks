<?php
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types = 1);

namespace OCA\Richdocuments\AppInfo;

use \OCP\Files\Template\TemplateFileCreator;

use \OCA\Richdocuments\WOPI;
use \OCA\Richdocuments\Service;

class Application extends \OCP\AppFramework\App
implements \OCP\AppFramework\Bootstrap\IBootstrap {
    public const APPNAME = 'richdocuments';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APPNAME, $urlParams);
    }

    public function register(
        \OCP\AppFramework\Bootstrap\IRegistrationContext $context
    ): void {
        $context->registerTemplateProvider(
            \OCA\Richdocuments\Template\CollaboraTemplateProvider::class
        );

        $context->registerCapability(\OCA\Richdocuments\Capabilities::class);

        $context->registerMiddleWare(\OCA\Richdocuments\Middleware\WOPIMiddleware::class);

        $context->registerEventListener(
            \OCP\Files\Template\FileCreatedFromTemplateEvent::class,
            \OCA\Richdocuments\Listener\FileCreatedFromTemplateListener::class
        );
        $context->registerEventListener(
            \OCP\Security\CSP\AddContentSecurityPolicyEvent::class,
            \OCA\Richdocuments\Listener\CSPListener::class
        );
        $context->registerEventListener(
            \OCA\Viewer\Event\LoadViewer::class,
            \OCA\Richdocuments\Listener\LoadViewerListener::class
        );
        $context->registerEventListener(
            \OCA\Files_Sharing\Event\ShareLinkAccessedEvent::class,
            \OCA\Richdocuments\Listener\ShareLinkListener::class
        );
        $context->registerEventListener(
            \OCP\Preview\BeforePreviewFetchedEvent::class,
            \OCA\Richdocuments\Listener\BeforeFetchPreviewListener::class
        );
        $context->registerEventListener(
            \OCP\Collaboration\Reference\RenderReferenceEvent::class,
            \OCA\Richdocuments\Listener\ReferenceListener::class
        );

        $context->registerReferenceProvider(
            \OCA\Richdocuments\Reference\OfficeTargetReferenceProvider::class
        );
    }

    public function boot(
        \OCP\AppFramework\Bootstrap\IBootContext $context
    ): void {
        $context->injectFn(static function (
            \OCP\IL10N $l10n,
            \OCP\Files\Template\ITemplateManager $templateManager,
            \OCA\Richdocuments\PermissionManager $permissionManager,
            WOPI\DiscoveryManager $discoveryManager,
            Service\CodeService $codeService,
            Service\CapabilitiesService $capabilitiesService,
            \OCA\Richdocuments\Config\Application $config,
            \Psr\Log\LoggerInterface $logger
        ) {
            if ($config->get('code')) {
                $discoveryManager->clear();
                $capabilitiesService->clear();
            }

            elseif ((static function () use ($config) {
                $_url = $config->get('wopi_url');
                return \is_string($_url) && WOPI\EndpointResolver::seems_like_legacy($_url);
            }) ()) {
                $config->remove('wopi_url');
                $logger->warning('Code service disabled, legacy wopi_url option (proxy.php?req=) was removed from config');
            }

            if (\in_array(
                'public_wopi_url', $config->keys(), true
            )) $config->remove('public_wopi_url');

            try { $codeService->initialize(); } catch (\Throwable $exception) { $logger->error(
                'failed to initialize ' . \get_class($codeService), ['exception' => $exception]
            ); }

            if ((! $permissionManager->isEnabledForUser()) || empty($capabilitiesService->getCapabilities())) return;

            $ooxml = 'ooxml' === $config->get('doc_format');
            $templateManager->registerTemplateFileCreator(static function () use ($l10n, $ooxml) {
                $odtType = new TemplateFileCreator(self::APPNAME, $l10n->t('New document'), ($ooxml ? '.docx' : '.odt'));
                if ($ooxml) {
                    $odtType->addMimetype('application/msword');
                    $odtType->addMimetype('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                } else {
                    $odtType->addMimetype('application/vnd.oasis.opendocument.text');
                    $odtType->addMimetype('application/vnd.oasis.opendocument.text-template');
                }
                $odtType->setIconClass('icon-filetype-document');
                $odtType->setRatio(21 / 29.7);
                return $odtType;
            });
            $templateManager->registerTemplateFileCreator(static function () use ($l10n, $ooxml) {
                $odsType = new TemplateFileCreator(self::APPNAME, $l10n->t('New spreadsheet'), ($ooxml ? '.xlsx' : '.ods'));
                if ($ooxml) {
                    $odsType->addMimetype('application/vnd.ms-excel');
                    $odsType->addMimetype('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                } else {
                    $odsType->addMimetype('application/vnd.oasis.opendocument.spreadsheet');
                    $odsType->addMimetype('application/vnd.oasis.opendocument.spreadsheet-template');
                }
                $odsType->setIconClass('icon-filetype-spreadsheet');
                $odsType->setRatio(16 / 9);
                return $odsType;
            });
            $templateManager->registerTemplateFileCreator(static function () use ($l10n, $ooxml) {
                $odpType = new TemplateFileCreator(self::APPNAME, $l10n->t('New presentation'), ($ooxml ? '.pptx' : '.odp'));
                if ($ooxml) {
                    $odpType->addMimetype('application/vnd.ms-powerpoint');
                    $odpType->addMimetype('application/vnd.openxmlformats-officedocument.presentationml.presentation');
                } else {
                    $odpType->addMimetype('application/vnd.oasis.opendocument.presentation');
                    $odpType->addMimetype('application/vnd.oasis.opendocument.presentation-template');
                }
                $odpType->setIconClass('icon-filetype-presentation');
                $odpType->setRatio(16 / 9);
                return $odpType;
            });

            if (! $capabilitiesService->hasDrawSupport()) return;

            $templateManager->registerTemplateFileCreator(static function () use ($l10n, $ooxml) {
                $odpType = new TemplateFileCreator(self::APPNAME, $l10n->t('New diagram'), '.odg');
                $odpType->addMimetype('application/vnd.oasis.opendocument.graphics');
                $odpType->addMimetype('application/vnd.oasis.opendocument.graphics-template');
                $odpType->setIconClass('icon-filetype-draw');
                $odpType->setRatio(1);
                return $odpType;
            });
        });

        (function () {
            $container = $this->getContainer();
            $previewManager = $container->get(\OCP\IPreview::class);

            foreach ([
                ['/application\/vnd.ms-excel/', \OCA\Richdocuments\Preview\MSExcel::class],
                ['/application\/msword/', \OCA\Richdocuments\Preview\MSWord::class],
                ['/application\/vnd.openxmlformats-officedocument.*/', \OCA\Richdocuments\Preview\OOXML::class],
                ['/application\/vnd.oasis.opendocument.*/', \OCA\Richdocuments\Preview\OpenDocument::class],
                ['/application\/pdf/', \OCA\Richdocuments\Preview\Pdf::class]
            ] as list($mime, $delegate)) $previewManager->registerProvider(
                $mime, static fn () => $container->get($delegate)
            );
        }) ();
    }
}
