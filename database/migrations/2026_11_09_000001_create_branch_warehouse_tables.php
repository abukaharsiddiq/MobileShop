<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch & warehouse: branch er dhoron (branch | warehouse), manager, user ke ekadhik branch, ar
     * stock transfer (request -> approve -> dispatch -> receive, partial/damaged/missing).
     *
     * - "In Transit" ekta system location (branches.type = transit): dispatch e source -> transit, receive e
     *   transit -> destination. Tai mot stock (main_stocks.quantity) ar ledger shob shomoy mele, transit o ledger e.
     *   Branch list/dropdown e dekhay na (Branch model er global scope).
     * - Ekoi transfer duibar dispatch/receive hoy na: transfer row lock + status, stock movement idempotency key,
     *   receive form er request_token unique.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('type', 20)->default('branch')->after('name');   // branch | warehouse | transit (system)
            $table->foreignId('manager_id')->nullable()->after('address')->constrained('users')->nullOnDelete();
            $table->index('type');
        });

        if (!DB::table('branches')->where('type', 'transit')->exists()) {
            DB::table('branches')->insert([
                'name' => 'In Transit', 'type' => 'transit', 'code' => 'TRANSIT', 'is_default' => 0, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // user er mul branch (users.branch_id) chara aro je branch gulo dekhte/chalate pare
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unique(['user_id', 'branch_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 30)->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            // requested | approved | rejected | cancelled | in_transit | partially_received | received | discrepancy
            $table->string('status', 20)->default('requested');
            $table->text('note')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->string('request_token', 64)->nullable()->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();      // shesh receive
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'from_branch_id']);
            $table->index(['status', 'to_branch_id']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('main_stock_id')->constrained('main_stocks')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('requested_qty');
            $table->unsignedInteger('approved_qty')->nullable();
            $table->unsignedInteger('dispatched_qty')->default(0);
            $table->unsignedInteger('received_qty')->default(0);   // bhalo obosthay
            $table->unsignedInteger('damaged_qty')->default(0);    // bhanga obosthay pouchhechhe
            $table->unsignedInteger('missing_qty')->default(0);    // pouchhay ni (lost in transit)
            $table->decimal('unit_cost', 15, 2)->nullable();       // dispatch er somoy average
            $table->timestamps();
            $table->unique(['stock_transfer_id', 'main_stock_id']);
        });

        // IMEI wala phone: kon device gelo ar ki obosthay pouchhalo
        Schema::create('stock_transfer_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_item_id')->constrained('stock_transfer_items')->restrictOnDelete();
            $table->foreignId('product_imei_id')->constrained('product_imeis')->restrictOnDelete();
            $table->unsignedTinyInteger('sent_status');             // dispatch er age (available/damaged)
            $table->string('status', 20)->default('in_transit');    // in_transit | received | damaged | missing
            $table->timestamps();
            $table->unique(['stock_transfer_item_id', 'product_imei_id'], 'transfer_devices_item_imei_unique');
        });

        Schema::create('stock_transfer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->string('event', 30);
            $table->text('note')->nullable();
            $table->json('lines')->nullable();                      // receive: [item_id => [received, damaged]]
            $table->string('request_token', 64)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_events');
        Schema::dropIfExists('stock_transfer_devices');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('branch_user');
        if (!DB::table('stock_movements')->whereIn('branch_id', DB::table('branches')->where('type', 'transit')->pluck('id'))->exists()) {
            DB::table('branches')->where('type', 'transit')->delete();
        }
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
