<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('registry', 64);
            $table->string('message_id', 64);
            $table->string('operation', 64);
            $table->string('sequence', 128)->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('status', 16);
            $table->longText('payload');
            $table->json('meta')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('response_id', 64)->nullable();
            $table->text('error')->nullable();
            $table->string('result_code', 32)->nullable();
            $table->text('result_description')->nullable();
            $table->string('ref', 64)->nullable();
            $table->string('sec_ref', 64)->nullable();
            $table->timestamps();

            $table->unique(['registry', 'message_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['registry', 'sequence', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('reges.outbox.table');

        return is_string($table) && $table !== '' ? $table : 'reges_messages';
    }
};
