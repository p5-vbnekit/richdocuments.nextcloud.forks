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

namespace OCA\Richdocuments\Utils;

class RequestInfo {
    private ?object $_data = null;

    public function __construct(
        public readonly \OCP\IRequest $source,
        private readonly \Psr\Log\LoggerInterface $_logger
    ) {}

    public function summary(): object {
        if (\is_null($this->_data)) $this->_data = (object)[
            'uri' => (function () {
                $_value = $this->source->getRequestUri();
                Common::assert(\is_string($_value));
                return $_value;
            }) (),

            'get' => \iterator_to_array((static function () {
                foreach ($_GET as $_key => $_value) {
                    Common::assert(\is_string($_key));
                    Common::assert(\is_string($_value));
                    yield $_key => $_value;
                }
            }) ()),

            'post' => \iterator_to_array((static function () {
                foreach ($_POST as $_key => $_value) {
                    Common::assert(\is_string($_key));
                    Common::assert(\is_string($_value));
                    yield $_key => $_value;
                }
            }) ()),

            'body' => (static function () {
                $_value = Common::check_functions(
                    'http_get_request_body'
                ) ? \call_user_func('http_get_request_body') : null;
                if (! \is_string($_value)) $_value = \file_get_contents('php://input');
                return \is_string($_value) ? $_value : null;
            }) (),

            'files' => \iterator_to_array((function () {
                foreach ($_FILES as $_key => $_native) {
                    Common::assert(\is_string($_key));
                    Common::assert(\is_array($_native));
                    Common::assert(! empty($_native));
                    $_value = $this->source->getUploadedFile($_key);
                    Common::assert(\is_array($_value));
                    try { Common::assert(\array_keys($_native) === \array_keys($_value)); }
                    catch (\Throwable $exception) { $this->_logger->warning(
                        'cookie mismatch, key = ' . $_key, ['exception' => $exception]);
                    }
                    yield $_key => $_value;
                }
            }) ()),

            'method' => (function () {
                $value = $this->source->getMethod();
                Common::assert(\is_string($value));
                try { Common::assert(\in_array($value, [
                    'GET', 'PUT', 'HEAD', 'POST',
                    'TRACE', 'PATCH', 'DELETE',
                    'CONNECT', 'OPTIONS',
                ], true)); } catch (\Throwable $exception) { $this->_logger->warning(
                    'unknown method: ' . $value, ['exception' => $exception]);
                }
                return $value;
            }) (),

            'headers' => \iterator_to_array((function () {
                foreach (\getallheaders() as $_key => $_native) {
                    Common::assert(\is_string($_key));
                    Common::assert(\is_string($_native));
                    $_value = $this->source->getHeader($_key);
                    Common::assert(\is_string($_value));
                    try { Common::assert($_native === $_value); }
                    catch (\Throwable $exception) { $this->_logger->warning(
                        'cookie mismatch, key = ' . $_key, ['exception' => $exception]);
                    }
                    yield $_key => $_value;
                }
            }) ()),

            'cookies' => \iterator_to_array((function () {
                foreach ($_COOKIE as $_key => $_native) {
                    Common::assert(\is_string($_key));
                    Common::assert(\is_string($_native));
                    Common::assert(\is_string($_native));
                    $_value = $this->source->getCookie($_key);
                    Common::assert(\is_string($_value));
                    try { Common::assert($_native === $_value); }
                    catch (\Throwable $exception) { $this->_logger->warning(
                        'cookie mismatch, key = ' . $_key, ['exception' => $exception]);
                    }
                    yield $_key => $_value;
                }
            }) ())
        ];

        return $this->_data;
    }
}
