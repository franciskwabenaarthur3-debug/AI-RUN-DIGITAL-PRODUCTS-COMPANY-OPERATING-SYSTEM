<?php

use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadActionController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MaintenancePlanController;
use App\Http\Controllers\MaintenanceTaskController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ServicePackageController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
    Route::get('/login/two-factor', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/login/two-factor', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:20,1')->name('two-factor.store');
});

Route::middleware(['auth', 'active', 'two-factor'])->group(function () {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::prefix('account')->name('account.')->controller(SecurityController::class)->group(function () {
        Route::get('security', 'show')->name('security');
        Route::put('password', 'password')->middleware('throttle:10,1')->name('password');
        Route::post('two-factor', 'begin')->name('two-factor.begin');
        Route::post('two-factor/confirm', 'confirm')->middleware('throttle:10,1')->name('two-factor.confirm');
        Route::post('two-factor/recovery-codes', 'recoveryCodes')->middleware('throttle:10,1')->name('two-factor.recovery-codes');
        Route::post('two-factor/disable', 'disable')->middleware('throttle:10,1')->name('two-factor.disable');
    });

    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/system', SystemStatusController::class)->name('system.index');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::resource('leads', LeadController::class)->except('destroy');
    Route::prefix('leads/{lead}')->name('leads.')->controller(LeadActionController::class)->group(function () {
        Route::post('status', 'status')->name('status');
        Route::post('follow-up', 'followUp')->name('follow-up');
        Route::post('notes', 'note')->name('notes');
        Route::post('opt-out', 'optOut')->name('opt-out');
        Route::post('convert', 'convert')->name('convert');
    });

    Route::resource('customers', CustomerController::class)->except('destroy');
    Route::resource('service-packages', ServicePackageController::class)->except(['show', 'destroy']);

    Route::resource('proposals', ProposalController::class)->except('destroy');
    Route::prefix('proposals/{proposal}')->name('proposals.')->controller(ProposalController::class)->group(function () {
        Route::get('preview', 'preview')->name('preview');
        Route::post('submit', 'submit')->name('submit');
        Route::post('send', 'send')->name('send');
        Route::post('respond', 'respond')->name('respond');
        Route::post('withdraw', 'withdraw')->name('withdraw');
        Route::post('new-version', 'newVersion')->name('new-version');
    });
    Route::post('proposals/{proposal}/project', [ProjectController::class, 'createFromProposal'])->name('proposals.project');

    Route::resource('projects', ProjectController::class)->only(['index', 'show', 'edit', 'update']);
    Route::prefix('projects/{project}')->name('projects.')->controller(ProjectController::class)->scopeBindings()->group(function () {
        Route::post('status', 'status')->name('status');
        Route::post('feedback', 'feedback')->name('feedback');
        Route::post('handover', 'handover')->name('handover');
        Route::post('milestones', 'addMilestone')->name('milestones.store');
        Route::post('milestones/{milestone}/status', 'milestoneStatus')->name('milestones.status');
        Route::post('tasks', 'addTask')->name('tasks.store');
        Route::post('tasks/{task}/complete', 'completeTask')->name('tasks.complete');
        Route::post('deliverables', 'addDeliverable')->name('deliverables.store');
        Route::put('deliverables/{deliverable}', 'updateDeliverable')->name('deliverables.update');
        Route::post('releases', [ReleaseController::class, 'store'])->name('releases.store');
    });
    Route::get('releases/{release}', [ReleaseController::class, 'show'])->name('releases.show');
    Route::prefix('releases/{release}')->name('releases.')->controller(ReleaseController::class)->scopeBindings()->group(function () {
        Route::post('checks/{check}', 'check')->name('check');
        Route::post('deploy-staging', 'deployStaging')->name('deploy-staging');
        Route::post('submit', 'submit')->name('submit');
        Route::post('deploy', 'deploy')->name('deploy');
        Route::post('health-check', 'healthCheck')->name('health-check');
        Route::post('verify', 'verify')->name('verify');
        Route::post('rollback', 'rollback')->name('rollback');
        Route::post('cancel', 'cancel')->name('cancel');
    });

    Route::prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('/', [MaintenancePlanController::class, 'index'])->name('index');
        Route::get('plans/create', [MaintenancePlanController::class, 'create'])->name('plans.create');
        Route::post('plans', [MaintenancePlanController::class, 'store'])->name('plans.store');
        Route::get('plans/{plan}', [MaintenancePlanController::class, 'show'])->name('plans.show');
        Route::get('plans/{plan}/edit', [MaintenancePlanController::class, 'edit'])->name('plans.edit');
        Route::put('plans/{plan}', [MaintenancePlanController::class, 'update'])->name('plans.update');
        Route::post('plans/{plan}/status', [MaintenancePlanController::class, 'status'])->name('plans.status');
        Route::post('plans/{plan}/renew', [MaintenancePlanController::class, 'renew'])->name('plans.renew');
        Route::post('plans/{plan}/schedule', [MaintenancePlanController::class, 'schedule'])->name('plans.schedule');

        Route::get('tasks/{task}', [MaintenanceTaskController::class, 'show'])->name('tasks.show');
        Route::get('tasks/{task}/report', [MaintenanceTaskController::class, 'report'])->name('tasks.report');
        Route::prefix('tasks/{task}')->name('tasks.')->controller(MaintenanceTaskController::class)->scopeBindings()->group(function () {
            Route::post('start', 'start')->name('start');
            Route::post('checklist', 'checklist')->name('checklist');
            Route::post('health-check', 'healthCheck')->name('health-check');
            Route::post('findings', 'finding')->name('findings.store');
            Route::post('findings/{finding}/resolve', 'resolveFinding')->name('findings.resolve');
            Route::post('findings/{finding}/request-change', 'requestChange')->name('findings.request-change');
            Route::post('findings/{finding}/record-change', 'recordChange')->name('findings.record-change');
            Route::post('complete', 'complete')->name('complete');
            Route::post('fail', 'fail')->name('fail');
            Route::post('cancel', 'cancel')->name('cancel');
        });
    });

    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/costs', [ReportController::class, 'costs'])->name('reports.costs');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');

    Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
    Route::get('agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
    Route::put('agents/{agent}/status', [AgentController::class, 'updateStatus'])->name('agents.status');
    Route::put('agents/{agent}/configuration', [AgentController::class, 'updateConfiguration'])->name('agents.configuration');
    Route::put('agents/{agent}/permissions', [AgentController::class, 'updatePermissions'])->name('agents.permissions');

    Route::resource('tasks', TaskController::class)->only(['index', 'create', 'store', 'show']);
    Route::prefix('tasks/{task}')->name('tasks.')->controller(TaskController::class)->group(function () {
        Route::post('queue', 'queue')->name('queue');
        Route::post('retry', 'retry')->name('retry');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::post('accept', 'accept')->name('accept');
        Route::post('reject', 'reject')->name('reject');
    });

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::get('approvals/{approval}', [ApprovalController::class, 'show'])->name('approvals.show');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit.index');
});
