<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Catalog\PriceListController;
use App\Http\Controllers\Catalog\ProductCategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ServiceController;
use App\Http\Controllers\Crm\CustomerController;
use App\Http\Controllers\Crm\ContactController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Warehouse\WarehouseController;
use App\Http\Controllers\Warehouse\SupplierController;
use App\Http\Controllers\Warehouse\StockEntryController;
use App\Http\Controllers\Warehouse\StockExitController;
use App\Http\Controllers\Warehouse\StockTransferController;
use App\Http\Controllers\Warehouse\InventoryCountController;
use App\Http\Controllers\Sales\QuotationController;
use App\Http\Controllers\Sales\OrderController;
use App\Http\Controllers\Reports\ProfitController;
use App\Http\Controllers\Reports\ARAgingController;
use App\Http\Controllers\Reports\APAgingController;
use App\Http\Controllers\Reports\VatReportController;
use App\Http\Controllers\Reports\InventoryReportController;
use App\Http\Controllers\Reports\CashFlowController;
use App\Http\Controllers\Reports\IncomeStatementController;
use App\Http\Controllers\Sales\CommissionController;
use App\Http\Controllers\Sales\ContractController;
use App\Http\Controllers\Sales\SalesReturnController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\TaskController;
use App\Http\Controllers\Support\TicketController;
use App\Http\Controllers\Support\WarrantyController;
use App\Http\Controllers\Accounting\CashVoucherController;
use App\Http\Controllers\Accounting\FundController;
use App\Http\Controllers\Accounting\InvoiceController;
use App\Http\Controllers\Accounting\PaymentController;
use App\Http\Controllers\Reports\FundLedgerController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchaseInvoiceController;
use App\Http\Controllers\Purchasing\PurchaseInvoicePaymentController;
use App\Http\Controllers\Purchasing\PurchaseContractController;
use App\Http\Controllers\Purchasing\PurchaseContractPaymentScheduleController;
use App\Http\Controllers\Purchasing\PurchaseReturnController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Documents\DocumentTypeController;
use App\Http\Controllers\Reports\BalanceSheetController;
use App\Http\Controllers\Reports\TrialBalanceController;
use App\Http\Controllers\Reports\GeneralJournalController;
use App\Http\Controllers\Reports\AccountLedgerController;
use App\Http\Controllers\Reports\ExpenseDetailController;
use App\Http\Controllers\Reports\FixedAssetReportController;
use App\Http\Controllers\Admin\FixedAssetController;
use App\Http\Controllers\Admin\ActivityLogController;
use Illuminate\Support\Facades\Route;

// Auth routes (guest only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Admin - quản lý users và roles
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class);
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::delete('settings/logo', [SettingsController::class, 'deleteLogo'])->name('settings.logo.delete');
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::resource('fixed-assets', FixedAssetController::class);
        Route::post('fixed-assets/depreciate', [FixedAssetController::class, 'depreciate'])->name('fixed-assets.depreciate');
        Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
    });

    // Catalog - danh mục sản phẩm và dịch vụ
    Route::prefix('catalog')->name('catalog.')->middleware('can:products.view')->group(function () {
        Route::resource('product-categories', ProductCategoryController::class)->except(['show']);
        // Import routes must be before resource to avoid {product} wildcard conflict
        Route::post('products/import', [ProductController::class, 'import'])->name('products.import')->middleware('can:products.create');
        Route::get('products/import-template', [ProductController::class, 'importTemplate'])->name('products.import-template');
        Route::resource('products', ProductController::class);
        Route::resource('services', ServiceController::class)->except(['show']);
    });

    // Catalog - bảng giá
    Route::middleware('can:price-lists.view')->group(function () {
        Route::resource('catalog/price-lists', PriceListController::class)->names('catalog.price-lists');
        Route::get('catalog/price-lists/{priceList}/items', [PriceListController::class, 'items'])->name('catalog.price-lists.items');
    });

    // CRM - khách hàng
    Route::prefix('crm')->name('crm.')->middleware('can:customers.view')->group(function () {
        Route::post('customers/import', [CustomerController::class, 'import'])->name('customers.import')->middleware('can:customers.create');
        Route::get('customers/import-template', [CustomerController::class, 'importTemplate'])->name('customers.import-template');
        Route::resource('customers', CustomerController::class);
        Route::resource('customers.contacts', ContactController::class)->only(['store', 'update', 'destroy']);
    });

    // CRM - leads / pipeline
    Route::middleware('can:leads.view')->prefix('crm')->name('crm.')->group(function () {
        Route::resource('leads', LeadController::class);
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert')->middleware('can:leads.create');
    });

    // Warehouse - kho hàng
    Route::prefix('warehouse')->name('warehouse.')->middleware('can:warehouse.view')->group(function () {
        Route::resource('warehouses', WarehouseController::class)->except(['show']);
        // Import routes must be before resource to avoid {supplier} wildcard conflict
        Route::post('suppliers/import', [SupplierController::class, 'import'])->name('suppliers.import')->middleware('can:suppliers.create');
        Route::get('suppliers/import-template', [SupplierController::class, 'importTemplate'])->name('suppliers.import-template');
        Route::resource('suppliers', SupplierController::class)->except(['show']);

        Route::get('stock-entries/export-pdf', [StockEntryController::class, 'exportPdf'])->name('stock-entries.export-pdf');
        Route::resource('stock-entries', StockEntryController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('stock-entries/{stockEntry}/confirm', [StockEntryController::class, 'confirm'])->name('stock-entries.confirm');
        Route::post('stock-entries/{stockEntry}/cancel', [StockEntryController::class, 'cancel'])->name('stock-entries.cancel');
        Route::get('stock-entries/{stockEntry}/pdf', [StockEntryController::class, 'pdf'])->name('stock-entries.pdf');

        Route::resource('stock-exits', StockExitController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('stock-exits/{stockExit}/confirm', [StockExitController::class, 'confirm'])->name('stock-exits.confirm');
        Route::post('stock-exits/{stockExit}/cancel', [StockExitController::class, 'cancel'])->name('stock-exits.cancel');
        Route::get('stock-exits/{stockExit}/pdf', [StockExitController::class, 'pdf'])->name('stock-exits.pdf');

        Route::resource('stock-transfers', StockTransferController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('stock-transfers/{stockTransfer}/confirm', [StockTransferController::class, 'confirm'])->name('stock-transfers.confirm');
        Route::post('stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('stock-transfers.cancel');

        Route::resource('inventory-counts', InventoryCountController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
        Route::post('inventory-counts/{inventoryCount}/save-items', [InventoryCountController::class, 'saveItems'])->name('inventory-counts.save-items');
        Route::post('inventory-counts/{inventoryCount}/confirm', [InventoryCountController::class, 'confirm'])->name('inventory-counts.confirm');
        Route::post('inventory-counts/{inventoryCount}/cancel', [InventoryCountController::class, 'cancel'])->name('inventory-counts.cancel');
    });

    // Sales - báo giá, đơn hàng, hợp đồng
    Route::prefix('sales')->name('sales.')->middleware('can:quotations.view')->group(function () {
        Route::resource('quotations', QuotationController::class);
        Route::post('quotations/{quotation}/mark-sent', [QuotationController::class, 'markSent'])->name('quotations.mark-sent');
        Route::post('quotations/{quotation}/approve', [QuotationController::class, 'approve'])->name('quotations.approve');
        Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject'])->name('quotations.reject');
        Route::post('quotations/{quotation}/convert-to-order', [QuotationController::class, 'convertToOrder'])->name('quotations.convert-to-order');
        Route::get('quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->name('quotations.pdf');
        Route::post('quotations/{quotation}/attachment', [QuotationController::class, 'uploadAttachment'])->name('quotations.attachment.upload');
        Route::delete('quotations/{quotation}/attachment', [QuotationController::class, 'deleteAttachment'])->name('quotations.attachment.delete');

        Route::resource('orders', OrderController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('orders/{order}/process', [OrderController::class, 'process'])->name('orders.process');
        Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->name('orders.complete');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('orders/{order}/attachment', [OrderController::class, 'uploadAttachment'])->name('orders.attachment.upload');
        Route::delete('orders/{order}/attachment', [OrderController::class, 'deleteAttachment'])->name('orders.attachment.delete');

        Route::resource('contracts', ContractController::class);
        Route::post('contracts/{contract}/activate', [ContractController::class, 'activate'])->name('contracts.activate');
        Route::post('contracts/{contract}/complete', [ContractController::class, 'complete'])->name('contracts.complete');
        Route::post('contracts/{contract}/terminate', [ContractController::class, 'terminate'])->name('contracts.terminate');
        Route::get('contracts/{contract}/pdf', [ContractController::class, 'pdf'])->name('contracts.pdf');
        Route::post('contracts/{contract}/attachment', [ContractController::class, 'uploadAttachment'])->name('contracts.attachment.upload');
        Route::delete('contracts/{contract}/attachment', [ContractController::class, 'deleteAttachment'])->name('contracts.attachment.delete');
    });

    // Sales Returns - trả hàng bán
    Route::prefix('sales')->name('sales.')->middleware('can:sales-returns.view')->group(function () {
        Route::resource('sales-returns', SalesReturnController::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('sales-returns/{salesReturn}/confirm', [SalesReturnController::class, 'confirm'])->name('sales-returns.confirm');
        Route::post('sales-returns/{salesReturn}/cancel', [SalesReturnController::class, 'cancel'])->name('sales-returns.cancel');
        Route::get('sales-returns-order-items/{order}', [SalesReturnController::class, 'orderItems'])->name('sales-returns.order-items');
    });

    // Commissions - hoa hồng & chi phí khách hàng
    Route::prefix('sales/commissions')->name('sales.commissions.')->middleware('can:commissions.view')->group(function () {
        Route::get('/',                           [CommissionController::class, 'index'])->name('index');
        Route::get('/create',                     [CommissionController::class, 'create'])->name('create');
        Route::post('/',                          [CommissionController::class, 'store'])->name('store');
        Route::get('/{commission}',               [CommissionController::class, 'show'])->name('show');
        Route::get('/{commission}/edit',          [CommissionController::class, 'edit'])->name('edit');
        Route::put('/{commission}',               [CommissionController::class, 'update'])->name('update');
        Route::post('/{commission}/submit',       [CommissionController::class, 'submit'])->name('submit');
        Route::post('/{commission}/approve-l1',   [CommissionController::class, 'approveL1'])->name('approve-l1');
        Route::post('/{commission}/approve-l2',   [CommissionController::class, 'approveL2'])->name('approve-l2');
        Route::post('/{commission}/reject',       [CommissionController::class, 'reject'])->name('reject');
        Route::post('/{commission}/pay',          [CommissionController::class, 'pay'])->name('pay');
        Route::post('/{commission}/cancel',       [CommissionController::class, 'cancel'])->name('cancel');
        Route::delete('/{commission}',            [CommissionController::class, 'destroy'])->name('destroy');
    });

    // Projects - dự án thi công IT
    Route::prefix('projects')->name('projects.')->middleware('can:projects.view')->group(function () {
        Route::resource('projects', ProjectController::class);
        Route::post('projects/{project}/transition', [ProjectController::class, 'transition'])->name('projects.transition');

        Route::post('projects/{project}/members', [ProjectController::class, 'addMember'])->name('projects.members.store');
        Route::delete('projects/{project}/members/{member}', [ProjectController::class, 'removeMember'])->name('projects.members.destroy');

        Route::post('projects/{project}/materials', [ProjectController::class, 'addMaterial'])->name('projects.materials.store');
        Route::delete('projects/{project}/materials/{material}', [ProjectController::class, 'removeMaterial'])->name('projects.materials.destroy');

        Route::post('projects/{project}/expenses', [ProjectController::class, 'addExpense'])->name('projects.expenses.store');
        Route::delete('projects/{project}/expenses/{expense}', [ProjectController::class, 'removeExpense'])->name('projects.expenses.destroy');

        Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->name('projects.tasks.store');
        Route::put('projects/{project}/tasks/{task}', [TaskController::class, 'update'])->name('projects.tasks.update');
        Route::patch('projects/{project}/tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('projects.tasks.status');
        Route::delete('projects/{project}/tasks/{task}', [TaskController::class, 'destroy'])->name('projects.tasks.destroy');
    });

    // Accounting - kế toán
    Route::prefix('accounting')->name('accounting.')->middleware('can:accounting.view')->group(function () {
        Route::resource('invoices', InvoiceController::class);
        Route::post('invoices/{invoice}/mark-sent',    [InvoiceController::class, 'markSent'])->name('invoices.mark-sent');
        Route::post('invoices/{invoice}/mark-paid',    [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
        Route::post('invoices/{invoice}/mark-overdue', [InvoiceController::class, 'markOverdue'])->name('invoices.mark-overdue');
        Route::get('invoices/{invoice}/pdf',           [InvoiceController::class, 'pdf'])->name('invoices.pdf');

        Route::post('invoices/{invoice}/payments',             [PaymentController::class, 'store'])->name('invoices.payments.store');
        Route::delete('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'destroy'])->name('invoices.payments.destroy');

        // Quỹ và phiếu thu/chi
        Route::resource('funds', FundController::class)->except(['show']);
        Route::resource('cash-vouchers', CashVoucherController::class);
        Route::post('cash-vouchers/{cashVoucher}/confirm', [CashVoucherController::class, 'confirm'])->name('cash-vouchers.confirm');
        Route::post('cash-vouchers/{cashVoucher}/cancel',  [CashVoucherController::class, 'cancel'])->name('cash-vouchers.cancel');
    });

    // Support - ticket kỹ thuật và bảo hành
    Route::prefix('support')->name('support.')->middleware('can:tickets.view')->group(function () {
        Route::resource('tickets', TicketController::class);
        Route::post('tickets/{ticket}/transition', [TicketController::class, 'transition'])->name('tickets.transition');
        Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
        Route::post('tickets/{ticket}/note', [TicketController::class, 'addNote'])->name('tickets.note');

        Route::resource('warranties', WarrantyController::class);
        Route::patch('warranties/{warranty}/status', [WarrantyController::class, 'updateStatus'])->name('warranties.status');
    });

    // Purchasing - mua hàng
    Route::prefix('purchasing')->name('purchasing.')->middleware('can:purchasing.view')->group(function () {
        Route::resource('purchase-orders', PurchaseOrderController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('purchase-orders/{purchaseOrder}/send',    [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
        Route::post('purchase-orders/{purchaseOrder}/cancel',  [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

        Route::resource('purchase-invoices', PurchaseInvoiceController::class);
        Route::post('purchase-invoices/{purchaseInvoice}/transition', [PurchaseInvoiceController::class, 'transition'])->name('purchase-invoices.transition');
        Route::post('purchase-invoices/{purchaseInvoice}/payments', [PurchaseInvoicePaymentController::class, 'store'])->name('purchase-invoices.payments.store');
        Route::delete('purchase-invoices/{purchaseInvoice}/payments/{payment}', [PurchaseInvoicePaymentController::class, 'destroy'])->name('purchase-invoices.payments.destroy');
        Route::post('purchase-invoices/{purchaseInvoice}/attachment', [PurchaseInvoiceController::class, 'uploadAttachment'])->name('purchase-invoices.attachment.upload');
        Route::delete('purchase-invoices/{purchaseInvoice}/attachment', [PurchaseInvoiceController::class, 'deleteAttachment'])->name('purchase-invoices.attachment.delete');

        Route::resource('purchase-contracts', PurchaseContractController::class);
        Route::post('purchase-contracts/{purchaseContract}/activate',  [PurchaseContractController::class, 'activate'])->name('purchase-contracts.activate');
        Route::post('purchase-contracts/{purchaseContract}/complete',  [PurchaseContractController::class, 'complete'])->name('purchase-contracts.complete');
        Route::post('purchase-contracts/{purchaseContract}/terminate', [PurchaseContractController::class, 'terminate'])->name('purchase-contracts.terminate');
        Route::post('purchase-contracts/{purchaseContract}/attachment',   [PurchaseContractController::class, 'uploadAttachment'])->name('purchase-contracts.attachment.upload');
        Route::delete('purchase-contracts/{purchaseContract}/attachment', [PurchaseContractController::class, 'deleteAttachment'])->name('purchase-contracts.attachment.delete');

        // Payment schedules
        Route::post('purchase-contracts/{purchaseContract}/schedules', [PurchaseContractPaymentScheduleController::class, 'store'])->name('purchase-contracts.schedules.store');
        Route::put('purchase-contracts/{purchaseContract}/schedules/{schedule}', [PurchaseContractPaymentScheduleController::class, 'update'])->name('purchase-contracts.schedules.update');
        Route::delete('purchase-contracts/{purchaseContract}/schedules/{schedule}', [PurchaseContractPaymentScheduleController::class, 'destroy'])->name('purchase-contracts.schedules.destroy');
        Route::post('purchase-contracts/{purchaseContract}/schedules/{schedule}/mark-paid', [PurchaseContractPaymentScheduleController::class, 'markPaid'])->name('purchase-contracts.schedules.mark-paid');
        Route::post('purchase-contracts/{purchaseContract}/schedules/{schedule}/mark-pending', [PurchaseContractPaymentScheduleController::class, 'markPending'])->name('purchase-contracts.schedules.mark-pending');

        Route::get('purchase-returns/po/{purchaseOrder}/items', [PurchaseReturnController::class, 'poItems'])->name('purchase-returns.po-items');
        Route::resource('purchase-returns', PurchaseReturnController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('purchase-returns/{purchaseReturn}/confirm', [PurchaseReturnController::class, 'confirm'])->name('purchase-returns.confirm');
        Route::post('purchase-returns/{purchaseReturn}/cancel',  [PurchaseReturnController::class, 'cancel'])->name('purchase-returns.cancel');
    });

    // Reports - báo cáo
    Route::prefix('reports')->name('reports.')->middleware('can:reports.view')->group(function () {
        Route::get('profit/orders',   [ProfitController::class, 'orders'])->name('profit.orders');
        Route::get('profit/projects', [ProfitController::class, 'projects'])->name('profit.projects');

        Route::get('ar-aging',                [ARAgingController::class,         'index'])->name('ar.aging');
        Route::get('ar-aging/export',         [ARAgingController::class,         'export'])->name('ar.aging.export');
        Route::get('ap-aging',                [APAgingController::class,         'index'])->name('ap.aging');
        Route::get('ap-aging/export',         [APAgingController::class,         'export'])->name('ap.aging.export');
        Route::get('vat',                     [VatReportController::class,       'index'])->name('vat');
        Route::get('vat/export',              [VatReportController::class,       'export'])->name('vat.export');
        Route::get('inventory',               [InventoryReportController::class, 'index'])->name('inventory');
        Route::get('inventory/export',        [InventoryReportController::class, 'export'])->name('inventory.export');
        Route::get('cash-flow',               [CashFlowController::class,        'index'])->name('cash_flow');
        Route::get('cash-flow/export',        [CashFlowController::class,        'export'])->name('cash_flow.export');
        Route::get('income-statement',        [IncomeStatementController::class, 'index'])->name('income_statement');
        Route::get('income-statement/export', [IncomeStatementController::class, 'export'])->name('income_statement.export');

        Route::get('balance-sheet',           [BalanceSheetController::class,    'index'])->name('balance_sheet');
        Route::get('balance-sheet/export',    [BalanceSheetController::class,    'export'])->name('balance_sheet.export');
        Route::get('trial-balance',           [TrialBalanceController::class,    'index'])->name('trial_balance');
        Route::get('trial-balance/export',    [TrialBalanceController::class,    'export'])->name('trial_balance.export');
        Route::get('general-journal',         [GeneralJournalController::class,  'index'])->name('general_journal');
        Route::get('general-journal/export',  [GeneralJournalController::class,  'export'])->name('general_journal.export');
        Route::get('account-ledger',          [AccountLedgerController::class,   'index'])->name('account_ledger');
        Route::get('account-ledger/export',   [AccountLedgerController::class,   'export'])->name('account_ledger.export');
        Route::get('expense-detail',          [ExpenseDetailController::class,   'index'])->name('expense_detail');
        Route::get('expense-detail/export',   [ExpenseDetailController::class,   'export'])->name('expense_detail.export');
        Route::get('fixed-assets',            [FixedAssetReportController::class,'index'])->name('fixed_assets');
        Route::get('fixed-assets/export',     [FixedAssetReportController::class,'export'])->name('fixed_assets.export');
        Route::get('fund-ledger',             [FundLedgerController::class,      'index'])->name('fund-ledger.index');
    });

    // Documents - quản lý hồ sơ chứng từ
    Route::prefix('documents')->name('documents.')->middleware('can:documents.view')->group(function () {
        Route::resource('documents', DocumentController::class);
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::post('documents/{document}/attach',  [DocumentController::class, 'attach'])->name('documents.attach');
        Route::post('documents/{document}/detach',  [DocumentController::class, 'detach'])->name('documents.detach');

        Route::get('types',              [DocumentTypeController::class, 'index'])->name('types.index');
        Route::post('types',             [DocumentTypeController::class, 'store'])->name('types.store');
        Route::put('types/{type}',       [DocumentTypeController::class, 'update'])->name('types.update');
        Route::delete('types/{type}',    [DocumentTypeController::class, 'destroy'])->name('types.destroy');
    });

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [\App\Http\Controllers\NotificationController::class, 'index'])->name('index');
        Route::post('{id}/read', [\App\Http\Controllers\NotificationController::class, 'markRead'])->name('mark-read');
        Route::post('mark-all-read', [\App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('mark-all-read');
    });

    // JSON API for unread count
    Route::get('api/notifications/unread-count', [\App\Http\Controllers\NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
});
