<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Protiti role er default permission diye kon page khole ar kon page 403/redirect hoy.
 */
class RoleAccessTest extends SecurityTestCase
{
    // role => [khola jabe, 403 hobe]
    public static function roleMatrix(): array
    {
        return [
            'manager' => ['manager',
                ['sale.index', 'purchase.index', 'purchase-return.index', 'customer.index', 'stock-reconciliation.index', 'reports.stock', 'reports.net-profit', 'expense.index'],
                ['expense-category.index', 'expense-category.create']],
            'salesman' => ['salesman',
                ['sale.index', 'sale.create', 'customer.index', 'product.index', 'installments.index', 'reports.imei'],
                ['purchase.index', 'purchase.create', 'supplier.index', 'expense.index', 'reports.net-profit', 'reports.stock', 'stock-reconciliation.index', 'sale-return.create']],
            'accountant' => ['accountant',
                ['expense.index', 'expense-category.index', 'reports.net-profit', 'reports.sales_summary', 'customer.due.report', 'supplier.due.report', 'sale.index', 'purchase.index'],
                ['sale.create', 'purchase.create', 'product.create', 'stock-reconciliation.index', 'customer.create']],
            'technician' => ['technician',
                ['product.index', 'reports.stock', 'reports.imei', 'stock-reconciliation.index', 'stock-reconciliation.create'],
                ['sale.index', 'sale.create', 'purchase.index', 'customer.create', 'expense.index', 'reports.net-profit']],
        ];
    }

    #[DataProvider('roleMatrix')]
    public function test_role_default_permissions_allow_and_forbid_the_right_pages(string $role, array $allowed, array $forbidden): void
    {
        $user = $this->staff($role);

        foreach ($allowed as $name) {
            $this->actingAs($user)->get($this->url($name))->assertOk();
        }

        foreach ($forbidden as $name) {
            $this->actingAs($user)->get($this->url($name))->assertForbidden();
        }
    }

    public function test_admin_can_open_every_area(): void
    {
        $admin = $this->admin();

        foreach (['admin.dashboard', 'setting.create', 'permission.index', 'salesman.index', 'salesman.create', 'salesman-permission.assign.form',
                  'branch.index', 'sale.index', 'purchase.index', 'expense.index', 'reports.net-profit', 'stock-reconciliation.index'] as $name) {
            $this->actingAs($admin)->get($this->url($name))->assertOk();
        }
    }

    public function test_staff_are_sent_to_their_own_dashboard_from_admin_only_pages(): void
    {
        foreach (['manager', 'salesman', 'accountant', 'technician'] as $role) {
            $user = $this->staff($role);

            $this->actingAs($user)->get(route('sales.dashboard'))->assertOk();

            foreach (['admin.dashboard', 'setting.create', 'permission.index', 'salesman.index', 'branch.index'] as $name) {
                $this->actingAs($user)->get(route($name))->assertRedirect(route($user->homeRoute()));
            }
        }
    }

    public function test_admin_visiting_staff_dashboard_goes_to_admin_dashboard(): void
    {
        $this->actingAs($this->admin())->get(route('sales.dashboard'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_ajax_requests_get_a_403_instead_of_a_redirect(): void
    {
        $salesman = $this->staff('salesman');

        $this->actingAs($salesman)->getJson(route('admin.dashboard.imei-search', ['imei' => '1']))->assertForbidden();
        $this->actingAs($salesman)->getJson(route('purchase.index'))->assertForbidden();
    }

    public function test_actions_are_blocked_server_side_not_only_hidden_in_the_menu(): void
    {
        $salesman = $this->staff('salesman');

        // permission chara POST/DELETE o 403 (form na dekhleo request pathano jay)
        $this->actingAs($salesman)->post(route('expense-category.store'), ['name' => 'Hack'])->assertForbidden();
        $this->actingAs($salesman)->delete(route('customer.delete', $this->ids['customer']))->assertForbidden();
        $this->actingAs($salesman)->post(route('purchase.payment'), [])->assertForbidden();
        $this->actingAs($salesman)->post(route('setting.store'), ['name' => 'Hacked'])->assertRedirect(route('sales.dashboard'));

        $this->assertDatabaseMissing('expense_categories', ['name' => 'Hack']);
        $this->assertDatabaseMissing('settings', ['name' => 'Hacked']);
    }

    public function test_purchase_price_endpoint_needs_purchase_permission(): void
    {
        $productId = $this->product();

        $this->actingAs($this->staff('salesman'))->getJson(url("get/product-details/{$productId}"))->assertForbidden();
        $this->actingAs($this->staff('manager'))->getJson(url("get/product-details/{$productId}"))->assertOk()->assertJsonPath('id', $productId);
    }

    public function test_extra_permission_given_by_admin_opens_the_page(): void
    {
        $salesman = $this->staff('salesman');
        $this->actingAs($salesman)->get(route('expense.index'))->assertForbidden();

        $salesman->givePermissionTo('read expense');
        $this->actingAs($salesman->fresh())->get(route('expense.index'))->assertOk();
    }

    public function test_every_logged_in_route_has_an_authorization_check(): void
    {
        // shudhu login lage emon route (nijer account er kaj)
        $selfService = ['profile.change.password', 'profile.change.password.updated', 'logout', 'password.update', 'password.confirm',
            'verification.notice', 'verification.verify', 'verification.send'];

        $unprotected = [];
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (!in_array('auth', $middleware, true) || in_array($route->getName(), $selfService, true) || $route->uri() === 'confirm-password') {
                continue;
            }

            $checked = collect($middleware)->contains(fn ($m) => is_string($m) && preg_match('/^(role|can|permission):/', $m));
            if (!$checked) {
                $unprotected[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $unprotected, "Routes without role:/can:/permission: middleware:\n" . implode("\n", $unprotected));
    }

    private function url(string $name): string
    {
        return route($name);
    }

    private function product(): int
    {
        return DB::table('products')->insertGetId([
            'type' => 'smart_phone', 'product_name' => 'Phone', 'model_number' => 'M-1',
            'category_id' => DB::table('categories')->insertGetId(['name' => 'Phone', 'slug' => 'phone']),
            'brand_id' => DB::table('brands')->insertGetId(['brand_name' => 'Brand', 'slug' => 'brand']),
            'purchase_price' => 1000, 'sale_price' => 1200, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
