<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 2 — Chart of accounts & journals.
 *
 * Source: 02-database.md §5.4–5.7, 01-domain-modules.md (Ledger), 03-api.md Ledger.
 * Tables: account, journal, journal_line
 * + balance / immutability / period-lock / posting_key triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 32);
            $table->string('name', 255);
            // asset | liability | equity | income | expense
            $table->string('category', 32);
            // header | detail
            $table->string('kind', 16)->default('detail');
            $table->char('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('fs_line_item', 64)->nullable();
            $table->string('fs_sub_line', 64)->nullable();
            // debit | credit
            $table->string('normal_balance', 8)->default('debit');
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'category', 'is_active']);
            $table->index(['company_id', 'fs_line_item']);
        });

        Schema::create('journal', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('journal_no', 64)->nullable();
            $table->date('posting_date');
            $table->string('description', 512)->nullable();
            // manual | invoice | receipt | bill | payment | stock | payroll | depreciation | opening | bank | transfer | expense | reverse
            $table->string('source_type', 64);
            $table->uuid('source_id')->nullable();
            $table->string('event_type', 64)->nullable();
            // Deterministic unique key: source_type:source_id:event
            $table->string('posting_key', 191);
            // draft | posted | reversed
            $table->string('status', 16)->default('draft');
            $table->uuid('prepared_by')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('reverses_journal_id')->nullable();
            $table->uuid('reversed_by_journal_id')->nullable();
            $table->string('currency', 3)->default('GHS');
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('prepared_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('posted_by')->references('id')->on('app_user')->nullOnDelete();
            // Self-FKs added after table create (avoids PG "no unique constraint" during CREATE)
            $table->unique(['company_id', 'posting_key']);
            $table->unique(['company_id', 'journal_no']);
            $table->index(['company_id', 'status', 'posting_date']);
            $table->index(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'posting_date']);
        });

                // Self-referencing FKs must be added after the table (and its PK) fully exist
        Schema::table('journal', function (Blueprint $table) {
            $table->foreign('reverses_journal_id')
                ->references('id')->on('journal')
                ->nullOnDelete();
            $table->foreign('reversed_by_journal_id')
                ->references('id')->on('journal')
                ->nullOnDelete();
        });

        // High-volume append-only lines use bigIncrements
        Schema::create('journal_line', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('company_id');
            $table->uuid('journal_id');
            $table->unsignedInteger('line_no');
            $table->uuid('account_id');
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->uuid('party_id')->nullable();
            $table->string('memo', 512)->nullable();
            $table->string('dimension1', 64)->nullable();
            $table->string('dimension2', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->restrictOnDelete();
            $table->foreign('account_id')->references('id')->on('account')->restrictOnDelete();
            // party_id FK added after catalog migration (party table); index only here
            $table->unique(['journal_id', 'line_no']);
            $table->index(['company_id', 'account_id', 'journal_id']);
            $table->index(['company_id', 'party_id']);
        });

        // --- Database-enforced invariants (02-database.md §5.4) ---

        // 1. Period lock: no journal may post on or before company.period_lock_date
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_period_lock_check()
RETURNS trigger AS $$
DECLARE
    lock_date date;
BEGIN
    IF NEW.status = 'posted' THEN
        SELECT period_lock_date INTO lock_date
        FROM company
        WHERE id = NEW.company_id;

        IF lock_date IS NOT NULL AND NEW.posting_date <= lock_date THEN
            RAISE EXCEPTION 'period_locked: cannot post journal on or before %', lock_date
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER trg_journal_period_lock
    BEFORE INSERT OR UPDATE OF status, posting_date ON journal
    FOR EACH ROW
    EXECUTE FUNCTION journal_period_lock_check();
SQL);

        // 2. Immutability of posted journals (only reversed_by_journal_id may be set once)
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_immutable_check()
RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'posted' THEN
        IF NEW.status = 'posted'
           AND OLD.posting_date IS NOT DISTINCT FROM NEW.posting_date
           AND OLD.description IS NOT DISTINCT FROM NEW.description
           AND OLD.source_type IS NOT DISTINCT FROM NEW.source_type
           AND OLD.source_id IS NOT DISTINCT FROM NEW.source_id
           AND OLD.event_type IS NOT DISTINCT FROM NEW.event_type
           AND OLD.posting_key IS NOT DISTINCT FROM NEW.posting_key
           AND OLD.prepared_by IS NOT DISTINCT FROM NEW.prepared_by
           AND OLD.posted_by IS NOT DISTINCT FROM NEW.posted_by
           AND OLD.posted_at IS NOT DISTINCT FROM NEW.posted_at
           AND OLD.reverses_journal_id IS NOT DISTINCT FROM NEW.reverses_journal_id
           AND OLD.currency IS NOT DISTINCT FROM NEW.currency
           AND (
                (OLD.reversed_by_journal_id IS NULL AND NEW.reversed_by_journal_id IS NOT NULL)
                OR (OLD.reversed_by_journal_id IS NOT DISTINCT FROM NEW.reversed_by_journal_id)
           )
        THEN
            RETURN NEW;
        END IF;

        IF NEW.status = 'reversed'
           AND OLD.reversed_by_journal_id IS NULL
           AND NEW.reversed_by_journal_id IS NOT NULL
        THEN
            RETURN NEW;
        END IF;

        RAISE EXCEPTION 'journal_immutable: posted journal % cannot be modified', OLD.id
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER trg_journal_immutable
    BEFORE UPDATE ON journal
    FOR EACH ROW
    EXECUTE FUNCTION journal_immutable_check();
SQL);

        // Prevent delete of posted journals
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_no_delete_posted()
RETURNS trigger AS $$
BEGIN
    IF OLD.status IN ('posted', 'reversed') THEN
        RAISE EXCEPTION 'journal_immutable: cannot delete posted/reversed journal %', OLD.id
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER trg_journal_no_delete_posted
    BEFORE DELETE ON journal
    FOR EACH ROW
    EXECUTE FUNCTION journal_no_delete_posted();
SQL);

        // 3. Journal line immutability once parent is posted
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_line_immutable_check()
RETURNS trigger AS $$
DECLARE
    parent_status text;
BEGIN
    SELECT status INTO parent_status FROM journal WHERE id = COALESCE(NEW.journal_id, OLD.journal_id);

    IF parent_status IN ('posted', 'reversed') THEN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'journal_line_immutable: cannot delete line of posted journal'
                USING ERRCODE = 'check_violation';
        END IF;
        IF TG_OP = 'UPDATE' THEN
            RAISE EXCEPTION 'journal_line_immutable: cannot update line of posted journal'
                USING ERRCODE = 'check_violation';
        END IF;
        IF TG_OP = 'INSERT' THEN
            RAISE EXCEPTION 'journal_line_immutable: cannot insert line into posted journal'
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER trg_journal_line_immutable
    BEFORE INSERT OR UPDATE OR DELETE ON journal_line
    FOR EACH ROW
    EXECUTE FUNCTION journal_line_immutable_check();
SQL);

        // 4. Balanced journal (deferred — checked at COMMIT for posted journals)
        // Require ≥ 2 lines and sum(debit) = sum(credit) when status becomes posted.
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_balanced_check()
RETURNS trigger AS $$
DECLARE
    line_count integer;
    total_debit bigint;
    total_credit bigint;
BEGIN
    IF NEW.status = 'posted' AND (TG_OP = 'INSERT' OR OLD.status IS DISTINCT FROM 'posted') THEN
        SELECT COUNT(*), COALESCE(SUM(debit_minor), 0), COALESCE(SUM(credit_minor), 0)
        INTO line_count, total_debit, total_credit
        FROM journal_line
        WHERE journal_id = NEW.id;

        IF line_count < 2 THEN
            RAISE EXCEPTION 'journal_unbalanced: journal % must have at least 2 lines', NEW.id
                USING ERRCODE = 'check_violation';
        END IF;

        IF total_debit <> total_credit THEN
            RAISE EXCEPTION 'journal_unbalanced: journal % debit % != credit %', NEW.id, total_debit, total_credit
                USING ERRCODE = 'check_violation';
        END IF;

        IF total_debit = 0 THEN
            RAISE EXCEPTION 'journal_unbalanced: journal % has zero total', NEW.id
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER trg_journal_balanced
    AFTER INSERT OR UPDATE OF status ON journal
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION journal_balanced_check();
SQL);

        // 5. Line-level: each line has either debit or credit (not both, not neither) when parent posts
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_line_amount_check()
RETURNS trigger AS $$
BEGIN
    IF (NEW.debit_minor > 0 AND NEW.credit_minor > 0)
       OR (NEW.debit_minor = 0 AND NEW.credit_minor = 0)
       OR NEW.debit_minor < 0
       OR NEW.credit_minor < 0
    THEN
        RAISE EXCEPTION 'journal_line_invalid: line must have positive debit XOR credit'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER trg_journal_line_amount
    BEFORE INSERT OR UPDATE OF debit_minor, credit_minor ON journal_line
    FOR EACH ROW
    EXECUTE FUNCTION journal_line_amount_check();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_journal_line_amount ON journal_line');
        DB::statement('DROP FUNCTION IF EXISTS journal_line_amount_check()');
        DB::statement('DROP TRIGGER IF EXISTS trg_journal_balanced ON journal');
        DB::statement('DROP FUNCTION IF EXISTS journal_balanced_check()');
        DB::statement('DROP TRIGGER IF EXISTS trg_journal_line_immutable ON journal_line');
        DB::statement('DROP FUNCTION IF EXISTS journal_line_immutable_check()');
        DB::statement('DROP TRIGGER IF EXISTS trg_journal_no_delete_posted ON journal');
        DB::statement('DROP FUNCTION IF EXISTS journal_no_delete_posted()');
        DB::statement('DROP TRIGGER IF EXISTS trg_journal_immutable ON journal');
        DB::statement('DROP FUNCTION IF EXISTS journal_immutable_check()');
        DB::statement('DROP TRIGGER IF EXISTS trg_journal_period_lock ON journal');
        DB::statement('DROP FUNCTION IF EXISTS journal_period_lock_check()');

        Schema::dropIfExists('journal_line');
        Schema::dropIfExists('journal');
        Schema::dropIfExists('account');
    }
};
