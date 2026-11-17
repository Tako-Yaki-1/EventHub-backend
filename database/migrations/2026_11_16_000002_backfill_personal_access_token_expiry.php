<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $minutes = max(1, (int) config('security.token_ttl_minutes', 480));

        // Old rows were created before the expiry feature, so derive their expiry from created_at.
        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->chunkById(100, function ($tokens) use ($minutes) {
                foreach ($tokens as $token) {
                    DB::table('personal_access_tokens')->where('id', $token->id)->update([
                        'expires_at' => Carbon::parse($token->created_at ?? now())->addMinutes($minutes),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // The original nulls cannot be identified safely, and keeping an expiry is the safer state.
    }
};
