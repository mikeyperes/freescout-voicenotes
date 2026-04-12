<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateVoiceNotesTable extends Migration
{
    public function up()
    {
        Schema::create("voice_notes", function (Blueprint $table) {
            $table->increments("id");
            $table->unsignedInteger("thread_id")->nullable()->index();
            $table->unsignedInteger("conversation_id")->nullable()->index();
            $table->string("file_path", 500);
            $table->unsignedInteger("duration_seconds")->default(0);
            $table->string("mime_type", 127)->default("audio/mpeg");
            $table->unsignedBigInteger("file_size")->default(0);
            $table->string("original_name", 255)->nullable();
            $table->string("token", 64)->unique();
            $table->unsignedInteger("created_by")->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists("voice_notes");
    }
}
