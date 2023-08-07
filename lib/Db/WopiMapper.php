<?php
/**
 * @copyright 2018, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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

namespace OCA\Richdocuments\Db;

use \OCP\Security\ISecureRandom;
use \OCA\Richdocuments\Exceptions\ExpiredTokenException;
use \OCA\Richdocuments\Exceptions\UnknownTokenException;

/** @template-extends \OCP\AppFramework\Db\QBMapper<Wopi> */
class WopiMapper extends \OCP\AppFramework\Db\QBMapper {
    public function __construct(
        \OCP\IDBConnection $dbConnection,
        private readonly string $appName,
        private readonly ISecureRandom $random,
        private readonly \OCP\AppFramework\Utility\ITimeFactory $timeFactory,
        private readonly \OCA\Richdocuments\Config\Application $config,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
        parent::__construct($dbConnection, $appName . '_wopi', Wopi::class);
    }

    /**
     * @param int $fileId
     * @param string $owner
     * @param string $editor
     * @param int $version
     * @param bool $updatable
     * @param string $serverHost
     * @param string $guestDisplayname
     * @param int $templateDestination
     * @return Wopi
     */
    public function generateFileToken(
        $fileId, $owner, $editor, $version, $updatable, $serverHost,
        $guestDisplayname = null, $templateDestination = 0,
        $hideDownload = false, $direct = false, $templateId = 0, $share = null
    ) { return $this->insert(Wopi::fromParams([
        'fileid' => $fileId,
        'ownerUid' => $owner,
        'editorUid' => $editor,
        'version' => $version,
        'canwrite' => $updatable,
        'serverHost' => $serverHost,
        'token' => $this->random->generate(32, \implode('', [
            ISecureRandom::CHAR_LOWER,
            ISecureRandom::CHAR_UPPER,
            ISecureRandom::CHAR_DIGITS
        ])),
        'expiry' => $this->calculateNewTokenExpiry(),
        'guestDisplayname' => $guestDisplayname,
        'templateDestination' => $templateDestination,
        'hideDownload' => $hideDownload,
        'direct' => $direct,
        'templateId' => $templateId,
        'remoteServer' => '',
        'remoteServerToken' => '',
        'share' => $share,
        'tokenType' => $guestDisplayname === null ? Wopi::TOKEN_TYPE_USER : Wopi::TOKEN_TYPE_GUEST
    ])); }

    public function generateInitiatorToken($uid, $remoteServer) { return $this->insert(Wopi::fromParams([
        'fileid' => 0,
        'editorUid' => $uid,
        'token' => $this->random->generate(32, \implode('', [
            ISecureRandom::CHAR_LOWER,
            ISecureRandom::CHAR_UPPER,
            ISecureRandom::CHAR_DIGITS
        ])),
        'expiry' => $this->calculateNewTokenExpiry(),
        'remoteServer' => $remoteServer,
        'tokenType' => Wopi::TOKEN_TYPE_INITIATOR
    ])); }

    /**
     *
     * @deprecated
     * @param $token
     * @return Wopi
     * @throws ExpiredTokenException
     * @throws UnknownTokenException
     */
    public function getPathForToken($token) {
        return $this->getWopiForToken($token);
    }

    /**
     * Given a token, validates it and
     * constructs and validates the path.
     * Returns the path, if valid, else false.
     *
     * @param string $token
     * @return Wopi
     * @throws UnknownTokenException
     * @throws ExpiredTokenException
     */
    public function getWopiForToken($token) {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->appName . '_wopi')->where(
            $qb->expr()->eq('token', $qb->createNamedParameter($token))
        );
        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();

        $this->logger->debug('Loaded WOPI Token record: ' . \json_encode($row) . '.');
        if (false === $row) throw new UnknownTokenException('Could not find token.');

        $wopi = Wopi::fromRow($row);

        if ($wopi->getExpiry() < $this->timeFactory->getTime()) throw new ExpiredTokenException(
            'Provided token is expired.'
        );

        return $wopi;
    }

    /**
     * Calculates the expiry TTL for a newly created token.
     *
     * @return int
     */
    private function calculateNewTokenExpiry(): int {
        return $this->timeFactory->getTime() + $this->config->get('token_ttl');
    }

    /**
     * @param int|null $limit
     * @param int|null $offset
     * @return int[]
     * @throws \OCP\DB\Exception
     */
    public function getExpiredTokenIds(?int $limit = null, ?int $offset = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->appName . '_wopi')
            ->where($qb->expr()->lt('expiry', $qb->createNamedParameter(
                time() - 60, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT
            )))
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return \array_column($qb->executeQuery()->fetchAll(), 'id');
    }
}
