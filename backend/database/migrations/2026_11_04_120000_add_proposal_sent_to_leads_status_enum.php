<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The CRM pipeline offers a "Proposal Sent" stage that the leads.status enum could not store.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE leads MODIFY COLUMN status ENUM('new','contacted','qualified','proposal_sent','converted','lost','ignored') NOT NULL DEFAULT 'new'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE leads SET status = 'qualified' WHERE status = 'proposal_sent'");
            DB::statement("ALTER TABLE leads MODIFY COLUMN status ENUM('new','contacted','qualified','converted','lost','ignored') NOT NULL DEFAULT 'new'");
        }
    }
};
