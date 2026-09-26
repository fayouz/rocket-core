<?php

namespace Rocket\Core\Security;

use Rocket\Core\Entity\Application;
use Rocket\Core\Suite\SuiteAccessTokens;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * Application secrets and access tokens of Rocket Auth (suite mode) share the "Bearer" scheme with user JWTs: keep
 * Lexik from trying to decode them (ApplicationTokenAuthenticator handles them).
 */
#[AsDecorator('lexik_jwt_authentication.extractor.chain_extractor')]
final class JwtTokenExtractor implements TokenExtractorInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly TokenExtractorInterface $inner,
        private readonly SuiteAccessTokens $suiteTokens,
    ) {
    }

    public function extract(Request $request): string|false
    {
        $token = $this->inner->extract($request);

        return \is_string($token) && (str_starts_with($token, Application::tokenPrefix()) || $this->suiteTokens->isCandidate($token)) ? false : $token;
    }
}
