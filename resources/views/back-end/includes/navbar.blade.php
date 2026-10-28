<!-- Sidebar Menu -->
<nav class="mt-2">
    <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">

        @php
            $currentRoute = Route::currentRouteName();
            $user = auth()->user();
        @endphp

        <!-- Dashboard -->
        @if($user->role=='admin')
        <li class="nav-item {{ $currentRoute == 'admin.dashboard' ? 'menu-open' : '' }}">
            <a href="{{ route('admin.dashboard') }}" class="nav-link {{ $currentRoute == 'admin.dashboard' ? 'active' : '' }}">
                <i class="nav-icon fas fa-tachometer-alt"></i>
                <p>Dashboard</p>
            </a>
        </li>
        @endif

       @if (!$user->isAdmin())
        <li class="nav-item {{ $currentRoute == 'sales.dashboard' ? 'menu-open' : '' }}">
            <a href="{{ route('sales.dashboard') }}" class="nav-link {{ $currentRoute == 'sales.dashboard' ? 'active' : '' }}">
                <i class="nav-icon fas fa-tachometer-alt"></i>
                <p>Dashboard</p>
            </a>
        </li>
        @endif



        {{-- ================= PRODUCT MANAGEMENT ================= --}}
        @php
            $isAdmin = $user->role == 'admin';
        @endphp

        @if($isAdmin || auth()->user()->hasAnyPermission(['create category', 'read category', 'create brand', 'read brand', 'create product', 'read product']))
            <li class="nav-item {{ request()->routeIs('category.*','brand.*','product.*') ? 'menu-open' : '' }}">
                <a href="#" class="nav-link {{ request()->routeIs('category.*','brand.*','product.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-mobile-alt"></i>
                    <p>
                        Product Management
                        <i class="fas fa-angle-left right"></i>
                    </p>
                </a>

                <ul class="nav nav-treeview">

                    {{-- Category Section --}}
                    @if($isAdmin || auth()->user()->hasAnyPermission(['create category', 'read category']))
                    <li class="nav-item {{ request()->routeIs('category.*') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('category.*') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Category <i class="fas fa-angle-left right"></i></p>
                        </a>

                        <ul class="nav nav-treeview">
                            @if($isAdmin || auth()->user()->can('create category'))
                            <li class="nav-item">
                                <a href="{{ route('category.create') }}"
                                   class="nav-link {{ request()->routeIs('category.create') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Add Category</p>
                                </a>
                            </li>
                            @endif

                            @if($isAdmin || auth()->user()->can('read category'))
                            <li class="nav-item">
                                <a href="{{ route('category.index') }}"
                                   class="nav-link {{ request()->routeIs('category.index') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Manage Category</p>
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                    @endif

                    {{-- Brand Section --}}
                    @if($isAdmin || auth()->user()->hasAnyPermission(['create brand', 'read brand']))
                    <li class="nav-item {{ request()->routeIs('brand.*') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('brand.*') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Brand <i class="fas fa-angle-left right"></i></p>
                        </a>

                        <ul class="nav nav-treeview">
                            @if($isAdmin || auth()->user()->can('create brand'))
                            <li class="nav-item">
                                <a href="{{ route('brand.create') }}"
                                   class="nav-link {{ request()->routeIs('brand.create') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Add Brand</p>
                                </a>
                            </li>
                            @endif

                            @if($isAdmin || auth()->user()->can('read brand'))
                            <li class="nav-item">
                                <a href="{{ route('brand.index') }}"
                                   class="nav-link {{ request()->routeIs('brand.index') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Manage Brand</p>
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                    @endif

                    {{-- Product Section --}}
                    @if($isAdmin || auth()->user()->hasAnyPermission(['create product', 'read product']))
                    <li class="nav-item {{ request()->routeIs('product.*') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('product.*') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Product <i class="fas fa-angle-left right"></i></p>
                        </a>

                        <ul class="nav nav-treeview">
                            @if($isAdmin || auth()->user()->can('create product'))
                            <li class="nav-item">
                                <a href="{{ route('product.create') }}"
                                   class="nav-link {{ request()->routeIs('product.create') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Add Product</p>
                                </a>
                            </li>
                            @endif

                            @if($isAdmin || auth()->user()->can('read product'))
                            <li class="nav-item">
                                <a href="{{ route('product.index') }}"
                                   class="nav-link {{ request()->routeIs('product.index') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Manage Product</p>
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                    @endif

                </ul>
            </li>
        @endif
        {{-- ================= END PRODUCT MANAGEMENT ================= --}}


        {{-- ================= ATTRIBUTES ================= --}}
        @php
            $isAdmin = $user->role == 'admin';
        @endphp

        @if($isAdmin || auth()->user()->hasAnyPermission(['read ram', 'read rom', 'read color']))
            <li class="nav-item {{ request()->routeIs('ram.*','rom.*','color.*') ? 'menu-open' : '' }}">
                <a href="#" class="nav-link {{ request()->routeIs('ram.*','rom.*','color.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-tags"></i>
                    <p>
                        Attributes
                        <i class="fas fa-angle-left right"></i>
                    </p>
                </a>

                <ul class="nav nav-treeview">

                    {{-- RAM --}}
                    @if($isAdmin || auth()->user()->can('read ram'))
                    <li class="nav-item">
                        <a href="{{ route('ram.index') }}"
                           class="nav-link {{ request()->routeIs('ram.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>RAM</p>
                        </a>
                    </li>
                    @endif

                    {{-- ROM --}}
                    @if($isAdmin || auth()->user()->can('read rom'))
                    <li class="nav-item">
                        <a href="{{ route('rom.index') }}"
                           class="nav-link {{ request()->routeIs('rom.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>ROM</p>
                        </a>
                    </li>
                    @endif

                    {{-- COLOR --}}
                    @if($isAdmin || auth()->user()->can('read color'))
                    <li class="nav-item">
                        <a href="{{ route('color.index') }}"
                           class="nav-link {{ request()->routeIs('color.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>Color</p>
                        </a>
                    </li>
                    @endif

                </ul>
            </li>
        @endif
        {{-- ================= END ATTRIBUTES ================= --}}



        <!-- ======= purchase  ========== -->
        @php
            $isAdmin = $user->role == 'admin';
        @endphp

        @if($isAdmin || auth()->user()->hasAnyPermission(['create supplier', 'read supplier', 'create purchase', 'read purchase', 'create purchase return', 'read purchase return']))
            <li class="nav-item {{ request()->routeIs('purchase.*','supplier.*','purchase-return.*') ? 'menu-open' : '' }}">
                <a href="#" class="nav-link {{ request()->routeIs('purchase.*','supplier.*','purchase-return.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-truck-loading"></i>
                    <p>
                        Purchase
                        <i class="fas fa-angle-left right"></i>
                    </p>
                </a>

                <ul class="nav nav-treeview">

                    {{-- Supplier Section --}}
                    @if($isAdmin || auth()->user()->hasAnyPermission(['create supplier', 'read supplier']))
                    <li class="nav-item {{ request()->routeIs('supplier.*') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('supplier.*') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Supplier <i class="fas fa-angle-left right"></i></p>
                        </a>

                        <ul class="nav nav-treeview">
                            @if($isAdmin || auth()->user()->can('create supplier'))
                            <li class="nav-item">
                                <a href="{{ route('supplier.create') }}"
                                   class="nav-link {{ request()->routeIs('supplier.create') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Add Supplier</p>
                                </a>
                            </li>
                            @endif

                            @if($isAdmin || auth()->user()->can('read supplier'))
                            <li class="nav-item">
                                <a href="{{ route('supplier.index') }}"
                                   class="nav-link {{ request()->routeIs('supplier.index') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Manage Supplier</p>
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                    @endif

                    {{-- Purchase Management --}}
                    @if($isAdmin || auth()->user()->can('create purchase'))
                    <li class="nav-item">
                        <a href="{{ route('purchase.create') }}"
                           class="nav-link {{ request()->routeIs('purchase.create') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>Add Purchase</p>
                        </a>
                    </li>
                    @endif

                    @if($isAdmin || auth()->user()->can('read purchase'))
                    <li class="nav-item">
                        <a href="{{ route('purchase.index') }}"
                           class="nav-link {{ request()->routeIs('purchase.index') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>Manage Purchase</p>
                        </a>
                    </li>
                    @endif

                    {{-- Purchase Return Section --}}
                    @if($isAdmin || auth()->user()->hasAnyPermission(['create purchase return', 'read purchase return']))
                    <li class="nav-item {{ request()->routeIs('purchase-return.*') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('purchase-return.*') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Return <i class="fas fa-angle-left right"></i></p>
                        </a>

                        <ul class="nav nav-treeview">
                            @if($isAdmin || auth()->user()->can('create purchase return'))
                            <li class="nav-item">
                                <a href="{{ route('purchase-return.create') }}"
                                   class="nav-link {{ request()->routeIs('purchase-return.create') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Add Return</p>
                                </a>
                            </li>
                            @endif

                            @if($isAdmin || auth()->user()->can('read purchase return'))
                            <li class="nav-item">
                                <a href="{{ route('purchase-return.index') }}"
                                   class="nav-link {{ request()->routeIs('purchase-return.index') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Manage Return</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('purchase-return.history') }}"
                                   class="nav-link {{ request()->routeIs('purchase-return.history') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Return History</p>
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                    @endif

                </ul>
            </li>
        @endif


@php
    $isAdmin = $user->role == 'admin';
@endphp

@if($isAdmin || auth()->user()->hasAnyPermission(['create customer','read customer','create sale','read sale', 'create return', 'read return']))
    <li class="nav-item {{ request()->routeIs('sale.*','customer.*', 'sale-return.*') ? 'menu-open' : '' }}">
        <a href="#" class="nav-link {{ request()->routeIs('sale.*','customer.*', 'sale-return.*') ? 'active' : '' }}">
            <i class="nav-icon fas fa-cash-register"></i>
            <p>
                Sale
                <i class="fas fa-angle-left right"></i>
            </p>
        </a>

        <ul class="nav nav-treeview">
            {{-- Customer Section --}}
            @if($isAdmin || auth()->user()->hasAnyPermission(['create customer','read customer']))
            <li class="nav-item {{ request()->routeIs('customer.*') ? 'menu-open' : '' }}">
                <a href="#" class="nav-link {{ request()->routeIs('customer.*') ? 'active' : '' }}">
                    <i class="far fa-folder nav-icon"></i>
                    <p>Customer <i class="fas fa-angle-left right"></i></p>
                </a>

                <ul class="nav nav-treeview">
                    @if($isAdmin || auth()->user()->can('create customer'))
                    <li class="nav-item">
                        <a href="{{ route('customer.create') }}" class="nav-link {{ request()->routeIs('customer.create') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>Add Customer</p>
                        </a>
                    </li>
                    @endif

                    @if($isAdmin || auth()->user()->can('read customer'))
                    <li class="nav-item">
                        <a href="{{ route('customer.index') }}" class="nav-link {{ request()->routeIs('customer.index') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>Manage Customer</p>
                        </a>
                    </li>
                    @endif
                </ul>
            </li>
            @endif

            {{-- Sale Section --}}
            @if($isAdmin || auth()->user()->can('create sale'))
            <li class="nav-item">
                <a href="{{ route('sale.create') }}" class="nav-link {{ request()->routeIs('sale.create') ? 'active' : '' }}">
                    <i class="far fa-circle nav-icon"></i>
                    <p>Add Sale</p>
                </a>
            </li>
            @endif

            @if($isAdmin || auth()->user()->can('read sale'))
            <li class="nav-item">
                <a href="{{ route('sale.index') }}" class="nav-link {{ request()->routeIs('sale.index') ? 'active' : '' }}">
                    <i class="far fa-circle nav-icon"></i>
                    <p>Manage Sale</p>
                </a>
            </li>
            @endif

            {{-- Return Section --}}
            @if($isAdmin || auth()->user()->hasAnyPermission(['create return', 'read return']))
            <li class="nav-item {{ request()->routeIs('sale-return.*') ? 'menu-open' : '' }}">
                <a href="#" class="nav-link {{ request()->routeIs('sale-return.*') ? 'active' : '' }}">
                    <i class="far fa-folder nav-icon"></i>
                    <p>Return <i class="fas fa-angle-left right"></i></p>
                </a>

                <ul class="nav nav-treeview">
                    @if($isAdmin || auth()->user()->can('create return'))
                    <li class="nav-item">
                        <a href="{{ route('sale-return.create') }}" class="nav-link {{ request()->routeIs('sale-return.create') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>Add Return</p>
                        </a>
                    </li>
                    @endif

                    @if($isAdmin || auth()->user()->can('read return'))
                    <li class="nav-item">
                        <a href="{{ route('sale-return.index') }}" class="nav-link {{ request()->routeIs('sale-return.index') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>Manage Return</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('purchase-return.history') }}" class="nav-link {{ request()->routeIs('purchase-return.history') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i>
                            <p>Return History</p>
                        </a>
                    </li>
                    @endif
                </ul>
            </li>
            @endif
        </ul>
    </li>
@endif
        <!-- ======= sale  ========== -->


        <!-- ======= Report  ========== -->
        @php
            $isAdmin = $user->role == 'admin';
        @endphp


        @if($isAdmin || auth()->user()->can('read stock adjustment'))
            <li class="nav-item">
                <a href="{{ route('stock-reconciliation.index') }}" class="nav-link {{ request()->routeIs('stock-reconciliation.*') ? 'active' : '' }}">
                    <i class="fas fa-balance-scale nav-icon"></i>
                    <p>Stock Reconciliation</p>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('stock-count.index') }}" class="nav-link {{ request()->routeIs('stock-count.*') ? 'active' : '' }}">
                    <i class="fas fa-clipboard-check nav-icon"></i>
                    <p>Physical Stock Count</p>
                </a>
            </li>
        @endif

        {{-- IMEI khoja/itihash ar status onujayi list --}}
        @if($isAdmin || auth()->user()->hasAnyPermission(['search imei', 'read sales report']))
            <li class="nav-item">
                <a href="{{ route('reports.imei') }}" class="nav-link {{ request()->routeIs('reports.imei') ? 'active' : '' }}">
                    <i class="fas fa-mobile-alt nav-icon"></i>
                    <p>IMEI Search &amp; History</p>
                </a>
            </li>
        @endif
        @if($isAdmin || auth()->user()->hasAnyPermission(['search imei', 'read stock report']))
            <li class="nav-item">
                <a href="{{ route('reports.imei.stock') }}" class="nav-link {{ request()->routeIs('reports.imei.stock') ? 'active' : '' }}">
                    <i class="fas fa-list-ol nav-icon"></i>
                    <p>IMEI Stock List</p>
                </a>
            </li>
        @endif

        @if($isAdmin || auth()->user()->hasAnyPermission(['read stock report', 'read supplier report', 'read sales report', 'read customer report', 'read expense report']))
            <li class="nav-item {{ request()->routeIs('reports.*', 'supplier.due.report', 'customer.*') ? 'menu-open' : '' }}">
                <a href="#" class="nav-link {{ request()->routeIs('reports.*', 'supplier.due.report', 'customer.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-chart-pie"></i>
                    <p>
                        Report
                        <i class="fas fa-angle-left right"></i>
                    </p>
                </a>

                <ul class="nav nav-treeview">

                    {{-- Stock Report --}}
                    @if($isAdmin || auth()->user()->can('read stock report'))
                    @php $lowStockCount = \App\Http\Controllers\StockReportController::lowStockCount(); @endphp
                    <li class="nav-item {{ request()->routeIs('reports.stock', 'reports.stock_ledger', 'reports.stock_valuation', 'reports.low_stock') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('reports.stock', 'reports.stock_ledger', 'reports.stock_valuation', 'reports.low_stock') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Stock Report @if($lowStockCount)<span class="badge badge-warning right">{{ $lowStockCount }}</span>@endif <i class="fas fa-angle-left right"></i></p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('reports.low_stock') }}" class="nav-link {{ request()->routeIs('reports.low_stock') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Low Stock Alert @if($lowStockCount)<span class="badge badge-warning right">{{ $lowStockCount }}</span>@endif</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.stock_ledger') }}" class="nav-link {{ request()->routeIs('reports.stock_ledger') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Stock Ledger</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.stock_valuation') }}" class="nav-link {{ request()->routeIs('reports.stock_valuation') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Stock Valuation</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.stock') }}" class="nav-link {{ request()->routeIs('reports.stock') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Main Stock</p>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ route('reports.dead.stock') }}" class="nav-link {{ request()->routeIs('reports.dead.stock') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Dead Stock</p>
                                </a>
                            </li>

                        </ul>
                    </li>
                    @endif

                    {{-- Supplier Report --}}
                    @if($isAdmin || auth()->user()->can('read supplier report'))
                    <li class="nav-item {{ request()->routeIs('supplier.due.report') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('supplier.due.report') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Supplier Report <i class="fas fa-angle-left right"></i></p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('supplier.due.report') }}" class="nav-link {{ request()->routeIs('supplier.due.report') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Due Report</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endif

                    {{-- Sales Report (Profit/Loss) --}}
                    @if($isAdmin || auth()->user()->can('read sales report'))
                    <li class="nav-item {{ request()->routeIs('reports.sales_summary', 'reports.net-profit', 'reports.product.wise.profit', 'reports.invoice.wise.profit', 'reports.sales_item', 'reports.imei') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('reports.sales_summary', 'reports.net-profit', 'reports.product.wise.profit', 'reports.invoice.wise.profit', 'reports.sales_item', 'reports.imei') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Sales Report <i class="fas fa-angle-left right"></i></p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('reports.net-profit') }}" class="nav-link {{ request()->routeIs('reports.net-profit') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Net Profit</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.product.wise.profit') }}" class="nav-link {{ request()->routeIs('reports.product.wise.profit') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Product Wise Profit</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.invoice.wise.profit') }}" class="nav-link {{ request()->routeIs('reports.invoice.wise.profit') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Invoice Wise Profit</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.sales_summary') }}" class="nav-link {{ request()->routeIs('reports.sales_summary') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Sales Summary</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.sales_item') }}" class="nav-link {{ request()->routeIs('reports.sales_item') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Item Wise Sales</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.imei') }}" class="nav-link {{ request()->routeIs('reports.imei') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>IMEI Tracking</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endif

                    {{-- Customer Report --}}
                    @if($isAdmin || auth()->user()->can('read customer report'))
                    <li class="nav-item {{ request()->routeIs('customer.due.report', 'customer.payment.history') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('customer.due.report', 'customer.payment.history') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Customer Report <i class="fas fa-angle-left right"></i></p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('customer.due.report') }}" class="nav-link {{ request()->routeIs('customer.due.report') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Due Report</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('customer.payment.history') }}" class="nav-link {{ request()->routeIs('customer.payment.history') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Payment History</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endif

                    {{-- Expense Report --}}
                    @if($isAdmin || auth()->user()->can('read expense report'))
                    <li class="nav-item {{ request()->routeIs('reports.category.expense*', 'reports.monthly.summary', 'reports.expense.method', 'reports.custom.range.expense') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('reports.category.expense*', 'reports.monthly.summary', 'reports.expense.method', 'reports.custom.range.expense') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Expense Report <i class="fas fa-angle-left right"></i></p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('reports.category.expense') }}" class="nav-link {{ request()->routeIs('reports.category.expense') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Category Wise</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.monthly.summary') }}" class="nav-link {{ request()->routeIs('reports.monthly.summary') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Monthly Summary</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.expense.method') }}" class="nav-link {{ request()->routeIs('reports.expense.method') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Payment Method</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('reports.custom.range.expense') }}" class="nav-link {{ request()->routeIs('reports.custom.range.expense') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Date Wise</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    @endif

                </ul>
            </li>
        @endif
        <!-- ======= Report  ========== -->


       <!--  ======== Warranty ========= -->
        @if($user->role == 'admin' || auth()->user()->can('read warranty'))
            <li class="nav-item">
                <a href="{{ route('warranty.index') }}" class="nav-link {{ request()->routeIs('warranty.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-shield-alt"></i>
                    <p>Warranty</p>
                </a>
            </li>
        @endif
       <!--  ======== Warranty ========= -->

       <!--  ======== Used Phones ========= -->
        @if($user->role == 'admin' || auth()->user()->can('read used phone'))
            <li class="nav-item">
                <a href="{{ route('used-phone.index') }}" class="nav-link {{ request()->routeIs('used-phone.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-recycle"></i>
                    <p>Used Phones</p>
                </a>
            </li>
        @endif
       <!--  ======== Used Phones ========= -->

       <!--  ======== Cash Closing ========= -->
        @if($user->role == 'admin' || auth()->user()->hasAnyPermission(['open cash session', 'read cash closing']))
            <li class="nav-item">
                <a href="{{ route('cash-closing.index') }}" class="nav-link {{ request()->routeIs('cash-closing.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-money-bill-wave"></i>
                    <p>Cash Closing</p>
                </a>
            </li>
        @endif
       <!--  ======== Cash Closing ========= -->

       <!--  ======== Accounts & Finance ========= -->
        @php $isAdmin = $user->role == 'admin'; @endphp
        @if($isAdmin || auth()->user()->hasAnyPermission(['read accounts', 'read finance report', 'reconcile account', 'manage accounts']))
            <li class="nav-item {{ request()->routeIs('finance.*') ? 'menu-open' : '' }}">
                <a href="#" class="nav-link {{ request()->routeIs('finance.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-landmark"></i>
                    <p>Accounts & Finance <i class="fas fa-angle-left right"></i></p>
                </a>
                <ul class="nav nav-treeview">
                    @if($isAdmin || auth()->user()->can('read accounts'))
                    <li class="nav-item">
                        <a href="{{ route('finance.accounts.index') }}" class="nav-link {{ request()->routeIs('finance.accounts.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Chart of Accounts</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('read accounts'))
                    <li class="nav-item">
                        <a href="{{ route('finance.payments.index') }}" class="nav-link {{ request()->routeIs('finance.payments.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Receipts & Payments</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('read accounts'))
                    <li class="nav-item">
                        <a href="{{ route('finance.income.index') }}" class="nav-link {{ request()->routeIs('finance.income.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Other Income</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('read accounts'))
                    <li class="nav-item">
                        <a href="{{ route('finance.transfers.index') }}" class="nav-link {{ request()->routeIs('finance.transfers.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Cash / Bank Transfer</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('reconcile account'))
                    <li class="nav-item">
                        <a href="{{ route('finance.reconciliation.index') }}" class="nav-link {{ request()->routeIs('finance.reconciliation.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Reconciliation</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('read accounts'))
                    <li class="nav-item">
                        <a href="{{ route('finance.journal.index') }}" class="nav-link {{ request()->routeIs('finance.journal.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Journal / Day Book</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('read finance report'))
                    <li class="nav-item">
                        <a href="{{ route('finance.reports.income-expense') }}" class="nav-link {{ request()->routeIs('finance.reports.income-expense') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Income & Expense</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('read finance report'))
                    <li class="nav-item">
                        <a href="{{ route('finance.reports.profit-loss') }}" class="nav-link {{ request()->routeIs('finance.reports.profit-loss') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Profit & Loss</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('read finance report'))
                    <li class="nav-item">
                        <a href="{{ route('finance.reports.balance-sheet') }}" class="nav-link {{ request()->routeIs('finance.reports.balance-sheet') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Balance Sheet</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('read finance report'))
                    <li class="nav-item">
                        <a href="{{ route('finance.reports.trial-balance') }}" class="nav-link {{ request()->routeIs('finance.reports.trial-balance') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Trial Balance</p>
                        </a>
                    </li>
                    @endif
                    @if($isAdmin || auth()->user()->can('manage accounts'))
                    <li class="nav-item">
                        <a href="{{ route('finance.years.index') }}" class="nav-link {{ request()->routeIs('finance.years.*') ? 'active' : '' }}">
                            <i class="far fa-circle nav-icon"></i><p>Financial Years</p>
                        </a>
                    </li>
                    @endif
                </ul>
            </li>
        @endif
       <!--  ======== Accounts & Finance ========= -->


       <!--  ======== Expense ========= -->
        @php
            $isAdmin = $user->role == 'admin';
        @endphp

        @if($isAdmin || auth()->user()->hasAnyPermission(['create expense', 'read expense', 'create expense category', 'read expense category']))
            <li class="nav-item {{ request()->routeIs('expense.*','expense-category.*') ? 'menu-open' : '' }}">
                <a href="#" class="nav-link {{ request()->routeIs('expense.*','expense-category.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-wallet"></i>
                    <p>
                        Expense Management
                        <i class="fas fa-angle-left right"></i>
                    </p>
                </a>

                <ul class="nav nav-treeview">

                    {{-- Expense Category Section --}}
                    @if($isAdmin || auth()->user()->hasAnyPermission(['create expense category', 'read expense category']))
                    <li class="nav-item {{ request()->routeIs('expense-category.*') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('expense-category.*') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Category <i class="fas fa-angle-left right"></i></p>
                        </a>

                        <ul class="nav nav-treeview">
                            @if($isAdmin || auth()->user()->can('create expense category'))
                            <li class="nav-item">
                                <a href="{{ route('expense-category.create') }}"
                                   class="nav-link {{ request()->routeIs('expense-category.create') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Add Category</p>
                                </a>
                            </li>
                            @endif

                            @if($isAdmin || auth()->user()->can('read expense category'))
                            <li class="nav-item">
                                <a href="{{ route('expense-category.index') }}"
                                   class="nav-link {{ request()->routeIs('expense-category.index') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Manage Category</p>
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                    @endif

                    {{-- Expense Section --}}
                    @if($isAdmin || auth()->user()->hasAnyPermission(['create expense', 'read expense']))
                    <li class="nav-item {{ request()->routeIs('expense.create', 'expense.index') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('expense.*') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Expense <i class="fas fa-angle-left right"></i></p>
                        </a>

                        <ul class="nav nav-treeview">
                            @if($isAdmin || auth()->user()->can('create expense'))
                            <li class="nav-item">
                                <a href="{{ route('expense.create') }}"
                                   class="nav-link {{ request()->routeIs('expense.create') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Add Expense</p>
                                </a>
                            </li>
                            @endif

                            @if($isAdmin || auth()->user()->can('read expense'))
                            <li class="nav-item">
                                <a href="{{ route('expense.index') }}"
                                   class="nav-link {{ request()->routeIs('expense.index') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Manage Expense</p>
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                    @endif

                </ul>
            </li>
        @endif
       <!--  ======== Expense ========= -->


       <!--  ======== salesman ========= -->
        @php
            $isAdmin = $user->role == 'admin';
        @endphp

        {{-- staff route gulo role:admin --}}
        @if($isAdmin)
            <li class="nav-item {{ request()->routeIs('salesman.*') ? 'menu-open' : '' }}">
                <a href="#" class="nav-link {{ request()->routeIs('salesman.*') ? 'active' : '' }}">
                    <i class="nav-icon fas fa-user-tie"></i>
                    <p>
                        Employee Management
                        <i class="fas fa-angle-left right"></i>
                    </p>
                </a>

                <ul class="nav nav-treeview">

                    {{-- Salesman Section --}}
                    @if($isAdmin)
                    <li class="nav-item {{ request()->routeIs('salesman.*') ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ request()->routeIs('salesman.*') ? 'active' : '' }}">
                            <i class="far fa-folder nav-icon"></i>
                            <p>Staff <i class="fas fa-angle-left right"></i></p>
                        </a>

                        <ul class="nav nav-treeview">
                            @if($isAdmin)
                            <li class="nav-item">
                                <a href="{{ route('salesman.create') }}"
                                   class="nav-link {{ request()->routeIs('salesman.create') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Add Staff</p>
                                </a>
                            </li>
                            @endif

                            @if($isAdmin)
                            <li class="nav-item">
                                <a href="{{ route('salesman.index') }}"
                                   class="nav-link {{ request()->routeIs('salesman.index') ? 'active' : '' }}">
                                    <i class="far fa-circle nav-icon"></i>
                                    <p>Manage Staff</p>
                                </a>
                            </li>
                            @endif
                        </ul>
                    </li>
                    @endif

                </ul>
            </li>
        @endif
       <!--  ======== salesman ========= -->


       <!--  ======== permission ========= -->
       @if($user->role=='admin')
       <li class="nav-item {{ request()->routeIs('permission.*','salesman-permission.*') ? 'menu-open' : '' }}">
            <a href="#" class="nav-link {{ request()->routeIs('permission.*','salesman-permission.*') ? 'active' : '' }}">
                <i class="nav-icon fas fa-user-shield"></i>
                <p>
                    Permission Management
                    <i class="fas fa-angle-left right"></i>
                </p>
            </a>

            <ul class="nav nav-treeview">

                <li class="nav-item {{ request()->routeIs('permission.*') ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ request()->routeIs('permission.*') ? 'active' : '' }}">
                        <i class="far fa-folder nav-icon"></i>
                        <p>Permission <i class="fas fa-angle-left right"></i></p>
                    </a>

                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="{{ route('permission.create') }}"
                               class="nav-link {{ request()->routeIs('permission.create') ? 'active' : '' }}">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Add Permission</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('permission.index') }}"
                               class="nav-link {{ request()->routeIs('permission.index') ? 'active' : '' }}">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Manage Permission</p>
                            </a>
                        </li>
                    </ul>
                </li>

                 <li class="nav-item {{ request()->routeIs('salesman-permission.*') ? 'menu-open' : '' }}">
                    <a href="#" class="nav-link {{ request()->routeIs('salesman-permission.*') ? 'active' : '' }}">
                        <i class="far fa-folder nav-icon"></i>
                        <p>Assign Permission <i class="fas fa-angle-left right"></i></p>
                    </a>

                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="{{ route('salesman-permission.assign.form') }}"
                               class="nav-link {{ request()->routeIs('salesman-permission.assign.form') ? 'active' : '' }}">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Set Permission </p>
                            </a>
                        </li>
                    </ul>
                </li>


            </ul>
        </li>
        @endif
       <!--  ======== permission ========= -->


    @if($isAdmin || auth()->user()->hasAnyPermission(['create installment', 'read installment']))
        <li class="nav-item {{ request()->routeIs('installments.*') ? 'menu-open' : '' }}">
            <a href="#" class="nav-link {{ request()->routeIs('installments.*') ? 'active' : '' }}">
                <i class="nav-icon fas fa-calendar-alt"></i>
                <p>
                    Installment
                    <i class="fas fa-angle-left right"></i>
                </p>
            </a>
            <ul class="nav nav-treeview">
                @if($isAdmin || auth()->user()->can('create installment'))
                <li class="nav-item">
                    <a href="{{ route('installments.create') }}" class="nav-link {{ request()->routeIs('installments.create') ? 'active' : '' }}">
                        <i class="far fa-circle nav-icon"></i>
                        <p>Collect Installment</p>
                    </a>
                </li>
                @endif

                @if($isAdmin || auth()->user()->can('read installment'))
                <li class="nav-item">
                    <a href="{{ route('installments.index') }}" class="nav-link {{ request()->routeIs('installments.index') ? 'active' : '' }}">
                        <i class="far fa-circle nav-icon"></i>
                        <p>Manage Installments</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('installments.due') }}" class="nav-link {{ request()->routeIs('installments.due') ? 'active' : '' }}">
                        <i class="far fa-circle nav-icon"></i>
                        <p>Reminders (Due Soon)</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('installments.overdue') }}" class="nav-link {{ request()->routeIs('installments.overdue') ? 'active' : '' }}">
                        <i class="far fa-circle nav-icon"></i>
                        <p>Overdue Report</p>
                    </a>
                </li>
                @endif
            </ul>
        </li>
    @endif

    @if($isAdmin || auth()->user()->hasAnyPermission(['create attendance', 'read attendance']))
        <li class="nav-item {{ request()->routeIs('installments.*') ? 'menu-open' : '' }}">
            <a href="#" class="nav-link {{ request()->routeIs('installments.*') ? 'active' : '' }}">
                <i class="nav-icon fas fa-calendar-alt"></i>
                <p>
                    Attendance
                    <i class="fas fa-angle-left right"></i>
                </p>
            </a>
            <ul class="nav nav-treeview">

                @if($isAdmin || auth()->user()->can('read attendance'))

                <li class="nav-item">
                    <a href="{{ route('attendance.index') }}" class="nav-link {{ request()->routeIs('attendance.index') ? 'active' : '' }}">
                        <i class="far fa-circle nav-icon"></i>
                        <p>Add Attendance</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('attendance.monthly') }}" class="nav-link {{ request()->routeIs('attendance.monthly') ? 'active' : '' }}">
                        <i class="far fa-circle nav-icon"></i>
                        <p>Monthly Attendance</p>
                    </a>
                </li>

                @endif
            </ul>
        </li>
    @endif





         @php
              $settingsActive = request()->routeIs('setting.create') || request()->routeIs('extra-settings.create');
          @endphp

            {{-- setting route shudhu admin er --}}
            @if($user->isAdmin())
            <li class="nav-item {{ $settingsActive ? 'menu-open' : '' }}">
              <a href="#" class="nav-link {{ $settingsActive ? 'active' : '' }}">
                <i class="nav-icon fas fa-cog"></i>
                <p>
                  Settings
                  <i class="fas fa-angle-left right"></i>
                </p>
              </a>
              <ul class="nav nav-treeview">

                <li class="nav-item">
                  <a href="{{ route('setting.create') }}" class="nav-link {{ request()->routeIs('setting.create') ? 'active' : '' }}">
                    <i class="far fa-circle nav-icon"></i>
                    <p>Setting</p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="{{ route('extra-settings.create') }}" class="nav-link {{ request()->routeIs('extra-settings.create') ? 'active' : '' }}">
                    <i class="far fa-circle nav-icon"></i>
                    <p>Extra Setting</p>
                  </a>
                </li>

              </ul>
            </li>
            @endif

             <li class="nav-item">
            <a href="{{ route('profile.change.password') }}" class="nav-link">
              <i class="nav-icon fas fa-lock"></i>
              <p>
                Change Password
              </p>
            </a>
          </li>

        <!-- Logout -->
        <li class="nav-item">
            <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="nav-link">
                <i class="nav-icon fas fa-sign-out-alt"></i>
                <p>Logout</p>
            </a>
            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">@csrf</form>
        </li>

    </ul>
</nav>