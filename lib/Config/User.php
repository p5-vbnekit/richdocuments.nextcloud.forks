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

namespace OCA\Richdocuments\Config;

use \OCA\Richdocuments\Utils;

class User {
    private readonly ?string $_user;
    private readonly string $_application;

    public function __construct(
        ?string $userId,
        string $appName,
        private readonly \OCP\IConfig $_backend,
        private readonly \Psr\Log\LoggerInterface $_logger
    ) {
        $this->_user = $userId;
        $this->_application = $appName;
    }


    public function get(string $key, array $options = []): mixed {
        $_specification = $this->_specification($key, $options);
        $_getter = $this->_make_getter(
            $_specification['user'],
            $_specification['application'],
            $key
        );

        $_value = $_getter(null);
        if (! \is_null($_value)) return $this->_parse($_value, $_specification);
        $_value = $_getter('');
        if (\is_null($_value)) return $this->_parse($_value, $_specification);
        Utils\Common::assert('' === $_value);

        if (\array_key_exists('default', $_specification)) return $_specification['default'];
        return $this->_parse($_getter(), $_specification);
    }

    public function set(string $key, mixed $value, array $options = []): void {
        $_specification = $this->_specification($key, $options);
        $_arguments = [
            $_specification['user'],
            $_specification['application'],
            $key, $this->_serialize($value, $_specification)
        ];
        if (\array_key_exists('pre_condition', $options)) \array_push($_arguments, $options['pre_condition']);
        $this->_backend->setUserValue(...$_arguments);
    }

    public function remove(string $key, array $options = []): void {
        $_specification = $this->_specification($key, $options);
        $this->_backend->deleteUserValue(
            $_specification['user'],
            $_specification['application'],
            $key
        );
    }

    private function _make_getter(mixed ...$arguments): \Closure {
        $_mix = static function (array $default) use ($arguments): array {
            if (empty($default)) return $arguments;
            \array_push($arguments, \array_pop($default));
            Utils\Common::assert(empty($default));
            return $arguments;
        };

        return function (mixed ...$default) use ($_mix): mixed {
            return $this->_backend->getUserValue(...$_mix($default));
        };
    }

    private function _parse(mixed $value, array $specification): mixed {
        if (! \array_key_exists('parser', $specification)) return $value;
        try { $value = $specification['parser']($value); }
        catch (\Throwable $exception) {
            $value = \array_key_exists(
                'default', $specification
            ) ? $specification['default'] : null;
            $this->_logger->warning('failed to parse: '. \json_encode([
                'key' => $specification['key'],
                'application' => $specification['application'],
            ]), ['exception' => $exception]);
        }
        return $value;
    }

    private function _specification(string $key, array $options): array {
        Utils\Common::assert('' !== $key);

        if (\array_key_exists('user', $options)) {
            $_user = $options['user'];
            if (! \is_null($_user)) {
                Utils\Common::assert(\is_string($_user));
                Utils\Common::assert('' !== $_user);
            }
        }

        else $_user = $this->_user;

        if (\array_key_exists('application', $options)) {
            $_application = $options['application'];
            Utils\Common::assert(\is_string($_application));
            Utils\Common::assert('' !== $_application);
        }

        else $_application = \str_starts_with(
            $key, 'watermark_'
        ) ? 'files' : $this->_application;

        static $_storage = null;
        if (\is_null($_storage)) $_storage = [null => [
            'zoteroAPIKey' => [
                'default' => '',
                'parser' => static fn (string $value) => $value,
                'serializer' => static fn (string $value) => $value
            ],
            'templateFolder' => [
                'default' => '',
                'parser' => static fn (string $value) => $value,
                'serializer' => static fn (string $value) => $value
            ]
        ]];

        $_value = (function () use ($key, $_application, $_storage) {
            if ($this->_application === $_application) $_application = null;
            if (! \array_key_exists($_application, $_storage)) return null;
            $_storage = $_storage[$_application];
            if (! \array_key_exists($key, $_storage)) return null;
            return $_storage[$key];
        }) ();

        try { Utils\Common::assert(! \is_null($_value), 'not found'); }
        catch (\Throwable $exception) {
            $_value = [];
            $this->_logger->warning('not found: '. \json_encode([
                'key' => $key, 'application' => $_application,
            ]), ['exception' => $exception]);
        }

        Utils\Common::assert(\is_array($_value));

        $_value['key'] = $key;
        $_value['user'] = $_user;
        $_value['application'] = $_application;
        return $_value;
    }


    private static function _serialize(mixed $value, array $specification): mixed {
        if (! \array_key_exists('serializer', $specification)) return $value;
        return $specification['serializer']($value);
    }
}
