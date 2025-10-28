<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // ★追加: Topicとの関連付け用
use Illuminate\Database\Eloquent\Relations\HasMany;   // ★追加: Choiceとの関連付け用

class Question extends Model
{
    use HasFactory;

    // どのカラムを操作可能にするかを設定（今回は、全てOKにする）
    // $fillable を使う方法もありますが、$guarded = [] の方が最初は簡単です。
    protected $guarded = [];

    /**
     * この問題が持つ「選択肢」を取得 (1対多の関係: 1つの問題は複数の選択肢を持つ)
     * 関数名は、関連するモデルの複数形 (choices) にするのがLaravelの慣習です。
     */
    public function choices(): HasMany
    {
        // '私はたくさんの Choice を持っていますよ (hasMany)' という関係を定義
        // Laravelは賢いので、自動で Choice モデルの 'question_id' カラムを見に行きます。
        return $this->hasMany(Choice::class);
    }

    /**
     * この問題が属する「トピック」を取得 (多対1の関係: 複数の問題が1つのトピックに属する)
     * 関数名は、関連するモデルの単数形 (topic) にするのがLaravelの慣習です。
     */
    public function topic(): BelongsTo
    {
        // '私は Topic に属していますよ (belongsTo)' という関係を定義
        // Laravelは賢いので、自動で Question モデルの 'topic_id' カラムを見に行きます。
        return $this->belongsTo(Topic::class);
    }
}
