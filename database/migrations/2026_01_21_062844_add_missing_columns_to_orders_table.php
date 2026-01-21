<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            // Check and add missing columns
            if (!Schema::hasColumn('orders', 'city')) {
                $table->string('city')->nullable()->after('address');
            }
            
            if (!Schema::hasColumn('orders', 'zipcode')) {
                $table->string('zipcode', 20)->nullable()->after('city');
            }
            
            if (!Schema::hasColumn('orders', 'is_paid')) {
                $table->boolean('is_paid')->default(false)->after('status');
            }
            
            if (!Schema::hasColumn('orders', 'stripe_session_id')) {
                $table->string('stripe_session_id')->nullable()->unique()->after('is_paid');
            }
            
            if (!Schema::hasColumn('orders', 'is_customized')) {
                $table->boolean('is_customized')->default(false)->after('stripe_session_id');
            }
            
            if (!Schema::hasColumn('orders', 'customized_file')) {
                $table->string('customized_file')->nullable()->after('is_customized');
            }
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'city',
                'zipcode',
                'is_paid',
                'stripe_session_id',
                'is_customized',
                'customized_file'
            ]);
        });
    }
};