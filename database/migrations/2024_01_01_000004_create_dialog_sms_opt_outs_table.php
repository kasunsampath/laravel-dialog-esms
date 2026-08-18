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

            // Stored normalised (94XXXXXXXXX) so an opt-out recorded against
            // 0772345678 also suppresses +94772345678.
            $table->string('msisdn', 20);

            // Null means a global opt-out: no promotional messages of any
            // kind. A value scopes it to one list or product.
            $table->string('scope')->nullable();

            $table->string('reason')->nullable();
            $table->string('source')->nullable();
            $table->timestamp('opted_out_at');
            $table->timestamps();

            // One row per number per scope; re-recording an opt-out updates
            // rather than duplicating, because unsubscribe links get clicked
            // more than once.
            $table->unique(['msisdn', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('dialog-esms.logging.opt_out_table', 'dialog_sms_opt_outs');
    }
};
