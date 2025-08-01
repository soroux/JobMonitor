<?php
use Illuminate\Support\Facades\Route;
use Soroux\JobMonitor\Http\Controllers\JobMonitorController;

Route::get('/stats', [JobMonitorController::class, 'stats']);
Route::get('/jobs/failed', [JobMonitorController::class, 'failedJobs']);
Route::post('/jobs/failed/{id}/retry', [JobMonitorController::class, 'retryFailedJob']);
Route::delete('/jobs/failed/{id}', [JobMonitorController::class, 'deleteFailedJob']);

Route::get('/commands/running', [JobMonitorController::class, 'runningCommands']);
Route::get('/commands/finished', [JobMonitorController::class, 'finishedCommands']);

Route::get('/commands/{processId}/jobs', [JobMonitorController::class, 'getCommandJobs']);
Route::post('/commands/{processId}/retry-failed', [JobMonitorController::class, 'retryFailedCommandJobs']);

