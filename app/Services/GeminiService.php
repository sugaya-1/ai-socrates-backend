<?php

namespace App\Services;

use App\Models\Interaction;         // 会話履歴をDBに保存するために必要
use Illuminate\Support\Collection;  // 過去の履歴を扱うリスト（配列）の型
use Illuminate\Support\Facades\Http; // 外部のAIサーバーと通信するために必要
use Illuminate\Support\Facades\Log;  // サーバーのエラーを記録するために必要

class GeminiService
{
    private string $apiUrl;
    private string $model = 'gemini-2.5-flash-preview-09-2025'; // 使用するAIモデル名

    // サービスの初期設定（このクラスが最初に使われる時に実行される）
    public function __construct()
    {
        $apiKey = env('GEMINI_API_KEY', ''); // 環境変数からAPIキー（鍵）を取得
        // AIサーバーへのアクセスURLを組み立てる
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . $apiKey;
    }

    /**
     * AIの応答を生成し、Interaction履歴をデータベースに保存する
     * このメソッドが、コントローラーから呼び出されるメイン機能です。
     */
    public function generateAndSaveResponse(
        int $questionId,        // 現在の問題ID
        string $questionText,   // 問題文
        string $userAnswerText, // ユーザーの最新の回答
        string $correctAnswerText, // 正解の選択肢テキスト
        array $choices,         // ★追加: 画面の選択肢リスト
        bool $isCorrect,        // 最初の正誤判定の結果（true/false）
        Collection $pastInteractions // 過去の会話履歴のリスト
    ): array {


        // --- 1. 会話履歴の構築 ---
        $chatHistory = [
            ['role' => 'user', 'parts' => [['text' => '最初の質問: ' . $questionText]]],
        ];

        foreach ($pastInteractions as $interaction) {
            $chatHistory[] = ['role' => 'user', 'parts' => [['text' => '私の前の回答: ' . $interaction->user_answer]]];
            $chatHistory[] = ['role' => 'model', 'parts' => [['text' => $interaction->ai_response]]];
        }

        $chatHistory[] = ['role' => 'user', 'parts' => [['text' => '最新の私の回答: ' . $userAnswerText]]];

        // --- 2. AIの指示文（性格・ルール）の生成 ---
        $baseInstruction = <<<EOT
## あなたの役割 (Role)
あなたは「AIソクラテス」です。
古代ギリシャの哲学者ソクラテスのように、すぐに正解を教えるのではなく、対話を通じて学習者自身に真理（正解）を気づかせてください。

## 現在の問題と画面情報 (Context)
問題文: 「{$questionText}」
正解: 「{$correctAnswerText}」

**【重要】画面に表示されている選択肢:**


## 目標 (Goal)
あなたの最優先目的は、学習者が単なる単語の暗記ではなく、**知識を【体系的・構造的】に捉え、用語間の『関連性』を説明できる状態**に導くことです。

## 行動ルール (Rules) - 厳守してください
1. **答えの直接提示は禁止**:
   正解をそのまま教えてはいけません。ただし、学習者が答えに詰まっている場合は、**重要なキーワードを「穴埋め（〇〇）」にするなど、閃きを誘発するヒント**は積極的に出してください。

2. **焦点の維持**:
   問いは、学習者の回答に不足している「定義」または「役割」の**1点のみ**に絞ってください。一度に複数を問わないでください。
   また、話が逸れた場合は、必ず問題の核心テーマ（例: CPUなら『処理』）に引き戻してください。

3. **範囲の限定**:
   問いや例え話は、必ず**「この問題文」と「画面上の選択肢」**にある要素、または誰でもわかる一般的な概念（料理や手紙などの比喩）のみを使用してください。
   画面にない専門用語を勝手に持ち出すことは**厳禁**です。

4. **終了判定 ([FINAL])**:
   ユーザーが質問の核心となる概念の『繋がり』や『全体像における役割』を説明できたと判断した場合のみ、応答の末尾に `[FINAL]` を付けて対話を締めくくってください。

## 対話の具体例 (Few-Shot Examples)

【例1: ユーザーが間違えている場合 (問いかけによる誘導)】
ユーザー: 「CPUはデータを保存する場所です。」
AI: 「なるほど、『場所』という点に着目したのですね。しかし、保存が主役なら、HDDやメモリの役割は何でしょうか？ CPUはそれらのデータを『受け取って、どうする』のが仕事ですか？」

【例2: ユーザーが詰まっている場合 (ルール1: 穴埋めヒントの活用)】
ユーザー: 「うーん、わかりません…」
AI: 「では、料理で例えてみましょう。食材を保管するのは冷蔵庫（メモリ）ですが、その食材を切ったり煮たりして『調理』するシェフの役割にあたるのが〇〇〇です。この〇に入る言葉はなんでしょう？」

【例3: 理解が完了した場合】
ユーザー: 「CPUは命令を処理する頭脳で、メモリは作業机みたいなものですね。」
AI: 「その通りです！処理装置と記憶装置の関係性を完璧に理解できていますね。素晴らしい洞察です。[FINAL]」

## 思考プロセス (Thought Process)
回答を出力する前に、以下の手順で考えてください（出力不要）。
1. ユーザーの回答は「正解」か「誤解」か「無知（わからない）」か判定する。
2. 「誤解」なら、矛盾に気づく問いを考える。
3. 「無知」なら、概念の一部を隠した「穴埋め問題」や「身近な例え」を考える。
4. 画面外の専門用語を使っていないか最終チェックする。
EOT;

        $isInitialCheck = ($pastInteractions->count() === 0); // 初回回答かどうかをチェック

        if ($isInitialCheck) {
            $taskInstruction = "学習者の最初の回答を受け取りました。あなたのタスクは、まず学習者の回答の正誤判定（正解は「{$correctAnswerText}」）
            を**「正解です」「不正解です」という言葉のみで**伝え、その上で学習者の選んだ**選択肢の機能的な根拠（定義）**を問う**追加の質問**を生成することです。
            **警告：正解のテキスト（{$correctAnswerText}）を応答に含めてはいけません。**";

            $systemInstructionText = $baseInstruction . "\n\n" . $taskInstruction;
        } else {
            $taskInstruction = "これは対話の続きです。これまでの会話履歴を考慮し、学習者の最新の回答（「最新の私の回答」）に対する建設的なフィードバックを述べてください。その際、回答に不足している**単一の**技術用語の『定義』や『役割』を特定し、その概念に焦点を絞った次の質問を生成してください。ただし、学習者が十分な理解に達したと判断できる場合は、質問ではなく、最終ルールに従って対話を終了してください。";

            // ★ここで途切れていた箇所を修正
            $systemInstructionText = $baseInstruction . "\n\n" . $taskInstruction;
        }

        $explanation = "AIとの対話でエラーが発生しました.";

        // --- 3. Gemini API 呼び出し（AIサーバーとの通信実行） ---
        try {
            $response = Http::timeout(60)->post($this->apiUrl, [
                'contents' => $chatHistory,
                'systemInstruction' => [
                    'parts' => [['text' => $systemInstructionText]]
                ],
                'generationConfig' => [
                    'temperature' => 0.3
                ]
            ]);

            if ($response->successful()) {
                $generatedText = $response->json('candidates.0.content.parts.0.text');
                $explanation = $generatedText ?: "AI応答取得エラー(テキストなし)";
            } else {
                Log::error('Gemini API request failed.', ['status' => $response->status(), 'response' => $response->body()]);
                $explanation = "AI通信エラー (HTTP Status: " . $response->status() . ")";
            }
        } catch (\Exception $e) {
            Log::error('Gemini API connection error.', ['error' => $e->getMessage()]);
            $explanation = "AI接続エラー";
        }

        // --- 4. 会話履歴の保存とフラグ生成 ---

        $isSufficient = false;
        $explanationText = $explanation;

        if (str_contains($explanation, '[FINAL]')) {
            $isSufficient = true;
            $explanationText = str_replace('[FINAL]', '', $explanation);
        }

        try {
            Interaction::create([
                'question_id' => $questionId,
                'user_answer' => $userAnswerText,
                'ai_response' => $explanationText,
                'is_correct' => $isCorrect,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save interaction.', ['question_id' => $questionId, 'error' => $e->getMessage()]);
        }

        return [
            'explanation' => $explanationText,
            'is_correct' => $isCorrect,
            'is_sufficient' => $isSufficient
        ];
    }
}
