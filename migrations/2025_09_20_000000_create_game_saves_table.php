<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('ladybyron_game_saves', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('game_slug', 100);
    $table->string('slot', 50);
    $table->unsignedInteger('rev')->default(0);
    $table->longText('state_json');       // Ink 状态序列化文本
    $table->text('meta_json')->nullable(); // 额外展示用元数据（JSON）
    $table->string('story_hash', 64)->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'game_slug', 'slot'], 'ux_user_game_slot');
    $table->index(['user_id', 'game_slug']);
});
