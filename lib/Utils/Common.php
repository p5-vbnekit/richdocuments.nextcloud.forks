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

class Common {
    public static function with_lock_file(string $path, $pid = null): \Generator {
        $path = self::normalize_path($path);
        self::assert('' !== $path);

        if (false !== $pid) {
            if (\is_null($pid)) $pid = \getmypid();
            self::assert(\is_integer($pid));
            self::assert(0 < $pid);
        }

        $_resource = \fopen($path, 'we');
        self::assert(\is_resource($_resource));

        try {
            self::assert(\flock($_resource, \LOCK_EX));
            self::assert(\chmod($path, 0600));
            if (\is_integer($pid)) {
                $_text = \sprintf("%u\n", $pid);
                self::assert(\is_string($_text));
            } else $_text = "\n";
            $_expected = \strlen($_text);
            self::assert(\is_integer($_expected));
            self::assert(0 < $_expected);
            $_written = \fwrite($_resource, $_text);
            self::assert(\is_integer($_written));
            self::assert($_expected === $_written);
            self::assert(\fsync($_resource));
            yield $_resource;
        }

        finally { if (\is_resource($_resource)) try {
            self::assert(\ftruncate($_resource, 0));
            self::assert(0 === \fseek($_resource, 0, \SEEK_SET));
            $_text = "\n";
            $_expected = \strlen($_text);
            self::assert(\is_integer($_expected));
            self::assert(0 < $_expected);
            $_written = \fwrite($_resource, $_text);
            self::assert(\is_integer($_written));
            self::assert($_expected === $_written);
            self::assert(\fsync($_resource));
        } finally { self::assert(\fclose($_resource)); } }
    }

    public static function ensure_pid(string $path, ?array $options = null): ?int {
        self::assert(\is_string($path));
        self::assert('' !== $path);

        if (\is_null($options)) $options = [];
        else self::assert(\is_array($options));

        if (\array_key_exists('gentle', $options)) self::assert(\is_bool($options['gentle']));
        else $options['gentle'] = false;

        $_assert = (static function (bool $gentle) {
            if ($gentle) return static fn (bool $condition) => (! $condition);
            return static function (bool $condition) { self::assert($condition); return false; };
        }) ($options['gentle']);

        $path = self::resolve_path($path);
        if ($_assert(\is_string($path))) return null;
        if ($_assert(\is_file($path))) return null;
        $_pid = \file_get_contents($path);
        if ($_assert(\is_string($_pid))) return null;
        $_pid = \trim($_pid);
        if ($_assert('' !== $_pid)) return null;
        if ($_assert(1 === \preg_match('/^[0-9]+$/', $_pid))) return null;
        $_pid = \intval($_pid);
        if ($_assert(\is_integer($_pid))) return null;
        if ($_assert(0 < $_pid)) return null;
        if (self::check_functions('posix_kill') && $_assert(\posix_kill($_pid, 0))) return null;
        return $_pid;
    }

    public static function ensure_directory_path(string $path, ?array $options = null): ?string {
        if (\is_null($options)) $options = [];
        else self::assert(\is_array($options));

        if (\array_key_exists('gentle', $options)) self::assert(\is_bool($options['gentle']));
        else $options['gentle'] = false;

        if (\array_key_exists('create', $options)) self::assert(\is_bool($options['create']));
        else $options['create'] = false;

        $_assert = (static function (bool $gentle) {
            if ($gentle) return static fn (bool $condition) => (! $condition);
            return function (bool $condition) { Common::assert($condition); return false; };
        }) ($options['gentle']);

        $path = (static function () use ($path, $options) {
            $options['resolve'] = false;
            return self::ensure_path($path, $options);
        }) ();

        if ($_assert(\is_string($path))) return null;

        if (\file_exists($path)) {
            if ($_assert(\is_dir($path))) return null;
        } elseif ($_assert(
            $options['create'] && @\mkdir($path, 0700, true)
        )) return null;

        if ($options['resolve']) {
            $path = self::resolve_path($path);
            if ($_assert(\is_string($path))) return null;
        }

        return $path;
    }

    public static function ensure_path(string $path, ?array $options = null): ?string {
        if (\is_null($options)) $options = [];
        else self::assert(\is_array($options));

        if (\array_key_exists('gentle', $options)) self::assert(\is_bool($options['gentle']));
        else $options['gentle'] = true;

        if (\array_key_exists('resolve', $options)) self::assert(\is_bool($options['resolve']));
        else $options['resolve'] = true;

        if (\array_key_exists('may_root', $options)) self::assert(\is_bool($options['may_root']));
        else $options['may_root'] = false;

        if (\array_key_exists('may_relative', $options)) self::assert(\is_bool($options['may_relative']));
        else $options['may_relative'] = false;

        $_assert = (static function (bool $gentle) {
            if ($gentle) return static fn (bool $condition) => (! $condition);
            return static function (bool $condition) { Common::assert($condition); return false; };
        }) ($options['gentle']);

        if ($_assert('' !== $path)) return null;
        $path = self::normalize_path($path);

        if ($_assert(
            $options['may_relative'] || \str_starts_with($path, '/')
        )) return null;

        if ($options['resolve']) {
            $path = self::resolve_path($path);
            if ($_assert(\is_string($path))) return null;
        }

        if ($_assert($options['may_root'] || ('/' !== $path))) return null;

        return $path;
    }

    public static function normalize_path(string $path): string {
        self::assert('' !== $path);

        $_prefix = 0;
        $_collector = [];
        $_mapper = [
            '' => static function () use (&$_prefix, &$_collector) {
                if ((1 > $_prefix) && empty($_collector)) \array_push($_collector, '');
            },
            '.' => static function () {},
            '..' => static function () use (&$_prefix, &$_collector) {
                if (empty($_collector)) $_prefix += 1;
                else \array_pop($_collector);
            }
        ];

        foreach(\explode('/', $path) as $_member) {
            $_action = \array_key_exists($_member, $_mapper) ? $_mapper[$_member] : null;
            if (\is_null($_action)) \array_push($_collector, $_member);
            else $_action();
        };

        if (0 < $_prefix) return \rtrim(\str_repeat('../', $_prefix) . \ltrim(\implode('/', $_collector), '/'), '/');
        return \implode('/', $_collector);
    }

    public static function resolve_path(string $path): ?string {
        if ('' === $path) return null;
        \clearstatcache(true, $path);
        $path = \realpath($path);
        if (! \is_string($path)) return null;
        if ('' === $path) return null;
        return $path;
    }

    public static function get_from_tree(array $tree, array $path, ?array $options = null): mixed {
        self::assert(! empty($path));

        if (\is_null($options)) $options = [];
        else self::assert(\is_array($options));

        if (\array_key_exists('gentle', $options)) self::assert(\is_bool($options['gentle']));
        else $options['gentle'] = false;

        $_check = (static function () use ($options) {
            if ($options['gentle']) return static fn (
                mixed $tree, mixed $key
            ) => \is_array($tree) && \array_key_exists($key, $tree);
            if (\array_key_exists('default', $options)) return static fn (
                array $tree, mixed $key
            ) => \array_key_exists($key, $tree);
            return static function (array $tree, mixed $key) {
                self::assert(\array_key_exists($key, $tree));
                return true;
            };
        }) ();

        foreach ($path as $path) if ($_check($tree, $path)) $tree = $tree[$path];
        else return \array_key_exists('default', $options) ? $options['default'] : null;

        return $tree;
    }

    public static function check_functions(string ...$needle): bool {
        static $_functions = [];

        Common::assert(\is_array($needle));
        foreach($needle as $needle) {
            Common::assert(\is_string($needle));
            Common::assert('' !== $needle);
            if (! \array_key_exists($needle, $_functions)) $_functions[$needle] = (static function () use ($needle) {
                static $_disabled = null;

                $_disabled = (static function () {
                    $_config = \ini_get('disable_functions');
                    if (! \is_string($_config)) return [];
                    if ('' === $_config) return [];
                    $_config = \explode(',', $_config);
                    Common::assert(\is_array($_config));
                    return $_config;
                }) ();

                if (\in_array($needle, $_disabled, true)) return false;
                return true === \function_exists($needle);
            }) ();
            if (! $_functions[$needle]) return false;
        }

        return true;
    }

    public static function assert(bool $condition, ?string $description = null): void {
        if ($condition) return;
        if (\is_string($description) && ('' != $description)) throw new \AssertionError($description);
        throw new \AssertionError();
    }
}
