<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ExtraSettingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\PermissionController;

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\RamController;
use App\Http\Controllers\RomController;
use App\Http\Controllers\ColorController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductVariantController;
use App\Http\Controllers\AttributeController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\AdditionalController;
use App\Http\Controllers\PurchaseReturnController;

use App\Http\Controllers\StockReportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ExpenseReportController;

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;

use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;

use App\Http\Controllers\AdminController;
use App\Http\Controllers\PermissionAssignToSalesmanController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\InstallmentCollectionController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\StockReconciliationController;
use App\Http\Controllers\ImeiController;
use App\Models\User;


// login kora user ke login form na dekhiye nijer dashboard e
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route(auth()->user()->homeRoute())
        : redirect()->route('login');
});

require __DIR__.'/auth.php';

/*
| Login chara kono page khola jabe na.
| Admin sob kichu korte pare (AppServiceProvider e Gate::before).
| Baki role (manager, salesman, accountant, technician) shudhu tar assign kora permission er route e jete parbe.
| Password bodlale ba inactive korle onno device er session UserSessions diye bondho hoy.
| Notun route e obossoi role:/can:/permission: dite hobe (RouteAuthorizationTest eta check kore).
*/
Route::middleware(['auth'])->group(function () {

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'index'])
            ->name('dashboard')
            ->middleware('role:admin');
        Route::get('/dashboard/imei-search', [AdminController::class, 'imeiSearch'])
            ->name('dashboard.imei-search')
            ->middleware('role:admin');
    });

    // Staff Dashboard (admin chara shob role)
    Route::get('/sales/dashboard', function () {
        return view('back-end.sales.dashboard');
    })->name('sales.dashboard')->middleware('role:' . implode(',', User::staffRoles()));


    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/change/password', [ProfileController::class, 'change_password'])->name('change.password');
        Route::post('/change/password/updated', [ProfileController::class, 'password_updated'])->name('change.password.updated');
    });


    /* Admin only: setting, permission, branch, salesman */
    Route::middleware(['role:admin'])->group(function () {

        /* Setting Routes */
        Route::prefix('setting')->name('setting.')->group(function () {
            Route::get('/create', [SettingController::class, 'create'])->name('create');
            Route::post('/store', [SettingController::class, 'store'])->name('store');
            Route::get('/{id}/edit', [SettingController::class, 'edit'])->name('edit');
            Route::patch('/{id}/update', [SettingController::class, 'update'])->name('update');
        });

        /* Extra Setting Routes */
        Route::prefix('extra-settings')->name('extra-settings.')->group(function () {
            Route::get('/create', [ExtraSettingController::class, 'create'])->name('create');
            Route::post('/store', [ExtraSettingController::class, 'store'])->name('store');
            Route::get('/{id}/edit', [ExtraSettingController::class, 'edit'])->name('edit');
            Route::patch('/{id}/update', [ExtraSettingController::class, 'update'])->name('update');
        });

        /*permission*/
        Route::prefix('permission')->name('permission.')->group(function () {
            Route::get('/', [PermissionController::class, 'index'])->name('index');          // List
            Route::get('/create', [PermissionController::class, 'create'])->name('create');  // Create Form
            Route::post('/store', [PermissionController::class, 'store'])->name('store');    // Store
            Route::get('/edit/{id}', [PermissionController::class, 'edit'])->name('edit');   // Edit Form
            Route::put('/update/{id}', [PermissionController::class, 'update'])->name('update'); // Update
            Route::delete('/delete/{id}', [PermissionController::class, 'destroy'])->name('delete');
        });
        /*permission*/

        /*admin assign to salesman permission*/
        Route::prefix('salesman-permission')->name('salesman-permission.')->group(function () {
            Route::get('/', [PermissionAssignToSalesmanController::class,'assign_permission_form'])
                ->name('assign.form');
            Route::get('/get-permissions', [PermissionAssignToSalesmanController::class,'getAssignPermissions'])
                ->name('get-permissions');
            Route::get('/permissions', [PermissionAssignToSalesmanController::class, 'getAssignPermissions'])->name('ajax');
            Route::post('/assign-permissions', [PermissionAssignToSalesmanController::class,'assignPermissions'])
                ->name('assign.store');
        });
        /*admin assign to salesman permission*/

        /*branch*/
        Route::prefix('branch')->name('branch.')->group(function () {
            Route::get('/', [BranchController::class, 'index'])->name('index');
            Route::get('/create', [BranchController::class, 'create'])->name('create');
            Route::post('/store', [BranchController::class, 'store'])->name('store');
            Route::get('/edit/{id}', [BranchController::class, 'edit'])->name('edit');
            Route::post('/update', [BranchController::class, 'update'])->name('update');
            Route::delete('/delete/{id}', [BranchController::class, 'delete'])->name('delete');
            Route::post('/change/status/{id}', [BranchController::class, 'changeStatus'])->name('status.change');
        });
        /*branch*/

        /* employee (salesman) - salesman nijer ba admin er account edit korte parbe na */
        Route::prefix('salesman')->name('salesman.')->group(function () {
            Route::get('/', [EmployeeController::class, 'index'])->name('index');
            Route::get('/create', [EmployeeController::class, 'create'])->name('create');
            Route::post('/store', [EmployeeController::class, 'store'])->name('store');
            Route::get('/edit/{id}', [EmployeeController::class, 'edit'])->name('edit');
            Route::put('/update/{id}', [EmployeeController::class, 'update'])->name('update');
            Route::delete('/delete/{id}', [EmployeeController::class, 'destroy'])->name('delete');
        });
        /* employee */
    });


    /* category */
    Route::prefix('category')->name('category.')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])->name('index')->middleware('can:read category');
        Route::get('/create', [CategoryController::class, 'create'])->name('create')->middleware('can:create category');
        Route::post('/store', [CategoryController::class, 'store'])->name('store')->middleware('can:create category');
        Route::get('/edit/{id}', [CategoryController::class, 'edit'])->name('edit')->middleware('can:update category');
        Route::put('/update/{id}', [CategoryController::class, 'update'])->name('update')->middleware('can:update category');
        Route::delete('/delete/{id}', [CategoryController::class, 'delete'])->name('delete')->middleware('can:delete category');
        Route::post('/change/status/{id}', [CategoryController::class, 'changeStatus'])->name('status.change')->middleware('can:update category');
    });
    /* category */

    /* brand */
    Route::prefix('brand')->name('brand.')->group(function () {
        Route::get('/', [BrandController::class, 'index'])->name('index')->middleware('can:read brand');
        Route::get('/create', [BrandController::class, 'create'])->name('create')->middleware('can:create brand');
        Route::post('/store', [BrandController::class, 'store'])->name('store')->middleware('can:create brand');
        Route::get('/edit/{id}', [BrandController::class, 'edit'])->name('edit')->middleware('can:update brand');
        Route::put('/update/{id}', [BrandController::class, 'update'])->name('update')->middleware('can:update brand');
        Route::delete('/delete/{id}', [BrandController::class, 'delete'])->name('delete')->middleware('can:delete brand');
        Route::post('/change/status/{id}', [BrandController::class, 'changeStatus'])->name('status.change')->middleware('can:update brand');
    });
    /* brand */

    /* ram */
    Route::prefix('ram')->name('ram.')->group(function () {
        Route::get('/', [RamController::class, 'index'])->name('index')->middleware('can:read ram');
        Route::get('/create', [RamController::class, 'create'])->name('create')->middleware('can:create ram');
        Route::post('/store', [RamController::class, 'store'])->name('store')->middleware('can:create ram');
        Route::get('/edit/{id}', [RamController::class, 'edit'])->name('edit')->middleware('can:update ram');
        Route::put('/update/{id}', [RamController::class, 'update'])->name('update')->middleware('can:update ram');
        Route::delete('/delete/{id}', [RamController::class, 'delete'])->name('delete')->middleware('can:delete ram');
        Route::post('/change/status/{id}', [RamController::class, 'changeStatus'])->name('status.change')->middleware('can:update ram');
    });
    /* ram */

    /* rom */
    Route::prefix('rom')->name('rom.')->group(function () {
        Route::get('/', [RomController::class, 'index'])->name('index')->middleware('can:read rom');
        Route::get('/create', [RomController::class, 'create'])->name('create')->middleware('can:create rom');
        Route::post('/store', [RomController::class, 'store'])->name('store')->middleware('can:create rom');
        Route::get('/edit/{id}', [RomController::class, 'edit'])->name('edit')->middleware('can:update rom');
        Route::put('/update/{id}', [RomController::class, 'update'])->name('update')->middleware('can:update rom');
        Route::delete('/delete/{id}', [RomController::class, 'delete'])->name('delete')->middleware('can:delete rom');
        Route::post('/change/status/{id}', [RomController::class, 'changeStatus'])->name('status.change')->middleware('can:update rom');
    });
    /* rom */

    /* color */
    Route::prefix('color')->name('color.')->group(function () {
        Route::get('/', [ColorController::class, 'index'])->name('index')->middleware('can:read color');
        Route::get('/create', [ColorController::class, 'create'])->name('create')->middleware('can:create color');
        Route::post('/store', [ColorController::class, 'store'])->name('store')->middleware('can:create color');
        Route::get('/edit/{id}', [ColorController::class, 'edit'])->name('edit')->middleware('can:update color');
        Route::put('/update/{id}', [ColorController::class, 'update'])->name('update')->middleware('can:update color');
        Route::delete('/delete/{id}', [ColorController::class, 'delete'])->name('delete')->middleware('can:delete color');
        Route::post('/change/status/{id}', [ColorController::class, 'changeStatus'])->name('status.change')->middleware('can:update color');
    });
    /* color */

    /* product */
    Route::prefix('product')->name('product.')->group(function () {
        Route::get('/voice-search', [ProductController::class, 'voice_search'])->name('voice_search')->middleware('can:read product');

        Route::get('/', [ProductController::class, 'index'])->name('index')->middleware('can:read product');
        Route::get('/create', [ProductController::class, 'create'])->name('create')->middleware('can:create product');
        Route::post('/store', [ProductController::class, 'store'])->name('store')->middleware('can:create product');
        Route::get('/edit/{id}', [ProductController::class, 'edit'])->name('edit')->middleware('can:update product');
        Route::put('/update/{id}', [ProductController::class, 'update'])->name('update')->middleware('can:update product');
        Route::delete('/delete/{id}', [ProductController::class, 'delete'])->name('delete')->middleware('can:delete product');
        Route::post('/change/status/{id}', [ProductController::class, 'changeStatus'])->name('status.change')->middleware('can:update product');

        Route::get('/barcode/{id}', [ProductController::class, 'printBarcode'])->name('print.barcode')->middleware('can:read product');

        // product details + variant (RAM/ROM/color) list
        Route::get('/show/{id}', [ProductController::class, 'show'])->name('show')->middleware('can:read product');

        // variant: SKU, barcode, dam, status, threshold (quantity na - seta stock workflow e)
        Route::post('/{id}/variant/store', [ProductVariantController::class, 'store'])->name('variant.store')->middleware('can:update product');
        Route::put('/variant/update/{id}', [ProductVariantController::class, 'update'])->name('variant.update')->middleware('can:update product');
        Route::post('/variant/status/{id}', [ProductVariantController::class, 'changeStatus'])->name('variant.status')->middleware('can:update product');
        Route::delete('/variant/delete/{id}', [ProductVariantController::class, 'delete'])->name('variant.delete')->middleware('can:delete product');
    });
    /* product */

    Route::post('/attribute/store', [AttributeController::class, 'store'])
        ->name('attribute.store')
        ->middleware('can:create product');


    /* supplier */
    Route::prefix('supplier')->name('supplier.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index')->middleware('can:read supplier');
        Route::get('/create', [SupplierController::class, 'create'])->name('create')->middleware('can:create supplier');
        Route::post('/store', [SupplierController::class, 'store'])->name('store')->middleware('can:create supplier');
        Route::get('/edit/{id}', [SupplierController::class, 'edit'])->name('edit')->middleware('can:update supplier');
        Route::put('/update/{id}', [SupplierController::class, 'update'])->name('update')->middleware('can:update supplier');
        Route::delete('/delete/{id}', [SupplierController::class, 'delete'])->name('delete')->middleware('can:delete supplier');
        Route::post('/change/status/{id}', [SupplierController::class, 'changeStatus'])->name('status.change')->middleware('can:update supplier');

        Route::post('/store-ajax', [SupplierController::class, 'storeAjax'])->name('store.ajax')->middleware('can:create supplier');
        Route::get('/ledger/{id}', [SupplierController::class, 'ledger'])->name('ledger')->middleware('can:read supplier');
        Route::get('/payment-history/{id}', [SupplierController::class, 'paymentHistory'])->name('payment.history')->middleware('can:read supplier');

        Route::get('/due-report', [SupplierController::class, 'dueReport'])->name('due.report')->middleware('can:read supplier report');

        Route::get('/purchase-history/{id}', [SupplierController::class, 'purchaseHistory'])->name('purchase_history')->middleware('can:read supplier');
        Route::get('/show/{id}', [SupplierController::class, 'show'])->name('show')->middleware('can:read supplier');
        Route::get('/statement/{id}', [SupplierController::class, 'statement'])->name('statement')->middleware('can:read supplier');

        // payment / agrim / supplier er refund ('update purchase' purono invoice payment er moto)
        Route::get('/{id}/payment', [SupplierPaymentController::class, 'create'])->name('payment.create')->middleware('permission:create supplier payment|update purchase');
        Route::post('/{id}/payment', [SupplierPaymentController::class, 'store'])->name('payment.store')->middleware('permission:create supplier payment|update purchase');
        Route::post('/payment/{paymentId}/void', [SupplierPaymentController::class, 'void'])->name('payment.void')->middleware('can:void supplier payment');
    });
    /* supplier */

    /* purchase */
    Route::prefix('purchase')->name('purchase.')->group(function () {
        Route::get('/', [PurchaseController::class, 'index'])->name('index')->middleware('can:read purchase');
        Route::get('/create', [PurchaseController::class, 'create'])->name('create')->middleware('can:create purchase');
        Route::post('/store', [PurchaseController::class, 'store'])->name('store')->middleware('can:create purchase');
        Route::get('/edit/{id}', [PurchaseController::class, 'edit'])->name('edit')->middleware('can:update purchase');
        Route::put('/update/{id}', [PurchaseController::class, 'update'])->name('update')->middleware('can:update purchase');
        Route::delete('/delete/{id}', [PurchaseController::class, 'delete'])->name('delete')->middleware('can:delete purchase');
        Route::post('/change/status/{id}', [PurchaseController::class, 'changeStatus'])->name('status.change')->middleware('can:update purchase');

        Route::get('/show/{id}', [PurchaseController::class, 'show'])->name('show')->middleware('can:read purchase');
        Route::post('/payment', [PurchaseController::class, 'updatePayment'])->name('payment')->middleware('permission:create supplier payment|update purchase');

        Route::get('/print/{id}', [PurchaseController::class, 'printInvoice'])->name('print')->middleware('can:read purchase');
    });
    /* purchase */

    /* purchase return */
    Route::prefix('purchase-return')->name('purchase-return.')->group(function () {
        Route::get('/', [PurchaseReturnController::class, 'index'])->name('index')->middleware('can:read purchase return');
        Route::get('/create', [PurchaseReturnController::class, 'create'])->name('create')->middleware('can:create purchase return');
        Route::post('/store', [PurchaseReturnController::class, 'store'])->name('store')->middleware('can:create purchase return');
        Route::get('/available-imeis/{stock_id}', [PurchaseReturnController::class, 'availableImeis'])->name('available_imeis')->middleware('can:create purchase return');
        Route::get('/show/{id}', [PurchaseReturnController::class, 'show'])->name('show')->middleware('can:read purchase return');
        Route::get('/history', [PurchaseReturnController::class, 'history'])->name('history')->middleware('can:read purchase return');
        Route::get('/show-by-ref/{ref_no}', [PurchaseReturnController::class, 'showByRef'])->name('show_by_ref')->middleware('can:read purchase return');
    });
    /* purchase return */


    /* customer */
    Route::prefix('customer')->name('customer.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index')->middleware('can:read customer');
        Route::get('/create', [CustomerController::class, 'create'])->name('create')->middleware('can:create customer');
        Route::post('/store', [CustomerController::class, 'store'])->name('store')->middleware('can:create customer');
        Route::get('/edit/{id}', [CustomerController::class, 'edit'])->name('edit')->middleware('can:update customer');
        Route::put('/update/{id}', [CustomerController::class, 'update'])->name('update')->middleware('can:update customer');
        Route::delete('/delete/{id}', [CustomerController::class, 'delete'])->name('delete')->middleware('can:delete customer');
        Route::post('/change/status/{id}', [CustomerController::class, 'changeStatus'])->name('status.change')->middleware('can:update customer');

        Route::post('/quick-store', [CustomerController::class, 'quickStore'])->name('quick.store')->middleware('can:create customer');
        Route::get('/ledger/{id}', [CustomerController::class, 'ledger'])->name('ledger')->middleware('can:read customer');

        Route::get('/payment-history', [CustomerController::class, 'paymentHistory'])->name('payment.history')->middleware('can:read customer report');

        Route::get('/payment-history-indivisual/{id}', [CustomerController::class, 'paymentHistoryIndivisual'])->name('payment.history.indivisual')->middleware('can:read customer');

        Route::get('/due-report', [CustomerController::class, 'dueReport'])->name('due.report')->middleware('can:read customer report');

        Route::get('/purchase-history/{id}', [CustomerController::class, 'purchaseHistory'])->name('purchase_history')->middleware('can:read customer');

        Route::get('/get-due-invoices/{id}', [CustomerController::class, 'getDueInvoices'])->middleware('can:update customer');
        Route::post('/invoice-payment-store', [CustomerController::class, 'storeInvoicePayment'])->name('invoice_payment.store')->middleware('can:update customer');
    });
    /* customer */

    /* sale */
    Route::prefix('sale')->name('sale.')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index')->middleware('can:read sale');
        Route::get('/create', [SaleController::class, 'create'])->name('create')->middleware('can:create sale');
        Route::post('/store', [SaleController::class, 'store'])->name('store')->middleware('can:create sale');
        Route::get('/edit/{id}', [SaleController::class, 'edit'])->name('edit')->middleware('can:update sale');
        Route::put('/update/{id}', [SaleController::class, 'update'])->name('update')->middleware('can:update sale');
        Route::delete('/delete/{id}', [SaleController::class, 'delete'])->name('delete')->middleware('can:delete sale');
        Route::post('/change/status/{id}', [SaleController::class, 'changeStatus'])->name('status.change')->middleware('can:update sale');

        Route::get('/show/{id}', [SaleController::class, 'show'])->name('show')->middleware('can:read sale');
        Route::post('/payment', [SaleController::class, 'updatePayment'])->name('payment')->middleware('can:update sale');

        Route::get('/print/{id}', [SaleController::class, 'printInvoice'])->name('print')->middleware('can:read sale');
        Route::get('/invoice/print/{id}', [SaleController::class, 'InvoicePrintSystem'])->name('invoice.print.system')->middleware('can:read sale');

        Route::get('/find-by-imei/{imei}', [SaleController::class, 'findByImei'])->middleware('can:create sale');

        Route::get('/get-available-imeis/{stock_id}',[SaleController::class, 'getAvailableImeis'])->middleware('can:create sale');
    });
    /* sale */

    /* sale return */
    Route::prefix('sale-return')->name('sale-return.')->group(function () {
        Route::get('/', [SaleReturnController::class, 'index'])->name('index')->middleware('can:read return');
        Route::get('/find-invoice', [SaleReturnController::class, 'findInvoiceForReturn'])->name('find')->middleware('can:create return');
        Route::get('/create', [SaleReturnController::class, 'create'])->name('create')->middleware('can:create return');
        Route::post('/store', [SaleReturnController::class, 'store'])->name('store')->middleware('can:create return');
        Route::get('/show/{id}', [SaleReturnController::class, 'show'])->name('show')->middleware('can:read return');
        Route::get('/history', [SaleReturnController::class, 'history'])->name('history')->middleware('can:read return');
        Route::get('/show-by-ref/{ref_no}', [SaleReturnController::class, 'showByRef'])->name('show_by_ref')->middleware('can:read return');
    });
    /* sale return */

    /* expense */
    Route::prefix('expense-category')->name('expense-category.')->group(function () {
        Route::get('/', [ExpenseCategoryController::class, 'index'])->name('index')->middleware('can:read expense category');
        Route::get('/create', [ExpenseCategoryController::class, 'create'])->name('create')->middleware('can:create expense category');
        Route::post('/store', [ExpenseCategoryController::class, 'store'])->name('store')->middleware('can:create expense category');
        Route::get('/edit/{id}', [ExpenseCategoryController::class, 'edit'])->name('edit')->middleware('can:update expense category');
        Route::put('/update/{id}', [ExpenseCategoryController::class, 'update'])->name('update')->middleware('can:update expense category');
        Route::delete('/delete/{id}', [ExpenseCategoryController::class, 'delete'])->name('delete')->middleware('can:delete expense category');
        Route::post('/change/status/{id}', [ExpenseCategoryController::class, 'changeStatus'])->name('status.change')->middleware('can:update expense category');
    });

    Route::prefix('expense')->name('expense.')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('index')->middleware('can:read expense');
        Route::get('/create', [ExpenseController::class, 'create'])->name('create')->middleware('can:create expense');
        Route::post('/store', [ExpenseController::class, 'store'])->name('store')->middleware('can:create expense');
        Route::get('/edit/{id}', [ExpenseController::class, 'edit'])->name('edit')->middleware('can:update expense');
        Route::put('/update/{id}', [ExpenseController::class, 'update'])->name('update')->middleware('can:update expense');
        Route::delete('/delete/{id}', [ExpenseController::class, 'delete'])->name('delete')->middleware('can:delete expense');
        Route::post('/change/status/{id}', [ExpenseController::class, 'changeStatus'])->name('status.change')->middleware('can:update expense');
    });
    /* expense */


    /* get (sale/purchase form er ajax) */
    Route::prefix('get')->name('get.')->group(function () {
        // product er kenar dam thake, tai shudhu purchase form er jonno
        Route::get('/product-details/{id}', [AdditionalController::class, 'getProductDetails'])->name('product.details')->middleware('permission:create purchase');
        Route::get('/products-by-category/{id}', [AdditionalController::class, 'getProductsByCategory'])->middleware('permission:read stock report|read product|create purchase');
        Route::get('/product-variants/{id}', [AdditionalController::class, 'getProductVariants'])->name('product.variants')->middleware('permission:create purchase');
    });
    /* get */

    /* reports */
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/dead-stock', [StockReportController::class, 'DeadStockReport'])->name('dead.stock')->middleware('can:read stock report');
        Route::get('/stock', [StockReportController::class, 'stockReport'])->name('stock')->middleware('can:read stock report');
        // IMEI khoja + puro itihash; IMEI stock list (status onujayi)
        Route::get('/imei-tracking', [ImeiController::class, 'search'])->name('imei')->middleware('permission:read sales report|search imei');
        Route::get('/imei-stock', [ImeiController::class, 'index'])->name('imei.stock')->middleware('permission:read stock report|search imei');
        Route::get('/sales-summary', [ReportController::class, 'salesSummary'])->name('sales_summary')->middleware('can:read sales report');
        Route::get('/sales-item-wise', [ReportController::class, 'salesItemReport'])->name('sales_item')->middleware('can:read sales report');

        Route::get('/category-expense', [ExpenseReportController::class, 'categoryWiseReport'])->name('category.expense')->middleware('can:read expense report');
        Route::get('/monthly-summary', [ExpenseReportController::class, 'monthlySummary'])->name('monthly.summary')->middleware('can:read expense report');

        Route::get('/net-profit', [ReportController::class, 'netProfitReport'])->name('net-profit')->middleware('can:read sales report');
        Route::get('/expense-by-method', [ReportController::class, 'expenseByMethod'])->name('expense.method')->middleware('can:read expense report');
        Route::get('/custom-range-expense', [ReportController::class, 'customRangeReport'])->name('custom.range.expense')->middleware('can:read expense report');

        Route::get('/product-wise-profit', [ReportController::class, 'productWiseProfit'])->name('product.wise.profit')->middleware('can:read sales report');

        Route::get('/invoice-wise-profit', [ReportController::class, 'invoiceWiseProfit'])->name('invoice.wise.profit')->middleware('can:read sales report');
    });
    /* reports */


    /* IMEI status: reserve, repair e pathano/ferot, damaged, thik hoye available */
    Route::post('/imei/status/{id}', [ImeiController::class, 'changeStatus'])->name('imei.status')->middleware('can:manage imei');

    /* stock reconciliation (IMEI registration / quantity correction / write-off, approval shoho) */
    Route::prefix('stock-reconciliation')->name('stock-reconciliation.')->group(function () {
        Route::get('/', [StockReconciliationController::class, 'index'])->name('index')->middleware('can:read stock adjustment');
        Route::get('/create', [StockReconciliationController::class, 'create'])->name('create')->middleware('can:create stock adjustment');
        Route::post('/store', [StockReconciliationController::class, 'store'])->name('store')->middleware('can:create stock adjustment');
        Route::get('/show/{id}', [StockReconciliationController::class, 'show'])->name('show')->middleware('can:read stock adjustment');
        Route::post('/approve/{id}', [StockReconciliationController::class, 'approve'])->name('approve')->middleware('can:approve stock adjustment');
        Route::post('/reject/{id}', [StockReconciliationController::class, 'reject'])->name('reject')->middleware('can:approve stock adjustment');
    });
    /* stock reconciliation */


    /* installment */
    Route::prefix('installments')->name('installments.')->group(function () {
        Route::get('/', [InstallmentCollectionController::class, 'index'])->name('index')->middleware('can:read installment');
        Route::get('/create', [InstallmentCollectionController::class, 'create'])->name('create')->middleware('can:create installment');
        Route::post('/store', [InstallmentCollectionController::class, 'store'])->name('store')->middleware('can:create installment');

        Route::get('/due-list', [InstallmentCollectionController::class, 'dueList'])->name('due')->middleware('can:read installment');

        // কাস্টমার সিলেক্ট করলে কিস্তি লোড করার জন্য AJAX রুট
        Route::get('/get-customer-installments/{id}', [InstallmentCollectionController::class, 'getCustomerInstallments'])->middleware('can:create installment');
    });
    /* installment */

    /* attendance */
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->name('index')->middleware('can:read attendance');
        Route::get('/monthly', [AttendanceController::class, 'monthly'])->name('monthly')->middleware('can:read attendance');
        Route::get('/create', [AttendanceController::class, 'create'])->name('create')->middleware('can:create attendance');
        Route::post('/store', [AttendanceController::class, 'store'])->name('store')->middleware('can:create attendance');
    });
    /* attendance */

});
