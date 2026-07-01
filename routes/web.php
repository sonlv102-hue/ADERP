<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\EmployeeController;
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
use App\Http\Controllers\Warehouse\InventoryOpeningBalanceController;
use App\Http\Controllers\Warehouse\ProjectInventoryController;
use App\Http\Controllers\Warehouse\ReconcileAvcoController;
use App\Http\Controllers\Sales\QuotationController;
use App\Http\Controllers\Sales\OrderController;
use App\Http\Controllers\Reports\ProfitController;
use App\Http\Controllers\Reports\ARAgingController;
use App\Http\Controllers\Reports\APAgingController;
use App\Http\Controllers\Reports\VatReportController;
use App\Http\Controllers\Reports\InventoryReportController;
use App\Http\Controllers\Reports\StockEntryDetailReportController;
use App\Http\Controllers\Reports\StockExitDetailReportController;
use App\Http\Controllers\Reports\CashFlowController;
use App\Http\Controllers\Reports\CashFlowStatementController;
use App\Http\Controllers\Reports\IncomeStatementController;
use App\Http\Controllers\Sales\CommissionController;
use App\Http\Controllers\Sales\ContractController;
use App\Http\Controllers\Sales\CustomerAdvanceController;
use App\Http\Controllers\Sales\SalesReturnController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectDirectMaterialController;
use App\Http\Controllers\Projects\ProjectExtraCostTransferController;
use App\Http\Controllers\Projects\ProjectWipCorrectionController;
use App\Http\Controllers\Projects\TaskController;
use App\Http\Controllers\Support\TicketController;
use App\Http\Controllers\Support\WarrantyController;
use App\Http\Controllers\Accounting\AccountCodeController;
use App\Http\Controllers\Accounting\AccountingPeriodController;
use App\Http\Controllers\Accounting\PeriodCloseBatchController;
use App\Http\Controllers\Accounting\CashVoucherController;
use App\Http\Controllers\Accounting\FundController;
use App\Http\Controllers\Accounting\FundTransferController;
use App\Http\Controllers\Accounting\InvoiceController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\ArApOpeningBalanceController;
use App\Http\Controllers\Accounting\OpeningBalanceController;
use App\Http\Controllers\Accounting\PaymentController;
use App\Http\Controllers\Accounting\PrepaidExpenseController;
use App\Http\Controllers\Accounting\PaymentTermController;
use App\Http\Controllers\Accounting\BankAccountController;
use App\Http\Controllers\Accounting\BankTransactionController;
use App\Http\Controllers\Accounting\PayrollController;
use App\Http\Controllers\Accounting\TaxController;
use App\Http\Controllers\Accounting\ArCollectionController;
use App\Http\Controllers\Accounting\ApPaymentController;
use App\Http\Controllers\Accounting\CustomerAdvanceAllocationController;
use App\Http\Controllers\Reports\ArDetailController;
use App\Http\Controllers\Reports\ApDetailController;
use App\Http\Controllers\Reports\FundLedgerController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchaseInvoiceController;
use App\Http\Controllers\Purchasing\PurchaseInvoicePaymentController;
use App\Http\Controllers\Purchasing\PurchaseContractController;
use App\Http\Controllers\Purchasing\SupplierBankAccountController;
use App\Http\Controllers\Accounting\BankStatementImportController;
use App\Http\Controllers\Accounting\InternalBankAccountController;
use App\Http\Controllers\Accounting\InternalTransferReportController;
use App\Http\Controllers\Accounting\AccountingPostingJobController;
use App\Http\Controllers\Accounting\AccountingSettingsController;
use App\Http\Controllers\Accounting\FixedAssetCategoryController;
use App\Http\Controllers\Accounting\FixedAssetController as AccountingFixedAssetController;
use App\Http\Controllers\Accounting\FixedAssetDepreciationController;
use App\Http\Controllers\Accounting\FixedAssetRepairController;
use App\Http\Controllers\Accounting\FixedAssetDisposalController;
use App\Http\Controllers\Accounting\FixedAssetReportController as AccountingFixedAssetReportController;
use App\Http\Controllers\Purchasing\PurchaseContractPaymentScheduleController;
use App\Http\Controllers\Purchasing\PurchaseReturnController;
use App\Http\Controllers\Purchasing\SupplierAdvanceController;
use App\Http\Controllers\Purchasing\SupplierAdvanceAllocationController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Documents\DocumentTypeController;
use App\Http\Controllers\Reports\BalanceSheetController;
use App\Http\Controllers\Reports\TrialBalanceController;
use App\Http\Controllers\Reports\GeneralJournalController;
use App\Http\Controllers\Reports\GeneralJournalDetailController;
use App\Http\Controllers\Reports\AccountLedgerController;
use App\Http\Controllers\Reports\ExpenseDetailController;
use App\Http\Controllers\Reports\FixedAssetReportController;
use App\Http\Controllers\Reports\DocumentChecklistController;
use App\Http\Controllers\Reports\DocumentChecklistDetailController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\ShareholderController;
use App\Http\Controllers\Accounting\PersonalLoanController;
use App\Http\Controllers\Accounting\PersonalExpenseController;
use App\Http\Controllers\Accounting\JournalAuditController;
use App\Http\Controllers\Accounting\RepairCenterController;
use App\Http\Controllers\Accounting\SmallToolCategoryController;
use App\Http\Controllers\Accounting\SmallToolController;
use App\Http\Controllers\Accounting\SmallToolReceiptController;
use App\Http\Controllers\Accounting\SmallToolIssueController;
use App\Http\Controllers\Accounting\SmallToolAllocationController;
use App\Http\Controllers\Accounting\SmallToolTransferController;
use App\Http\Controllers\Accounting\SmallToolDisposalController;
use App\Http\Controllers\Accounting\SmallToolReportController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Auth routes (guest only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Shared attachments
    Route::post('attachments/{type}/{id}', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    // Admin - quản lý users và roles
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class);
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::delete('settings/logo', [SettingsController::class, 'deleteLogo'])->name('settings.logo.delete');
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        Route::resource('employees', EmployeeController::class);

        // Chấm công
        Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');
        Route::get('attendance/{attendance}', [AttendanceController::class, 'show'])->name('attendance.show');
        Route::post('attendance/{attendance}/lock', [AttendanceController::class, 'lock'])->name('attendance.lock');
        Route::post('attendance/{attendance}/unlock', [AttendanceController::class, 'unlock'])->name('attendance.unlock');
        Route::delete('attendance/{attendance}', [AttendanceController::class, 'destroy'])->name('attendance.destroy');
        Route::put('attendance/{attendance}/records/{record}', [AttendanceController::class, 'updateRecord'])->name('attendance.records.update');

        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [BackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{name}/download', [BackupController::class, 'download'])->name('backups.download');
        Route::delete('backups/{name}', [BackupController::class, 'destroy'])->name('backups.destroy');

        // Thành viên / Cổ đông
        Route::resource('shareholders', ShareholderController::class)->except(['show']);

        Route::get('system-health', SystemHealthController::class)->name('system-health.index');
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
        Route::post('suppliers/import', [SupplierController::class, 'import'])->name('suppliers.import')->middleware('can:warehouse.manage');
        Route::get('suppliers/import-template', [SupplierController::class, 'importTemplate'])->name('suppliers.import-template');
        Route::resource('suppliers', SupplierController::class)->except(['show']);
        // Supplier bank accounts
        Route::post('suppliers/{supplier}/bank-accounts', [SupplierBankAccountController::class, 'store'])->name('suppliers.bank-accounts.store');
        Route::put('suppliers/{supplier}/bank-accounts/{bankAccount}', [SupplierBankAccountController::class, 'update'])->name('suppliers.bank-accounts.update');
        Route::delete('suppliers/{supplier}/bank-accounts/{bankAccount}', [SupplierBankAccountController::class, 'destroy'])->name('suppliers.bank-accounts.destroy');
        Route::post('suppliers/{supplier}/bank-accounts/{bankAccount}/set-primary', [SupplierBankAccountController::class, 'setPrimary'])->name('suppliers.bank-accounts.set-primary');

        Route::get('stock-entries/export-pdf', [StockEntryController::class, 'exportPdf'])->name('stock-entries.export-pdf');
        Route::resource('stock-entries', StockEntryController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('stock-entries/{stockEntry}/confirm', [StockEntryController::class, 'confirm'])->name('stock-entries.confirm');
        Route::post('stock-entries/{stockEntry}/cancel', [StockEntryController::class, 'cancel'])->name('stock-entries.cancel');
        Route::post('stock-entries/{stockEntry}/recall', [StockEntryController::class, 'recall'])->name('stock-entries.recall');
        Route::get('stock-entries/{stockEntry}/pdf', [StockEntryController::class, 'pdf'])->name('stock-entries.pdf');

        Route::resource('stock-exits', StockExitController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('stock-exits/{stockExit}/confirm', [StockExitController::class, 'confirm'])->name('stock-exits.confirm');
        Route::post('stock-exits/{stockExit}/cancel', [StockExitController::class, 'cancel'])->name('stock-exits.cancel');
        Route::get('stock-exits/{stockExit}/pdf', [StockExitController::class, 'pdf'])->name('stock-exits.pdf');
        Route::get('stock-exits-available-lots', [StockExitController::class, 'availableLots'])->name('stock-exits.available-lots');
        Route::get('stock-exits-avco-costs', [StockExitController::class, 'avcoCosts'])->name('stock-exits.avco-costs');
        Route::get('stock-exits-prefill-from-order', [StockExitController::class, 'prefillFromOrder'])->name('stock-exits.prefill-from-order');
        Route::post('stock-exits/reconcile-avco-preview', [ReconcileAvcoController::class, 'preview'])->name('stock-exits.reconcile-avco-preview')->middleware('can:warehouse.manage');
        Route::post('stock-exits/reconcile-avco-apply', [ReconcileAvcoController::class, 'apply'])->name('stock-exits.reconcile-avco-apply')->middleware('can:warehouse.manage');
        Route::get('project-inventory', [ProjectInventoryController::class, 'index'])->name('project-inventory.index');

        Route::resource('stock-transfers', StockTransferController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('stock-transfers/{stockTransfer}/confirm', [StockTransferController::class, 'confirm'])->name('stock-transfers.confirm');
        Route::post('stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('stock-transfers.cancel');

        Route::resource('inventory-counts', InventoryCountController::class)->only(['index', 'create', 'store', 'show', 'destroy']);

        // Tồn kho đầu kỳ
        Route::get('opening-balance', [InventoryOpeningBalanceController::class, 'index'])->name('opening-balance.index')->middleware('can:warehouse.manage');
        Route::get('opening-balance/create', [InventoryOpeningBalanceController::class, 'create'])->name('opening-balance.create')->middleware('can:warehouse.manage');
        Route::post('opening-balance', [InventoryOpeningBalanceController::class, 'store'])->name('opening-balance.store')->middleware('can:warehouse.manage');
        Route::delete('opening-balance/{openingBalance}', [InventoryOpeningBalanceController::class, 'destroy'])->name('opening-balance.destroy')->middleware('can:warehouse.manage');
        Route::post('inventory-counts/{inventoryCount}/save-items', [InventoryCountController::class, 'saveItems'])->name('inventory-counts.save-items');
        Route::post('inventory-counts/{inventoryCount}/confirm', [InventoryCountController::class, 'confirm'])->name('inventory-counts.confirm');
        Route::post('inventory-counts/{inventoryCount}/cancel', [InventoryCountController::class, 'cancel'])->name('inventory-counts.cancel');
    });

    // Sales - báo giá, đơn hàng, hợp đồng
    Route::prefix('sales')->name('sales.')->middleware('can:quotations.view')->group(function () {
        Route::get('quotations/export-excel', [QuotationController::class, 'exportExcel'])->name('quotations.export-excel');
        Route::resource('quotations', QuotationController::class);
        Route::post('quotations/{quotation}/mark-sent', [QuotationController::class, 'markSent'])->name('quotations.mark-sent');
        Route::post('quotations/{quotation}/approve', [QuotationController::class, 'approve'])->name('quotations.approve');
        Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject'])->name('quotations.reject');
        Route::post('quotations/{quotation}/cancel', [QuotationController::class, 'cancel'])->name('quotations.cancel');
        Route::post('quotations/{quotation}/recall', [QuotationController::class, 'recall'])->name('quotations.recall');
        Route::post('quotations/{quotation}/unapprove', [QuotationController::class, 'unapprove'])->name('quotations.unapprove');
        Route::post('quotations/{quotation}/convert-to-order', [QuotationController::class, 'convertToOrder'])->name('quotations.convert-to-order');
        Route::get('quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->name('quotations.pdf');
        Route::post('quotations/{quotation}/attachment', [QuotationController::class, 'uploadAttachment'])->name('quotations.attachment.upload');
        Route::delete('quotations/{quotation}/attachment', [QuotationController::class, 'deleteAttachment'])->name('quotations.attachment.delete');

        Route::get('orders/export-excel', [OrderController::class, 'exportExcel'])->name('orders.export-excel');
        Route::resource('orders', OrderController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('orders/{order}/process', [OrderController::class, 'process'])->name('orders.process');
        Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->name('orders.complete');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::patch('orders/{order}/dates', [OrderController::class, 'updateDates'])->name('orders.update-dates');
        Route::post('orders/{order}/customs', [OrderController::class, 'declareCustoms'])->name('orders.customs.declare');
        Route::post('orders/{order}/attachment', [OrderController::class, 'uploadAttachment'])->name('orders.attachment.upload');
        Route::delete('orders/{order}/attachment', [OrderController::class, 'deleteAttachment'])->name('orders.attachment.delete');
        Route::post('orders/{order}/force-revert', [OrderController::class, 'forceRevert'])->name('orders.force-revert');

        Route::resource('contracts', ContractController::class);
        Route::post('contracts/{contract}/activate', [ContractController::class, 'activate'])->name('contracts.activate');
        Route::post('contracts/{contract}/complete', [ContractController::class, 'complete'])->name('contracts.complete');
        Route::post('contracts/{contract}/terminate', [ContractController::class, 'terminate'])->name('contracts.terminate');
        Route::post('contracts/{contract}/recall', [ContractController::class, 'recall'])->name('contracts.recall');
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

        // Customer Advances — ứng trước khách hàng
        Route::resource('customer-advances', CustomerAdvanceController::class)
            ->only(['index', 'create', 'store', 'show']);
        Route::post('customer-advances/{customerAdvance}/cancel', [CustomerAdvanceController::class, 'cancel'])->name('customer-advances.cancel');
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
        Route::get('projects/export-excel', [ProjectController::class, 'exportExcel'])->name('projects.export-excel');
        Route::resource('projects', ProjectController::class);
        Route::post('projects/{project}/transition', [ProjectController::class, 'transition'])->name('projects.transition');

        Route::post('projects/{project}/members', [ProjectController::class, 'addMember'])->name('projects.members.store');
        Route::delete('projects/{project}/members/{member}', [ProjectController::class, 'removeMember'])->name('projects.members.destroy');

        Route::post('projects/{project}/materials', [ProjectController::class, 'addMaterial'])->name('projects.materials.store');
        Route::delete('projects/{project}/materials/{material}', [ProjectController::class, 'removeMaterial'])->name('projects.materials.destroy');

        Route::get('projects/{project}/expenses/export-excel', [ProjectController::class, 'exportExpensesExcel'])->name('projects.expenses.export-excel');
        Route::get('projects/{project}/wip/export-excel', [ProjectController::class, 'exportWipExcel'])->name('projects.wip.export-excel');
        Route::get('projects/{project}/expenses/create', [ProjectController::class, 'expenseCreate'])->name('projects.expenses.create');
        Route::post('projects/{project}/expenses/batch', [ProjectController::class, 'expenseBatchStore'])->name('projects.expenses.batch');
        Route::post('projects/{project}/expenses', [ProjectController::class, 'addExpense'])->name('projects.expenses.store');
        Route::get('projects/{project}/expenses/{expense}/edit', [ProjectController::class, 'expenseEdit'])->name('projects.expenses.edit');
        Route::patch('projects/{project}/expenses/{expense}', [ProjectController::class, 'expenseUpdate'])->name('projects.expenses.update');
        Route::delete('projects/{project}/expenses/{expense}', [ProjectController::class, 'removeExpense'])->name('projects.expenses.destroy');

        // Kết chuyển chi phí PS sang TK 154 (đơn lẻ)
        Route::prefix('projects/{project}/expenses/{expense}/transfers')->name('projects.expense-transfers.')->group(function () {
            Route::get('preview', [ProjectExtraCostTransferController::class, 'preview'])->name('preview');
            Route::post('', [ProjectExtraCostTransferController::class, 'store'])->name('store');
            Route::delete('{transfer}', [ProjectExtraCostTransferController::class, 'destroy'])->name('destroy');
        });

        // Kết chuyển nhiều chi phí PS (batch)
        Route::prefix('projects/{project}/expense-transfers-batch')->name('projects.expense-transfers-batch.')->group(function () {
            Route::post('preview', [ProjectExtraCostTransferController::class, 'previewBatch'])->name('preview');
            Route::post('', [ProjectExtraCostTransferController::class, 'storeBatch'])->name('store');
        });

        Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->name('projects.tasks.store');
        Route::put('projects/{project}/tasks/{task}', [TaskController::class, 'update'])->name('projects.tasks.update');
        Route::patch('projects/{project}/tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('projects.tasks.status');
        Route::delete('projects/{project}/tasks/{task}', [TaskController::class, 'destroy'])->name('projects.tasks.destroy');

        Route::post('projects/{project}/recognize-cost', [ProjectController::class, 'recognizeCost'])
            ->middleware('can:accounting.manage')
            ->name('projects.recognize-cost');

        Route::post('projects/{project}/direct-materials', [ProjectDirectMaterialController::class, 'store'])
            ->name('projects.direct-materials.store');
        Route::post('projects/{project}/direct-materials/preview', [ProjectDirectMaterialController::class, 'preview'])
            ->name('projects.direct-materials.preview');
        Route::delete('projects/{project}/direct-materials/{directMaterial}', [ProjectDirectMaterialController::class, 'destroy'])
            ->name('projects.direct-materials.destroy');

        // WIP Correction (hủy, chuyển dự án, điều chỉnh tài khoản)
        Route::prefix('projects/{project}/wip/{wip}')->name('projects.wip.')->group(function () {
            Route::get('history',           [ProjectWipCorrectionController::class, 'history'])->name('history');
            Route::post('preview-cancel',   [ProjectWipCorrectionController::class, 'previewCancel'])->name('preview-cancel');
            Route::post('cancel',           [ProjectWipCorrectionController::class, 'cancel'])->name('cancel');
            Route::post('preview-transfer', [ProjectWipCorrectionController::class, 'previewTransfer'])->name('preview-transfer');
            Route::post('transfer',         [ProjectWipCorrectionController::class, 'transfer'])->name('transfer');
            Route::post('preview-reclass',  [ProjectWipCorrectionController::class, 'previewReclass'])->name('preview-reclass');
            Route::post('reclass',          [ProjectWipCorrectionController::class, 'reclass'])->name('reclass');
        });
    });

    // Accounting - kế toán
    Route::prefix('accounting')->name('accounting.')->middleware('can:accounting.view')->group(function () {
        Route::get('invoices/export-excel', [InvoiceController::class, 'exportExcel'])->name('invoices.export-excel');
        Route::resource('invoices', InvoiceController::class);
        Route::post('invoices/{invoice}/mark-sent',       [InvoiceController::class, 'markSent'])->name('invoices.mark-sent');
        Route::post('invoices/{invoice}/mark-paid',       [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
        Route::post('invoices/{invoice}/mark-overdue',    [InvoiceController::class, 'markOverdue'])->name('invoices.mark-overdue');
        Route::post('invoices/{invoice}/cancel',          [InvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::get('invoices/{invoice}/pdf',              [InvoiceController::class, 'pdf'])->name('invoices.pdf');
        Route::post('invoices/{invoice}/issue-einvoice',  [InvoiceController::class, 'issueEInvoice'])->name('invoices.issue-einvoice')->middleware('can:accounting.manage');
        Route::post('invoices/{invoice}/cancel-einvoice', [InvoiceController::class, 'cancelEInvoice'])->name('invoices.cancel-einvoice')->middleware('can:accounting.manage');
        Route::get('invoices/{invoice}/e-invoice-pdf',    [InvoiceController::class, 'eInvoicePdf'])->name('invoices.e-invoice-pdf');

        Route::post('invoices/{invoice}/payments',             [PaymentController::class, 'store'])->name('invoices.payments.store');
        Route::delete('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'destroy'])->name('invoices.payments.destroy');
        Route::post('invoices/{invoice}/advance-allocations',  [CustomerAdvanceAllocationController::class, 'store'])->name('invoices.advance-allocations.store');
        Route::delete('invoice-advance-allocations/{allocation}', [CustomerAdvanceAllocationController::class, 'destroy'])->name('invoice-advance-allocations.destroy');

        // Công nợ phải thu / phải trả
        Route::get('ar-collections/customer-advances', [ArCollectionController::class, 'customerAdvances'])->name('ar-collections.customer-advances');
        Route::get('ar-collections',                   [ArCollectionController::class, 'index'])->name('ar-collections.index');
        Route::get('ap-payments/advances',             [ApPaymentController::class,    'advances'])->name('ap-payments.advances');
        Route::get('ap-payments',                      [ApPaymentController::class,    'index'])->name('ap-payments.index');

        // Quỹ và phiếu thu/chi
        Route::resource('funds', FundController::class)->except(['show']);
        Route::get('cash-vouchers/export-excel', [CashVoucherController::class, 'exportExcel'])->name('cash-vouchers.export-excel');
        Route::resource('cash-vouchers', CashVoucherController::class);
        Route::post('cash-vouchers/{cashVoucher}/confirm', [CashVoucherController::class, 'confirm'])->name('cash-vouchers.confirm');
        Route::post('cash-vouchers/{cashVoucher}/cancel',  [CashVoucherController::class, 'cancel'])->name('cash-vouchers.cancel');
        Route::post('cash-vouchers/{cashVoucher}/unpost',  [CashVoucherController::class, 'unpost'])->name('cash-vouchers.unpost');

        // Luân chuyển quỹ
        Route::get('fund-transfers/export-excel', [FundTransferController::class, 'exportExcel'])->name('fund-transfers.export-excel');
        Route::resource('fund-transfers', FundTransferController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
        Route::post('fund-transfers/{fundTransfer}/post',    [FundTransferController::class, 'post'])->name('fund-transfers.post');
        Route::post('fund-transfers/{fundTransfer}/reverse', [FundTransferController::class, 'reverse'])->name('fund-transfers.reverse');
        Route::post('fund-transfers/{fundTransfer}/cancel',  [FundTransferController::class, 'cancel'])->name('fund-transfers.cancel');

        // Vay cá nhân (3411)
        Route::resource('personal-loans', PersonalLoanController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('personal-loans/{personalLoan}/post',   [PersonalLoanController::class, 'post'])->name('personal-loans.post');
        Route::post('personal-loans/{personalLoan}/repay',  [PersonalLoanController::class, 'repay'])->name('personal-loans.repay');
        Route::post('personal-loans/{personalLoan}/cancel', [PersonalLoanController::class, 'cancel'])->name('personal-loans.cancel');

        // Chi hộ cá nhân (3388)
        Route::resource('personal-expenses', PersonalExpenseController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('personal-expenses/{personalExpense}/post',      [PersonalExpenseController::class, 'post'])->name('personal-expenses.post');
        Route::post('personal-expenses/{personalExpense}/reimburse', [PersonalExpenseController::class, 'reimburse'])->name('personal-expenses.reimburse');

        // Tiền lương (Payroll)
        Route::resource('payrolls', PayrollController::class)->except(['edit', 'update']);
        Route::put('payrolls/{payroll}/items/{item}', [PayrollController::class, 'updateItem'])->name('payrolls.items.update');
        Route::post('payrolls/{payroll}/confirm', [PayrollController::class, 'confirm'])->name('payrolls.confirm');
        Route::post('payrolls/{payroll}/unconfirm', [PayrollController::class, 'unconfirm'])->name('payrolls.unconfirm');
        Route::post('payrolls/{payroll}/sync-employees', [PayrollController::class, 'syncFromEmployees'])->name('payrolls.sync-employees');
        Route::post('payrolls/{payroll}/set-union-fee', [PayrollController::class, 'setUnionFee'])->name('payrolls.set-union-fee');
        Route::post('payrolls/{payroll}/items/{item}/pay', [PayrollController::class, 'payEmployee'])->name('payrolls.items.pay');
        Route::post('payrolls/{payroll}/lock', [PayrollController::class, 'lock'])->name('payrolls.lock')->middleware('can:accounting.manage');
        Route::post('payrolls/{payroll}/unlock', [PayrollController::class, 'unlock'])->name('payrolls.unlock')->middleware('can:accounting.manage');
        Route::patch('payrolls/{payroll}/items/{item}/adjustment', [PayrollController::class, 'updateAdjustment'])->name('payrolls.items.adjustment');
        Route::get('payrolls/{payroll}/export-excel', [PayrollController::class, 'exportExcel'])->name('payrolls.export-excel');
        Route::get('payrolls/{payroll}/export-pdf',   [PayrollController::class, 'exportPdf'])->name('payrolls.export-pdf');
        Route::post('payrolls/{payroll}/rollback-preview', [PayrollController::class, 'rollbackPreview'])->name('payrolls.rollback-preview')->middleware('can:accounting.manage');
        Route::post('payrolls/{payroll}/rollback',         [PayrollController::class, 'rollback'])->name('payrolls.rollback')->middleware('can:accounting.manage');

        // Kê khai thuế (Taxes)
        Route::get('taxes', [TaxController::class, 'index'])->name('taxes.index');
        Route::get('taxes/export-xml', [TaxController::class, 'exportXml'])->name('taxes.export-xml');

        // Hệ thống tài khoản kế toán (Chart of Accounts)
        Route::get('account-codes', [AccountCodeController::class, 'index'])->name('account-codes.index');
        Route::get('account-codes/sample', [AccountCodeController::class, 'downloadSample'])->name('account-codes.sample');
        Route::post('account-codes/import', [AccountCodeController::class, 'importExcel'])->name('account-codes.import')->middleware('can:accounting.manage');
        Route::post('account-codes', [AccountCodeController::class, 'store'])->name('account-codes.store')->middleware('can:accounting.manage');
        Route::put('account-codes/{accountCode}', [AccountCodeController::class, 'update'])->name('account-codes.update')->middleware('can:accounting.manage');
        Route::delete('account-codes/{accountCode}', [AccountCodeController::class, 'destroy'])->name('account-codes.destroy')->middleware('can:accounting.manage');

        // Kết chuyển cuối kỳ
        Route::prefix('period-close')->name('period-close.')->middleware('can:accounting.manage')->group(function () {
            Route::get('/',                                  [PeriodCloseBatchController::class, 'index'])        ->name('index');
            Route::post('/preview',                          [PeriodCloseBatchController::class, 'preview'])      ->name('preview');
            Route::post('/',                                 [PeriodCloseBatchController::class, 'store'])        ->name('store');
            Route::post('/year-end-preview',                 [PeriodCloseBatchController::class, 'yearEndPreview'])->name('year-end-preview');
            Route::post('/year-open',                        [PeriodCloseBatchController::class, 'yearOpen'])     ->name('year-open');
            Route::get('/{batch}',                           [PeriodCloseBatchController::class, 'show'])         ->name('show');
            Route::post('/{batch}/reverse',                  [PeriodCloseBatchController::class, 'reverse'])      ->name('reverse');
        });

        // Kỳ kế toán (Accounting Periods)
        Route::get('accounting-periods', [AccountingPeriodController::class, 'index'])->name('accounting-periods.index');
        Route::post('accounting-periods', [AccountingPeriodController::class, 'store'])->name('accounting-periods.store')->middleware('can:accounting.manage');
        Route::post('accounting-periods/{accountingPeriod}/close', [AccountingPeriodController::class, 'close'])->name('accounting-periods.close')->middleware('can:accounting.manage');
        Route::post('accounting-periods/{accountingPeriod}/lock', [AccountingPeriodController::class, 'lock'])->name('accounting-periods.lock')->middleware('can:accounting.manage');
        Route::post('accounting-periods/{accountingPeriod}/reopen', [AccountingPeriodController::class, 'reopen'])->name('accounting-periods.reopen')->middleware('can:accounting.manage');

        // Số dư đầu kỳ
        Route::get('opening-balance',  [OpeningBalanceController::class, 'index'])->name('opening-balance.index');
        Route::post('opening-balance', [OpeningBalanceController::class, 'store'])->name('opening-balance.store')->middleware('can:accounting.manage');
        Route::post('opening-balance/import-excel', [OpeningBalanceController::class, 'importExcel'])->name('opening-balance.import-excel')->middleware('can:accounting.manage');

        // Công nợ đầu kỳ (AR/AP Opening Balance)
        Route::get('ar-ap-opening-balance',                             [ArApOpeningBalanceController::class, 'index'])->name('ar-ap-opening-balance.index');
        Route::get('ar-ap-opening-balance/create',                      [ArApOpeningBalanceController::class, 'create'])->name('ar-ap-opening-balance.create')->middleware('can:accounting.manage');
        Route::post('ar-ap-opening-balance',                            [ArApOpeningBalanceController::class, 'store'])->name('ar-ap-opening-balance.store')->middleware('can:accounting.manage');
        Route::post('ar-ap-opening-balance/{arApOpeningBalance}/pay',   [ArApOpeningBalanceController::class, 'pay'])->name('ar-ap-opening-balance.pay')->middleware('can:accounting.manage');
        Route::delete('ar-ap-opening-balance/{arApOpeningBalance}',     [ArApOpeningBalanceController::class, 'destroy'])->name('ar-ap-opening-balance.destroy')->middleware('can:accounting.manage');

        // Phiếu kế toán / Bút toán (Journal Entries)
        Route::get('journal-entries/export-excel', [JournalEntryController::class, 'exportExcel'])->name('journal-entries.export-excel');
        Route::resource('journal-entries', JournalEntryController::class)->only(['index', 'create', 'store', 'show', 'update', 'destroy']);
        Route::get('journal-entries/{journalEntry}/edit', [JournalEntryController::class, 'edit'])->name('journal-entries.edit')->middleware('can:accounting.manage');
        Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'markPosted'])->name('journal-entries.post')->middleware('can:accounting.manage');
        Route::post('journal-entries/{journalEntry}/unpost', [JournalEntryController::class, 'unpost'])->name('journal-entries.unpost')->middleware('can:accounting.manage');
        Route::post('journal-entries/{journalEntry}/restore-original', [JournalEntryController::class, 'restoreOriginal'])->name('journal-entries.restore-original')->middleware('can:accounting.manage');
        Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->name('journal-entries.reverse')->middleware('can:accounting.manage');
        Route::post('journal-entries/{journalEntry}/void', [JournalEntryController::class, 'void'])->name('journal-entries.void')->middleware('can:accounting.manage');
        Route::post('journal-entries/bulk-approve', [JournalEntryController::class, 'bulkApprove'])->name('journal-entries.bulk-approve')->middleware('can:accounting.manage');

        // Chi phí trả trước (Prepaid Expenses)
        Route::resource('prepaid-expenses', PrepaidExpenseController::class)->only(['index', 'create', 'store', 'show'])->middleware('can:accounting.view');
        Route::post('prepaid-expenses/{prepaidExpense}/amortize', [PrepaidExpenseController::class, 'amortize'])->name('prepaid-expenses.amortize')->middleware('can:accounting.manage');
        Route::post('prepaid-expenses/run-batch', [PrepaidExpenseController::class, 'runBatch'])->name('prepaid-expenses.run-batch')->middleware('can:accounting.manage');

        // Điều khoản thanh toán (Payment Terms)
        Route::resource('payment-terms', PaymentTermController::class)->only(['index', 'create', 'store', 'edit', 'update'])->middleware('can:accounting.manage');

        // Tài khoản ngân hàng + Đối chiếu (Bank Accounts & Reconciliation)
        Route::resource('bank-accounts', BankAccountController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
        Route::get('bank-accounts/{bankAccount}/transactions/export-excel', [BankTransactionController::class, 'exportExcel'])->name('bank-accounts.transactions.export-excel');
        Route::resource('bank-accounts.transactions', BankTransactionController::class)->only(['index', 'store']);
        Route::post('bank-accounts/{bankAccount}/transactions/{bankTransaction}/reconcile',   [BankTransactionController::class, 'reconcile'])->name('bank-accounts.transactions.reconcile')->middleware('can:accounting.manage');
        Route::post('bank-accounts/{bankAccount}/transactions/{bankTransaction}/unreconcile', [BankTransactionController::class, 'unreconcile'])->name('bank-accounts.transactions.unreconcile')->middleware('can:accounting.manage');
        Route::post('bank-accounts/{bankAccount}/transactions/import-excel',                  [BankTransactionController::class, 'importExcel'])->name('bank-accounts.transactions.import-excel')->middleware('can:accounting.manage');
        Route::post('bank-accounts/{bankAccount}/transactions/import-excel/batch',            [BankStatementImportController::class, 'uploadBatch'])->name('bank-accounts.transactions.import-excel-batch')->middleware('can:accounting.manage');
        // Batch import — preview / confirm / cancel
        Route::get( 'bank-statement-import-batches/{batch}/preview', [BankStatementImportController::class, 'preview'])->name('bank-statement-import-batches.preview')->middleware('can:accounting.manage');
        Route::post('bank-statement-import-batches/{batch}/confirm', [BankStatementImportController::class, 'confirm'])->name('bank-statement-import-batches.confirm')->middleware('can:accounting.manage');
        Route::post('bank-statement-import-batches/{batch}/cancel',  [BankStatementImportController::class, 'cancel'])->name('bank-statement-import-batches.cancel')->middleware('can:accounting.manage');
        Route::post('bank-accounts/{bankAccount}/transactions/recategorize',                 [BankTransactionController::class, 'recategorize'])->name('bank-accounts.transactions.recategorize')->middleware('can:accounting.manage');
        // Đối soát tự động (Matching)
        Route::post('bank-accounts/{bankAccount}/transactions/match-all',                                    [BankTransactionController::class, 'matchAll'])->name('bank-accounts.transactions.match-all')->middleware('can:accounting.manage');
        Route::post('bank-accounts/{bankAccount}/transactions/{bankTransaction}/confirm-match',              [BankTransactionController::class, 'confirmMatch'])->name('bank-accounts.transactions.confirm-match')->middleware('can:accounting.manage');
        Route::post('bank-accounts/{bankAccount}/transactions/{bankTransaction}/ignore-match',               [BankTransactionController::class, 'ignoreMatch'])->name('bank-accounts.transactions.ignore-match')->middleware('can:accounting.manage');
        Route::post('bank-accounts/{bankAccount}/transactions/{bankTransaction}/create-journal-entry',       [BankTransactionController::class, 'createJournalEntry'])->name('bank-accounts.transactions.create-journal-entry')->middleware('can:accounting.manage');
        // Đối chiếu thủ công (Allocation)
        Route::get( 'bank-accounts/{bankAccount}/transactions/{bankTransaction}/reconcile-data',   [BankTransactionController::class, 'reconcileData'])->name('bank-accounts.transactions.reconcile-data')->middleware('can:accounting.manage');
        Route::post('bank-accounts/{bankAccount}/transactions/{bankTransaction}/allocate',         [BankTransactionController::class, 'allocate'])->name('bank-accounts.transactions.allocate')->middleware('can:accounting.manage');
        Route::post('bank-accounts/{bankAccount}/transactions/{bankTransaction}/cancel-allocation',[BankTransactionController::class, 'cancelAllocation'])->name('bank-accounts.transactions.cancel-allocation')->middleware('can:accounting.manage');
        // Tài khoản nội bộ
        Route::resource('internal-bank-accounts', InternalBankAccountController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('can:accounting.manage');
        // Báo cáo chuyển khoản nội bộ
        Route::get('internal-transfers', [InternalTransferReportController::class, 'index'])->name('internal-transfers.index');
        Route::post('internal-transfers/{bankTransaction}/status', [InternalTransferReportController::class, 'updateStatus'])->name('internal-transfers.update-status')->middleware('can:accounting.manage');

        // Accounting posting jobs — theo dõi và retry bút toán tự động
        Route::get('posting-jobs', [AccountingPostingJobController::class, 'index'])->name('posting-jobs.index');
        Route::post('posting-jobs/{accountingPostingJob}/retry', [AccountingPostingJobController::class, 'retry'])->name('posting-jobs.retry')->middleware('can:accounting.manage');

        // Cài đặt tài khoản kế toán
        Route::get('settings', [AccountingSettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [AccountingSettingsController::class, 'update'])->name('settings.update')->middleware('can:accounting.manage');

        // Rà soát bút toán kế toán
        Route::get('journal-audit', [JournalAuditController::class, 'index'])->name('journal-audit.index');

        // Trung tâm rà soát & xử lý kế toán (Accounting Repair Center)
        Route::prefix('repair-center')->name('repair-center.')->middleware('can:accounting.manage')->group(function () {
            Route::get('/',                        [RepairCenterController::class, 'index'])->name('index');
            Route::post('repair-cancelled-advance',[RepairCenterController::class, 'repairCancelledAdvance'])->name('repair-cancelled-advance');
            Route::post('repair-invoice-status',   [RepairCenterController::class, 'repairInvoiceStatus'])->name('repair-invoice-status');
            Route::post('repair-all-invoice-statuses', [RepairCenterController::class, 'repairAllInvoiceStatuses'])->name('repair-all-invoice-statuses');
            Route::post('reclass',                 [RepairCenterController::class, 'reclass'])->name('reclass');
        });

        // Tài sản cố định
        Route::prefix('fixed-assets')->name('fixed-assets.')->group(function () {
            // Nhóm TSCĐ
            Route::get('categories', [FixedAssetCategoryController::class, 'index'])->name('categories.index');
            Route::post('categories', [FixedAssetCategoryController::class, 'store'])->name('categories.store')->middleware('can:accounting.manage');
            Route::put('categories/{fixedAssetCategory}', [FixedAssetCategoryController::class, 'update'])->name('categories.update')->middleware('can:accounting.manage');
            Route::delete('categories/{fixedAssetCategory}', [FixedAssetCategoryController::class, 'destroy'])->name('categories.destroy')->middleware('can:accounting.manage');

            // Khấu hao
            Route::get('depreciation/run', [FixedAssetDepreciationController::class, 'runPage'])->name('depreciation.run-page');
            Route::get('depreciation/preview', [FixedAssetDepreciationController::class, 'preview'])->name('depreciation.preview');
            Route::post('depreciation/run', [FixedAssetDepreciationController::class, 'run'])->name('depreciation.run')->middleware('can:accounting.manage');
            Route::post('depreciation/post-journal', [FixedAssetDepreciationController::class, 'postJournal'])->name('depreciation.post-journal')->middleware('can:accounting.manage');
            Route::post('depreciation/{depreciation}/reverse', [FixedAssetDepreciationController::class, 'reverse'])->name('depreciation.reverse')->middleware('can:accounting.manage');

            // Báo cáo
            Route::get('reports/ledger', [AccountingFixedAssetReportController::class, 'ledger'])->name('reports.ledger');
            Route::get('reports/schedule', [AccountingFixedAssetReportController::class, 'schedule'])->name('reports.schedule');
            Route::get('reports/movement', [AccountingFixedAssetReportController::class, 'movement'])->name('reports.movement');
            Route::get('reports/reconciliation', [AccountingFixedAssetReportController::class, 'reconciliation'])->name('reports.reconciliation');
            Route::get('reports/compliance', [AccountingFixedAssetReportController::class, 'compliance'])->name('reports.compliance');

            // CRUD tài sản (resource đặt sau các route cụ thể)
            Route::resource('/', AccountingFixedAssetController::class)->parameters(['' => 'fixedAsset']);

            // Hành động trên tài sản
            Route::post('{fixedAsset}/place-in-service', [AccountingFixedAssetController::class, 'placeInService'])->name('place-in-service')->middleware('can:accounting.manage');
            Route::post('{fixedAsset}/transfer', [AccountingFixedAssetController::class, 'transfer'])->name('transfer')->middleware('can:accounting.manage');
            Route::post('{fixedAsset}/suspend', [AccountingFixedAssetController::class, 'suspend'])->name('suspend')->middleware('can:accounting.manage');
            Route::post('{fixedAsset}/resume', [AccountingFixedAssetController::class, 'resume'])->name('resume')->middleware('can:accounting.manage');

            // Sửa chữa
            Route::get('{fixedAsset}/repairs/create', [FixedAssetRepairController::class, 'create'])->name('repairs.create');
            Route::post('{fixedAsset}/repairs', [FixedAssetRepairController::class, 'store'])->name('repairs.store')->middleware('can:accounting.manage');
            Route::delete('{fixedAsset}/repairs/{repair}', [FixedAssetRepairController::class, 'destroy'])->name('repairs.destroy')->middleware('can:accounting.manage');

            // Thanh lý
            Route::get('{fixedAsset}/disposals/create', [FixedAssetDisposalController::class, 'create'])->name('disposals.create');
            Route::post('{fixedAsset}/disposals', [FixedAssetDisposalController::class, 'store'])->name('disposals.store')->middleware('can:accounting.manage');
            Route::delete('{fixedAsset}/disposals/{disposal}', [FixedAssetDisposalController::class, 'destroy'])->name('disposals.destroy')->middleware('can:accounting.manage');
        });

        // CCDC — Công cụ dụng cụ
        Route::prefix('small-tools')->name('small-tools.')->group(function () {
            // Categories
            Route::get('categories', [SmallToolCategoryController::class, 'index'])->name('categories.index')->middleware('can:ccdc.view');
            Route::post('categories', [SmallToolCategoryController::class, 'store'])->name('categories.store')->middleware('can:ccdc.manage');
            Route::put('categories/{category}', [SmallToolCategoryController::class, 'update'])->name('categories.update')->middleware('can:ccdc.manage');
            Route::delete('categories/{category}', [SmallToolCategoryController::class, 'destroy'])->name('categories.destroy')->middleware('can:ccdc.manage');

            // Tools list (fixed segments — must come before {tool} wildcard)
            Route::get('',      [SmallToolController::class, 'index'])->name('index')->middleware('can:ccdc.view');
            Route::get('create',[SmallToolController::class, 'create'])->name('create')->middleware('can:ccdc.manage');
            Route::post('',     [SmallToolController::class, 'store'])->name('store')->middleware('can:ccdc.manage');

            // Receipts (CCNK-)
            Route::get('receipts',                   [SmallToolReceiptController::class, 'index'])->name('receipts.index')->middleware('can:ccdc.view');
            Route::get('receipts/create',            [SmallToolReceiptController::class, 'create'])->name('receipts.create')->middleware('can:ccdc.manage');
            Route::post('receipts',                  [SmallToolReceiptController::class, 'store'])->name('receipts.store')->middleware('can:ccdc.manage');
            Route::get('receipts/{receipt}',         [SmallToolReceiptController::class, 'show'])->name('receipts.show')->middleware('can:ccdc.view')->whereNumber('receipt');
            Route::post('receipts/{receipt}/confirm',[SmallToolReceiptController::class, 'confirm'])->name('receipts.confirm')->middleware('can:ccdc.manage')->whereNumber('receipt');
            Route::post('receipts/{receipt}/cancel', [SmallToolReceiptController::class, 'cancel'])->name('receipts.cancel')->middleware('can:ccdc.cancel')->whereNumber('receipt');

            // Issues (CCXD-)
            Route::get('issues',                  [SmallToolIssueController::class, 'index'])->name('issues.index')->middleware('can:ccdc.view');
            Route::get('issues/create',           [SmallToolIssueController::class, 'create'])->name('issues.create')->middleware('can:ccdc.manage');
            Route::post('issues',                 [SmallToolIssueController::class, 'store'])->name('issues.store')->middleware('can:ccdc.manage');
            Route::get('issues/{issue}',          [SmallToolIssueController::class, 'show'])->name('issues.show')->middleware('can:ccdc.view')->whereNumber('issue');
            Route::post('issues/{issue}/confirm', [SmallToolIssueController::class, 'confirm'])->name('issues.confirm')->middleware('can:ccdc.manage')->whereNumber('issue');
            Route::post('issues/{issue}/cancel',  [SmallToolIssueController::class, 'cancel'])->name('issues.cancel')->middleware('can:ccdc.cancel')->whereNumber('issue');

            // Allocations (phân bổ hàng tháng)
            Route::get('allocations',                      [SmallToolAllocationController::class, 'index'])->name('allocations.index')->middleware('can:ccdc.view');
            Route::post('allocations/run',                 [SmallToolAllocationController::class, 'run'])->name('allocations.run')->middleware('can:ccdc.allocate');
            Route::post('allocations/{allocation}/reverse',[SmallToolAllocationController::class, 'reverse'])->name('allocations.reverse')->middleware('can:ccdc.allocate');

            // Reports
            Route::get('reports/ledger',              [SmallToolReportController::class, 'ledger'])->name('reports.ledger')->middleware('can:ccdc.view');
            Route::get('reports/allocation-schedule', [SmallToolReportController::class, 'allocationSchedule'])->name('reports.allocation-schedule')->middleware('can:ccdc.view');
            Route::get('reports/gl-reconcile',        [SmallToolReportController::class, 'glReconcile'])->name('reports.gl-reconcile')->middleware('can:ccdc.view');

            // Tool detail — {tool} wildcard LAST; whereNumber prevents non-ID segments from hitting model binding
            Route::get('{tool}',         [SmallToolController::class, 'show'])->name('show')->middleware('can:ccdc.view')->whereNumber('tool');
            Route::get('{tool}/edit',    [SmallToolController::class, 'edit'])->name('edit')->middleware('can:ccdc.manage')->whereNumber('tool');
            Route::put('{tool}',         [SmallToolController::class, 'update'])->name('update')->middleware('can:ccdc.manage')->whereNumber('tool');
            Route::post('{tool}/confirm',[SmallToolController::class, 'confirm'])->name('confirm')->middleware('can:ccdc.manage')->whereNumber('tool');

            // Transfers (CCCT-)
            Route::get('{tool}/transfers/create', [SmallToolTransferController::class, 'create'])->name('transfers.create')->middleware('can:ccdc.manage')->whereNumber('tool');
            Route::post('{tool}/transfers',       [SmallToolTransferController::class, 'store'])->name('transfers.store')->middleware('can:ccdc.manage')->whereNumber('tool');

            // Disposals (CCXL-)
            Route::get('{tool}/disposals/create', [SmallToolDisposalController::class, 'create'])->name('disposals.create')->middleware('can:ccdc.dispose')->whereNumber('tool');
            Route::post('{tool}/disposals',       [SmallToolDisposalController::class, 'store'])->name('disposals.store')->middleware('can:ccdc.dispose')->whereNumber('tool');
        });
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
        Route::get('purchase-orders/import/template', [PurchaseOrderController::class, 'importTemplate'])->name('purchase-orders.import.template');
        Route::post('purchase-orders/import/preview',  [PurchaseOrderController::class, 'importPreview'])->name('purchase-orders.import.preview');
        Route::post('purchase-orders/import/confirm',  [PurchaseOrderController::class, 'importConfirm'])->name('purchase-orders.import.confirm');
        Route::get('purchase-orders/export-excel', [PurchaseOrderController::class, 'exportExcel'])->name('purchase-orders.export-excel');
        Route::resource('purchase-orders', PurchaseOrderController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('purchase-orders/{purchaseOrder}/send',    [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
        Route::post('purchase-orders/{purchaseOrder}/cancel',  [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

        Route::get('purchase-invoices/export-excel', [PurchaseInvoiceController::class, 'exportExcel'])->name('purchase-invoices.export-excel');
        Route::resource('purchase-invoices', PurchaseInvoiceController::class);
        Route::post('purchase-invoices/{purchaseInvoice}/transition', [PurchaseInvoiceController::class, 'transition'])->name('purchase-invoices.transition');
        Route::post('purchase-invoices/{purchaseInvoice}/recall-payments', [PurchaseInvoiceController::class, 'recallPayments'])->name('purchase-invoices.recall-payments')->middleware('can:purchasing.approve');
        Route::post('purchase-invoices/{purchaseInvoice}/payments', [PurchaseInvoicePaymentController::class, 'store'])->name('purchase-invoices.payments.store');
        Route::delete('purchase-invoices/{purchaseInvoice}/payments/{payment}', [PurchaseInvoicePaymentController::class, 'destroy'])->name('purchase-invoices.payments.destroy');
        Route::post('purchase-invoices/{purchaseInvoice}/attachment', [PurchaseInvoiceController::class, 'uploadAttachment'])->name('purchase-invoices.attachment.upload');
        Route::delete('purchase-invoices/{purchaseInvoice}/attachment', [PurchaseInvoiceController::class, 'deleteAttachment'])->name('purchase-invoices.attachment.delete');
        Route::patch('purchase-invoices/{purchaseInvoice}/items/{item}/line-type', [PurchaseInvoiceController::class, 'updateItemLineType'])->name('purchase-invoices.items.line-type')->middleware('can:purchasing.approve');
        Route::post('purchase-invoices/{purchaseInvoice}/advance-allocations', [SupplierAdvanceAllocationController::class, 'store'])->name('purchase-invoices.advance-allocations.store');
        Route::delete('advance-allocations/{allocation}', [SupplierAdvanceAllocationController::class, 'destroy'])->name('advance-allocations.destroy');

        Route::resource('supplier-advances', SupplierAdvanceController::class);
        Route::post('supplier-advances/{supplierAdvance}/cancel', [SupplierAdvanceController::class, 'cancel'])->name('supplier-advances.cancel');
        Route::post('supplier-advances/{supplierAdvance}/refund', [SupplierAdvanceController::class, 'refund'])->name('supplier-advances.refund');

        Route::get('purchase-contracts/export-excel', [PurchaseContractController::class, 'exportExcel'])->name('purchase-contracts.export-excel');
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
        Route::get('stock-card',              [InventoryReportController::class, 'stockCard'])->name('stock_card');
        Route::get('stock-entry-details',     [StockEntryDetailReportController::class, 'index'])->name('stock_entry_details');
        Route::get('stock-entry-details/export', [StockEntryDetailReportController::class, 'export'])->name('stock_entry_details.export');
        Route::get('stock-exit-details',      [StockExitDetailReportController::class, 'index'])->name('stock_exit_details');
        Route::get('stock-exit-details/export', [StockExitDetailReportController::class, 'export'])->name('stock_exit_details.export');
        Route::get('cash-flow',                  [CashFlowController::class,          'index'])->name('cash_flow');
        Route::get('cash-flow/export',           [CashFlowController::class,          'export'])->name('cash_flow.export');
        Route::get('cash-flow-statement',             [CashFlowStatementController::class, 'index'])->name('cash_flow_statement');
        Route::get('cash-flow-statement/export',      [CashFlowStatementController::class, 'exportExcel'])->name('cash_flow_statement.export');
        Route::get('cash-flow-statement/pdf',         [CashFlowStatementController::class, 'exportPdf'])->name('cash_flow_statement.pdf');
        Route::get('cash-flow-statement/line-detail', [CashFlowStatementController::class, 'lineDetail'])->name('cash_flow_statement.line_detail');
        Route::patch('cash-flow-statement/update-code', [CashFlowStatementController::class, 'updateVoucherCode'])->name('cash_flow_statement.update_code');
        Route::get('income-statement',             [IncomeStatementController::class, 'index'])->name('income_statement');
        Route::get('income-statement/export',     [IncomeStatementController::class, 'exportExcel'])->name('income_statement.export');
        Route::get('income-statement/pdf',        [IncomeStatementController::class, 'exportPdf'])->name('income_statement.pdf');
        Route::get('income-statement/line-detail',[IncomeStatementController::class, 'lineDetail'])->name('income_statement.line_detail');

        Route::get('balance-sheet',              [BalanceSheetController::class, 'index'])->name('balance_sheet');
        Route::get('balance-sheet/export',       [BalanceSheetController::class, 'export'])->name('balance_sheet.export');
        Route::get('balance-sheet/pdf',          [BalanceSheetController::class, 'exportPdf'])->name('balance_sheet.pdf');
        Route::post('balance-sheet/map-account', [BalanceSheetController::class, 'mapAccount'])->name('balance_sheet.map_account');
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
        Route::get('ar-detail',               [ArDetailController::class,        'index'])->name('ar.detail');
        Route::get('ar-detail/export',        [ArDetailController::class,        'export'])->name('ar.detail.export');
        Route::get('ap-detail',               [ApDetailController::class,        'index'])->name('ap.detail');
        Route::get('ap-detail/export',        [ApDetailController::class,        'export'])->name('ap.detail.export');
        Route::get('document-checklist',        [DocumentChecklistController::class,'index'])->name('document_checklist');
        Route::get('document-checklist/export', [DocumentChecklistController::class,'export'])->name('document_checklist.export');
        Route::get('document-checklist/pdf',    [DocumentChecklistController::class,'exportPdf'])->name('document_checklist.pdf');
        Route::get('general-journal-detail',        [GeneralJournalDetailController::class, 'index'])->name('general_journal_detail');
        Route::get('general-journal-detail/export', [GeneralJournalDetailController::class, 'export'])->name('general_journal_detail.export');
        Route::get('document-checklist-detail',        [DocumentChecklistDetailController::class,'index'])->name('document_checklist_detail');
        Route::get('document-checklist-detail/export', [DocumentChecklistDetailController::class,'export'])->name('document_checklist_detail.export');
        Route::get('document-checklist-detail/pdf',    [DocumentChecklistDetailController::class,'exportPdf'])->name('document_checklist_detail.pdf');
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

    // ─── Lightweight JSON search endpoints (used by RemoteSearchSelect components) ───
    Route::prefix('api/search')->name('search.')->group(function () {
        Route::get('suppliers',     [SearchController::class, 'suppliers'])->name('suppliers');
        Route::get('customers',     [SearchController::class, 'customers'])->name('customers');
        Route::get('products',      [SearchController::class, 'products'])->name('products');
        Route::get('account-codes', [SearchController::class, 'accountCodes'])->name('account-codes');
        Route::get('employees',     [SearchController::class, 'employees'])->name('employees');
        Route::get('projects',      [SearchController::class, 'projects'])->name('projects');
        Route::get('warehouses',    [SearchController::class, 'warehouses'])->name('warehouses');
        Route::get('services',           [SearchController::class, 'services'])->name('services');
        Route::get('warehouse-products',      [SearchController::class, 'warehouseProducts'])->name('warehouse-products');
        Route::get('project-purchase-orders', [SearchController::class, 'projectPurchaseOrders'])->name('project-purchase-orders');
        Route::get('orders',                  [SearchController::class, 'orders'])->name('orders');
    });
});
