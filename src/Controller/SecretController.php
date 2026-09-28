<?php

namespace Rocket\Core\Controller;

use Rocket\Core\Entity\Secret;
use Rocket\Core\Repository\SecretRepository;
use Rocket\Core\Secrets\SecretsKeyMissingException;
use Rocket\Core\Secrets\SecretVault;
use Rocket\Core\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The secrets vault, managed by administrators: values are write-only, never returned. */
#[IsGranted(Roles::ADMIN)]
final class SecretController extends AbstractController
{
    public function __construct(
        private readonly SecretVault $vault,
        private readonly SecretRepository $secrets,
    ) {
    }

    /** Instance secrets (?scope= for an account's), with the state of the master key. */
    #[Route('/api/secrets', name: 'api_secrets', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $scope = $request->query->get('scope') ?: null;

        return $this->json([
            'configured' => $this->vault->isConfigured(),
            'error' => $this->vault->configurationError(),
            'secrets' => array_map(self::payload(...), $this->vault->list($scope)),
        ]);
    }

    /** { name, value, scope? } */
    #[Route('/api/secrets', name: 'api_secrets_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $request->toArray();
        $name = trim((string) ($body['name'] ?? ''));
        $scope = ($body['scope'] ?? null) ?: null;
        if ($this->vault->has($name, $scope)) {
            throw new ConflictHttpException(\sprintf('A secret named "%s" already exists.', $name));
        }

        return $this->json(self::payload($this->write($name, $body['value'] ?? null, $scope)), Response::HTTP_CREATED);
    }

    /** { value }: replaces the value. */
    #[Route('/api/secrets/{id}', name: 'api_secrets_update', methods: ['PUT'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $secret = $this->secrets->find($id) ?? throw new NotFoundHttpException('Unknown secret.');

        return $this->json(self::payload($this->write($secret->getName(), $request->toArray()['value'] ?? null, $secret->getScope())));
    }

    #[Route('/api/secrets/{id}', name: 'api_secrets_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function delete(string $id): Response
    {
        $secret = $this->secrets->find($id) ?? throw new NotFoundHttpException('Unknown secret.');
        $this->vault->delete($secret->getName(), $secret->getScope());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function write(string $name, mixed $value, ?string $scope): Secret
    {
        if (!\is_string($value) || '' === $value) {
            throw new UnprocessableEntityHttpException('The value of the secret is required.');
        }
        try {
            return $this->vault->set($name, $value, $scope);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        } catch (SecretsKeyMissingException $e) {
            throw new ServiceUnavailableHttpException(null, $e->getMessage(), $e);
        }
    }

    /** @return array<string, mixed> */
    private static function payload(Secret $secret): array
    {
        return [
            'id' => $secret->getId()->toRfc4122(),
            'name' => $secret->getName(),
            'scope' => $secret->getScope(),
            'masked' => $secret->getMasked(),
            'createdAt' => $secret->getCreatedAt()?->format(\DATE_ATOM),
            'updatedAt' => $secret->getUpdatedAt()?->format(\DATE_ATOM),
            'lastUsedAt' => $secret->getLastUsedAt()?->format(\DATE_ATOM),
            'createdBy' => $secret->getCreatedBy(),
            'updatedBy' => $secret->getUpdatedBy(),
        ];
    }
}
