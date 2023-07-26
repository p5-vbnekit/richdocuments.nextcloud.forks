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

class CodeApplicationInfo {
    public static function name(): ?string {
        static $_result = false;

        if (false === $_result) $_result = (static function () {
            if ('Linux' !== (70200 <= \PHP_VERSION_ID ? \PHP_OS_FAMILY : \PHP_OS)) return null;
            $_value = 'richdocumentscode';
            $_mapping = ['x86_64' => $_value, 'aarch64' => $_value = '_arm64'];
            $_value = \php_uname('m');
            if (! \array_key_exists($_value, $_mapping)) return null;
            return $_mapping[$_value];
        }) ();

        return $_result;
    }
};
