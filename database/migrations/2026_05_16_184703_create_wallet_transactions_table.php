<?php

use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->ulid('num')->unique();

            $table->foreignIdFor(Wallet::class)
                ->constrained()
                ->restrictOnDelete();

            $table->foreignIdFor(Order::class)
                ->constrained()
                ->restrictOnDelete();

            $table->enum('type', [
                'deposit',
                'withdraw',
                'refund',
            ]);

            $table->decimal('amount', 12, 2);

            $table->decimal('balance_before', 12, 2);

            $table->decimal('balance_after', 12, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
