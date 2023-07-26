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

namespace OCA\Richdocuments\WOPI;

use \OCA\Richdocuments\Utils;

class EndpointResolver {
    private ?array $_code_data = null;

    public function __construct(
        private readonly \OCP\IURLGenerator $_generator,
        private readonly \OCA\Richdocuments\Config\Collector $_config
    ) {}

    public function legacy(bool $relative): ?string {
        $_relative = \OCA\Richdocuments\Utils\CodeApplicationInfo::name();
        if (\is_null($_relative)) return null;
        $_relative = $this->_generator->linkTo($_relative, '');
        if ('' === $_relative) return null;
        $_relative = \trim($_relative, '/');
        if ('' === $_relative) return null;
        $_relative = $_relative . '/proxy.php?req=';
        if ($relative) return '/' . $_relative;
        $_root = $this->loopback();
        return \implode(
            \str_ends_with($_root, '/') ? '' : '/',
            [$_root, $_relative]
        );
    }

    public function loopback(): string {
        $_value = $this->_config->application->get('loopback_url');
        if ('' === $_value) {
            $_value = $this->_config->system->get('overwrite.cli.url');
            if ('' === $_value) $_value = $this->_generator->getBaseUrl();
        }
        Utils\Common::assert(\is_string($_value));
        Utils\Common::assert('' !== $_value);
        $_parts = \parse_url($_value);
        Utils\Common::assert(\is_array($_parts));
        if (! \array_key_exists('scheme', $_parts)) {
            $_value = 'http://' . $_value;
            $_parts = \parse_url($_value);
            Utils\Common::assert(\is_array($_parts));
            Utils\Common::assert(\array_key_exists('scheme', $_parts));
        }
        Utils\Common::assert(\in_array($_parts['scheme'], ['http', 'https'], true));
        Utils\Common::assert(\array_key_exists('host', $_parts));
        Utils\Common::assert(! \array_key_exists('query', $_parts));
        Utils\Common::assert(! \array_key_exists('fragment', $_parts));
        return $_value;
    }

    public function internal(?string $default = null): ?string { return $this->_resolve('internal', $default); }

    public function external(?string $default = null): ?string { return $this->_resolve('external', $default); }

    public function code_service_handler(array $data) {
        $_collector = ['internal' => null, 'external' => null];
        foreach ($data as $_key => $_value) {
            Utils\Common::assert(\is_string($_key));
            Utils\Common::assert(\is_string($_value));
            Utils\Common::assert(\array_key_exists($_key, $_collector));
            Utils\Common::assert(\is_null($_collector[$_key]));
            $_collector[$_key] = self::_validate_code_url($_value);
        }
        foreach (\array_values($_collector) as $_value) Utils\Common::assert(\is_string($_value));
        $this->_code_data = $_collector;
    }


    public static function seems_like_legacy(string $url) {
        if ('' === $url) return false;
        $url = \parse_url($url);
        if (! \is_array($url)) return false;
        if ((function () use ($url) {
            if (! \array_key_exists('path', $url)) return false;
            $url = $url['path'];
            if (! \is_string($url)) return false;
            if ('' === $url) return false;
            $url = Utils\Common::normalize_path($url);
            return \str_ends_with($url, 'proxy.php');
        }) ()) return true;
        if ((function () use ($url) {
            if (! \array_key_exists('query', $url)) return false;
            $_parsed = $url['query'];
            return \str_starts_with($_parsed, 'req=');
        }) ()) return true;
        return false;
    }


    private function _resolve(string $key, ?string $default = null): ?string {
        Utils\Common::assert(\in_array($key, ['internal', 'external'], true));
        if ($this->_config->application->get('code')) return $this->_from_code($key);
        $_url = $this->_config->application->get("wopi_url");
        if (self::seems_like_legacy($_url)) return $default;
        return $_url;
    }

    private function _from_code(string $key): ?string {
        if (\is_null($this->_code_data)) return null;
        Utils\Common::assert(\array_key_exists($key, $this->_code_data));
        return $this->_code_data[$key];
    }


    private static function _validate_code_url(string $value): string {
        Utils\Common::assert('' !== $value);
        $_parsed = \parse_url($value);
        Utils\Common::assert(\is_array($_parsed));
        Utils\Common::assert(\array_key_exists('scheme', $_parsed));
        Utils\Common::assert(\in_array($_parsed['scheme'], ['http', 'https'], true));
        Utils\Common::assert(! \array_key_exists('query', $_parsed));
        Utils\Common::assert(! \array_key_exists('fragment', $_parsed));
        Utils\Common::assert(! self::seems_like_legacy($value));
        return $value;
    }
};
