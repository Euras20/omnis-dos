<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * System administrator may complete both steps of a stock assignment.
 * Application SoD checks already skip the admin role; this keeps the DB
 * trigger consistent so the admin path does not fail at commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_stock_assignment_sod()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.status = 'assigned'
                   AND NEW.decided_by IS NOT NULL
                   AND NEW.requested_by IS NOT NULL
                   AND NEW.decided_by = NEW.requested_by
                   AND NOT EXISTS (
                       SELECT 1
                       FROM user_role ur
                       JOIN role r ON r.id = ur.role_id
                       WHERE ur.user_id = NEW.decided_by
                         AND r.code IN ('admin', 'system_administrator')
                         AND r.is_active
                   ) THEN
                    RAISE EXCEPTION 'stock assignment SoD: decided_by must differ from requested_by';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
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
        SQL);
    }
};
