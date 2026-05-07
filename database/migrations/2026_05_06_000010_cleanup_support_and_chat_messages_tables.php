<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Defensive cleanup: these artifacts may exist only in some environments.

        // Drop legacy columns referencing support/chat (if any).
        foreach ([
            'orders',
            'customers',
            'users',
            'companies',
            'branches',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach ([
                    'support_id',
                    'support_ticket_id',
                    'support_thread_id',
                    'chat_message_id',
                    'chat_messages_id',
                    'chat_id',
                ] as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue;
                    }

                    // Try to drop FK if it exists (ignore if it doesn't).
                    try {
                        $blueprint->dropForeign([$column]);
                    } catch (\Throwable) {
                        // no-op
                    }

                    try {
                        $blueprint->dropIndex([$column]);
                    } catch (\Throwable) {
                        // no-op
                    }

                    try {
                        $blueprint->dropUnique([$column]);
                    } catch (\Throwable) {
                        // no-op
                    }

                    $blueprint->dropColumn($column);
                }
            });
        }

        // Drop tables (order matters: children first).
        foreach ([
            'support_messages',
            'support_ticket_messages',
            'support_threads',
            'support_tickets',
            'chat_messages',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Intencionalmente irreversível: esta migration remove tabelas/colunas legadas.
    }
};
