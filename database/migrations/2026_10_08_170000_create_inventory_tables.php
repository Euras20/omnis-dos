<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 5 — Inventory.
 *
 * Source: 02-database.md §5.4–5.7, 03-api.md Stock, 07-services-use-cases.md §7.5.5.
 * Tables: stock_movement (append-only ledger), stock_assignment (reservations).
 * Derived: stock_on_hand and stock_available views.
 *
 * Sales-order / goods-receipt FKs are nullable until Sales + Procurement land (14.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Stock movement (append-only inventory ledger) ─────────────────

        Schema::create('stock_movement', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('company_id');
            $table->string('movement_type', 32);
            // receipt | issue | transfer_in | transfer_out | adjustment | delivery | opening | return
            $table->uuid('item_id');
            $table->uuid('location_id');
            $table->string('lot', 64)->nullable();
            $table->decimal('qty', 18, 3);
            // Signed: positive increases on-hand for receipt/transfer_in/opening/return;
            // negative decreases for issue/transfer_out/delivery/adjustment write-off.
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->date('movement_date');
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('sales_order_line_id')->nullable();
            $table->uuid('goods_receipt_line_id')->nullable();
            $table->uuid('stock_assignment_id')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('location')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();

            $table->index(['company_id', 'item_id', 'location_id', 'lot']);
            $table->index(['company_id', 'movement_date']);
            $table->index(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'sales_order_line_id']);
        });

        // Append-only: no UPDATE / DELETE on stock_movement
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_stock_movement_append_only()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'stock_movement is append-only; updates and deletes are not allowed';
            END;
            $$;

            CREATE TRIGGER stock_movement_no_update
                BEFORE UPDATE ON stock_movement
                FOR EACH ROW
                EXECUTE FUNCTION trg_stock_movement_append_only();

            CREATE TRIGGER stock_movement_no_delete
                BEFORE DELETE ON stock_movement
                FOR EACH ROW
                EXECUTE FUNCTION trg_stock_movement_append_only();
        SQL);

        // ─── Stock assignment (reservations against order lines) ───────────

        Schema::create('stock_assignment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('item_id');
            $table->uuid('location_id');
            $table->string('lot', 64)->nullable();
            $table->decimal('qty', 18, 3);
            $table->string('status', 32)->default('pending');
            // pending | assigned | rejected | released | delivered
            $table->uuid('sales_order_line_id')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('location')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('decided_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'item_id', 'location_id']);
            $table->index(['company_id', 'sales_order_line_id']);
        });

        // SoD: confirmer ≠ requester when status becomes assigned
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_stock_assignment_sod()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.status = 'assigned'
                   AND NEW.decided_by IS NOT NULL
                   AND NEW.requested_by IS NOT NULL
                   AND NEW.decided_by = NEW.requested_by THEN
                    RAISE EXCEPTION 'stock assignment SoD: decided_by must differ from requested_by';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER stock_assignment_sod
                BEFORE INSERT OR UPDATE ON stock_assignment
                FOR EACH ROW
                EXECUTE FUNCTION trg_stock_assignment_sod();
        SQL);

        // ─── Derived views ─────────────────────────────────────────────────

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW stock_on_hand AS
            SELECT
                company_id,
                item_id,
                location_id,
                lot,
                SUM(qty) AS qty_on_hand,
                CASE
                    WHEN SUM(qty) = 0 THEN 0
                    ELSE ROUND(SUM(qty * unit_cost_minor) / NULLIF(SUM(qty), 0))
                END AS avg_unit_cost_minor,
                SUM(qty * unit_cost_minor) AS value_minor
            FROM stock_movement
            GROUP BY company_id, item_id, location_id, lot
            HAVING SUM(qty) <> 0;

            CREATE OR REPLACE VIEW stock_available AS
            SELECT
                oh.company_id,
                oh.item_id,
                oh.location_id,
                oh.lot,
                oh.qty_on_hand,
                COALESCE(a.qty_assigned, 0) AS qty_assigned,
                oh.qty_on_hand - COALESCE(a.qty_assigned, 0) AS qty_available,
                oh.avg_unit_cost_minor,
                oh.value_minor
            FROM stock_on_hand oh
            LEFT JOIN (
                SELECT
                    company_id,
                    item_id,
                    location_id,
                    lot,
                    SUM(qty) AS qty_assigned
                FROM stock_assignment
                WHERE status IN ('pending', 'assigned')
                GROUP BY company_id, item_id, location_id, lot
            ) a ON a.company_id = oh.company_id
               AND a.item_id = oh.item_id
               AND a.location_id = oh.location_id
               AND (a.lot IS NOT DISTINCT FROM oh.lot);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS stock_available');
        DB::unprepared('DROP VIEW IF EXISTS stock_on_hand');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_assignment_sod ON stock_assignment');
        DB::unprepared('DROP FUNCTION IF EXISTS trg_stock_assignment_sod()');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movement_no_delete ON stock_movement');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movement_no_update ON stock_movement');
        DB::unprepared('DROP FUNCTION IF EXISTS trg_stock_movement_append_only()');
        Schema::dropIfExists('stock_assignment');
        Schema::dropIfExists('stock_movement');
    }
};
