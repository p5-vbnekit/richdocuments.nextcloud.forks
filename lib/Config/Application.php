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

class Application {
    private readonly string $_application;

    public function __construct(
        string $appName,
        private readonly \OCP\IConfig $_backend,
        private readonly \Psr\Log\LoggerInterface $_logger
    ) {
        $this->_application = $appName;
    }


    public function keys(?string $application = null): array {
        if (\is_null($application)) $application = $this->_application;
        else Utils\Common::assert('' !== $application);
        return $this->_backend->getAppKeys($application);
    }

    public function get(string $key, array $options = []): mixed {
        $_specification = $this->_specification($key, $options);
        $_getter = $this->_make_getter($_specification['application'], $key);

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
        $this->_backend->setAppValue(
            $_specification['application'], $key,
            $this->_serialize($value, $_specification)
        );
    }

    public function remove(string $key, array $options = []): void {
        $this->_backend->deleteAppValue(
            $this->_specification($key, $options)['application'], $key
        );
    }

    public function specification(string $key, string $application = null): array {
        $_specification = [];
        if (! \is_null($application)) $_specification['application'] = $application;
        return $this->_specification($key, $_specification);
    }

    private function _make_getter(mixed ...$arguments): \Closure {
        $_mix = static function (array $default) use ($arguments): array {
            if (empty($default)) return $arguments;
            \array_push($arguments, \array_pop($default));
            Utils\Common::assert(empty($default));
            return $arguments;
        };

        return function (mixed ...$default) use ($_mix): mixed {
            return $this->_backend->getAppValue(...$_mix($default));
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

        if (\array_key_exists('application', $options)) {
            $_application = $options['application'];
            Utils\Common::assert(\is_string($_application));
            Utils\Common::assert('' !== $_application);
        }

        else $_application = \str_starts_with(
            $key, 'watermark_'
        ) ? 'files' : $this->_application;

        static $_storage = null;
        if (\is_null($_storage)) $_storage = [
            null => [
                'code' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'theme' => [
                    'default' => '',
                    'parser' => static fn (string $value) => $value,
                    'serializer' => static fn (string $value) => $value
                ],
                'enabled' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'timeout' => [
                    'default' => 15,
                    'parser' => static fn (string $value) => self::_validate_integer(self::_parse_integer($value), ['min' => 0]),
                    'serializer' => static fn (int $value) => self::_validate_integer($value, ['min' => 0])
                ],
                'wopi_url' => [
                    'default' => '',
                    'parser' => static fn (string $value) => self::_validate_wopi_url($value),
                    'serializer' => static fn (string $value) => self::_validate_wopi_url($value)
                ],
                'token_ttl' => [
                    'default' => 36000, // 10 hours
                    'parser' => static fn (string $value) => self::_validate_integer(self::_parse_integer($value), ['min' => 0]),
                    'serializer' => static fn (int $value) => self::_validate_integer($value, ['min' => 0])
                ],
                'doc_format' => [
                    'default' => '',
                    'parser' => static fn (string $value) => $value,
                    'serializer' => static fn (string $value) => $value
                ],
                'use_groups' => [
                    'default' => [],
                    'parser' => static fn (string $value) => self::_parse_array($value),
                    'serializer' => static fn (array $value) => self::_serialize_array($value)
                ],
                'edit_groups' => [
                    'default' => [],
                    'parser' => static fn (string $value) => self::_parse_array($value),
                    'serializer' => static fn (array $value) => self::_serialize_array($value)
                ],
                'loopback_url' => [
                    'default' => '',
                    'parser' => static fn (string $value) => self::_validate_loopback_url($value),
                    'serializer' => static fn (string $value) => self::_validate_loopback_url($value),
                ],
                'external_apps' => [
                    'default' => [],
                    'parser' => static fn (string $value) => self::_parse_array($value),
                    'serializer' => static fn (array $value) => self::_serialize_array($value)
                ],
                'zoteroEnabled' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'wopi_allowlist' => [
                    'default' => [],
                    'parser' => static fn (string $value) => self::_validate_wopi_allow_list(self::_parse_array($value)),
                    'serializer' => static fn (array $value) => self::_validate_wopi_allow_list($value)
                ],
                'wopi_override' => [
                    'default' => [],
                    'parser' => static fn (string $value) => self::_parse_array($value),
                    'serializer' => static fn (string $value) => self::_serialize_array($value)
                ],
                'template_public' => [
                    'default' => true,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'uiDefaults-UIMode' => [
                    'default' => '',
                    'parser' => static fn (string $value) => $value,
                    'serializer' => static fn (string $value) => $value
                ],
                'canonical_webroot' => [
                    'default' => '',
                    'parser' => static fn (string $value) => $value,
                    'serializer' => static fn (string $value) => $value
                ],
                'read_only_feature_lock' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'federation_use_trusted_domains' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'disable_certificate_verification' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ]
            ],
            'files' => [
                'watermark_text' => [
                    'default' => '{userId}',
                    'parser' => static fn (string $value) => $value,
                    'serializer' => static fn (string $value) => $value
                ],
                'watermark_enabled' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'watermark_allTags' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'watermark_allTagsList' => [
                    'default' => [],
                    'parser' => static fn (string $value) => '' === $value ? [] : \explode(',', $value),
                    'serializer' => static fn (array $value) => \implode(',', $value)
                ],
                'watermark_linkAll' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'watermark_linkRead' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'watermark_linkSecure' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'watermark_linkTags' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'watermark_linkTagsList' => [
                    'default' => [],
                    'parser' => static fn (string $value) => '' === $value ? [] : \explode(',', $value),
                    'serializer' => static fn (array $value) => \implode(',', $value)
                ],
                'watermark_allGroups' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'watermark_allGroupsList' => [
                    'default' => [],
                    'parser' => static fn (string $value) => '' === $value ? [] : \explode(',', $value),
                    'serializer' => static fn (array $value) => \implode(',', $value)
                ],
                'watermark_shareAll' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'watermark_shareRead' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ],
                'watermark_shareDisabledDownload' => [
                    'default' => false,
                    'parser' => static fn (string $value) => self::_parse_boolean($value),
                    'serializer' => static fn (bool $value) => self::_serialize_boolean($value)
                ]
            ],
            'theming' => ['logoMime' => [], 'logoheaderMime' => []]
        ];

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
        $_value['application'] = $_application;
        return $_value;
    }


    private static function _serialize(mixed $value, array $specification): mixed {
        if (! \array_key_exists('serializer', $specification)) return $value;
        return $specification['serializer']($value);
    }

    private static function _validate_integer(int $value, array $options = []): int {
        if (\array_key_exists('min', $options)) {
            Utils\Common::assert(\is_integer($options['min']));
            Utils\Common::assert($value >= $options['min']);
        }
        if (\array_key_exists('max', $options)) {
            Utils\Common::assert(\is_integer($options['max']));
            Utils\Common::assert($value <= $options['max']);
        }
        return $value;
    }

    private static function _validate_wopi_url(string $value): string {
        if ('' !== $value) {
            $_parts = \parse_url($value);
            Utils\Common::assert(\is_array($_parts));
            Utils\Common::assert(\array_key_exists('scheme', $_parts));
            Utils\Common::assert(\in_array($_parts['scheme'], ['http', 'https'], true));
            Utils\Common::assert(\array_key_exists('host', $_parts));
            Utils\Common::assert(! \array_key_exists('fragment', $_parts));
        }
        return $value;
    }

    private static function _validate_loopback_url(string $value): string {
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
    }

    private static function _validate_wopi_allow_list(array $value): array {
        foreach ($value as $_item) {
            Utils\Common::assert(\is_string($_item));
            $_item = \trim($_item);
            Utils\Common::assert('' !== $_item);
        }
        return $value;
    }

    private static function _parse_array(string $value): array {
        $value = \trim($value);
        if ('' === $value) return [];
        Utils\Common::assert(\str_starts_with($value, '['));
        Utils\Common::assert(\str_ends_with($value, ']'));
        $value = \json_decode($value, false);
        Utils\Common::assert(\is_array($value));
        return $value;
    }

    private static function _parse_boolean(string $value): bool {
        $value = \trim($value);
        if ('yes' === $value) return true;
        Utils\Common::assert('no' === $value);
        return false;
    }

    private static function _parse_integer(string $value): int {
        $value = \trim($value);
        Utils\Common::assert(1 === \preg_match('/^[\-\+]?[0-9]+$/', $value));
        return \intval($value);
    }

    private static function _serialize_array(array $value): string {
        $value = \json_encode(\array_values($value));
        Utils\Common::assert(\is_string($value));
        Utils\Common::assert(\str_starts_with($value, '['));
        Utils\Common::assert(\str_ends_with($value, ']'));
        return $value;
    }

    private static function _serialize_boolean(bool $value): string { return $value ? 'yes' : 'no'; }
}
