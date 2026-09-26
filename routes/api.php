<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\DefaultController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\WpSyncController;

Route::get('runDefaultValueForNewProject', [DefaultController::class, 'runDefaultValueForNewProject']);
Route::post('telegram-webhook', [TelegramBotController::class, 'index']);
Route::get('setWebhook', [DefaultController::class, 'setWebhook']);
Route::get('deleteWebhook', [DefaultController::class, 'deleteWebhook']);
Route::get('export', [DefaultController::class, 'exportData']);
Route::get('send-bulk-message', [JobController::class, 'sendBulkMessage']);
Route::get('importUsers', [DefaultController::class, 'importUsers']);

Route::prefix('wp-sync')->group(function () {
    Route::get('ping', [WpSyncController::class, 'ping']);

    // Account link
    Route::post('link/status', [WpSyncController::class, 'linkStatus']);
    Route::post('link/disconnect', [WpSyncController::class, 'disconnectLink']);

    Route::post('customer-loyalty/events', [WpSyncController::class, 'customerLoyaltyEvents']);

    // Wallet
    Route::post('wallet/balance', [WpSyncController::class, 'walletBalance']);
    Route::post('wallet/transactions', [WpSyncController::class, 'walletTransactions']);
    Route::post('wallet/credit', [WpSyncController::class, 'walletCredit']);
    Route::post('wallet/debit', [WpSyncController::class, 'walletDebit']);

    // Users
    Route::post('users/list', [WpSyncController::class, 'usersList']);
    Route::post('users/show', [WpSyncController::class, 'usersShow']);
    Route::post('users/update-status', [WpSyncController::class, 'usersUpdateStatus']);
    Route::post('users/wallet-adjust', [WpSyncController::class, 'usersWalletAdjust']);

    // Orders
    Route::post('orders/list', [WpSyncController::class, 'ordersList']);
    Route::post('orders/show', [WpSyncController::class, 'ordersShow']);
    Route::post('orders/import-site', [WpSyncController::class, 'importSiteOrder']);
    Route::post('orders/update-bot', [WpSyncController::class, 'updateBotOrder']);
    Route::post('orders/renew-bot', [WpSyncController::class, 'renewBotOrder']);

    // Payments / receipts
    Route::post('payments/list', [WpSyncController::class, 'paymentsList']);
    Route::post('payments/show', [WpSyncController::class, 'paymentsShow']);
    Route::post('payments/approve', [WpSyncController::class, 'paymentsApprove']);
    Route::post('payments/reject', [WpSyncController::class, 'paymentsReject']);

    // Admin reports / legacy compatibility
    Route::post('admin/stats', [WpSyncController::class, 'adminStats']);
    Route::post('admin/list', [WpSyncController::class, 'adminList']);
    Route::post('sync/user-full', [WpSyncController::class, 'syncUserFull']);

    // Catalog and admin resources
    Route::post('catalog/{type}', [WpSyncController::class, 'catalogList']);
    Route::post('catalog/{type}/{id}', [WpSyncController::class, 'catalogShow'])->whereNumber('id');
});
