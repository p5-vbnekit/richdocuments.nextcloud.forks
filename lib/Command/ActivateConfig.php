<?php
/**
 * @copyright Copyright (c) 2019 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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

namespace OCA\Richdocuments\Command;

class ActivateConfig extends \Symfony\Component\Console\Command\Command {
    public function __construct(
        private readonly string $appName,
        private readonly \OCA\Richdocuments\WOPI\Parser $wopiParser,
        private readonly \OCA\Richdocuments\WOPI\DiscoveryManager $discoveryManager,
        private readonly \OCA\Richdocuments\Config\Application $config,
        private readonly \OCA\Richdocuments\Service\CapabilitiesService $capabilitiesService
    ) {
        parent::__construct();
    }

    protected function configure() {
        $this->setName($this->appName . ':activate-config');
        $this->setDescription('Activate config changes');
    }

    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ) {
        try {
            if (\in_array(
                'public_wopi_url', $this->config->keys(), true
            )) $this->config->remove('public_wopi_url');

            $this->discoveryManager->clear();
            $this->capabilitiesService->clear();
            $this->wopiParser->getUrlSrc('Capabilities');
            $this->capabilitiesService->clear();
            $this->capabilitiesService->refetch();
            $output->writeln('<info>Activated any config changes</info>');
            return 0;
        }

        catch (\Exception $exception) {
            $output->writeln('<error>Failed to activate any config changes</error>');
            $output->writeln($exception->getMessage());
            $output->writeln($exception->getTraceAsString());
        }

        return 1;
    }
}
