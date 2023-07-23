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

namespace OCA\Richdocuments\WOPI;

class Parser {
    /** @var DiscoveryManager */
    private $discoveryManager;

    /** @var UrlMagic */
    private $urlMagic;

    /**
     * @param DiscoveryManager $discoveryManager
     */
    public function __construct(
        DiscoveryManager $discoveryManager,
        UrlMagic $urlMagic
    ) {
        $this->discoveryManager = $discoveryManager;
        $this->urlMagic = $urlMagic;
    }

    /**
     * @param $mimetype
     * @return array
     * @throws \Exception
     */
    public function getUrlSrc($mimetype) {
        $discovery = $this->discoveryManager->get();
        if (\PHP_VERSION_ID < 80000) {
            $loadEntities = libxml_disable_entity_loader(true);
            $parsed = simplexml_load_string($discovery);
            libxml_disable_entity_loader($loadEntities);
        } else {
            $parsed = simplexml_load_string($discovery);
        }

        $parsed = $parsed->xpath(sprintf('/wopi-discovery/net-zone/app[@name=\'%s\']/action', $mimetype));
        if (! (is_array($parsed) && (0 < count($parsed)))) throw new \Exception('Could not find urlsrc in WOPI');
        $url = (string)$parsed[0]['urlsrc'];
        $action = (string)$parsed[0]['name'];

        if ('' === $url) throw new \Exception('Could not parse discovery response: "urlsrc" is empty');
        if ('' === $action) throw new \Exception('Could not parse discovery response: "name" is empty');

        $url = (function ($context, $url) {
            try {
                $parts = parse_url($url);
                if (! is_array($parts)) throw new \InvalidArgumentException('invalid url');

                if (! (function($parts) {
                    if (! array_key_exists('scheme', $parts)) return false;
                    if (! is_string($parts['scheme'])) return false;
                    if (! in_array($parts['scheme'], ['http', 'https'])) return false;
                    return true;
                }) ($parts)) throw new \InvalidArgumentException('invalid scheme');

                if (! (function($parts) {
                    if (! array_key_exists('user', $parts)) return true;
                    if (! is_string($parts['user'])) return false;
                    if ('' === $parts['user']) return false;
                    return true;
                }) ($parts)) throw new \InvalidArgumentException('user');

                if (! (function($parts) {
                    if (! array_key_exists('pass', $parts)) return true;
                    if (! is_string($parts['pass'])) return false;
                    if ('' === $parts['pass']) return false;
                    return true;
                }) ($parts)) throw new \InvalidArgumentException('password');

                if (! (function($parts) {
                    if (! array_key_exists('host', $parts)) return false;
                    if (! is_string($parts['host'])) return false;
                    if ('' === $parts['host']) return false;
                    return true;
                }) ($parts)) throw new \InvalidArgumentException('host');

                if (! (function($parts) {
                    if (! array_key_exists('port', $parts)) return true;
                    if (! is_integer($parts['port'])) return false;
                    if (! (0 < $parts['port']) && (65536 > $parts['port'])) return false;
                    return true;
                }) ($parts)) throw new \InvalidArgumentException('port');

                if (! (function($parts) {
                    if (! array_key_exists('path', $parts)) return true;
                    if (! is_string($parts['path'])) return false;
                    if (! str_starts_with($parts['path'], '/')) return false;
                    return true;
                }) ($parts)) throw new \InvalidArgumentException('path');

                if (! (function($parts) {
                    if (! array_key_exists('query', $parts)) return true;
                    if (! is_string($parts['query'])) return false;
                    if ('' === $parts['query']) return false;
                    return true;
                }) ($parts)) throw new \InvalidArgumentException('query');

                if (! (function($parts) {
                    if (! array_key_exists('fragment', $parts)) return true;
                    if (! is_string($parts['fragment'])) return false;
                    if ('' === $parts['fragment']) return false;
                    return true;
                }) ($parts)) throw new \InvalidArgumentException('fragment');

                if ($context->urlMagic->is_code_proxy()) {
                    $proxy = parse_url($context->urlMagic->external());
                    if (! array_key_exists('scheme', $proxy)) throw new \InvalidArgumentException('CODE proxy url scheme not set');
                    
                    $url = $proxy['scheme'] . '://';
                    if (array_key_exists('user', $proxy)) {
                        $url = $url . $proxy['user'];
                        if (array_key_exists('pass', $proxy)) $url = $url . ':' . $proxy['pass'];
                        $url = $url . '@';
                    }
    
                    $url = $url . $proxy['host'];
                    if (array_key_exists('port', $proxy)) $url = $url . ':' .$proxy['port'];
    
                    if (! (array_key_exists('path', $parts) && ($parts['path'] === $proxy['path'])) ) throw new \InvalidArgumentException('path');
                    $url = $url . $proxy['path'];
    
                    if (! (array_key_exists('query', $parts) && str_starts_with($parts['query'], $proxy['query']))) throw new \InvalidArgumentException('query');
                    $url = $url . '?' . $parts['query'];
    
                    if (array_key_exists('fragment', $parts)) throw new \UnexpectedValueException('fragment');
                }
            }
            catch(\Exception) { throw new \UnexpectedValueException('Could not parse discovery response: invalid "urlsrc"'); }

            return $url;
        }) ($this, $url);

        return ['urlsrc' => $url, 'action' => $action];
    }
}
