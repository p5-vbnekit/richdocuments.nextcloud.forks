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

class System {
    public function __construct(
        private readonly \OCP\IConfig $_backend,
        private readonly \Psr\Log\LoggerInterface $_logger
    ) {}


    public function get(string $key, mixed ...$default): mixed {
        $_specification = $this->_specification($key);
        $_getter = $this->_make_getter($key);

        if (! empty($default)) {
            $_specification['default'] = \array_pop($default);
            Utils\Common::assert(empty($default));
        }

        $_value = $_getter(null);
        if (! \is_null($_value)) return $this->_parse($_value, $_specification);
        $_value = $_getter('');
        if (\is_null($_value)) return $this->_parse($_value, $_specification);
        Utils\Common::assert('' === $_value);

        if (\array_key_exists('default', $_specification)) return $_specification['default'];
        return $this->_parse($_getter(), $_specification);
    }


    private function _make_getter(mixed ...$arguments): \Closure {
        $_mix = static function (array $default) use ($arguments): array {
            if (empty($default)) return $arguments;
            \array_push($arguments, \array_pop($default));
            Utils\Common::assert(empty($default));
            return $arguments;
        };

        return function (mixed ...$default) use ($_mix): mixed {
            return $this->_backend->getSystemValue(...$_mix($default));
        };
    }

    private function _parse(mixed $value, array $specification): mixed {
        if (! \array_key_exists('parser', $specification)) return $value;
        try { $value = $specification['parser']($value); }
        catch (\Throwable $exception) {
            $value = \array_key_exists(
                'default', $specification
            ) ? $specification['default'] : null;
            $this->_logger->warning(
                'failed to parse: ' . $specification['key'],
                ['exception' => $exception]
            );
        }
        return $value;
    }

    private function _specification(string $key): array {
        Utils\Common::assert('' !== $key);

        static $_storage = null;
        if (\is_null($_storage)) $_storage = [
            'instanceid' => [],
            'gs.trustedHosts' => ['default' => [], 'parser' => static fn (array $value) => \array_filter(
                $value, (static fn (string $value) => ('' !== $value))
            )],
            'overwrite.cli.url' => ['default' => '', 'parser' => static function (string $value) {
                if ('' !== $value) {
                    $_parts = \parse_url($value);
                    Utils\Common::assert(\is_array($_parts));
                    if (! \array_key_exists('scheme', $_parts)) {
                        $value = 'http://' . $value;
                        $_parts = \parse_url($value);
                        Utils\Common::assert(\is_array($_parts));
                        Utils\Common::assert(\array_key_exists('scheme', $_parts));
                    }
                    Utils\Common::assert(\in_array($_parts['scheme'], ['http', 'https'], true));
                    Utils\Common::assert(\array_key_exists('host', $_parts));
                    Utils\Common::assert(! \array_key_exists('query', $_parts));
                    Utils\Common::assert(! \array_key_exists('fragment', $_parts));
                }
                return $value;
            }]
        ];

        $_value = (static function () use ($key, $_storage) {
            if (! \array_key_exists($key, $_storage)) return null;
            return $_storage[$key];
        }) ();

        try { Utils\Common::assert(! \is_null($_value), 'not found'); }
        catch (\Throwable $exception) {
            $_value = [];
            $this->_logger->warning(
                'key not found: '. $key,
                ['exception' => $exception]
            );
        }

        Utils\Common::assert(\is_array($_value));

        $_value['key'] = $key;
        return $_value;
    }
}
