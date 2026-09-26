<?php

namespace Rocket\Core\Controller;

use Rocket\Core\Entity\Application;
use Rocket\Core\Repository\ApplicationRepository;
use Rocket\Core\Security\Roles;
use Rocket\Core\Theme\ProjectTheme;
use Rocket\Core\Theme\ProjectThemeInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class ThemeController extends AbstractController
{
    public function __construct(private readonly ProjectTheme $theme)
    {
    }

    /**
     * Public (the login page and the embedded pages need it before any session; the application's security.yaml
     * makes ^/api/theme$ public): the palette to apply, the application's one with ?app=<id>, else the project's one.
     * Colors are not secret.
     */
    #[Route('/api/theme', name: 'api_theme', methods: ['GET'])]
    public function __invoke(ApplicationRepository $applications, #[MapQueryParameter] string $app = ''): JsonResponse
    {
        $application = Uuid::isValid($app) ? $applications->find($app) : null;

        // Not cached: a palette change shows at the next page load.
        return $this->json($this->theme->for($application instanceof Application && $application->isEnabled() ? $application : null));
    }

    #[Route('/api/theme/project', name: 'api_theme_project', methods: ['GET'])]
    #[IsGranted(Roles::ADMIN)]
    public function project(): JsonResponse
    {
        return $this->json(['palette' => $this->theme->projectPalette()?->toTheme()]);
    }

    #[Route('/api/theme/project', name: 'api_theme_project_update', methods: ['PUT'])]
    #[IsGranted(Roles::ADMIN)]
    public function updateProject(EntityManagerInterface $em, #[MapRequestPayload] ProjectThemeInput $input = new ProjectThemeInput()): JsonResponse
    {
        try {
            $this->theme->setProjectPalette($this->theme->resolve($input->palette ?? ''));
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
        $em->flush();

        return $this->project();
    }
}
