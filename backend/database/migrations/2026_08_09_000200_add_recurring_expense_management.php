<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->string('name');
            $table->string('category');
            $table->string('description');
            $table->decimal('usual_amount', 14, 2);
            $table->unsignedTinyInteger('due_day');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('usual_payment_method')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'status']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('expense_type')->default('variable_manual')->after('description');
            $table->string('financial_status')->default('pending')->after('amount');
            $table->char('period', 7)->nullable()->after('expense_date');
            $table->date('due_date')->nullable()->after('period');
            $table->timestamp('paid_at')->nullable()->after('due_date');
            $table->string('payment_method')->nullable()->after('paid_at');
            $table->string('payment_reference')->nullable()->after('payment_method');
            $table->foreignId('recurring_expense_id')->nullable()->after('payment_reference')->constrained('recurring_expenses');
            $table->foreignId('meter_reading_id')->nullable()->after('recurring_expense_id')->constrained('meter_readings');
            $table->unique(['recurring_expense_id', 'period'], 'expenses_recurring_period_unique');
            $table->unique('meter_reading_id');
            $table->index(['company_id', 'branch_id', 'period', 'financial_status'], 'expenses_period_status_index');
        });

        DB::table('expenses')->update([
            'expense_type' => 'variable_manual',
            'financial_status' => 'paid',
            'period' => DB::raw("substr(expense_date, 1, 7)"),
            'paid_at' => DB::raw('expense_date'),
        ]);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique('expenses_recurring_period_unique');
            $table->dropUnique(['meter_reading_id']);
            $table->dropIndex('expenses_period_status_index');
            $table->dropConstrainedForeignId('meter_reading_id');
            $table->dropConstrainedForeignId('recurring_expense_id');
            $table->dropColumn(['expense_type', 'financial_status', 'period', 'due_date', 'paid_at', 'payment_method', 'payment_reference']);
        });
        Schema::dropIfExists('recurring_expenses');
    }
};
