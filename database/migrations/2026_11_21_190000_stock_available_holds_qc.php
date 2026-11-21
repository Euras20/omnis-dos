<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * stock_available subtracts pending, quarantine and rejected QC quantities
 * so they cannot be assigned or sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW stock_available AS
            SELECT
                oh.company_id,
                oh.item_id,
                oh.location_id,
                oh.lot,
                oh.qty_on_hand,
                COALESCE(a.qty_assigned, 0) AS qty_assigned,
                GREATEST(
                    0,
                    oh.qty_on_hand
                    - COALESCE(a.qty_assigned, 0)
                    - COALESCE(q.qty_held, 0)
                ) AS qty_available,
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
               AND (a.lot IS NOT DISTINCT FROM oh.lot)
            LEFT JOIN (
                SELECT
                    gr.company_id,
                    grl.item_id,
                    grl.store_location_id AS location_id,
                    grl.lot,
                    SUM(
                        CASE
                            WHEN grl.qc_status = 'pending' THEN grl.qty_received
                            ELSE grl.qty_quarantine + grl.qty_rejected
                        END
                    ) AS qty_held
                FROM goods_receipt_line grl
                JOIN goods_receipt gr ON gr.id = grl.goods_receipt_id
                WHERE grl.qc_status IN ('pending', 'quarantine', 'rejected', 'partial')
                GROUP BY gr.company_id, grl.item_id, grl.store_location_id, grl.lot
            ) q ON q.company_id = oh.company_id
               AND q.item_id = oh.item_id
               AND (q.location_id IS NOT DISTINCT FROM oh.location_id)
               AND (q.lot IS NOT DISTINCT FROM oh.lot);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
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
                SELECT company_id, item_id, location_id, lot, SUM(qty) AS qty_assigned
                FROM stock_assignment
                WHERE status IN ('pending', 'assigned')
                GROUP BY company_id, item_id, location_id, lot
            ) a ON a.company_id = oh.company_id
               AND a.item_id = oh.item_id
               AND a.location_id = oh.location_id
               AND (a.lot IS NOT DISTINCT FROM oh.lot);
        SQL);
    }
};
