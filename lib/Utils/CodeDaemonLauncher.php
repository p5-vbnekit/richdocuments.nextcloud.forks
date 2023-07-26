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

class CodeDaemonLauncher {
    private string $_application_id;

    public function __construct(
        string $appName,
        private readonly \OCP\IURLGenerator $_url_generator,
        private readonly \OCA\Richdocuments\Config\System $_config,
        private readonly \OCA\Richdocuments\WOPI\EndpointResolver $_endpointResolver,
        private readonly \Psr\Log\LoggerInterface $_logger
    ) {
        $this->_application_id = $appName;
    }


    public function spawn(
        string $pid_file,
        string $executable
    ): int {
        if (\file_exists($pid_file)) {
            Common::assert(\is_file($pid_file));
            Common::assert(\unlink($pid_file));
            Common::assert(! \file_exists($pid_file));
        }

        $_logger = $this->_logger;
        $_command = \escapeshellarg($executable);

        foreach (self::_with_shell($_logger) as $_shell) {
            $_common_check = (function () use ($_shell, $_logger) {
                $_shell = $_shell->executors;
                Common::assert(! \is_null($_shell));
                $_shell = $_shell->command;
                Common::assert(! \is_null($_shell));
                return function(
                    string $name, string $command,
                    ?\Closure $delegate = null
                ) use ($_shell, $_logger) {
                    $_result = $_shell($command, 1, 2);
                    try {
                        if (! \is_null($delegate)) return $delegate($_result);
                        Common::assert(0 === $_result->code);
                    } catch (\Throwable $exception) {
                        $_logger->warning($name . ' check failed.' . (function (string $message) {
                            if ('' === $message) return $message;
                            return $message = "\n" . $message;
                        })(trim($_result->output[2])), ['exception'  => $exception]);
                        throw $exception;
                    }
                };
            }) ();

            (function () use ($_command, $_common_check) { foreach ([
                'glibc' => 'LD_TRACE_LOADED_OBJECTS=1 ' . $_command,
                'fontconfig' => '(ldconfig -p || scanelf -l) | grep fontconfig'
            ] as $_name => $_command) $_common_check($_name, $_command, function (object $result) {
                Common::assert(0 === $result->code);
                $result = \array_map(fn (string $line) => \trim($line), \explode("\n", ($result->output)[1]));
                $result = \array_filter($result, fn (string $line) => ('' !== $line));
                Common::assert(! empty($result));
            }); }) ();

            $_version_check = function (bool $gentle = false) use (
                $_common_check, &$_command
            ): bool {
                return $_common_check(
                    'version', $_command . ' --version-hash',
                    function (object $response) use ($gentle)
                {
                    if (0 !== $response->code) {
                        Common::assert($gentle);
                        return false;
                    }
                    $response = \substr(self::_ensure_process_output(($response->output)[1], false), 0, -1);
                    Common::assert(self::version_hash() === $response);
                    return true;
                });
            };

            if (! $_version_check(true)) {
                $_command = $_command . ' --appimage-extract-and-run';
                $_version_check(false);
            }

            // Cuz overridden ("nailed") in AppImage entry point script
            // $_command = $_command . ' --daemon --disable-cool-user-checking';
            // $_command = $_command . ' --port=' . self::http_port();
            $_command = $_command . ' --disable-ssl --pidfile=' . \escapeshellarg($pid_file);

            (function () use (&$_command) {
                $_url = $this->_endpointResolver->loopback();
                if (! \str_starts_with($_url, 'https://')) return;
                $_url = '--o:remote_font_config.url=' . \escapeshellarg(\implode(
                    \str_ends_with($_url, '/') ? '' : '/',
                    [$_url, \trim($this->_url_generator->linkTo(
                        $this->_application_id, ''
                    ), '/'), 'settings/fonts.json']
                ));
                $_command = \implode(' ', [$_command, $_url]);
            }) ();

            $_fork = (function () use ($_shell, $_command) {
                if (! Common::check_functions('pcntl_fork', 'pcntl_exec')) return null;

                $_magic = self::_make_magic();
                Common::assert(\is_string($_magic));
                Common::assert('' !== $_magic);
                $_magic = $_magic;

                $_make_exec_arguments = (function () {
                    $_remix = function (string $path, string ...$arguments) {
                        Common::assert('' !== $path);
                        Common::assert($path === Common::ensure_path($path, [
                            'gentle' => false, 'resolve' => true,
                            'may_root' => false, 'may_relative' => false
                        ]));
                        Common::assert(\is_file($path));
                        Common::assert(\is_executable($path));
                        Common::assert(! empty($arguments));
                        return [$path, $arguments];
                    };
                    return function (array $command) use ($_remix) {
                        Common::assert(! empty($command));
                        return $_remix(...$command);
                    };
                }) ();

                if (Common::check_functions('posix_setsid')) $_new_session = function () {
                    $_session = \posix_setsid();
                    Common::assert(\is_integer($_session));
                    Common::assert(0 < $_session);
                }; else $_new_session = null;

                $_terminate = (function (array $command) {
                    return function () use ($command) { try { \pcntl_exec(...$command); } finally {
                        try {
                            $_pid = \getmypid();
                            Common::assert(\is_integer($_pid));
                            Common::assert(0 < $_pid);
                            Common::assert(\posix_kill($_pid, \SIGTERM));
                        }
                        finally { die(1); }
                    }};
                }) ($_make_exec_arguments(($_shell->make_command)('exit 1')));

                foreach (self::_with_temp_file() as $_lock_path) {
                    foreach (($_shell->with_script)(\implode("\n", [
                        'exec >&- 2>&-',
                        'exec >/dev/null 2>/dev/null',
                        'echo ' . \escapeshellarg($_magic) . '>' . \escapeshellarg($_lock_path),
                        'exec ' . $_command
                    ])) as $_command) {
                        $_command = $_make_exec_arguments($_command);

                        foreach (Common::with_lock_file($_lock_path) as $_lock_resource) {
                            Common::assert(\is_resource($_lock_resource));
                            $_pid = \pcntl_fork();
                            Common::assert(\is_integer($_pid));
                            if (0 === $_pid) try {
                                if (! \is_null($_new_session)) $_new_session();
                                \pcntl_exec(...$_command);
                            } finally { $_terminate(); }
                            break;
                        }

                        Common::assert(0 < $_pid);

                        (function () use ($_pid, $_magic, $_lock_path) {
                            $_counter = 0;
                            $_magic = $_magic . "\n";
                            $_zero_kill = Common::check_functions('posix_kill');
                            $_resource = \fopen($_lock_path, 'r');
                            Common::assert(\is_resource($_resource));
                            try { Common::assert(\flock($_resource, \LOCK_SH)); }
                            finally { Common::assert(\fclose($_resource)); }
                            while (true) {
                                Common::assert(4 > $_counter++);
                                if ($_zero_kill) Common::assert(\posix_kill($_pid, 0));
                                $_content = \file_get_contents($_lock_path);
                                if ($_magic === $_content) break;
                                Common::assert(0 === \sleep(1));
                            }
                        }) ();

                        break;
                    }

                    break;
                }

                return $_pid;
            }) ();

            if (\is_null($_fork)) Common::assert(0 === ($_shell->executors->script)(\implode("\n", [
                'exec </dev/null >/dev/null 2>/dev/null',
                'exec ' . $_command . ' &', 'readlink -e /proc/$!/exe',
                'shopt -u huponexit || true', 'disown || true', # not in legacy POSIX
            ]))->code, 'unable to spawn collabora daemon process');

            break;
        }

        return (function () use ($pid_file, $_fork) {
            $_counter = 0;
            $_zero_kill = (\is_integer($_fork) && Common::check_functions('posix_kill'));
            while (! \file_exists($pid_file)) {
                Common::assert(8 > $_counter++, "timeout occured");
                if ($_zero_kill) Common::assert(\posix_kill($_fork, 0));
                Common::assert(0 === \sleep(1));
            }
            if ($_zero_kill) Common::assert(\posix_kill($_fork, 0));
            Common::assert(\is_file($pid_file));
            (function () use ($pid_file) {
                $_resource = \fopen($pid_file, 'r');
                Common::assert(\is_resource($_resource));
                try { Common::assert(\flock($_resource, \LOCK_SH)); }
                finally { Common::assert(\fclose($_resource)); }
            }) ();
            $_pid = Common::ensure_pid($pid_file);
            Common::assert('coolwsd' === \basename(Common::ensure_path('/proc/' . $_pid . '/exe', [
                'gentle' => false, 'resolve' => true,
                'may_root' => false, 'may_relative' => false
            ])));
            Common::assert(\chmod($pid_file, 0600));
            return $_pid;
        }) ();
    }


    public static function http_port(): int {
        // AppImage entry point doesn't support `--port=value` argument
        // return 9984;
        return 9983;
    }

    public static function version_hash(): string { return '10deb70'; }


    private static function _with_shell(?\Psr\Log\LoggerInterface $logger = null): \Generator {
        static $_cache = null;

        if (\is_null($_cache)) try { $_cache = ['factory' => (function () use ($logger) {
            $_executors = \iterator_to_array((function () use ($logger) {
                if (Common::check_functions('exec')) yield 'exec' => function (
                    array $command, array $descriptors
                ) use ($logger) {
                    $command = \implode(' ', \array_map(function($item) {
                        return \escapeshellarg($item);
                    }, $command));

                    $_output = function () use ($logger, $descriptors) {
                        $_readers = [1 => null, 2 => null];
                        foreach ($descriptors as $_descriptor) {
                            Common::assert(\is_integer($_descriptor));
                            Common::assert(\array_key_exists($_descriptor, $_readers));
                            Common::assert(\is_null($_readers[$_descriptor]));
                            $_readers[$_descriptor] = true;
                        }

                        $_script = \iterator_to_array((function () use ($_readers) {
                            foreach ($_readers as $_descriptor => $_reader) if (\is_null($_reader)) {
                                yield $_descriptor;
                            } else Common::assert(true === $_reader);
                        }) ());

                        $_script = \array_filter([
                            'exec </dev/null',
                            \implode(' ', \array_map(function (int $d) { return $d . '>&-'; }, $_script)),
                            \implode(' ', \array_map(function (int $d) { return $d . '>/dev/null'; }, $_script))
                        ], function (string $value) { return '' !== $value; });

                        $_readers = \iterator_to_array((function () use ($_readers) {
                            foreach ($_readers as $_descriptor => $_reader) {
                                if (true === $_reader) yield $_descriptor => $_reader;
                            }
                        }) ());

                        $_generators = [];

                        $_open_generator = function (\Generator $generator) use (&$_generators) {
                            Common::assert($generator->valid());
                            $_path = $generator->current();
                            Common::assert($_path === Common::ensure_path($_path, [
                                'gentle' => false, 'resolve' => true,
                                'may_root' => false, 'may_relative' => false
                            ]));
                            Common::assert(\is_file($_path));
                            \array_push($_generators, $generator);
                            return $_path;
                        };

                        $_open_generator = function () use ($_open_generator) {
                            return $_open_generator(self::_with_temp_file());
                        };

                        $_close_generators = function() use ($logger, &$_generators) {
                            $_exceptions = [];
                            foreach ($_generators as $_generator) {
                                if (! $_generator->valid()) continue;
                                try {
                                    Common::assert(\is_null($_generator->send(null)));
                                    Common::assert(! $_generator->valid());
                                }
                                catch (\Throwable $exception) {
                                    \array_push($_exceptions, $exception);
                                    try { $_generator->throw($exception); }
                                    catch (\Throwable $exception) { \array_push($_exceptions, $exception); };
                                }
                            }
                            if (! is_null($logger)) foreach ($_exceptions as $_exception) $logger->error(
                                'unable to remove temporary file', ['exception' => $_exception]
                            );
                            Common::assert(empty($_exceptions));
                        };

                        try {
                            foreach ($_readers as $_descriptor => &$_reader) {
                                common::assert(true === $_reader);
                                $_path = $_open_generator();
                                \array_push($_script, $_descriptor . '>' . \escapeshellarg($_path));
                                $_reader = function () use ($_path) { return \file_get_contents($_path); };
                            }

                            yield (object)['script' => \implode(' ', $_script), 'readers' => $_readers];
                        }

                        finally { $_close_generators(); }
                    };

                    foreach ($_output() as $_output) {
                        $_script = $_output->script . ' ' . $command;
                        $_output = $_output->readers;

                        $_code = null;
                        $_response = null;
                        $_result = \exec($_script, $_response, $_code);

                        Common::assert(\is_string($_result));
                        Common::assert(\is_array($_response));
                        Common::assert(\is_integer($_code));
                        Common::assert('' === $_result);
                        Common::assert(empty($_response));

                        $_output = \iterator_to_array((function () use ($_output) {
                            foreach ($_output as $_descriptor => $_reader) {
                                yield $_descriptor => $_reader();
                            }
                        }) ());

                        break;
                    }

                    return [$_code, $_output];
                };

                if (Common::check_functions('proc_open', 'proc_close')) yield 'proc' => function (
                    array $command, array $descriptors
                ) use ($logger) {
                    $_streams = null;
                    $_buffer = [1 => null, 2=> null];

                    $descriptors = \iterator_to_array((function () use ($descriptors, $_buffer) {
                        foreach ($descriptors as $_descriptor) {
                            Common::assert(\is_integer($_descriptor));
                            Common::assert(\array_key_exists($_descriptor, $_buffer));
                        }
                        foreach (\array_keys($_buffer) as $_descriptor) if (
                            \in_array($_descriptor, $descriptors, true)
                        ) yield $_descriptor => ['pipe', 'w'];
                    }) ());

                    foreach ((function () use ($descriptors) {
                        $_dev_null = \fopen('/dev/null', 're');
                        Common::assert(\is_resource($_dev_null));
                        try { yield self::_strict_popen_descriptors($descriptors, $_dev_null); }
                        finally { Common::assert(\fclose($_dev_null)); }
                    }) () as $_output_config) {
                        list($command, $_streams) = (function () use ($command, $_output_config) {
                            $_resource = \proc_open($command, $_output_config, $_streams);
                            return [$_resource, $_streams];
                        }) ();

                        try {
                            Common::assert(\is_resource($command));

                            try {
                                Common::assert(\is_array($_streams));
                                if (empty($descriptors)) Common::assert(empty($_streams));
                                else {
                                    foreach (\array_keys($descriptors) as $_key) Common::assert(
                                        \array_key_exists($_key, $_streams)
                                    );
                                    foreach ($_streams as $_descriptor => $_stream) {
                                        Common::assert(\is_integer($_descriptor));
                                        Common::assert(\array_key_exists($_descriptor, $descriptors));
                                        $_content = \stream_get_contents($_stream);
                                        Common::assert(\is_string($_content));
                                        $_buffer[$_descriptor] = $_content;
                                    }
                                }
                            }

                            finally { $command = \proc_close($command); }
                        }

                        finally { if (\is_array($_streams)) {
                            $_exceptions = [];
                            foreach ($_streams as $_stream) try {
                                $_id = \get_resource_id($_stream);
                                Common::assert(\is_integer($_id));
                                $_type = \get_resource_type($_stream);
                                Common::assert(\is_string($_type));
                                if (! \is_resource($_stream)) continue;
                                try { throw new \LogicException('resource #' . $_id . ' is not closed'); }
                                catch (\LogicException $exception) { \array_push($_exceptions, $exception); }
                                if ('stream' !== $_type) try { throw new \LogicException('invalid resource #' . $_id . 'type: ' . $_type); }
                                catch (\LogicException $exception) { \array_push($_exceptions, $exception); }
                                Common::assert(\fclose($_stream));
                            } catch (\Throwable $exception) { \array_push($_exceptions, $exception); }
                            if (! is_null($logger)) foreach ($_exceptions as $_exception) $logger->error(
                                'unable to handle popen', ['exception' => $_exception]
                            );
                            Common::assert(empty($_exceptions), 'unable to close all streams');
                        }}

                        break;
                    }

                    Common::assert(\is_integer($command));

                    $_buffer = \iterator_to_array((function () use (
                        $descriptors, $_buffer
                    ) { foreach (
                        $_buffer as $_descriptor => $_content
                    ) {
                        Common::assert(\is_integer($_descriptor));
                        if (\array_key_exists($_descriptor, $descriptors)) {
                            Common::assert(\is_string($_content));
                            yield $_descriptor => $_content;
                        } else Common::assert(\is_null($_content));
                    }}) ());

                    return [$command, $_buffer];
                };
            }) ());

            Common::assert(! empty($_executors), 'not available: exec || (proc_open && proc_close)');

            $_validators = (object)[
                'command' => function(string|array $value) {
                    if (\is_string($value)) {
                        Common::assert('' !== $value);
                        return [$value];
                    }
                    Common::assert(\is_array($value));
                    Common::assert(! empty($value));
                    $_first = null;
                    $_collector = [];
                    foreach ($value as $_item) {
                        Common::assert(\is_string($_item));
                        if (\is_null($_first)) $_first = $_item;
                        \array_push($_collector, $_item);
                    }
                    Common::assert('' !== $_first);
                    return $_collector;
                },
                'descriptors' => function (null|int|array $value) {
                    if (\is_null($value)) return [];
                    static $_valid = [1, 2];
                    if (\is_integer($value)) {
                        Common::assert(\in_array($value, $_valid, true));
                        return [$value];
                    }
                    $_collector = [];
                    foreach ($value as $_item) {
                        Common::assert(\is_integer($_item));
                        Common::assert(\in_array($_item, $_valid, true));
                        Common::assert(! \in_array($_item, $_collector, true));
                        \array_push($_collector, $_item);
                    }
                    return $_collector;
                }
            ];

            $_executors = \iterator_to_array((function () use ($_executors, $_validators) {
                $_demix = function (mixed $arguments) {
                    Common::assert(! empty($arguments));

                    $_strings = null;
                    $_integers = null;

                    $arguments = (function () use ($arguments) { foreach ($arguments as $_argument) {
                        $_value = yield $_argument;
                        Common::assert(\is_bool($_value));
                        if ($_value) break;
                    }}) ();

                    Common::assert($arguments->valid());

                    try {
                        $_current = $arguments->current();
                        if (\is_string($_current)) {
                            $_strings = [$_current];
                            while ($arguments->valid()) {
                                $_current = $arguments->send(false);
                                if (! \is_string($_current)) break;
                                \array_push($_strings, $_current);
                            }
                        } else {
                            Common::assert(\is_array($_current));
                            $_strings = \iterator_to_array((function () use ($_current) {
                                foreach ($_current as $_current) {
                                    Common::assert(\is_string($_current));
                                    yield $_current;
                                }
                            }) ());
                            $_current = $arguments->send(false);
                        }
                        if ($arguments->valid()) {
                            if (\is_integer($_current)) {
                                $_integers = [$_current];
                                while ($arguments->valid()) {
                                    $_current = $arguments->send(false);
                                    if (\is_null($_current)) break;
                                    Common::assert(\is_integer($_current));
                                    \array_push($_integers, $_current);
                                }
                            } elseif (\is_array($_current)) {
                                $_integers = \iterator_to_array((function () use ($_current) {
                                    foreach ($_current as $_current) {
                                        Common::assert(\is_integer($_current));
                                        yield $_current;
                                    }
                                }) ());
                                $_current = $arguments->send(false);
                            }
                            else {
                                Common::assert(\is_null($_current));
                                $_current = $arguments->send(false);
                            }
                            Common::assert(! $arguments->valid());
                        }
                        Common::assert(\is_null($_current));
                    }

                    finally {
                        if ($arguments->valid()) {
                            $arguments->send(true);
                            Common::assert(! $arguments->valid());
                        }
                    }

                    return [$_strings, $_integers];
                };

                $_validator = function (mixed ...$arguments) use ($_demix, $_validators) {
                    list($_command, $_descriptors) = $_demix($arguments);
                    return [
                        ($_validators->command)($_command),
                        ($_validators->descriptors)($_descriptors)
                    ];
                };
                foreach ($_executors as $_key => $_value) yield $_key => (
                    fn (mixed ...$arguments) => $_value(...$_validator(...$arguments))
                );
            }) ());

            $_executors['preferred'] = \array_key_exists('proc', $_executors) ? $_executors['proc'] : $_executors['exec'];
            $_executors = (object)['direct' => (object)$_executors];

            $_script_generator = function (string $script) {
                Common::assert(\is_string($script));
                if (! \str_ends_with($script, "\n")) $script = $script . "\n";
                foreach(self::_with_temp_file() as $_path) {
                    $_resource = \fopen($_path, 'w');
                    Common::assert(\is_resource($_resource));
                    try {
                        Common::assert(\chmod($_path, 0400));
                        \clearstatcache(true, $_path);
                        $_expected = \strlen($script);
                        Common::assert(\is_integer($_expected));
                        Common::assert(0 < $_expected);
                        $_written = \fwrite($_resource, $script);
                        Common::assert(\is_integer($_written));
                        Common::assert($_expected === $_written);
                        Common::assert(\fsync($_resource));
                    }
                    finally { Common::assert(\fclose($_resource)); }
                    yield $_path;
                    break;
                }
            };

            $_executors->script = (function () use ($_executors, $_validators, $_script_generator) {
                $_executor = $_executors->direct->preferred;
                Common::assert(! \is_null($_executor));
                return function (array $keywords) use ($_validators, $_executor, $_script_generator) {
                    Common::assert(\is_array($keywords));
                    $_script = $keywords['script'];
                    Common::assert(\is_string('script'));
                    Common::assert('' !== $_script);
                    $_command = ($_validators->command)($keywords['interpreter']);
                    if (\array_key_exists('arguments', $keywords)) {
                        $_arguments = $keywords['arguments'];
                        Common::assert(\is_array($_arguments));
                    } else $_arguments = null;
                    $_descriptors = ($_validators->descriptors)($keywords['descriptors']);
                    foreach($_script_generator($_script) as $_script) {
                        \array_push($_command, $_script);
                        if (\is_array($_arguments)) \array_push($_command, ...$_arguments);
                        return $_executor($_command, $_descriptors);
                    }
                };
            }) ();

            $_wrapper = (function () use (
                $logger, $_executors, $_validators
            ) { foreach ((function () use ($logger) { if (\is_null($logger)) yield; else {
                $_collector = [];
                $_action = function (
                    string $message, ?array $context
                ) use (&$_collector) { \array_push($_collector, [$message, $context]); };
                try { yield $_action; } finally { foreach ($_collector as $_payload) $logger->warning(...$_payload); }
            }}) () as $_warnings) { return (function () use (
                $_executors, $_validators, $_warnings
            ) {
                $_sum_test = (function (\Closure $delegate) {
                    $_terms = \iterator_to_array((function () { foreach (\range(0, 7) as $_index) {
                        unset($_index);
                        $_value = \rand(24, 42);
                        Common::assert(\is_integer($_value));
                        Common::assert(24 <= $_value);
                        Common::assert(42 >= $_value);
                        yield $_value;
                    }}) ());
                    $_expected = \array_sum($_terms);
                    Common::assert(\is_integer($_expected));
                    Common::assert((8 * 24) <= $_expected);
                    Common::assert((8 * 42) >= $_expected);
                    $delegate = $delegate($_terms, $_expected);
                    if (\is_null($delegate)) return false;
                    list($delegate, $_output) = $delegate;
                    Common::assert(\is_integer($delegate));
                    Common::assert(0 === $delegate);
                    Common::assert(\is_array($_output));
                    Common::assert([1] === \array_keys($_output));
                    $_output = self::_ensure_process_output($_output[1], true);
                    if (! \is_string($_output)) return false;
                    $_output = \substr($_output, 0, -1);
                    if (! \is_string($_output)) return false;
                    if (1 !== \preg_match('/^[0-9]+$/', $_output)) return false;
                    $_output = \intval($_output);
                    Common::assert(\is_integer($_output));
                    return $_expected === $_output;
                });

                $_resolve = (function () use ($_executors, $_sum_test) {
                    $_script = 'set -e; exec <&- 2>&-; (readlink -e "/proc/$$/exe")';

                    $_sum_test = (function () use (
                        $_executors, $_sum_test
                    ) {
                        $_executor = $_executors->script;
                        Common::assert(! \is_null($_executor));
                        return function (string $path) use (
                            $_executor, $_sum_test
                        ) { return $_sum_test(function (
                            array $terms, int $expected
                        ) use ($path, $_executor) { return $_executor([
                            'script' => \implode("\n", [
                                '_sum=$((' . \implode('+', $terms). '))',
                                'test "' . $expected . '" -eq "${_sum}"',
                                'echo "${_sum}"'
                            ]),
                            'interpreter' => [$path, '-e', '--'],
                            'descriptors' => [1]
                        ]); }); };
                    }) ();

                    $_executors = $_executors->direct;
                    Common::assert(! \is_null($_executors));

                    return function (?string $name) use ($_executors, $_script, $_sum_test) {
                        if (\is_string($name)) {
                            Common::assert('' !== $name);
                            $_executor = $_executors->preferred;
                            Common::assert(! \is_null($_executor));
                        }
                        else {
                            Common::assert(\is_null($name));
                            $_executor = $_executors->exec;
                            Common::assert(! \is_null($_executor));
                            $name = '/proc/self/exe';
                        }
                        list($_exit_code, $_path) = $_executor([$name, '-e', '-c', $_script], [1]);
                        Common::assert(0 === $_exit_code);
                        $_path = \substr(self::_ensure_process_output($_path[1], false), 0, -1);
                        Common::assert($_path === Common::ensure_path($_path, [
                            'gentle' => false, 'resolve' => true,
                            'may_root' => false, 'may_relative' => false
                        ]));
                        Common::assert(\is_file($_path));
                        Common::assert(\is_executable($_path));
                        Common::assert($_sum_test($_path));
                        $_executor = $_executors->preferred;
                        Common::assert(! \is_null($_executor));
                        list($_exit_code, $_output) = $_executor([$_path, '-e', '-c', \implode('; ', [
                            'exec <&- >&- 2>&-',
                            'exec </dev/null >/dev/null 2>/dev/null',
                            'exit 42'
                        ])], [1]);
                        Common::assert(42 === $_exit_code);
                        Common::assert('' === $_output[1]);
                        return $_path;
                    };
                }) ();

                list($_default, $_bash) = (function () use ($_resolve, $_warnings) {
                    foreach ([null, 'sh', 'bash'] as $_name) {
                        try { $_path = $_resolve($_name); } catch (\Throwable $exception) {
                            if (! \is_null($_warnings)) {
                                $_name = \is_null($_name) ? 'default' : '`' . $_name . '`';
                                $_warnings('failed to resolve ' . $_name . ' shell', ['exception' => $exception]);
                            }
                            continue;
                        }
                        return [$_path, ('bash' === $_name)];
                    }

                    throw new \RuntimeException('failed to resolve any shell');
                }) ();

                $_python = (function () use ($_executors, $_sum_test, $_validators) {
                    $_executor = $_executors->direct->preferred;
                    Common::assert(! \is_null($_executor));

                    list($_exit_code, $_path) = $_executor([
                        'python3', '-c', 'import sys; print(sys.executable, file = sys.stdout, flush = True)'
                    ], [1]);
                    if (0 !== $_exit_code) return null;
                    $_path = self::_ensure_process_output($_path[1], true);
                    if (! \is_string($_path)) return null;
                    $_path = \substr($_path, 0, -1);
                    if (! \is_string($_path)) return null;
                    $_path = Common::ensure_path($_path, [
                        'gentle' => true, 'resolve' => true,
                        'may_root' => false, 'may_relative' => false
                    ]);
                    if (! \is_string($_path)) return null;
                    if (! \is_file($_path)) return null;
                    if (! \is_executable($_path)) return null;

                    $_executor = $_executors->script;
                    Common::assert(! \is_null($_executor));

                    $_interpreter = function () use ($_path) { return [$_path, '--']; };

                    $_make_head = function (mixed ...$descriptors) use ($_validators) {
                        Common::assert(\is_array($descriptors));
                        if (empty($descriptors)) $descriptors = null;
                        else $descriptors = \iterator_to_array((function () use ($descriptors) {
                            $_next = $_begin = true;
                            foreach ($descriptors as $_item) {
                                Common::assert($_next);
                                if (! \is_integer($_item)) {
                                    Common::assert($_begin);
                                    $_next = false;
                                }
                                $_begin = false;
                                if ($_next) yield $_item;
                                elseif (\is_array($_item)) yield from $_item;
                                else Common::assert(\is_null($_item));
                            }
                        }) (), false);
                        $descriptors = ($_validators->descriptors)($descriptors);
                        $descriptors = \iterator_to_array((function () use ($descriptors) {
                            foreach ([0 => 'stdin', 1 => 'stdout', 2 => 'stderr'] as $_descriptor => $_stream) {
                                if (\in_array($_descriptor, $descriptors, true)) continue;
                                yield $_stream;
                            }
                        }) ());
                        $descriptors = \implode(', ', \array_map(function (string $descriptor) { return 'sys.' . $descriptor; }, $descriptors));
                        return \implode("\n", [
                            'assert \'__main__\' == __name__',
                            'import os',
                            'import sys',
                            'for _stream in [' . $descriptors . ']:',
                            '  try: _descriptor = _stream.fileno()',
                            '  except ValueError: pass',
                            '  else:',
                            '    try:',
                            '      try: os.set_inheritable(_descriptor, False)',
                            '      finally: os.close(_descriptor)',
                            '    except OSError: pass',
                            '  finally: _stream.close()',
                            'def _descriptors():',
                            '  for _descriptor in os.listdir(f\'/proc/self/fd\'):',
                            '    _descriptor = int(_descriptor)',
                            '    if 2 < _descriptor: yield _descriptor',
                            '    assert 0 <= _descriptor',
                            'for _descriptor in tuple(_descriptors()):',
                            '  try:',
                            '    try: os.set_inheritable(_descriptor, False)',
                            '    finally: os.close(_descriptor)',
                            '  except OSError: pass'
                        ]);
                    };

                    list($_exit_code, $_path) = $_executor([
                        'script' => \implode("\n", [
                            $_make_head(1),
                            '_self = os.path.realpath(sys.executable)',
                            'assert _self', 'print(_self)'
                        ]),
                        'interpreter' => $_interpreter(),
                        'descriptors' => [1]
                    ]);
                    if (0 !== $_exit_code) return null;
                    $_path = self::_ensure_process_output($_path[1], true);
                    if (! \is_string($_path)) return null;
                    $_path = \substr($_path, 0, -1);
                    if (! \is_string($_path)) return null;
                    if (! \is_file($_path)) return null;
                    if (! \is_executable($_path)) return null;
                    if ($_path !== Common::ensure_path($_path, [
                        'gentle' => true, 'resolve' => true,
                        'may_root' => false, 'may_relative' => false
                    ])) return null;

                    $_interpreter = $_interpreter();

                    $_escape = function (array $value) use($_executor, $_make_head, $_interpreter) {
                        list($_exit_code, $_output) = $_executor([
                            'script' => \implode("\n", [
                                $_make_head(1),
                                'assert sys.argv[1]',
                                'import ast',
                                '_command = ast.parse(\'(0, )\')',
                                '_body, = _command.body',
                                '_body.value.elts = list(map(ast.Constant, sys.argv[1:]))',
                                'print(ast.unparse(_command))'
                            ]),
                            'interpreter' => $_interpreter,
                            'descriptors' => [1],
                            'arguments' => $value
                        ]);
                        if (0 !== $_exit_code) return null;
                        $_output = self::_ensure_process_output($_output[1], true);
                        if (! \is_string($_output)) return null;
                        return \substr($_output, 0, -1);
                    };

                    if (! $_sum_test(function (
                        array $terms, int $expected
                    ) use (
                        $_escape, $_executor, $_make_head, $_interpreter
                    ) {
                        Common::assert(! empty($terms));
                        $terms = $_escape(\array_map(function (int $term) { return '' . $term; }, $terms));
                        if (\is_null($terms)) return null;
                        return $_executor([
                            'script' => \implode("\n", [
                                $_make_head(1),
                                'def _s():',
                                '  for _s in ' . $terms . ': yield int(_s)',
                                '_s = sum(_s())',
                                'assert isinstance(_s, int)',
                                'assert _s == ' . $expected,
                                'print(_s, file = sys.stdout, flush = True)'
                            ]),
                            'interpreter' => $_interpreter,
                            'descriptors' => [1]
                        ]);
                    })) return null;

                    return function (array $target) use ($_escape, $_validators, $_interpreter, $_make_head) {
                        $target = $_escape(($_validators->command)($target));
                        return [$_interpreter, \implode("\n", [
                            $_make_head(1, 2),
                            '_target = *' . $target . ', *sys.argv[1:]',
                            'os.execv(_target[0], _target)',
                            'raise RuntimeError(\'exec failure\')'
                        ])];
                    };
                }) ();

                if (! \is_null($_python)) return $_python([$_default, '-e']);

                list($_boot, $_final, $_max_fd) = (function () use (
                    $_executors, $_default, $_bash, $_resolve, $_warnings
                ) {
                    $_fd42_test = (function () use ($_executors) {
                        $_message = 'fd/42 test passed';
                        $_executor = $_executors->script;
                        Common::assert(! \is_null($_executor));
                        return function (string $path) use ($_executor, $_message) {
                            Common::assert($path === Common::ensure_path($path, [
                                'gentle' => false, 'resolve' => true,
                                'may_root' => false, 'may_relative' => false
                            ]));
                            Common::assert(\is_file($path));
                            Common::assert(\is_executable($path));
                            list($_exit_code, $_output) = $_executor([
                                'script' => '/proc/self/exe -c \'echo "' . $_message . '" >&42\' 42>&1',
                                'interpreter' => [$path, '-e'], 'descriptors' => [1]]
                            );
                            if (0 !== $_exit_code) return false;
                            $_output = self::_ensure_process_output($_output[1], true);
                            if (! \is_string($_output)) return false;
                            $_output = \substr($_output, 0, -1);
                            if (! \is_string($_output)) return false;
                            return $_message === $_output;
                        };
                    }) ();
                    if ($_fd42_test($_default)) return [$_default, $_default, null];
                    Common::assert(! $_bash, 'multidigit (over 9) fd control is required for bash');
                    try { $_bash = $_resolve('bash'); } catch (\Throwable $exception) {
                        if (! \is_null($_warnings)) $_warnings(
                            'failed to resolve `bash` shell, default shell doesn\'t support multidigit (over 9) fd control',
                            ['exception' => $exception]
                        );
                        return [$_default, $_default, 9];
                    }
                    Common::assert(
                        ($_default !== $_bash) && $_fd42_test($_bash),
                        'multidigit (over 9) fd control is required for bash'
                    );
                    return [$_bash, $_default, null];
                }) ();

                return [[$_boot, '-e'], \implode("\n", \iterator_to_array((function () use (
                    $_max_fd, $_final
                ) {
                    yield from [
                        '_asterisk=\'\'',
                        '_descriptors=\'\'',
                        '_proc_content="$$"',
                        'test 0 -lt "${_proc_content}"',
                        '_proc_content=`cd "/proc/${_proc_content}/fd" && echo *`',
                        'test -n "${_proc_content}"',
                        'for _descriptor in `echo "${_proc_content}"`; do',
                        '  test -n "${_descriptor}"',
                        '  test -z "${_asterisk}" || test -z "${_descriptors}"',
                        '  if test ".${_descriptor}" = \'.*\'; then',
                        '    _asterisk=\'*\'',
                        '    continue',
                        '  fi'
                    ];
                    if (! \is_null($_max_fd)) {
                        Common::assert(\is_integer($_max_fd));
                        Common::assert(2 < $_max_fd);
                        yield '  if test ' . $_max_fd . ' -lt "${_descriptor}"; then continue; fi';
                    }
                    yield from [
                        '  if test 0 -eq "${_descriptor}"; then',
                        '    _descriptors="${_descriptors} <&-"',
                        '    continue',
                        '  fi',
                        '  if test 2 -lt "${_descriptor}"; then',
                        '    _descriptors="${_descriptors} ${_descriptor}>&-"',
                        '    continue',
                        '  fi',
                        '  if test 0 -lt "${_descriptor}"; then continue; fi',
                        '  exit 1',
                        'done',
                        'if test -n "${_descriptors}"; then',
                        '  test -z "${_asterisk}"',
                        '  eval "exec ${_descriptors}"',
                        'fi',
                        'exec </dev/null ' . \escapeshellarg($_final) . ' -e "$@"'
                    ];
                }) (), false))];
            }) (); }}) ();

            $_wrapper = (function() use ($_wrapper, $_validators) {
                Common::assert(\is_array($_wrapper));
                Common::assert(! empty($_wrapper));
                $_script = \array_pop($_wrapper);
                Common::assert(\is_string($_script));
                Common::assert('' !== $_script);
                Common::assert(! empty($_wrapper));
                $_interpreter = \array_pop($_wrapper);
                Common::assert(empty($_wrapper));
                Common::assert(\is_array($_interpreter));
                Common::assert(! empty($_interpreter));
                $_interpreter = ($_validators->command)($_interpreter);
                return (object)['interpreter' => $_interpreter, 'script' => $_script];
            }) ();

            $_executor = (function () use ($_executors) {
                $_executors = $_executors->direct;
                Common::assert(! \is_null($_executors));
                $_executor = $_executors->preferred;
                Common::assert(! \is_null($_executor));
                return function (mixed ...$arguments) use ($_executor) {
                    list($_code, $_output) = $_executor(...$arguments);
                    return (object)['code' => $_code, 'output' => $_output];
                };
            }) ();

            return function () use (
                $_script_generator, $_wrapper, $_executor
            ) { foreach (
                $_script_generator($_wrapper->script) as $_entry_point
            ) {
                $_entry_point = [...($_wrapper->interpreter), $_entry_point];

                $_make_command = function (string $body) use ($_entry_point) {
                    Common::assert('' !== $body);
                    return [...$_entry_point, '-c', $body];
                };

                $_with_script = function (string $body) use (
                    $_script_generator, $_entry_point
                ) {
                    Common::assert('' !== $body);
                    foreach ($_script_generator($body) as $body) {
                        yield [...$_entry_point, $body];
                        break;
                    }
                };

                yield (object)[
                    'make_command' => $_make_command,
                    'with_script' => $_with_script,
                    'executors' => (object)[
                        'command' => fn (string $body, mixed ...$descriptors) => $_executor(
                            $_make_command($body), ...$descriptors
                        ),
                        'script' => function (string $body, mixed ...$descriptors) use(
                            $_with_script, $_executor
                        ) { foreach ($_with_script($body) as $body) {
                            return $_executor($body, ...$descriptors);
                        }}
                    ]
                ];

                break;
            }};
        }) ()]; } catch (\Throwable $exception) { $_cache = ['exception' => $exception]; throw $exception; }

        if (\array_key_exists('exception', $_cache)) throw $_cache['exception'];
        Common::assert(\array_key_exists('factory', $_cache));
        yield from $_cache['factory']();
    }

    private static function _ensure_process_output(string $value, bool $gentle = false): ?string {
        Common::assert(\is_bool($gentle));

        $_assert = (function () use ($gentle) {
            if ($gentle) return fn (bool $condition) => (! $condition);
            return function (bool $condition) { Common::assert($condition); return false; };
        }) ();

        if ($_assert('' !== $value)) return null;
        $_counter = 0;
        foreach (\explode("\n", $value) as $_line) if ($_assert(3 > ++$_counter)) return null;
        if ($_assert('' === $_line)) return null;
        if ($_assert(2 === $_counter)) return null;

        return $value;
    }

    private static function _strict_popen_descriptors(?array $descriptors = null, $common = null): array {
        if (\is_null($common)) $common = ['file', '/dev/null', 'r'];
        else Common::assert(\is_array($common) || is_resource($common));

        return (function ($descriptors) use ($common) {
            $_collector = [];
            foreach (self::_get_all_opened_descriptors() as $_key) $_collector[$_key] = $descriptors[$_key] ?? $common;
            return $_collector;
        }) ((function () use ($descriptors): array {
            $_collector = [];

            if (\is_array($descriptors)) foreach ($descriptors as $_key => $_value) {
                Common::assert(\is_integer($_key));
                Common::assert(0 <= $_key);
                Common::assert(! \array_key_exists($_key, $_collector));
                Common::assert(\is_array($_value) || is_resource($_value));
                Common::assert(! empty($_value));
                $_collector[$_key] = $_value;
            }

            else Common::assert(\is_null($descriptors));

            return $_collector;
        }) ());
    }

    private static function _get_all_opened_descriptors(): array {
        static $_directory = '/proc/self/fd/';

        $_keys = \scandir($_directory, \SCANDIR_SORT_NONE);
        Common::assert(\is_array($_keys));

        $_mask = 0;
        $_descriptors = [];

        static $_flags = ['.' => 1, '..' => 2];

        foreach($_keys as $_key) {
            Common::assert(\is_string($_key));
            Common::assert('' !== $_key);

            $_flag = $_flags[$_key] ?? null;
            if (\is_integer($_flag)) {
                Common::assert(0 === ($_flag & $_mask), $_key);
                $_mask = $_mask | $_flag;
                continue;
            }

            Common::assert(1 === \preg_match('/^[0-9]+$/', $_key), $_key);
            $_descriptor = \intval($_key);
            Common::assert(\is_integer($_descriptor), $_key);
            Common::assert(0 <= $_descriptor, $_key);
            Common::assert(! \in_array($_descriptor, $_descriptors, true), $_key);

            \array_push($_descriptors, $_descriptor);
        }

        Common::assert(3 === $_mask);
        return $_descriptors;
    }

    private static function _with_temp_file(): \Generator {
        $_path = Common::ensure_directory_path(\sys_get_temp_dir(), [
            'gentle' => false, 'resolve' => true, 'create' => false,
            'may_root' => false, 'may_relative' => false
        ]);

        $_enter_pid = \getmypid();
        Common::assert(\is_integer($_enter_pid));
        Common::assert(0 < $_enter_pid);

        static $_prefix = null;
        if (\is_null($_prefix)) $_prefix = 'nextcloud.' . \implode('.', \explode('\\', ltrim(self::class, '\\'))) . '.';

        $_path = \tempnam($_path, $_prefix);
        Common::assert($_path === Common::ensure_path($_path, [
            'gentle' => false, 'resolve' => true,
            'may_root' => false, 'may_relative' => false
        ]));
        Common::assert(\is_file($_path));

        try { yield $_path; } finally {
            $_exit_pid = \getmypid();
            Common::assert(\is_integer($_exit_pid));
            Common::assert($_enter_pid === $_exit_pid);
            Common::assert(true === \unlink($_path));
        }
    }

    private static function _make_magic(): string {
        static $_prefix = null;
        if (\is_null($_prefix)) $_prefix = (function () {
            $_executable = Common::ensure_path('/proc/self/exe', [
                'gentle' => false, 'resolve' => true,
                'may_root' => false, 'may_relative' => false
            ]);
            Common::assert(\is_file($_executable));
            Common::assert(\is_executable($_executable));
            $_class = self::class;
            Common::assert(\is_string($_class));
            Common::assert('' !== $_class);
            $_pid = \getmypid();
            Common::assert(\is_integer($_pid));
            Common::assert(0 < $_pid);
            return \implode(':', [$_executable, 'nextcloud', $_class, $_pid]);
        }) ();
        static $_counter = 0;
        return \implode(':', [$_prefix, ++$_counter, \rand()]);
    }
}
