<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QuestionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/topics/{topicId}/next-question', [QuestionController::class, 'getNextQuestion']);

Route::post('/questions/{questionId}/check', [QuestionController::class, 'checkAnswer']);

Route::get('/interactions', [QuestionController::class, 'getInteractions']);


Route::get('/questions/{questionId}/history', [QuestionController::class, 'getHistory']);
