<?php

declare(strict_types = 1);

namespace OCA\Richdocuments\Controller;

use \OCP\AppFramework\Http;

trait DocumentTrait {
    protected $appName;
    private readonly \OCP\EventDispatcher\IEventDispatcher $eventDispatcher;
    private readonly \OCA\Richdocuments\WOPI\EndpointResolver $endpointResolver;
    private readonly \OCA\Richdocuments\Service\InitialStateService $initialStateService;

    private function documentTemplateResponse(
        \OCA\Richdocuments\Db\Wopi $wopi, array $params
    ): Http\TemplateResponse {
        $this->eventDispatcher->dispatchTyped(
            new \OCP\Collaboration\Reference\RenderReferenceEvent()
        );
        $this->initialStateService->provideDocument($wopi, $params);
        $response = new Http\TemplateResponse($this->appName, 'documents', $params, 'base');
        $this->applyPolicies($response);
        return $response;
    }

    /**
     * Setup policy headers for the response
     */
    private function applyPolicies($response): void {
        // FIXME We can skip inline source once templates/documents.php is migrated to IInitialState
        $response->setContentSecurityPolicy((function () {
            $policy = new Http\ContentSecurityPolicy();
            $policy->allowInlineScript(true);
            return $policy;
        }) ());

        $response->setFeaturePolicy((function () {
            $policy = new Http\FeaturePolicy();
            $url = $this->endpointResolver->external();
            if (\is_string($url)) $policy->addAllowedFullScreenDomain($url);
            return $policy;
        }) ());

        $response->addHeader('X-Frame-Options', 'ALLOW');
    }

    /**
     * Strips the path and query parameters from the URL.
     *
     * @param string $url
     * @return string
     */
    private function domainOnly(string $url): string {
        $parsed_url = \parse_url($url);
        $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        return "$scheme$host$port";
    }
}
