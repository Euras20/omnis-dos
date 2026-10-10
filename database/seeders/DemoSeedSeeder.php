<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Minimal demo data for local / Render testing with OMNIS_AUTH_DRIVER=dev.
 *
 * Seeds:
 *  - 1 company (DOS Limited)
 *  - 1 system admin role (all catalogue permissions)
 *  - 1 admin staff user
 *  - 1 customer party
 *  - 1 customer-portal role + portal user linked to that party
 *
 * Fixed UUIDs so Postman / docs can hard-code them.
 *
 * Usage:
 *   php artisan db:seed --class=DemoSeedSeeder
 *   # or via DatabaseSeeder after migrate
 */
final class DemoSeedSeeder extends Seeder
{
    public const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    public const ADMIN_USER_ID = '22222222-2222-2222-2222-222222222222';

    public const ADMIN_ROLE_ID = '33333333-3333-3333-3333-333333333333';

    public const PORTAL_ROLE_ID = '44444444-4444-4444-4444-444444444444';

    public const CUSTOMER_PARTY_ID = '55555555-5555-5555-5555-555555555555';

    public const PORTAL_USER_ID = '66666666-6666-6666-6666-666666666666';

    public const ADMIN_EMAIL = 'admin@dos.local';

    public const PORTAL_EMAIL = 'customer@acme.local';

    public function run(): void
    {
        $now = now();

        DB::transaction(function () use ($now): void {
            $this->seedCompany($now);
            $this->seedRoles($now);
            $this->seedAdminUser($now);
            $this->seedCustomerParty($now);
            $this->seedPortalUser($now);
        });

        $this->command?->info('Demo seed complete.');
        $this->command?->table(
            ['Who', 'Email', 'Header for dev auth'],
            [
                ['Staff admin', self::ADMIN_EMAIL, 'x-dev-user: '.self::ADMIN_EMAIL],
                ['Customer portal', self::PORTAL_EMAIL, 'x-dev-user: '.self::PORTAL_EMAIL],
            ]
        );
        $this->command?->line('Company ID:  '.self::COMPANY_ID);
        $this->command?->line('Customer party ID: '.self::CUSTOMER_PARTY_ID);
    }

    private function seedCompany(\DateTimeInterface $now): void
    {
        if (DB::table('company')->where('id', self::COMPANY_ID)->exists()) {
            $this->command?->warn('Company already exists — skipping company insert.');

            return;
        }

        DB::table('company')->insert([
            'id' => self::COMPANY_ID,
            'code' => 'DOS',
            'legal_name' => 'DOS Limited',
            'tin' => null,
            'functional_currency' => 'GHS',
            'fiscal_year_start_month' => 1,
            'timezone' => 'Africa/Accra',
            'period_lock_date' => null,
            'address' => 'Accra, Ghana',
            'phone' => null,
            'email' => 'info@dos.local',
            'bank_details' => null,
            'vat_number' => null,
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedRoles(\DateTimeInterface $now): void
    {
        // Admin role (system)
        if (! DB::table('role')->where('id', self::ADMIN_ROLE_ID)->exists()) {
            DB::table('role')->insert([
                'id' => self::ADMIN_ROLE_ID,
                'company_id' => self::COMPANY_ID,
                'code' => 'admin',
                'name' => 'System Administrator',
                'description' => 'Full access for MVP testing',
                'approval_limit_minor' => null,
                'is_system' => true,
                'is_active' => true,
                'row_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $permissions = PermissionCatalogue::codes();
            $rows = array_map(
                static fn (string $code): array => [
                    'role_id' => self::ADMIN_ROLE_ID,
                    'permission' => $code,
                ],
                $permissions
            );
            DB::table('role_permission')->insert($rows);
        }

        // Customer portal role
        if (! DB::table('role')->where('id', self::PORTAL_ROLE_ID)->exists()) {
            DB::table('role')->insert([
                'id' => self::PORTAL_ROLE_ID,
                'company_id' => self::COMPANY_ID,
                'code' => 'customer_portal',
                'name' => 'Customer Portal',
                'description' => 'Access customer portal only',
                'approval_limit_minor' => null,
                'is_system' => true,
                'is_active' => true,
                'row_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('role_permission')->insert([
                'role_id' => self::PORTAL_ROLE_ID,
                'permission' => 'portal.customer',
            ]);
        }
    }

    private function seedAdminUser(\DateTimeInterface $now): void
    {
        if (! DB::table('app_user')->where('id', self::ADMIN_USER_ID)->exists()) {
            DB::table('app_user')->insert([
                'id' => self::ADMIN_USER_ID,
                'company_id' => self::COMPANY_ID,
                'email' => self::ADMIN_EMAIL,
                'display_name' => 'DOS Admin',
                'external_subject' => null,
                'user_type' => 'staff',
                'party_id' => null,
                'employee_id' => null,
                'is_active' => true,
                'phone' => null,
                'job_title' => 'System Administrator',
                'created_by' => null,
                'last_seen_at' => null,
                'deactivated_at' => null,
                'deactivation_reason' => null,
                'row_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $linkExists = DB::table('user_role')
            ->where('user_id', self::ADMIN_USER_ID)
            ->where('role_id', self::ADMIN_ROLE_ID)
            ->exists();

        if (! $linkExists) {
            DB::table('user_role')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => self::COMPANY_ID,
                'user_id' => self::ADMIN_USER_ID,
                'role_id' => self::ADMIN_ROLE_ID,
                'valid_from' => null,
                'valid_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedCustomerParty(\DateTimeInterface $now): void
    {
        if (DB::table('party')->where('id', self::CUSTOMER_PARTY_ID)->exists()) {
            return;
        }

        DB::table('party')->insert([
            'id' => self::CUSTOMER_PARTY_ID,
            'company_id' => self::COMPANY_ID,
            'code' => 'CUST-ACME',
            'name' => 'Acme Trading Ltd',
            'party_type' => 'customer',
            'tin' => null,
            'reg_no' => null,
            'city' => 'Accra',
            'region' => 'Greater Accra',
            'country' => 'GH',
            'address' => null,
            'gps' => null,
            'contact_name' => 'Portal Contact',
            'email' => self::PORTAL_EMAIL,
            'phone' => null,
            'payment_terms' => 'Net 30',
            'credit_limit_minor' => 50000000,
            'currency' => 'GHS',
            'vat_registered' => false,
            'wht_rule_code' => null,
            'account_owner' => null,
            'is_main_supplier' => false,
            'contacts' => null,
            'bank_details' => null,
            'is_active' => true,
            'row_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedPortalUser(\DateTimeInterface $now): void
    {
        if (! DB::table('app_user')->where('id', self::PORTAL_USER_ID)->exists()) {
            DB::table('app_user')->insert([
                'id' => self::PORTAL_USER_ID,
                'company_id' => self::COMPANY_ID,
                'email' => self::PORTAL_EMAIL,
                'display_name' => 'Acme Portal User',
                'external_subject' => null,
                'user_type' => 'portal',
                'party_id' => self::CUSTOMER_PARTY_ID,
                'employee_id' => null,
                'is_active' => true,
                'phone' => null,
                'job_title' => null,
                'created_by' => self::ADMIN_USER_ID,
                'last_seen_at' => null,
                'deactivated_at' => null,
                'deactivation_reason' => null,
                'row_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $linkExists = DB::table('user_role')
            ->where('user_id', self::PORTAL_USER_ID)
            ->where('role_id', self::PORTAL_ROLE_ID)
            ->exists();

        if (! $linkExists) {
            DB::table('user_role')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => self::COMPANY_ID,
                'user_id' => self::PORTAL_USER_ID,
                'role_id' => self::PORTAL_ROLE_ID,
                'valid_from' => null,
                'valid_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
