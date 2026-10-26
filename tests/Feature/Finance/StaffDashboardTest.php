<?php

namespace Tests\Feature\Finance;

/**
 * Staff dashboard: nijer bikri, cash drawer, permission onujayi ongsho; light/dark theme switch shob page e.
 */
class StaffDashboardTest extends FinanceTestCase
{
    public function test_dashboard_shows_my_sales_and_drawer_and_only_permitted_sections(): void
    {
        $this->purchase(10, 1000);
        $manager = $this->staff('manager', ['branch_id' => $this->ids['branchA'], 'name' => 'Rahim Uddin']);
        $this->actingAs($manager)->post(route('cash-closing.open'), ['opening_balance' => 500])->assertSessionMissing('error');
        $this->actingAs($manager)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(),
            'stock_ids' => [$this->stock->id], 'qtys' => [2], 'sale_prices' => [1500], 'item_discounts' => [0], 'imeis' => [[]],
            'payments' => [['method' => 'Cash', 'amount' => 2000]],
        ])->assertSessionMissing('error');
        $this->sale(1, 1500);   // admin er bikri - manager er "my sales" e na

        $this->actingAs($manager)->get(route('sales.dashboard'))->assertOk()
            ->assertSee('Rahim')
            ->assertSee('3,000.00')            // ajke nijer bikri
            ->assertSee('received 2,000.00')
            ->assertSee('2,500.00')            // drawer: 500 + 2000
            ->assertSee('Open drawer / close day')
            ->assertSee('Collect installment')
            ->assertSee('Low stock');

        // technician: bikri/cash nei, warranty ache
        $this->actingAs($this->staff('technician'))->get(route('sales.dashboard'))->assertOk()
            ->assertDontSee('My sales today')->assertDontSee('My cash drawer')->assertSee('Warranty claims')->assertSee('Stock correction');

        // salesman: cash session nei - kholar button
        $this->actingAs($this->staff('salesman'))->get(route('sales.dashboard'))->assertOk()->assertSee("Open today's session", false);
    }

    public function test_theme_switch_is_on_every_layout_and_print_stays_light(): void
    {
        foreach ([route('sales.dashboard') => $this->staff('accountant'), route('finance.accounts.index') => $this->admin] as $url => $user) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('data-theme-toggle', false)
                ->assertSee("localStorage.getItem(KEY)", false)
                ->assertSee('back-end/css/premium.css', false);
        }
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('data-theme-toggle', false)->assertSee('Welcome back');

        $css = file_get_contents(public_path('back-end/css/premium.css'));
        $this->assertStringContainsString('[data-theme="dark"] {', $css);
        $this->assertMatchesRegularExpression('/@media print \{\s*:root, \[data-theme="dark"\] \{\s*color-scheme: light;/', $css);
    }
}
