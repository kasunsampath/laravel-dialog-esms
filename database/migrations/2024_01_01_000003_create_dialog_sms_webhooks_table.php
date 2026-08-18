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

            // Nullable on purpose: receipts that match no known send are still
            // stored, because an uncorrelated receipt is evidence about the
            // callback format, not garbage to be discarded.
            $table->foreignId('sms_log_id')->nullable()->constrained($this->logTable())->nullOnDelete();

            $table->string('dialog_campaign_id')->nullable()->index();
            $table->string('msisdn')->nullable()->index();

            // The status token exactly as Dialog sent it, before mapping.
            $table->string('raw_status')->nullable();
            $table->string('mapped_status', 20)->nullable();

            $table->json('payload');
            $table->string('http_method', 10)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('dialog-esms.logging.webhook_table', 'dialog_sms_webhooks');
    }

    private function logTable(): string
    {
        return (string) config('dialog-esms.logging.log_table', 'dialog_sms_logs');
    }
};
