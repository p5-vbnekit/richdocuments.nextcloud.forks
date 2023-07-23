<?php
/**
 * @copyright Copyright (c) 2023 Nikita Pushchin <vbnekit@gmail.com>
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

declare(strict_types=1);

namespace OCA\Richdocuments\WOPI;

class UrlMagic {
    private string $application;
    private \OCP\IConfig $config;
    private \OCP\IURLGenerator $url_generator;
    private \OCP\App\IAppManager $application_manager;

    private array $data;

    public function __construct(
        string $appName,
        \OCP\IConfig $config,
        \OCP\IURLGenerator $url_generator,
        \OCP\App\IAppManager $application_manager
    ) {
        $this->application = $appName;
        $this->config = $config;
        $this->url_generator = $url_generator;
        $this->application_manager = $application_manager;
    }

    public function internal(): string {
        $this->update();
        return $this->data['results']['internal'];
    }

    public function external(): string {
        $this->update();
        return $this->data['results']['external'];
    }

    public function code_proxy(): string {
        $this->update();
        return $this->data['results']['code_proxy'];
    }

    public function is_code_proxy(): bool {
        $code_proxy = $this->code_proxy();
        return is_string($code_proxy) && ('' !== $code_proxy);
    }

    private function update(): void {
        if ((function (UrlMagic &$context) {
            $actual = array (
                'cli' => $this->config->getSystemValueString('overwrite.cli.url', ''),
                'wopi' => $this->config->getAppValue($this->application, 'wopi_url', '')
            );
            if (! is_string($actual['cli'])) $actual['cli'] = '';
            if (! is_string($actual['wopi'])) $actual['wopi'] = '';
            if ((! empty($context->data)) && ($actual === $this->data['config'])) return true;
            $context->data['config'] = $actual;
            return false;
        }) ($this)) return;

        $results = &$this->data['results'];
        $results = array('internal' => '', 'external' => '', 'code_proxy' => '');

        $config = $this->data['config'];
        $legacy = $config['wopi'];

        $legacy = (function(string $url): ?array {
            if ('' === $url) return null;
            $parsed = parse_url($url);
            if (! is_array($parsed)) return null;
            if (array_key_exists('scheme', $parsed)) {
                $scheme = $parsed['scheme'];
                if (! is_string($scheme)) return null;
                if (! in_array($scheme, array('http', 'https'))) return null;
            }
            if (array_key_exists('user', $parsed)) {
                $user = $parsed['user'];
                if (! is_string($user)) return null;
                if ('' === $user) return null;
            }
            if (array_key_exists('pass', $parsed)) {
                $pass = $parsed['pass'];
                if (! is_string($pass)) return null;
                if ('' === $pass) return null;
            }
            if (! array_key_exists('host', $parsed)) return null;
            $host = $parsed['host'];
            if (! is_string($host)) return null;
            if ('' === $host) return null;
            if (array_key_exists('port', $parsed)) {
                $port = $parsed['port'];
                if (! is_integer($port)) return null;
                if (! ((0 < $port) && (65536 > $port))) return null;
            }
            if (array_key_exists('path', $parsed)) {
                $path = $parsed['path'];
                if (! is_string($path)) return null;
                if (! str_starts_with($parsed['path'], '/')) return null;
            }
            if (array_key_exists('query', $parsed)) {
                $query = $parsed['query'];
                if (! is_string($query)) return null;
                if ('' === $query) return null;
            }
            if (array_key_exists('fragment', $parsed)) {
                $fragment = $parsed['fragment'];
                if (! is_string($fragment)) return null;
                if ('' === $fragment) return null;
            }
            return $parsed;
        })($config['wopi']);

        if (! is_array($legacy)) $legacy = array();
        else {
            if ((function (?array $url) {
                if (empty($url)) return false;
                if (! array_key_exists('scheme', $url)) return false;
                if (! array_key_exists('path', $url)) return true;
                if (! str_ends_with($url['path'], '/proxy.php')) return true;
                return false;
            }) ($legacy)) {
                $results['interlal'] = $results['external'] = $config['wopi'];
                return;
            }

            if (array_key_exists('fragment', $legacy)) return;
            if (! str_starts_with($legacy['path'], '/')) return;
            if (! array_key_exists('query', $legacy)) return;
            if ('req=' !== $legacy['query']) return;
        }

        if (! array_key_exists('code_proxy', $this->data)) $this->data['code_proxy'] = (function (UrlMagic $context) {
            // Supported only on Linux OS, and x86_64 & ARM64 platforms
            if (! in_array(php_uname('m'), array('x86_64', 'aarch64'))) return null;
            if ('Linux' !== (\PHP_VERSION_ID >= 70200 ? \PHP_OS_FAMILY : \PHP_OS)) return null;
            $application = (php_uname('m') === 'x86_64') ? 'richdocumentscode' : 'richdocumentscode_arm64';
            if (! $context->application_manager->isEnabledForUser($application)) return null;
            $generator = $context->url_generator;
            $relative = rtrim($generator->linkTo($application, ''), '/') . '/proxy.php';
            $absolute = $generator->getAbsoluteURL($relative);
            return array('absolute' => $absolute, 'relative' => $relative);
        }) ($this);

        if (null === $this->data['code_proxy']) return;

        $cli = $config['cli'];
        if ('' === $cli) {
            if (! array_key_exists('SERVER_ADDR', $_SERVER)) return;
            $legacy['host'] = $_SERVER('SERVER_ADDR');
            if (! is_string($legacy['host'])) return;
            if ('' === $legacy['host']) return;
            if (array_key_exists('SERVER_PORT', $_SERVER)) {
                $legacy['port'] = $_SERVER('SERVER_PORT');
                if (! is_string($legacy['port'])) return;
                if ('' === $legacy['port']) unset($legacy['port']);
                else {
                    $port = intval($legacy['port']);
                    if (! ((0 < $port) && (65536 > $port))) return;
                }
            } else unset($legacy['port']);
            if (array_key_exists('HTTPS', $_SERVER) && ('' !== $_SERVER['HTTPS'])) $legacy['scheme'] = 'https';
            else $legacy['scheme'] = 'http';
            $legacy['path'] = '';
        }

        else {
            $cli = parse_url($cli);
            if (! is_array($cli)) return;
            if (array_key_exists('query', $cli)) return;
            if (array_key_exists('fragment', $cli)) return;
            if (! array_key_exists('host', $cli)) return;
            $legacy['host'] = $cli['host'];
            if (! is_string($legacy['host'])) return;
            if ('' === $legacy['host']) return;
            if (array_key_exists('path', $cli)) {
                $legacy['path'] = $cli['path'];
                if (! is_string($legacy['path'])) return;
                if (! str_starts_with($legacy['path'], '/')) return;
            } else $legacy['path'] = '';
            if (array_key_exists('scheme', $cli)) {
                $legacy['scheme'] = $cli['scheme'];
                if (! is_string($legacy['scheme'])) return;
                if (! in_array($legacy['scheme'], array('http', 'https'))) return;
            } else $legacy['scheme'] = 'http';
            if (array_key_exists('port', $cli)) {
                $legacy['port'] = $cli['port'];
                if (! is_integer($legacy['port'])) return;
                if (! ((0 < $legacy['port']) && (65536 > $legacy['port']))) return;
            } else unset($legacy['port']);
            if (array_key_exists('user', $cli)) {
                $legacy['user'] = $cli['user'];
                if (! is_string($legacy['user'])) return;
                if ('' === $legacy['user']) return;
            } else unset($legacy['user']);
            if (array_key_exists('pass', $cli)) {
                $legacy['pass'] = $cli['pass'];
                if (! is_string($legacy['pass'])) return;
                if ('' === $legacy['pass']) return;
            } else unset($legacy['pass']);
        }

        $code_proxy = $legacy['scheme'] . "://";

        if (array_key_exists('user', $legacy)) {
            $code_proxy = $code_proxy . $legacy['user'];
            if (array_key_exists('pass', $legacy)) $code_proxy = $code_proxy . ':' . $legacy['pass'];
            $code_proxy = $code_proxy . "@";
        }

        $code_proxy = $code_proxy . $legacy['host'];
        if (array_key_exists('port', $legacy)) $code_proxy = $code_proxy . ":" . $legacy['port'];
        $code_proxy = $code_proxy . rtrim($legacy['path'], '/') . '/' . trim($this->data['code_proxy']['relative'], '/');

        $results['internal'] = $code_proxy . '?req=';
        $results['external'] = rtrim($this->data['code_proxy']['absolute']) . '?req=';
        $results['code_proxy'] = $code_proxy;
    }
}
