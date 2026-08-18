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
            $table->string('name');
            $table->text('message');
            $table->string('sender_id')->nullable();
            $table->string('type', 20)->default('promotional')->index();
            $table->string('status', 20)->default('draft')->index();

            // Costing, captured at build time so a later config change cannot
            // rewrite what the campaign was expected to cost.
            $table->string('encoding', 10)->nullable();
            $table->unsignedInteger('segments_per_message')->default(1);
            $table->unsignedInteger('billable_messages')->default(0);

            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('suppressed_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);

            // Kept so an operator can answer "why didn't X get it?" without
            // re-deriving the filtering.
            $table->json('suppressed_numbers')->nullable();
            $table->json('invalid_numbers')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('dialog-esms.logging.campaign_table', 'dialog_sms_campaigns');
    }
};
