<?php
/**
 * @copyright Copyright (c) 2023 Nikita Pushchin <vbnekit@gmail.com>
 *
 * @author Nikita Pushchin <vbnekit@gmail.com>
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

namespace OCA\Richdocuments\Controller;

use \OCA\Richdocuments\Utils;

class CodeController extends \OCP\AppFramework\Controller {
    private readonly string $_base_wopi_uri;

    public function __construct(
        string $appName,
        \OCP\IURLGenerator $_url_generator,
        private readonly \OCA\Richdocuments\Utils\RequestInfo $_request,
        private readonly \OCA\Richdocuments\Service\CodeService $_service
    ) {
        parent::__construct($appName, $_request->source);
        $this->_base_wopi_uri = (function () use($appName, $_url_generator) {
            $_value = $_url_generator->linkToRoute(
                \implode('.', [$appName, 'code', 'getWopi'])
            );
            Utils\Common::assert(\is_string($_value));
            Utils\Common::assert('' !== $_value);
            Utils\Common::assert(Utils\Common::normalize_path($_value) === $_value);
            return $_value;
        }) ();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getWopi(string $path = '') { return $this->_wopi($path); }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function postWopi(string $path = '') { return $this->_wopi($path); }

    /**
     * @NoCSRFRequired
     */
    public function getStatus() {
        $_state = $this->_service->state();
        Utils\Common::assert(\is_array($_state));
        return new \OCP\AppFramework\Http\JSONResponse($_state);
    }


    private function _wopi(string $path): \OCP\AppFramework\Http\Response {
        if (
            ('' === $path) || str_starts_with($path, '/')
        ) return new \OCP\AppFramework\Http\JSONResponse([
            'reason' => 'invalid path'
        ], \OCP\AppFramework\Http::STATUS_BAD_REQUEST);

        $path = \implode('/', [$this->_base_wopi_uri, $path]);

        $_request = $this->_request->summary();

        $_uri = (static function () use ($path, $_request) {
            $_value = $_request->uri;
            Utils\Common::assert(\is_string($_value));
            Utils\Common::assert(\str_starts_with($_value, '/'));
            $_url = \parse_url('scheme://user:pass@host:42' . $_value);
            Utils\Common::assert(\array_key_exists('scheme', $_url));
            Utils\Common::assert(\array_key_exists('user', $_url));
            Utils\Common::assert(\array_key_exists('pass', $_url));
            Utils\Common::assert(\array_key_exists('host', $_url));
            Utils\Common::assert(\array_key_exists('port', $_url));
            Utils\Common::assert(\array_key_exists('path', $_url));
            Utils\Common::assert('scheme' === $_url['scheme']);
            Utils\Common::assert('user' === $_url['user']);
            Utils\Common::assert('pass' === $_url['pass']);
            Utils\Common::assert('host' === $_url['host']);
            Utils\Common::assert(42 === $_url['port']);
            Utils\Common::assert($path === \urldecode($_url['path']));
            if (\array_key_exists('fragment', $_url)) return null;
            return $_value;
        }) ();

        if (\is_null($_uri)) return new \OCP\AppFramework\Http\JSONResponse([
            'reason' => 'invalid request uri'
        ], \OCP\AppFramework\Http::STATUS_BAD_REQUEST);
        Utils\Common::assert(\is_string($_uri));
        Utils\Common::assert('' !== $_uri);

        $_body = $_request->body;
        if (\is_null($_body)) return new \OCP\AppFramework\Http\JSONResponse([
            'reason' => 'failed to read request body'
        ], \OCP\AppFramework\Http::STATUS_NOT_IMPLEMENTED);
        Utils\Common::assert(\is_string($_body));

        $_method = $_request->method;
        Utils\Common::assert(\is_string($_method));
        Utils\Common::assert(\in_array($_method, ['GET', 'POST'], true));

        $_headers = $_request->headers;
        Utils\Common::assert(\is_array($_headers));

        return $this->_service->dispatch_request(
            $_uri, $_body, $_method, $_headers
        );
    }
}
