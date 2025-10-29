<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Interaction extends Model
{
    use HasFactory;

    // 全てのカラムを一括で登録・更新可能にする
    protected $guarded = [];

    // 将来的にリレーションを定義する場合
    // public function question() { return $this->belongsTo(Question::class); }
    // public function user() { return $this->belongsTo(User::class); }
}
