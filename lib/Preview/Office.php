<?php
/**
 * @copyright Copyright (c) 2018, Collabora Productivity.
 *
 * @author Tor Lillqvist <tml@collabora.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

declare(strict_types = 1);

namespace OCA\Richdocuments\Preview;

use \OCP\Files\FileInfo;
use \OCA\Richdocuments\Utils;

abstract class Office extends \OC\Preview\Provider {
    public function __construct(
        private readonly string $appName,
        private readonly \OCP\Http\Client\IClientService $clientService,
        private readonly \OCA\Richdocuments\Capabilities $capabilities,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $endpointResolver,
        private readonly \OCA\Richdocuments\Config\Application $config,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct();
    }

    private function getWopiURL() {
        $_url = $this->endpointResolver->internal();
        \OCA\Richdocuments\Utils\Common::assert(\is_string($_url));
        return $_url;
    }

    public function isAvailable(FileInfo $file) {
        return true === Utils\Common::get_from_tree(
            $this->capabilities->getCapabilities(),
            [$this->appName, 'collabora', 'convert-to', 'available'],
            ['gentle' => true, 'default' => false]
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getThumbnail($path, $maxX, $maxY, $scalingup, $fileview) {
        $fileInfo = $fileview->getFileInfo($path);
        if (! ($fileInfo instanceof FileInfo)) return false;
        if (0 === $fileInfo->getSize()) return false;

        if ($fileInfo->isEncrypted() || (! $fileInfo->getStorage()->isLocal())) {
            $fileName = $fileview->toTmpFile($path);
            $stream = \fopen($fileName, 'r');
        }

        else $stream = $fileview->fopen($path, 'r');

        $client = $this->clientService->newClient();
        $options = ['timeout' => 25, 'multipart' => [
            ['name' => $path, 'contents' => $stream]
        ]];

        if ($this->config->get('disable_certificate_verification')) $options['verify'] = false;

        try { $response = $client->post($this->getWopiURL(). '/lool/convert-to/png', $options); }
        catch (\Throwable $e) {
            $this->logger->info(
                'Failed to convert file to preview',
                ['exception' => $e]
            );
            return false;
        }

        $image = new \OCP\Image();
        $image->loadFromData($response->getBody());

        if ($image->valid()) {
            $image->scaleDownToFit($maxX, $maxY);
            return $image;
        }

        return false;
    }
}
