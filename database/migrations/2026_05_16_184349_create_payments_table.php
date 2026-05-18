<?php

use App\Models\Order;
use App\Models\User;
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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->ulid('num')->unique();

            $table->foreignIdFor(Order::class)
                ->constrained()
                ->restrictOnDelete();

            $table->foreignIdFor(User::class)
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->enum('method', [
                'wallet',
                'card',
            ])->default('wallet');

            $table->enum('status', [
                'pending',
                'success',
                'failed',
            ])->default('pending');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
