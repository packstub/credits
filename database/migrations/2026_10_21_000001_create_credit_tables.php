<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger. A wallet caches its balance and what holds reserve; the lots
 * (credit_grants) say where every credit came from and when it expires; the
 * transactions are append-only and each records the balance after it; the
 * allocations say which lots a transaction took from or gave back to. The
 * cached numbers can always be recomputed from the rows below them, which
 * is what `credits:audit` checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->id();

            match (config('packstub-credits.holder_key_type', 'int')) {
                'uuid' => $table->uuidMorphs('holder'),
                'ulid' => $table->ulidMorphs('holder'),
                default => $table->morphs('holder'),
            };

            $table->string('name')->default('default');
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('held')->default(0);
            $table->bigInteger('low_balance_threshold')->nullable();
            $table->json('meter_remainders')->nullable();
            $table->timestamps();

            $table->unique(['holder_type', 'holder_id', 'name']);
        });

        Schema::create('credit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id');
            $table->bigInteger('amount');
            $table->bigInteger('remaining');
            $table->string('source')->default('grant');
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'remaining', 'expires_at']);
        });

        Schema::create('credit_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id');
            $table->bigInteger('amount');
            $table->bigInteger('captured')->nullable();
            $table->bigInteger('shortfall')->default(0);
            $table->string('status')->default('pending');
            $table->string('reason')->nullable();
            $table->timestamp('expires_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status', 'expires_at']);
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id');
            $table->string('type');
            $table->bigInteger('amount');
            $table->bigInteger('held_change')->default(0);
            $table->bigInteger('balance_after');
            $table->bigInteger('held_after');
            $table->string('reason')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->foreignId('parent_id')->nullable();
            $table->foreignId('hold_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['wallet_id', 'idempotency_key']);
            $table->index(['wallet_id', 'id']);
            $table->index('parent_id');
        });

        Schema::create('credit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id');
            $table->foreignId('grant_id');
            $table->bigInteger('amount');

            $table->index('transaction_id');
            $table->index('grant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_allocations');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_holds');
        Schema::dropIfExists('credit_grants');
        Schema::dropIfExists('credit_wallets');
    }
};
