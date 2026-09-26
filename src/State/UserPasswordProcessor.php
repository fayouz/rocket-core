<?php

namespace Rocket\Core\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Rocket\Core\Entity\User;
use ApiPlatform\Metadata\Post;
use Rocket\Core\Enum\UserSource;
use Rocket\Core\Suite\SuiteSettings;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** @implements ProcessorInterface<User, User> */
final class UserPasswordProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly SuiteSettings $suite,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        if ($this->suite->isSuite() && $operation instanceof Post) {
            throw new ConflictHttpException('Accounts are managed in Rocket Auth: people get an account here when they first sign in.');
        }
        if (null !== $data->getPlainPassword()) {
            if (UserSource::Ldap === $data->getSource()) {
                throw new UnprocessableEntityHttpException('Directory (LDAP) users authenticate against the directory; their password cannot be set here.');
            }
            $data->setPassword($this->hasher->hashPassword($data, $data->getPlainPassword()));
            $data->setPlainPassword(null);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
