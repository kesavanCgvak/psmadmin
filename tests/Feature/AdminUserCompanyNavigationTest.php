<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserCompanyNavigationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_details_company_name_links_back_to_the_same_user_page(): void
    {
        $company = Company::create([
            'name' => 'Nav Co '.uniqid(),
            'account_type' => 'provider',
        ]);
        $admin = $this->createAdmin();
        $member = User::create([
            'account_type' => 'provider',
            'username' => 'member_'.uniqid(),
            'email' => uniqid('member_', true).'@example.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'is_admin' => 0,
            'role' => 'user',
            'email_verified' => true,
        ]);

        $userPageQuery = [
            'from' => 'company',
            'company_id' => $company->id,
            'page' => 3,
            'search' => 'stage lights',
        ];

        $userResponse = $this->actingAs($admin)
            ->get(route('admin.users.show', $member).'?'.http_build_query($userPageQuery));

        $userResponse->assertOk();
        $userResponse->assertSee('>'.$company->name.'</a>', false);

        $companyUrl = $this->companyUrlFromUserPage($userResponse->getContent(), $company->name);
        $this->assertNotNull($companyUrl);

        $companyQuery = [];
        parse_str((string) parse_url($companyUrl, PHP_URL_QUERY), $companyQuery);
        $this->assertSame('user', $companyQuery['from'] ?? null);
        $this->assertSame((string) $member->id, (string) ($companyQuery['user_id'] ?? ''));

        $companyPath = parse_url($companyUrl, PHP_URL_PATH);
        $companyResponse = $this->actingAs($admin)->get($companyPath.'?'.http_build_query($companyQuery));
        $companyResponse->assertOk();

        $backHref = $this->backHref($companyResponse->getContent());
        $this->assertNotNull($backHref);

        $backQuery = [];
        parse_str((string) parse_url($backHref, PHP_URL_QUERY), $backQuery);
        $this->assertSame(parse_url(route('admin.users.show', $member), PHP_URL_PATH), parse_url($backHref, PHP_URL_PATH));
        $this->assertSame('company', $backQuery['from'] ?? null);
        $this->assertSame((string) $company->id, (string) ($backQuery['company_id'] ?? ''));
        $this->assertSame('3', (string) ($backQuery['page'] ?? ''));
        $this->assertSame('stage lights', $backQuery['search'] ?? null);

        $returnedUser = $this->actingAs($admin)->get($backHref);
        $returnedUser->assertOk();
        $returnedUser->assertSee('User Details: '.$member->username);
        $returnedUser->assertSee('Back to Company');
        $this->assertDoesNotMatchRegularExpression(
            '/<i class="fas fa-arrow-left"><\/i>\s*Back to (Users|List)/',
            $companyResponse->getContent()
        );
    }

    public function test_company_page_keeps_existing_back_links_for_other_entry_points(): void
    {
        $company = Company::create([
            'name' => 'List Co '.uniqid(),
            'account_type' => 'user',
        ]);
        $admin = $this->createAdmin();

        $fromUsers = $this->actingAs($admin)->get(route('admin.companies.show', $company).'?'.http_build_query([
            'from' => 'users',
            'page' => 2,
            'search' => 'acme',
        ]));

        $fromUsers->assertOk();
        $fromUsers->assertSee('Back to Users');
        $fromUsers->assertSee(
            'href="'.e(route('admin.users.index', ['page' => 2, 'search' => 'acme'])).'"',
            false
        );
        $this->assertNull($this->backHref($fromUsers->getContent()));

        $fromList = $this->actingAs($admin)->get(route('admin.companies.show', $company));
        $fromList->assertOk();
        $fromList->assertSee('Back to List');
        $fromList->assertSee('href="'.route('admin.companies.index').'"', false);
        $this->assertNull($this->backHref($fromList->getContent()));
    }

    private function createAdmin(): User
    {
        return User::create([
            'account_type' => 'provider',
            'username' => 'admin_'.uniqid(),
            'email' => uniqid('admin_', true).'@example.com',
            'password' => Hash::make('password'),
            'is_admin' => 1,
            'role' => 'super_admin',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function companyUrlFromUserPage(string $html, string $companyName): ?string
    {
        $pattern = '/href="([^"]+)"[^>]*>'.preg_quote($companyName, '/').'<\/a>/';
        if (! preg_match($pattern, $html, $matches)) {
            return null;
        }

        return html_entity_decode($matches[1]);
    }

    private function backHref(string $html): ?string
    {
        if (! preg_match('/<a href="([^"]+)" class="btn btn-primary btn-lg">\s*<i class="fas fa-arrow-left"><\/i>\s*Back\s*<\/a>/', $html, $matches)) {
            return null;
        }

        return html_entity_decode($matches[1]);
    }
}
