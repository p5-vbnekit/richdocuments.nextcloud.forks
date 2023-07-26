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

class ByPassResponse extends \OCP\AppFramework\Http\Response {
    private readonly string $_data;
    private readonly array $_headers;

    public function __construct(\OCP\Http\Client\IResponse $source) {
        parent::__construct();
        $this->_data = $source->getBody();
        $this->_headers = \iterator_to_array((function () use ($source) {
            foreach ($source->getHeaders() as $_key => $_payload) {
                Common::assert(\is_string($_key));
                Common::assert('' !== $_key);
                Common::assert(\is_array($_payload));
                Common::assert(! empty($_payload));
                $_value = \array_pop($_payload);
                Common::assert(empty($_payload));
                Common::assert(\is_string($_value));
                yield $_key => $_value;
            }
        }) ());
        parent::setStatus($source->getStatusCode());
        parent::setHeaders(\array_merge(parent::getHeaders(), $this->_headers));
    }

    public function getHeaders() { return \array_merge(parent::getHeaders(), $this->_headers); }

    public function render() { return $this->_data; }
};
