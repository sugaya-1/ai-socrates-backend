<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Question;
use App\Models\Interaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Services\GeminiService;

class QuestionController extends Controller
{
    /**
     * 指定されたIDの問題を取得する
     * 問題IDが変わるタイミングで、その問題の「自分だけの」古い履歴をリセットします。
     */
    public function getNextQuestion($id)
    {
        $userId = Auth::id(); // 現在ログインしているユーザーのIDを取得

        $question = Question::find($id);

        if (!$question) {
            return response()->json(['message' => "ID {$id} の問題が見つかりません。"], 404);
        }

        // 新しい挑戦のために、この問題に関する「自分の」過去の会話履歴だけをリセット
        Interaction::where('question_id', $question->id)
                   ->where('user_id', $userId) // 自分のデータだけを対象にする
                   ->delete();

        $question->load('choices');

        $nextId = Question::where('id', '>', $question->id)->min('id');

        return response()->json([
            'id' => $question->id,
            'question_text' => $question->question_text,
            'question_type' => $question->question_type,
            'choices' => $question->choices,
            'next_topic_id' => $nextId
        ]);
    }

    /**
     * 会話履歴を取得する
     */
    public function getHistory(int $questionId)
    {
        try {
            $userId = Auth::id(); // ユーザーID取得

            // 自分の履歴だけを取得
            $history = Interaction::where('question_id', $questionId)
                ->where('user_id', $userId)
                ->orderBy('created_at', 'asc')
                ->get([
                    'id',
                    'question_id',
                    'user_answer',
                    'ai_response',
                    'is_correct',
                    'created_at'
                ]);

            return response()->json([
                'history' => $history
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to retrieve conversation history: " . $e->getMessage());
            return response()->json(['error' => '履歴の取得に失敗しました。'], 500);
        }
    }

    /**
     * ユーザーの回答を受け取り、GeminiServiceに処理を委譲する
     */
    public function checkAnswer(Request $request, $questionId, GeminiService $geminiService)
    {
        $userId = Auth::id(); // ユーザーID取得
        $userAnswerText = $request->input('answer_text');
        $question = Question::with('choices')->find($questionId);

        if (!$question || !$userAnswerText) {
            return response()->json(['message' => 'Invalid request or Question not found.'], $question ? 400 : 404);
        }

        // 1. 過去の会話履歴の取得（自分の履歴のみ）
        $pastInteractions = Interaction::where('question_id', $questionId)
            ->where('user_id', $userId)
            ->orderBy('created_at', 'asc')
            ->get();

        // 2. 最初の正誤判定
        $correctChoice = $question->choices->firstWhere('is_correct', true);
        $correctAnswerText = $correctChoice ? $correctChoice->choice_text : '（正解情報なし）';
        $lowerText = strtolower($userAnswerText);
        $isCorrect = false;

        if ($correctChoice) {
            $correctKeywords = [
                strtolower(trim(str_replace(')', '', $correctAnswerText))),
                strtolower(trim(substr($correctAnswerText, 0, 1)))
            ];
            // CPUなどの特殊ケース対応
            if (stripos($correctAnswerText, 'CPU') !== false) {
                $correctKeywords[] = 'cpu';
            }

            foreach ($correctKeywords as $keyword) {
                if ($keyword && str_contains($lowerText, $keyword)) {
                    $isCorrect = true;
                    break;
                }
            }
        }


        // 3. Gemini API 呼び出し
        // ユーザーIDを第1引数として渡す
        $result = $geminiService->generateAndSaveResponse(
            $userId,
            $question->id,
            $question->question_text,
            $userAnswerText,
            $correctAnswerText,
            $isCorrect,
            $pastInteractions,
            $question->choices
        );

        // 4. 結果をJSONで返す
        return response()->json([
            'question_id' => $questionId,
            'user_answer' => $userAnswerText,
            'is_correct' => $result['is_correct'],
            'explanation' => $result['explanation'],
            'is_sufficient' => $result['is_sufficient'],
        ]);
    }

}
