<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('resolved_at');
            $table->unsignedInteger('reopened_count')->default(0)->after('closed_at');
            $table->timestamp('last_public_message_at')->nullable()->after('reopened_count');
            $table->timestamp('last_customer_message_at')->nullable()->after('last_public_message_at');
            $table->timestamp('last_agent_message_at')->nullable()->after('last_customer_message_at');
            $table->timestamp('last_activity_at')->nullable()->after('last_agent_message_at');
        });

        Schema::create('ticket_satisfaction_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamp('submitted_at');
            $table->string('source_channel', 30)->default('customer_app');
            $table->timestamps();

            $table->index(['customer_id', 'submitted_at']);
            $table->index(['rating', 'submitted_at']);
        });

        $this->backfillTicketLifecycle();
    }

    /** Existing tickets keep a truthful lifecycle trail instead of starting every report from empty. */
    private function backfillTicketLifecycle(): void
    {
        DB::table('tickets')->where('status', 'closed')->whereNull('closed_at')->update(['closed_at' => DB::raw('updated_at')]);

        DB::table('tickets')->orderBy('id')->chunkById(500, function ($tickets): void {
            $ids = $tickets->pluck('id')->all();

            $messages = static fn () => DB::table('ticket_messages')->whereIn('ticket_messages.ticket_id', $ids)
                ->where('ticket_messages.is_internal_note', false)
                ->join('tickets', 'tickets.id', '=', 'ticket_messages.ticket_id')
                ->join('customer_profiles', 'customer_profiles.id', '=', 'tickets.customer_id')
                ->groupBy('ticket_messages.ticket_id')
                ->selectRaw('ticket_messages.ticket_id as ticket_id, MAX(ticket_messages.created_at) as at');

            $public = $messages()->pluck('at', 'ticket_id');
            $customer = $messages()->whereColumn('ticket_messages.sender_user_id', 'customer_profiles.user_id')->pluck('at', 'ticket_id');
            $agent = $messages()->whereColumn('ticket_messages.sender_user_id', '!=', 'customer_profiles.user_id')->pluck('at', 'ticket_id');
            $latest = DB::table('ticket_messages')->whereIn('ticket_id', $ids)->groupBy('ticket_id')
                ->selectRaw('ticket_id, MAX(created_at) as at')->pluck('at', 'ticket_id');

            foreach ($tickets as $ticket) {
                DB::table('tickets')->where('id', $ticket->id)->update([
                    'last_public_message_at' => $public[$ticket->id] ?? null,
                    'last_customer_message_at' => $customer[$ticket->id] ?? null,
                    'last_agent_message_at' => $agent[$ticket->id] ?? null,
                    'last_activity_at' => max($ticket->updated_at, $latest[$ticket->id] ?? $ticket->updated_at),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_satisfaction_responses');

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn([
                'closed_at',
                'reopened_count',
                'last_public_message_at',
                'last_customer_message_at',
                'last_agent_message_at',
                'last_activity_at',
            ]);
        });
    }
};
