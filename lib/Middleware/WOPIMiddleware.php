<?php
/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 * @copyright Copyright (c) 2017 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types = 1);

namespace OCA\Richdocuments\Middleware;

use \OCP\Files\NotPermittedException;
use \OCP\AppFramework\Http;
use \OCA\Richdocuments\Controller\WopiController;

class WOPIMiddleware extends \OCP\AppFramework\Middleware {
    public function __construct(
        private readonly \OCP\IRequest $request,
        private readonly \OCA\Richdocuments\Db\WopiMapper $wopiMapper,
        private readonly \OCA\Richdocuments\Config\Application $config,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {}

    public function beforeController($controller, $methodName) {
        parent::beforeController($controller, $methodName);

        if (! ($controller instanceof WopiController)) return;
        if (! $this->isWOPIAllowed()) throw new NotPermittedException();

        try {
            [$fileId, ,] = \OCA\Richdocuments\Helper::parseFileId(
                $this->request->getParam('fileId')
            );
            $fileId = (int)$fileId;
            $wopi = $this->wopiMapper->getWopiForToken(
                $this->request->getParam('access_token')
            );
            if (! (
                ($fileId === $wopi->getFileid()) || ($fileId === $wopi->getTemplateId())
            )) throw new NotPermittedException();
        }

        catch (\Throwable $e) {
            $this->logger->error('Failed to validate WOPI access', [ 'exception' => $e ]);
            throw new NotPermittedException();
        }
    }

    public function afterException($controller, $methodName, \Exception $exception): Http\Response {
        if (! ($controller instanceof WopiController)) throw $exception;
        if (! ($exception instanceof NotPermittedException)) throw $exception;
        return new Http\JSONResponse([], Http::STATUS_FORBIDDEN);
    }

    public function isWOPIAllowed(): bool {
        $allowed = $this->config->get('wopi_allowlist');
        if (empty($allowed)) return true;

        $address = $this->request->getRemoteAddress();
        if (\Symfony\Component\HttpFoundation\IpUtils::checkIp(
            $address, $allowed
        )) return true;

        $this->logger->info(\implode(' ', [
            'WOPI request denied from', $address,
            'as it does not match the configured ranges:',
            implode(', ', $allowed)
        ]));

        return false;
    }
}
