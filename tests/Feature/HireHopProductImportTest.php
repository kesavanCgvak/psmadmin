<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class HireHopProductImportTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN = 'hirehop-live-token-9f3c1a7e-DO-NOT-LEAK';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hirehop.base_url' => 'https://myhirehop.com',
            'hirehop.timeout' => 15,
            'hirehop.stock_list_path' => '/modules/stock/asset_assign_list.php',
            'hirehop.list_rows_min' => 20,
            'hirehop.list_rows_max' => 200,
            'hirehop.list_rows_default' => 20,
            'hirehop.list_cache_seconds' => 60,
            'hirehop.retry_delay_ms' => 0,
        ]);
    }

    public function test_browse_returns_rental_products_and_pagination(): void
    {
        [$user] = $this->connect();
        $this->fakeCatalogue([
            $this->row(55, 'Widget Alpha', 'PN-55', 'BC-55'),
            $this->row(56, 'Widget Beta', 'PN-56', 'BC-56'),
            $this->row(99, 'Sold Asset', 'PN-99', 'BC-99', 1),
        ], 4, 80);

        $response = $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/hirehop/search?page=2&rows=40');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.rows', 40)
            ->assertJsonPath('pagination.total_pages', 4)
            ->assertJsonPath('pagination.total_records', 80)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.hirehop_stock_id', '55')
            ->assertJsonPath('data.0.name', 'Widget Alpha')
            ->assertJsonPath('data.0.part_number', 'PN-55')
            ->assertJsonPath('data.0.barcode', 'BC-55')
            ->assertJsonPath('data.0.type', 2);

        $this->assertStringNotContainsString('CUSTOM_FIELDS', $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertHireHopRequest(function (array $query, Request $request): bool {
            return ($query['page'] ?? null) === '2'
                && ($query['rows'] ?? null) === '40'
                && ($query['filter'] ?? null) === 'rental'
                && ($query['del'] ?? null) === '0'
                && !array_key_exists('pq_filter', $query)
                && $request->hasHeader('X-TOKEN', self::TOKEN)
                && !str_contains($request->url(), self::TOKEN)
                && str_contains($request->url(), '/modules/stock/asset_assign_list.php')
                && !str_contains($request->url(), '/modules/stock/list.php');
        });
    }

    public function test_search_sends_title_filter_and_empty_results_stay_empty(): void
    {
        [$user] = $this->connect();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $query = $this->queryOf($request);
            $filter = json_decode((string) ($query['pq_filter'] ?? ''), true);
            if (($filter['data'][0]['dataIndx'] ?? null) === 'TITLE') {
                return Http::response($this->payload([], 1, 0, 0), 200);
            }

            return Http::response($this->payload([
                $this->row(55, 'Widget Alpha'),
            ]), 200);
        });

        $response = $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/hirehop/search?keyword=Widget');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('pagination.total_records', 0);

        $this->assertHireHopRequest(function (array $query): bool {
            $filter = json_decode((string) ($query['pq_filter'] ?? ''), true);

            return ($filter['data'][0]['dataIndx'] ?? null) === 'TITLE'
                && ($filter['data'][0]['value'] ?? null) === 'Widget'
                && !array_key_exists('condition', $filter['data'][0] ?? []);
        });
    }

    public function test_short_keyword_is_rejected_without_calling_hirehop(): void
    {
        [$user] = $this->connect();
        Http::preventStrayRequests();
        Http::fake();

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/hirehop/search?keyword=a')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Keyword must be at least 2 characters.');

        Http::assertNothingSent();
    }

    public function test_rows_are_clamped_to_the_documented_hirehop_limits(): void
    {
        [$user] = $this->connect();
        $this->fakeCatalogue([$this->row(55, 'Widget Alpha')]);

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/hirehop/search?rows=5')
            ->assertOk()
            ->assertJsonPath('pagination.rows', 20);

        $this->assertHireHopRequest(fn (array $query): bool => ($query['rows'] ?? null) === '20');
    }

    public function test_repeated_browse_for_the_same_company_uses_the_cache(): void
    {
        [$user] = $this->connect();
        $this->fakeCatalogue([$this->row(55, 'Widget Alpha')]);
        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];

        $this->withHeaders($headers)->getJson('/api/hirehop/search')->assertOk();
        $this->withHeaders($headers)->getJson('/api/hirehop/search')->assertOk()->assertJsonPath('data.0.hirehop_stock_id', '55');

        Http::assertSentCount(1);
    }

    public function test_cached_products_are_not_shared_between_companies(): void
    {
        [$userA] = $this->connect();
        [$userB] = $this->connect();
        $this->fakeCatalogue([$this->row(55, 'Widget Alpha')]);

        $this->withToken(JWTAuth::fromUser($userA))->getJson('/api/hirehop/search')->assertOk();
        $this->withToken(JWTAuth::fromUser($userB))->getJson('/api/hirehop/search')->assertOk();

        Http::assertSentCount(2);
    }

    public function test_duplicate_stock_id_and_catalogue_match_are_distinct(): void
    {
        [$user, $company] = $this->connect();
        $brand = Brand::create(['name' => 'Hhbrand'.uniqid()]);
        $title = $brand->name.' ZX991155';
        $this->fakeCatalogue([
            $this->row(101, $title, 'PN-101'),
            $this->row(202, $title, 'PN-202'),
        ]);

        $import = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '101',
            'quantity' => 3,
            'rental_price' => 12.5,
        ]);
        $import->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('hirehop_stock_id', '101');

        $inventory = Equipment::query()->findOrFail($import->json('inventory_id'));
        $this->assertSame('101', (string) $inventory->hirehop_stock_id);
        $this->assertSame(3, (int) $inventory->quantity);
        $this->assertSame('12.50', number_format((float) $inventory->rental_price, 2, '.', ''));
        $this->assertSame('PN-101', $inventory->software_code);
        $this->assertNull($inventory->flex_resource_id);
        $this->assertNull($inventory->rentman_equipment_id);
        $this->assertNull($inventory->description);
        $this->assertNull($inventory->height);
        $this->assertSame($company->id, (int) $inventory->company_id);

        $product = Product::query()->findOrFail($inventory->product_id);
        $this->assertSame('hirehop', $product->source);
        $this->assertSame($brand->id, (int) $product->brand_id);
        $this->assertSame('ZX991155', $product->model);
        $this->assertNull($product->height);
        $this->assertNull($product->replacement_price);

        $check = $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/hirehop/import/check?hirehop_stock_id=101');
        $check->assertOk()
            ->assertJsonPath('status', 'already_in_inventory')
            ->assertJsonPath('hirehop.hirehop_stock_id', '101')
            ->assertJsonPath('hirehop.name', $title);
        $this->assertArrayNotHasKey('description', $check->json('hirehop'));
        $this->assertArrayNotHasKey('height', $check->json('hirehop'));

        $other = $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/hirehop/import/check?hirehop_stock_id=202');
        $other->assertOk()
            ->assertJsonPath('status', 'inventory_exists')
            ->assertJsonPath('inventory_id', $inventory->id)
            ->assertJsonPath('model', 'ZX991155');
        $this->assertNotSame('already_in_inventory', $other->json('status'));

        $again = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '101',
            'quantity' => 9,
        ]);
        $again->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'already_in_inventory');
        $this->assertSame(1, Equipment::query()->where('company_id', $company->id)->where('hirehop_stock_id', '101')->count());
        $this->assertSame(3, (int) $inventory->fresh()->quantity);
    }

    public function test_new_product_check_and_existing_catalogue_product_can_be_imported(): void
    {
        [$user, $company] = $this->connect();
        $brand = Brand::create(['name' => 'Hhbrand'.uniqid()]);
        $existing = Product::create([
            'brand_id' => $brand->id,
            'model' => 'KeptModel',
            'psm_code' => 'HH'.strtoupper(bin2hex(random_bytes(4))),
            'is_verified' => 1,
            'source' => 'manual',
        ]);
        $title = $brand->name.' KeptModel';
        $this->fakeCatalogue([
            $this->row(303, $title, 'PN-303', 'BC-303'),
            $this->row(404, $brand->name.' NotInCatalog', 'PN-404'),
        ]);
        $preview = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '404',
            'quantity' => 1,
            'confirm' => false,
        ]);
        $preview->assertOk()->assertJsonPath('status', 'new_product');
        $this->assertSame(0, Equipment::query()->where('company_id', $company->id)->count());

        $created = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '404',
            'quantity' => 2,
        ]);
        $created->assertCreated()->assertJsonPath('message', 'Equipment imported successfully');
        $this->assertSame('hirehop', Product::query()->findOrFail($created->json('product_id'))->source);

        $linked = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '303',
            'quantity' => 4,
            'product_id' => $existing->id,
            'rental_price' => 8,
        ]);
        $linked->assertCreated()
            ->assertJsonPath('message', 'Equipment imported and linked to HireHop')
            ->assertJsonPath('product_id', $existing->id)
            ->assertJsonPath('hirehop_stock_id', '303');

        $existing->refresh();
        $this->assertSame('KeptModel', $existing->model);
        $this->assertSame('manual', $existing->source);
        $row = Equipment::query()->where('company_id', $company->id)->where('hirehop_stock_id', '303')->firstOrFail();
        $this->assertSame($existing->id, (int) $row->product_id);
        $this->assertSame('PN-303', $row->software_code);
        $this->assertSame(4, (int) $row->quantity);
    }

    public function test_link_sets_the_stock_id_without_overwriting_the_catalogue_product(): void
    {
        [$user, $company] = $this->connect();
        $product = Product::create([
            'model' => 'Original Model',
            'psm_code' => 'HH'.strtoupper(bin2hex(random_bytes(4))),
            'is_verified' => 1,
            'source' => 'manual',
        ]);
        $inventory = Equipment::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'quantity' => 6,
            'rental_price' => 20,
            'software_code' => 'KEEP-ME',
            'description' => 'Existing description',
            'flex_resource_id' => 'flex-keep',
        ]);
        $this->fakeCatalogue([$this->row(55, 'HireHop Name', 'PN-SHOULD-NOT-REPLACE')]);

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/link-inventory', [
            'inventory_id' => $inventory->id,
            'hirehop_stock_id' => '55',
            'api_key' => 'submitted-token-must-not-be-used',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'HireHop linked successfully')
            ->assertJsonPath('inventory_id', $inventory->id)
            ->assertJsonPath('product_id', $product->id)
            ->assertJsonPath('hirehop_stock_id', '55');

        $inventory->refresh();
        $product->refresh();
        $this->assertSame('55', (string) $inventory->hirehop_stock_id);
        $this->assertSame('KEEP-ME', $inventory->software_code);
        $this->assertSame('Existing description', $inventory->description);
        $this->assertSame('flex-keep', $inventory->flex_resource_id);
        $this->assertNull($inventory->rentman_equipment_id);
        $this->assertSame(6, (int) $inventory->quantity);
        $this->assertSame('20.00', number_format((float) $inventory->rental_price, 2, '.', ''));
        $this->assertSame('Original Model', $product->model);
        $this->assertSame('manual', $product->source);
        $this->assertSame($company->id.':55', $inventory->active_hirehop_key);

        $this->assertHireHopRequest(function (array $query, Request $request): bool {
            return $request->hasHeader('X-TOKEN', self::TOKEN)
                && !str_contains($request->url(), self::TOKEN)
                && !str_contains($request->url(), 'submitted-token-must-not-be-used');
        });
    }

    public function test_duplicate_link_and_cross_company_link_are_rejected(): void
    {
        [$userA, $companyA] = $this->connect();
        [$userB, $companyB] = $this->connect();
        $this->fakeCatalogue([$this->row(55, 'Shared Name', 'PN-55')]);

        $first = $this->inventory($userA, $companyA, 'First');
        $second = $this->inventory($userA, $companyA, 'Second');
        $foreign = $this->inventory($userB, $companyB, 'Foreign');

        $this->withToken(JWTAuth::fromUser($userA))->postJson('/api/hirehop/link-inventory', [
            'inventory_id' => $first->id,
            'hirehop_stock_id' => '55',
        ])->assertOk()->assertJsonPath('success', true);

        $this->withToken(JWTAuth::fromUser($userA))->postJson('/api/hirehop/link-inventory', [
            'inventory_id' => $second->id,
            'hirehop_stock_id' => '55',
        ])->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'already_linked');

        $this->assertNull($second->fresh()->hirehop_stock_id);
        $this->assertSame('55', (string) $first->fresh()->hirehop_stock_id);

        $this->withToken(JWTAuth::fromUser($userB))->postJson('/api/hirehop/link-inventory', [
            'inventory_id' => $first->id,
            'hirehop_stock_id' => '55',
        ])->assertStatus(400)
            ->assertJsonPath('message', 'Inventory not found or does not belong to this company.');

        $this->assertNull($foreign->fresh()->hirehop_stock_id);
        $own = $this->withToken(JWTAuth::fromUser($userB))->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '55',
            'quantity' => 1,
        ]);
        $own->assertCreated();
        $this->assertSame($companyB->id, (int) Equipment::query()->findOrFail($own->json('inventory_id'))->company_id);
        $this->assertSame(1, Equipment::query()->where('company_id', $companyA->id)->where('hirehop_stock_id', '55')->count());
    }

    public function test_same_link_without_changes_is_rejected_and_quantity_update_is_allowed(): void
    {
        [$user, $company] = $this->connect();
        $inventory = $this->inventory($user, $company, 'Linked');
        $this->fakeCatalogue([$this->row(55, 'Widget', 'PN-55')]);
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)->postJson('/api/hirehop/link-inventory', [
            'inventory_id' => $inventory->id,
            'hirehop_stock_id' => '55',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/hirehop/link-inventory', [
            'inventory_id' => $inventory->id,
            'hirehop_stock_id' => '55',
        ])->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'already_linked');

        $this->withToken($token)->postJson('/api/hirehop/link-inventory', [
            'inventory_id' => $inventory->id,
            'hirehop_stock_id' => '55',
            'quantity' => 11,
            'rental_price' => 4,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'HireHop inventory updated');

        $inventory->refresh();
        $this->assertSame(11, (int) $inventory->quantity);
        $this->assertSame('4.00', number_format((float) $inventory->rental_price, 2, '.', ''));
    }

    public function test_invalid_token_and_numeric_hirehop_error_hide_the_token(): void
    {
        [$user] = $this->connect();
        $logged = $this->captureLogs();
        $token = JWTAuth::fromUser($user);
        $step = 0;
        Http::preventStrayRequests();
        Http::fake(function () use (&$step) {
            $step++;

            return $step === 1
                ? Http::response('nope '.self::TOKEN, 401)
                : Http::response(['error' => 3], 200);
        });

        $this->withToken($token)
            ->getJson('/api/hirehop/search')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_API_TOKEN')
            ->assertJsonPath('message', 'The HireHop API token is invalid or no longer valid.');

        $error = $this->withToken($token)->getJson('/api/hirehop/search');
        $error->assertStatus(422)
            ->assertJsonPath('error_code', 'HIREHOP_ERROR')
            ->assertJsonPath('message', 'HireHop returned an error (3: Missing parameters).');
        $this->assertStringNotContainsString(self::TOKEN, $error->getContent());
        $this->assertTokenIsAbsent($logged);
    }

    public function test_timeout_does_not_expose_the_token(): void
    {
        [$user] = $this->connect();
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake(function () {
            throw new ConnectionException('timed out '.self::TOKEN);
        });

        $response = $this->withToken(JWTAuth::fromUser($user))->getJson('/api/hirehop/search');
        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'NETWORK_TIMEOUT')
            ->assertJsonPath('message', 'HireHop request failed due to a network or timeout issue.');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertTokenIsAbsent($logged);
    }

    public function test_unavailable_hirehop_does_not_expose_the_token(): void
    {
        [$user] = $this->connect();
        $logged = $this->captureLogs();
        Http::preventStrayRequests();
        Http::fake(function () {
            return Http::response('unavailable '.self::TOKEN, 503);
        });

        $response = $this->withToken(JWTAuth::fromUser($user))->getJson('/api/hirehop/search');
        $response->assertStatus(503)
            ->assertJsonPath('error_code', 'HIREHOP_UNAVAILABLE')
            ->assertJsonPath('message', 'HireHop is unavailable.');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertTokenIsAbsent($logged);
    }

    public function test_text_token_error_and_numeric_rate_limit_do_not_expose_the_token(): void
    {
        [$user, $company] = $this->connect();
        $logged = $this->captureLogs();
        $token = JWTAuth::fromUser($user);
        $step = 0;
        Http::preventStrayRequests();
        Http::fake(function () use (&$step) {
            $step++;
            if ($step === 1) {
                return Http::response(['error' => 'Token expired '.self::TOKEN], 200);
            }

            return Http::response(['error' => 327], 200);
        });

        $expired = $this->withToken($token)->getJson('/api/hirehop/search');
        $expired->assertStatus(422)->assertJsonPath('error_code', 'INVALID_API_TOKEN');
        $this->assertStringNotContainsString(self::TOKEN, $expired->getContent());

        $limited = $this->withToken($token)->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '55',
            'quantity' => 1,
        ]);
        $limited->assertStatus(429)->assertJsonPath('error_code', 'HIREHOP_RATE_LIMIT');
        $this->assertSame(0, Equipment::query()->where('company_id', $company->id)->count());
        $this->assertStringNotContainsString(self::TOKEN, $limited->getContent());
        $this->assertTokenIsAbsent($logged);
    }

    public function test_rate_limited_read_can_be_retried_once_and_imports_a_single_row(): void
    {
        [$user, $company] = $this->connect();
        Http::preventStrayRequests();
        Http::fake([
            'https://myhirehop.com/*' => Http::sequence()
                ->push(['error' => 327], 200)
                ->push($this->payload([$this->row(55, 'Retried Widget', 'PN-55')]), 200)
                ->push($this->payload([$this->row(55, 'Retried Widget', 'PN-55')]), 200),
        ]);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '55',
            'quantity' => 2,
            'rental_price' => 5,
        ])->assertCreated()->assertJsonPath('hirehop_stock_id', '55');

        $this->assertSame(1, Equipment::query()->where('company_id', $company->id)->where('hirehop_stock_id', '55')->count());
        Http::assertSentCount(2);
    }

    public function test_failed_import_rolls_back_the_catalogue_product(): void
    {
        [$user, $company] = $this->connect();
        $this->fakeCatalogue([$this->row(55, 'Rollback Widget '.uniqid(), 'PN-55')]);
        $before = Product::query()->where('source', 'hirehop')->count();
        Equipment::creating(function () {
            throw new \RuntimeException('inventory insert failed');
        });

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '55',
            'quantity' => 1,
        ])->assertStatus(500);

        $this->assertSame($before, Product::query()->where('source', 'hirehop')->count());
        $this->assertSame(0, Equipment::query()->where('company_id', $company->id)->count());
    }

    public function test_failed_link_rolls_back(): void
    {
        [$user, $company] = $this->connect();
        $inventory = $this->inventory($user, $company, 'Rollback');
        $this->fakeCatalogue([$this->row(55, 'Widget')]);
        Equipment::updating(function () {
            throw new \RuntimeException('link write failed');
        });

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/link-inventory', [
            'inventory_id' => $inventory->id,
            'hirehop_stock_id' => '55',
        ])->assertStatus(500);

        $this->assertNull($inventory->fresh()->hirehop_stock_id);
    }

    public function test_soft_deleted_hirehop_link_can_be_imported_again(): void
    {
        [$user, $company] = $this->connect();
        $this->fakeCatalogue([$this->row(55, 'Again '.uniqid(), 'PN-55')]);
        $token = JWTAuth::fromUser($user);

        $first = $this->withToken($token)->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '55',
            'quantity' => 1,
        ])->assertCreated();

        $removed = Equipment::query()->findOrFail($first->json('inventory_id'));
        $removed->delete();
        $this->assertNull(Equipment::withTrashed()->findOrFail($removed->id)->active_hirehop_key);

        $second = $this->withToken($token)->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '55',
            'quantity' => 2,
        ])->assertCreated();

        $this->assertNotSame($first->json('inventory_id'), $second->json('inventory_id'));
        $this->assertSame(1, Equipment::query()->where('company_id', $company->id)->where('hirehop_stock_id', '55')->count());
        $this->assertSame(2, (int) Equipment::query()->findOrFail($second->json('inventory_id'))->quantity);
    }

    public function test_company_without_hirehop_is_rejected_and_guests_are_unauthorized(): void
    {
        [$user] = $this->createCompanyUser();
        Http::preventStrayRequests();
        Http::fake();

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/hirehop/search')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NOT_CONFIGURED');

        $this->withHeaders(['Authorization' => 'Bearer not-a-real-jwt'])
            ->getJson('/api/hirehop/search')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Session expired');
        Http::assertNothingSent();
    }

    public function test_missing_base_url_fails_before_a_request(): void
    {
        [$user] = $this->connect();
        config(['hirehop.base_url' => '']);
        Http::preventStrayRequests();
        Http::fake();

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/hirehop/search')
            ->assertStatus(500)
            ->assertJsonPath('error_code', 'MISSING_BASE_URL');

        Http::assertNothingSent();
    }

    public function test_flex_and_rentman_import_routes_and_existing_links_stay_unchanged(): void
    {
        [$user, $company] = $this->connect();
        $flex = $this->inventory($user, $company, 'Flex Item');
        $flex->flex_resource_id = 'flex-resource-1';
        $flex->rentman_equipment_id = 'rentman-1';
        $flex->save();
        $this->fakeCatalogue([$this->row(55, 'HireHop Only '.uniqid())]);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/hirehop/import', [
            'hirehop_stock_id' => '55',
            'quantity' => 1,
        ])->assertCreated();

        $flex->refresh();
        $this->assertSame('flex-resource-1', $flex->flex_resource_id);
        $this->assertSame('rentman-1', $flex->rentman_equipment_id);
        $this->assertNull($flex->hirehop_stock_id);

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/flex/search?keyword=a')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Keyword must be at least 2 characters.');

        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/rentman/equipment/search?q=a')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Query must be at least 2 characters.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function fakeCatalogue(array $rows, int $totalPages = 1, int $totalRecords = 0): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($rows, $totalPages, $totalRecords) {
            $query = $this->queryOf($request);
            $filter = json_decode((string) ($query['pq_filter'] ?? ''), true);
            $selected = $rows;
            if (($filter['data'][0]['dataIndx'] ?? null) === 'MAIN_ID') {
                $wanted = (string) $filter['data'][0]['value'];
                $selected = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => (string) $row['MAIN_ID'] === $wanted
                ));
            }

            $page = (int) ($query['page'] ?? 1);

            return Http::response($this->payload(
                $selected,
                $page,
                $totalPages,
                $totalRecords > 0 ? $totalRecords : count($selected)
            ), 200);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function payload(array $rows, int $page = 1, int $totalPages = 1, int $totalRecords = 0): array
    {
        return [
            'page' => $page,
            'total' => $totalPages,
            'totalRecords' => $totalRecords,
            'data' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $title, string $part = 'PN', string $barcode = 'BC', int $type = 2): array
    {
        return [
            'MAIN_ID' => $id,
            'TYPE' => $type,
            'TITLE' => $title,
            'PART_NUMBER' => $part,
            'BARCODE' => $barcode,
            'STATUS' => 0,
            'CUSTOM_FIELDS' => ['secret' => self::TOKEN],
        ];
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function connect(): array
    {
        [$user, $company] = $this->createCompanyUser();
        CompanyIntegration::create([
            'company_id' => $company->id,
            'integration_type' => 'hirehop',
            'api_key' => self::TOKEN,
            'api_base_url' => 'https://myhirehop.com',
        ]);

        return [$user, $company];
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function createCompanyUser(): array
    {
        $company = Company::create([
            'name' => 'HireHop Import '.uniqid(),
            'account_type' => 'provider',
        ]);
        $user = User::create([
            'account_type' => 'provider',
            'username' => 'hhimport_'.uniqid(),
            'email' => uniqid('hhimport_', true).'@example.com',
            'password' => 'password',
            'company_id' => $company->id,
            'is_admin' => 0,
            'role' => 'user',
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);

        return [$user, $company];
    }

    private function inventory(User $user, Company $company, string $model): Equipment
    {
        $product = Product::create([
            'model' => $model.' '.uniqid(),
            'psm_code' => 'HH'.strtoupper(bin2hex(random_bytes(4))),
            'is_verified' => 0,
        ]);

        return Equipment::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'software_code' => 'CODE-'.$product->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryOf(Request $request): array
    {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @param  callable(array<string, mixed>, Request): bool  $assertion
     */
    private function assertHireHopRequest(callable $assertion): void
    {
        Http::assertSent(function (Request $request) use ($assertion) {
            return $assertion($this->queryOf($request), $request);
        });
    }

    private function captureLogs(): \ArrayObject
    {
        $logged = new \ArrayObject;
        Log::listen(function (MessageLogged $event) use ($logged) {
            $logged->append($event->message.' '.json_encode($event->context));
        });

        return $logged;
    }

    private function assertTokenIsAbsent(\ArrayObject $logged): void
    {
        foreach ($logged as $entry) {
            $this->assertStringNotContainsString(self::TOKEN, (string) $entry);
        }
    }
}
