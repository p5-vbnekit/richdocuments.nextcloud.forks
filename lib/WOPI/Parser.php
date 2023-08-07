<?php
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
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

declare(strict_types = 1);

namespace OCA\Richdocuments\WOPI;

class Parser {
    private DiscoveryManager $_discoveryManager;

    public function __construct(DiscoveryManager $discoveryManager) {
        $this->_discoveryManager = $discoveryManager;
    }

    public function getUrlSrc($mime): array {
        $_discovery = (function () {
            $_value = $this->_discoveryManager->get();

            if (80000 > \PHP_VERSION_ID) {
                $_load_entities = \libxml_disable_entity_loader(true);
                $_value = \simplexml_load_string($_value);
                \libxml_disable_entity_loader($_load_entities);
                return $_value;
            }

            return \simplexml_load_string($_value);
        }) ()->xpath(sprintf(
            '/wopi-discovery/net-zone/app[@name=\'%s\']/action', $mime
        ));

        if ($_discovery && (0 < count($_discovery))) return [
            'urlsrc' => (string)$_discovery[0]['urlsrc'],
            'action' => (string)$_discovery[0]['name']
        ];

        throw new \Exception('Could not find urlsrc in WOPI');
    }
}
