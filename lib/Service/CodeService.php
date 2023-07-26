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

namespace OCA\Richdocuments\Service;

use \OCA\Richdocuments\Utils;

class CodeService {
    private ?array $_data = null;

    private readonly string $_application_id;


    public function __construct(
        string $appName,

        private readonly \OCP\IURLGenerator $_url_generator,
        private readonly \OCP\App\IAppManager $_application_manager,
        private readonly \OCP\Http\Client\IClientService $_client_service,

        private readonly \OCA\Richdocuments\Config\Application $_config,
        private readonly \OCA\Richdocuments\PermissionManager $_permission_manager,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $_endpoint_resolver,
        private readonly \OCA\Richdocuments\Utils\CodeDaemonLauncher $_daemon_launcher
    ) {
        $this->_application_id = $appName;
    }


    public function state(): array {
        $_state = ['initialized' => \is_array($this->_data)];
        if ($_state['initialized'] && (! empty($this->_data))) $_state['data'] = $this->_data;
        return $_state;
    }

    public function discovery(): ?string {
        return $this->_from_data(['code', 'discovery'], null);
    }

    public function capabilities(): ?array {
        return $this->_from_data(['code', 'capabilities'], null);
    }

    public function dispatch_request(
        string $uri, string $body, string $method, array $headers
    ): \OCP\AppFramework\Http\Response {
        $_url = (function () {
            if (true !== $this->_from_data(['enabled'], false)) return;
            if (true !== $this->_from_data(['self', 'enabled'], false)) return null;
            if (true !== $this->_from_data(['code', 'enabled'], false)) return null;
            return $this->_from_data(['code', 'daemon', 'url'], null);
        }) ();
        if (\is_null($_url)) return new \OCP\AppFramework\Http\JSONResponse([
            'reason' => 'service unavailable'
        ], \OCP\AppFramework\Http::STATUS_SERVICE_UNAVAILABLE);
        Utils\Common::assert(\is_string($_url));
        Utils\Common::assert('' !== $_url);
        Utils\Common::assert(\rtrim($_url, '/') === $_url);

        $uri = (function () use ($uri) {
            $_value = $uri;
            $_base = $this->_from_data(['code', 'endpoints', 'wopi', 'relative']);
            Utils\Common::assert(\is_string($_base));
            Utils\Common::assert(\str_starts_with($_base, '/'));
            Utils\Common::assert(\rtrim($_base, '/') === $_base);
            Utils\Common::assert(\str_starts_with($_value, $_base));
            $_value = \substr($_value, \strlen($_base));
            Utils\Common::assert(\is_string($_value));
            Utils\Common::assert(\str_starts_with($_value, '/'));
            return $_value;
        }) ();

        $headers = \iterator_to_array((function () use ($headers) {
            $_keys = [];
            foreach ($headers as $_key => $_value) {
                Utils\Common::assert(\is_string($_key));
                Utils\Common::assert(\is_string($_value));
                Utils\Common::assert('' !== $_key);
                Utils\Common::assert(! \in_array($_key, $_keys, true));
                \array_push($_keys, $_key);
                yield $_key => $_value;
            }
        }) ());

        $_client = (function () use ($method) {
            static $_mapping = null;
            if (\is_null($_mapping)) $_mapping = [
                'GET' => static fn (\OCP\Http\Client\IClient $client) => static fn (array $arguments) => $client->get(...$arguments),
                'POST' => static fn (\OCP\Http\Client\IClient $client) => static fn (array $arguments) => $client->post(...$arguments)
            ];

            if (! \array_key_exists($method, $_mapping)) return null;
            $_client = $_mapping[$method]($this->_client_service->newClient());
            return static function (mixed ...$arguments) use ($_client) {
                $_response = $_client($arguments);
                Utils\Common::assert($_response instanceof \OCP\Http\Client\IResponse);
                return $_response;
            };
        }) ();

        if (\is_null($_client)) return new \OCP\AppFramework\Http\JSONResponse([
            'reason' => 'method not allowed'
        ], \OCP\AppFramework\Http::STATUS_METHOD_NOT_ALLOWED);

        $_url = (function () use ($uri, $_url) {
            $_value = \parse_url('scheme://user:pass@host:42' . $uri);
            if (! \array_key_exists('scheme', $_value)) return null;
            if ('scheme' !== $_value['scheme']) return null;
            if (! \array_key_exists('user', $_value)) return null;
            if ('user' !== $_value['user']) return null;
            if (! \array_key_exists('pass', $_value)) return null;
            if ('pass' !== $_value['pass']) return null;
            if (! \array_key_exists('host', $_value)) return null;
            if ('host' !== $_value['host']) return null;
            if (! \array_key_exists('port', $_value)) return null;
            if (42 !== $_value['port']) return null;
            if (\array_key_exists('fragment', $_value)) return null;
            if (! \array_key_exists('path', $_value)) return null;
            $_base = \iterator_to_array((function () {
                $_external = $this->_url_generator->getBaseUrl();
                if (! \str_ends_with($_external, '/')) $_external = $_external . '/';
                $_loopback = $this->_endpoint_resolver->loopback();
                if (! \str_ends_with($_loopback, '/')) $_loopback = $_loopback . '/';
                $_external = \urlencode($_external);
                $_loopback = \urlencode($_loopback);
                yield 'external' => $_external;
                if ($_external === $_loopback) return;
                $_offset = \strlen($_external);
                yield 'replacer' => (static fn (string $value) => $_loopback . \substr($value, $_offset));
            }) ());
            $_path = (static function () use ($_value, $_base) {
                $_value = $_value['path'];
                if (! \str_starts_with($_value, '/')) return null;
                if (Utils\Common::normalize_path($_value) !== $_value) return null;
                static $_cool = null;
                if (\is_null($_cool)) $_cool = (static function () {
                    $_text = '/cool/';
                    return ['text' => $_text, 'size' => \strlen($_text)];
                }) ();
                if (\str_starts_with($_value, $_cool['text'])) {
                    $_value = \substr($_value, $_cool['size']);
                    if (! \str_starts_with($_value, $_base['external'])) return null;
                    if (\array_key_exists('replacer', $_base)) $_value = $_base['replacer']($_value);
                    $_value = $_cool['text'] . $_value;
                }
                return $_value;
            }) ();
            if (! \is_string($_path)) return null;
            if (\array_key_exists('query', $_value)) {
                $_query = $_value['query'];
                if ('' === $_query) return null;
                $_query = (function () use ($_query, $_base) {
                    $_collector = [];
                    foreach (\explode('&', $_query) as $_query) {
                        if ('' === $_query) return null;
                        $_query_value = \explode('=', $_query);
                        $_query_key = \array_shift($_query_value);
                        if ('wopisrc' === \strtolower($_query_key)) {
                            if (empty($_query_value)) return null;
                            if ('WOPISrc' !== $_query_key) return null;
                            $_query_value = \implode('=', $_query_value);
                            if (! \str_starts_with($_query_value, $_base['external'])) return null;
                            if (\array_key_exists('replacer', $_base)) $_query = \implode('=', [
                                $_query_key, $_base['replacer']($_query_value)
                            ]);
                        }
                        \array_push($_collector, $_query);
                    }
                    if (empty($_collector)) return '';
                    return '?' . \implode('&', $_collector);
                }) ();
                if (! \is_string($_query)) return null;
            } else $_query = '';
            return $_url . $_path . $_query;
        }) ();

        if (\is_null($_url)) return new \OCP\AppFramework\Http\JSONResponse([
            'reason' => 'invalid uri'
        ], \OCP\AppFramework\Http::STATUS_BAD_REQUEST);

        if (\array_key_exists('content-type', $headers)) {
            if (\array_key_exists(
                'Content-Type', $headers
            )) return new \OCP\AppFramework\Http\JSONResponse([
                'reason' => 'invalid headers'
            ], \OCP\AppFramework\Http::STATUS_BAD_REQUEST);
            $headers['Content-Type'] = $headers['content-type'];
            unset($headers['content-type']);
        }

        if (\array_key_exists('content-length', $headers)) {
            if (\array_key_exists(
                'Content-Length', $headers
            )) return new \OCP\AppFramework\Http\JSONResponse([
                'reason' => 'invalid headers'
            ], \OCP\AppFramework\Http::STATUS_BAD_REQUEST);
            $headers['Content-Length'] = $headers['content-length'];
            unset($headers['content-length']);
        }

        $headers['ProxyPrefix'] = $this->_from_data(['code', 'endpoints', 'wopi', 'absolute']);

        if (\array_key_exists(
            'Content-Length', $headers
        )) $headers['Content-Length'] = '' . \strlen($body);

        $_options = [
            'timeout' => \max(0, $this->_config->get('timeout', ['default' => 15])),
            'verify' => false, 'body' => $body, 'headers' => $headers,
            'http_errors' => false, 'nextcloud' => ['allow_local_address' => true]
        ];

        try { $_response = $_client($_url, $_options); }

        catch (\Throwable $exception) { return new \OCP\AppFramework\Http\JSONResponse([
            'reason' => $exception->getMessage()
        ], \OCP\AppFramework\Http::STATUS_BAD_GATEWAY); }

        return new Utils\ByPassResponse($_response);
    }

    public function initialize(): void {
        Utils\Common::assert(! \is_array($this->_data), 'initialized already');

        $this->_data = ['self' => ['application_id' => $this->_application_id]];

        $this->_data['enabled'] = (function () {
            $_value = $this->_config->get('code');
            Utils\Common::assert(\is_bool($_value));
            return $_value;
        }) ();

        $this->_data['self']['enabled'] = (function () {
            if (true !== $this->_application_manager->isEnabledForUser(
                $this->_from_data(['self', 'application_id'])
            )) return false;
            return true === $this->_permission_manager->isEnabledForUser();
        }) ();

        $this->_data['self']['pid'] = (function (): int {
            $_pid = \getmypid();
            Utils\Common::assert(\is_integer($_pid));
            Utils\Common::assert(0 < $_pid);
            return $_pid;
        }) ();

        if ((function () {
            $_application = Utils\CodeApplicationInfo::name();
            if (\is_null($_application)) return true;
            $this->_data['code'] = [
                'enabled' => $this->_application_manager->isEnabledForUser($_application),
                'application_id' => $_application
            ];
            return false;
        }) ()) return;

        if (! $this->_from_data(['enabled'])) return;
        if (! $this->_from_data(['code', 'enabled'])) return;

        $this->_data['code']['endpoints'] = \iterator_to_array((function() {
            $_prefix = $this->_from_data(['self', 'application_id']) . '.code.';
            foreach([['wopi', 'getWopi'], ['status', 'getStatus']] as list($_key, $_route)) {
                $_route = $_prefix . $_route;
                yield $_key => [
                    'relative' => $this->_url_generator->linkToRoute($_route),
                    'absolute' => $this->_url_generator->linkToRouteAbsolute($_route)
                ];
            };
        }) ());

        $this->_data['code']['endpoints']['legacy'] = (function () {
            $_relative = $this->_endpoint_resolver->legacy(true);
            $_absolute = $this->_endpoint_resolver->legacy(false);
            Utils\Common::assert(\is_string($_relative));
            Utils\Common::assert(\is_string($_absolute));
            return ['relative' => $_relative, 'absolute' => $_absolute];
        }) ();

        $this->_data['code']['daemon'] = ['executable' => (function(string $path) {
            $_prefix = Utils\Common::ensure_directory_path($path . '/collabora', [
                'gentle' => false, 'create' => false, 'resolve' => true,
                'may_root' => false, 'may_relative' => false
            ]) . '/';
            $path = Utils\Common::ensure_path($_prefix . 'Collabora_Online.AppImage', [
                'gentle' => false, 'resolve' => true,
                'may_root' => false, 'may_relative' => false
            ]);
            Utils\Common::assert(\str_starts_with($path, $_prefix));
            Utils\Common::assert(\is_file($path));
            Utils\Common::assert(\chmod($path, 0700));
            \clearstatcache(true, $path);
            Utils\Common::assert(\is_executable($path));
            return $path;
        }) ($this->_application_manager->getAppPath($this->_from_data(['code', 'application_id'])))];

        $_control_directory_path = (function () {
            $_make_final_path = (function(string $relative) {
                return function(string $base) use ($relative): ?string {
                    Utils\Common::assert($base === Utils\Common::ensure_directory_path($base, [
                        'gentle' => false, 'create' => false, 'resolve' => true,
                        'may_root' => false, 'may_relative' => false
                    ]));
                    return Utils\Common::ensure_directory_path($base . '/' . $relative, [
                        'gentle' => true, 'create' => true, 'resolve' => true,
                        'may_root' => false, 'may_relative' => false
                    ]);
                };
            }) (\sprintf('nextcloud/%s/collabora', $this->_data['self']['application_id']));

            $_base_path = Utils\Common::ensure_directory_path('/run', [
                'gentle' => true, 'create' => false, 'resolve' => true,
                'may_root' => false, 'may_relative' => false
            ]);

            if (\is_string($_base_path)) {
                $_posix_user_id = \posix_getuid();
                Utils\Common::assert(\is_integer($_posix_user_id) && (0 <= $_posix_user_id));
                $_path = Utils\Common::ensure_directory_path(\sprintf('%s/user/%u', $_base_path, $_posix_user_id), [
                    'gentle' => true, 'create' => false, 'resolve' => true,
                    'may_root' => false, 'may_relative' => false
                ]);
                if (\is_string($_path)) {
                    $_path = $_make_final_path($_path);
                    if (\is_string($_path)) return $_path;
                }

                $_path = Utils\Common::ensure_directory_path(\sprintf('%s/lock', $_base_path), [
                    'gentle' => true, 'create' => false, 'resolve' => true,
                    'may_root' => false, 'may_relative' => false
                ]);
                if (\is_string($_path)) {
                    $_path = $_make_final_path($_path);
                    if (\is_string($_path)) return $_path;
                }

                $_path = $_make_final_path($_base_path);
                if (\is_string($_path)) return $_path;
            }

            $_base_path = \ini_get('upload_tmp_dir');
            if (\is_string($_base_path)) {
                $_path = Utils\Common::ensure_directory_path($_base_path, [
                    'gentle' => true, 'create' => false, 'resolve' => true,
                    'may_root' => false, 'may_relative' => false
                ]);
                if (\is_string($_path)) {
                    $_path = $_make_final_path($_path);
                    if (\is_string($_path)) return $_path;
                }
            }

            $_path = Utils\Common::ensure_directory_path(\sys_get_temp_dir(), [
                'gentle' => false, 'create' => false, 'resolve' => true,
                'may_root' => false, 'may_relative' => false
            ]);
            $_path = $_make_final_path($_path);
            Utils\Common::assert(\is_string($_path));
            return $_path;
        }) ();

        Utils\Common::assert(\chmod($_control_directory_path, 0700));

        foreach(['pid', 'lock'] as $_key) $this->_data['code']['daemon'][$_key . '_file'] = (function () use ($_key, $_control_directory_path) {
            $_path = \sprintf('%s/coolwsd.%s', $_control_directory_path, $_key);
            if (\file_exists($_path)) {
                Utils\Common::assert(Utils\Common::resolve_path($_path) === $_path);
                Utils\Common::assert(\is_file($_path));
            }
            return $_path;
        }) ();

        (function () {
            $_pid = (function () {
                foreach (Utils\Common::with_lock_file(
                    $this->_from_data(['code', 'daemon', 'lock_file']),
                    $this->_from_data(['self', 'pid'])
                ) as $_lock) {
                    unset($_lock);
                    $_file = $this->_from_data(['code', 'daemon', 'pid_file']);
                    $_pid = (function () use ($_file) {
                        $_pid = Utils\Common::ensure_pid($_file, ['gentle' => true]);
                        if (\is_null($_pid)) return null;
                        $_path = Utils\Common::ensure_path('/proc/' . $_pid . '/exe', [
                            'gentle' => true, 'resolve' => true,
                            'may_root' => false, 'may_relative' => false
                        ]);
                        if (\is_null($_path)) return null;
                        if (! \is_file($_path)) return null;
                        if (! \is_executable($_path)) return null;
                        if ('coolwsd' !== \basename($_path)) return null;
                        return $_pid;
                    }) ();
                    if (\is_null($_pid)) $_pid = $this->_daemon_launcher->spawn(
                        $_file, $this->_from_data(['code', 'daemon', 'executable'])
                    );
                    Utils\Common::assert(\is_integer($_pid));
                    Utils\Common::assert(0 < $_pid);
                    return $_pid;
                }
            }) ();
            $_port = $this->_daemon_launcher->http_port();
            $_version = $this->_daemon_launcher->version_hash();
            $_url = 'http://localhost:' . $_port;
            $_client = (function () use ($_url) {
                $_headers = [
                    'User-Agent' => 'Nextcloud Server / ' . $this->_application_id,
                    'ProxyPrefix' => $this->_from_data(['code', 'endpoints', 'wopi', 'absolute'])
                ];
                $_options = [
                    'timeout' => \max(0, $this->_config->get('timeout', ['default' => 45])),
                    'verify' => false, 'http_errors' => false, 'headers' => $_headers,
                    'nextcloud' => ['allow_local_address' => true]
                ];
                $_client = $this->_client_service->newClient();
                Utils\Common::assert($_client instanceof \OCP\Http\Client\IClient);
                return static function (string $uri) use ($_client, $_url, $_options) {
                    $_response = $_client->get(\implode('/', [$_url, $uri]), $_options);
                    Utils\Common::assert($_response instanceof \OCP\Http\Client\IResponse);
                    $_response = $_response->getBody();
                    Utils\Common::assert(\is_string($_response));
                    Utils\Common::assert('' !== $_response);
                    return $_response;
                };
            }) ();
            $_capabilities = \json_decode($_client('hosting/capabilities'), true);
            Utils\Common::assert(\is_array($_capabilities));
            Utils\Common::assert(\array_key_exists('productVersionHash', $_capabilities));
            Utils\Common::assert($_version === $_capabilities['productVersionHash']);
            $_discovery = $_client('hosting/discovery');
            $this->_data['code']['daemon']['url'] = $_url;
            $this->_data['code']['daemon']['pid'] = $_pid;
            $this->_data['code']['daemon']['port'] = $_port;
            $this->_data['code']['discovery'] = $_discovery;
            $this->_data['code']['capabilities'] = $_capabilities;
        }) ();

        $this->_endpoint_resolver->code_service_handler([
            'internal' => $this->_from_data(['code', 'daemon', 'url']),
            'external' => $this->_from_data(['code', 'endpoints', 'wopi', 'absolute'])
        ]);
    }


    private function _from_data(mixed ...$arguments): mixed {
        return Utils\Common::get_from_tree(...(function () use ($arguments) {
            $_options = ['gentle' => false];
            Utils\Common::assert(! empty($arguments));
            $_current = \array_pop($arguments);
            if (empty($arguments)) $_path = $_current;
            else {
                $_options['default'] = $_current;
                $_path = \array_pop($arguments);
                Utils\Common::assert(empty($arguments));
            }
            Utils\Common::assert(\is_array($_path));
            Utils\Common::assert(\is_array($this->_data), 'service not initialized');
            return [$this->_data, $_path, $_options];
        }) ());
    }
}
