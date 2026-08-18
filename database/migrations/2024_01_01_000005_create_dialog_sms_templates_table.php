<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->text('template');

            // Transactional templates bypass opt-out and quiet hours, so this
            // column decides whether marketing rules apply to anything sent
            // through the template.
            $table->string('type', 20)->default('transactional');

            $table->boolean('is_active')->default(true);
            $table->json('variables')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('dialog-esms.logging.template_table', 'dialog_sms_templates');
    }
};
