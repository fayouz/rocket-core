<?php

namespace Rocket\Core\Tests\Functional;

use Rocket\Core\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThemeTest extends WebTestCase
{
    use ApiTestTrait;

    private function admin(): string
    {
        return 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
    }

    public function testAdminsManagePalettes(): void
    {
        $admin = $this->admin();
        $palette = $this->api('POST', '/api/color_palettes', ['name' => 'Acme', 'primary' => '#4F46E5', 'success' => '#16a34a', 'error' => '', 'neutral' => 'slate'], $admin);
        $this->assertStatus(201);
        self::assertSame(['#4f46e5', '#16a34a', null, 'slate'], [$palette['primary'], $palette['success'], $palette['error'], $palette['neutral']]);
        self::assertSame('admin@example.org', $palette['createdBy']);

        $this->api('POST', '/api/color_palettes', ['name' => 'Bad', 'primary' => 'blue'], $admin);
        $this->assertStatus(422);
        $this->api('PATCH', '/api/color_palettes/'.$palette['id'], ['neutral' => 'purple'], $admin);
        $this->assertStatus(422);

        $user = 'Bearer '.$this->jwtFor($this->createUser('user@example.org'));
        $this->api('GET', '/api/color_palettes', authorization: $user);
        $this->assertStatus(403);
        $this->api('GET', '/api/theme/project', authorization: $user);
        $this->assertStatus(403);
        $this->api('PUT', '/api/theme/project', ['palette' => '/api/color_palettes/'.$palette['id']], $user);
        $this->assertStatus(403);
        $this->api('PUT', '/api/theme/project', ['palette' => '/api/color_palettes/'.$palette['id']]);
        $this->assertStatus(401);
    }

    public function testProjectPaletteIsTheDefaultOfApplications(): void
    {
        $admin = $this->admin();
        // No palette: the brick's default colors, readable without a session (login page).
        self::assertSame(['source' => 'default', 'palette' => null], $this->api('GET', '/api/theme'));
        self::assertSame(['palette' => null], $this->api('GET', '/api/theme/project', authorization: $admin));

        $project = $this->api('POST', '/api/color_palettes', ['name' => 'Projet', 'primary' => '#0ea5e9'], $admin);
        $settings = $this->api('PUT', '/api/theme/project', ['palette' => '/api/color_palettes/'.$project['id']], $admin);
        self::assertSame('Projet', $settings['palette']['name']);
        $this->api('PUT', '/api/theme/project', ['palette' => '/api/color_palettes/00000000-0000-7000-8000-000000000000'], $admin);
        $this->assertStatus(422);
        $this->api('PUT', '/api/theme/project', ['palette' => 'not-an-id'], $admin);
        $this->assertStatus(422);

        $theme = $this->api('GET', '/api/theme');
        self::assertSame('project', $theme['source']);
        self::assertSame(['primary' => '#0ea5e9'], $theme['palette']['colors']);
        self::assertSame('zinc', $theme['palette']['neutral']);

        // An application without palette uses the project's one; with one, its own.
        [$crm, $token] = $this->createApplication();
        self::assertNull($this->api('GET', '/api/applications/'.$crm->getId(), authorization: $admin)['palette'] ?? null);
        self::assertSame('project', $this->api('GET', '/api/theme?app='.$crm->getId())['source']);
        $own = $this->api('POST', '/api/color_palettes', ['name' => 'CRM', 'primary' => '#db2777', 'neutral' => 'stone'], $admin);
        $app = $this->api('PATCH', '/api/applications/'.$crm->getId(), ['palette' => '/api/color_palettes/'.$own['id']], $admin);
        self::assertSame(['@id' => '/api/color_palettes/'.$own['id'], 'id' => $own['id'], 'name' => 'CRM'], $app['palette']);
        $theme = $this->api('GET', '/api/theme?app='.$crm->getId());
        self::assertSame(['application', '#db2777', 'stone'], [$theme['source'], $theme['palette']['colors']['primary'], $theme['palette']['neutral']]);
        // A disabled application, or an unknown one: the project's palette.
        self::assertSame('project', $this->api('GET', '/api/theme?app=nope')['source']);

        // The embedded pages may read it.
        $embed = 'Embed '.$this->api('POST', '/api/embed/token', authorization: 'Bearer '.$token, headers: ['X-Impersonate-User' => 'admin@example.org'])['token'];
        self::assertSame('application', $this->api('GET', '/api/theme?app='.$crm->getId(), authorization: $embed)['source']);

        $this->api('PATCH', '/api/applications/'.$crm->getId(), ['enabled' => false], $admin);
        self::assertSame('project', $this->api('GET', '/api/theme?app='.$crm->getId())['source']);
        $this->api('PATCH', '/api/applications/'.$crm->getId(), ['enabled' => true], $admin);

        // Deleting palettes: the application falls back to the project, the project to the default colors.
        $this->api('DELETE', '/api/color_palettes/'.$own['id'], authorization: $admin);
        $this->assertStatus(204);
        self::assertSame('project', $this->api('GET', '/api/theme?app='.$crm->getId())['source']);
        $this->api('DELETE', '/api/color_palettes/'.$project['id'], authorization: $admin);
        self::assertSame('default', $this->api('GET', '/api/theme')['source']);
        self::assertNull($this->api('GET', '/api/theme/project', authorization: $admin)['palette']);

        // Back to the default colors explicitly.
        $again = $this->api('POST', '/api/color_palettes', ['name' => 'Encore', 'primary' => '#111111'], $admin);
        $this->api('PUT', '/api/theme/project', ['palette' => $again['id']], $admin);
        self::assertSame('project', $this->api('GET', '/api/theme')['source']);
        self::assertSame(['palette' => null], $this->api('PUT', '/api/theme/project', ['palette' => ''], $admin));
        self::assertSame('default', $this->api('GET', '/api/theme')['source']);
    }
}
