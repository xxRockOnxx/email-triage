<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailActionController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\SenderStatController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TriageResultController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::prefix('emails')->name('emails.')->group(function () {
    Route::get('/', [EmailController::class, 'index'])->name('index');
    Route::get('/{email}', [EmailController::class, 'show'])->name('show');

    Route::post('/{email}/archive', [EmailActionController::class, 'archive'])->name('archive');
    Route::post('/{email}/delete', [EmailActionController::class, 'delete'])->name('delete');
    Route::post('/{email}/flag', [EmailActionController::class, 'flag'])->name('flag');
    Route::post('/{email}/reply-draft', [EmailActionController::class, 'createReplyDraft'])->name('reply-draft');
});

Route::post('/action-logs/{actionLog}/undo', [EmailActionController::class, 'undo'])->name('action-logs.undo');

Route::prefix('triage-results')->name('triage-results.')->group(function () {
    Route::post('/{triageResult}/approve', [TriageResultController::class, 'approve'])->name('approve');
    Route::post('/{triageResult}/correct', [TriageResultController::class, 'correct'])->name('correct');
});

Route::resource('categories', CategoryController::class)->except(['create', 'edit', 'show']);
Route::post('/categories/{category}/accept', [CategoryController::class, 'accept'])->name('categories.accept');
Route::post('/categories/{category}/merge', [CategoryController::class, 'merge'])->name('categories.merge');
Route::post('/categories/{category}/reject', [CategoryController::class, 'reject'])->name('categories.reject');

Route::prefix('senders')->name('senders.')->group(function () {
    Route::get('/', [SenderStatController::class, 'index'])->name('index');
    Route::get('/{senderStat}', [SenderStatController::class, 'show'])->name('show');
});

Route::get('/settings', SettingsController::class.'@index')->name('settings.index');
