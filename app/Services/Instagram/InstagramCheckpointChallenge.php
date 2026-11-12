<?php

namespace App\Services\Instagram;

use App\Services\Slack\SlackClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What happens when an Instagram login ends in `checkpoint_required`.
 *
 * The admins get a DM asking for the code Instagram sent (SMS or email), and
 * a DM of 4 to 8 digits from an admin is taken as that code. Submitting it
 * is NOT implemented: the web checkpoint is a React flow that wants a real
 * browser (JS-computed tokens, device fingerprint), and `InstagramLoginClient`
 * only speaks the plain login AJAX endpoint. So the code is kept in the
 * cache for a short time and the admin is told the truth: finish the
 * challenge in a browser, then run `instagram refresh`.
 */
class InstagramCheckpointChallenge
{
    public const AWAITING_KEY = 'ig:checkpoint:awaiting';

    public const CODE_KEY = 'ig:checkpoint:code';

    private const ANNOUNCED_KEY = 'ig:checkpoint:announced';

    private const AWAITING_TTL = 60 * 15;

    private const CODE_TTL = 60 * 10;

    private const ANNOUNCE_EVERY = 60 * 30;

    public function __construct(
        private SlackClient $slack,
    ) {}

    /**
     * DMs every admin once per ANNOUNCE_EVERY, so a download job that logs
     * in again does not repeat the message.
     */
    public function announce(): void
    {
        $admins = (array) config('services.slack.admin_user_ids', []);

        if ($admins === [] || (string) config('services.slack.bot_token', '') === '') {
            return;
        }

        if (! Cache::add(self::ANNOUNCED_KEY, true, self::ANNOUNCE_EVERY)) {
            return;
        }

        Cache::put(self::AWAITING_KEY, true, self::AWAITING_TTL);

        $text = ":warning: *Instagram wants a checkpoint* (`checkpoint_required`).\n"
            ."If Instagram sent you a code by SMS or email, reply here with the code (4 to 8 digits).\n"
            .'I cannot finish the challenge myself yet, so also open Instagram in a browser and confirm it there, then send `instagram refresh`.';

        foreach ($admins as $adminId) {
            try {
                $this->slack->postMessage((string) $adminId, $text);
            } catch (Throwable $e) {
                Log::error('slack.ig.checkpoint.dm_failed', [
                    'user_id' => $adminId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        Log::info('slack.ig.checkpoint.announced', ['admins' => count($admins)]);
    }

    public function isAwaitingCode(): bool
    {
        return Cache::has(self::AWAITING_KEY);
    }

    /**
     * Keeps the code for a few minutes. Returns whether it was submitted to
     * Instagram, which it never is (see the class comment).
     */
    public function receiveCode(string $code): bool
    {
        Cache::put(self::CODE_KEY, $code, self::CODE_TTL);

        Log::info('slack.ig.checkpoint.code_stored');

        return false;
    }
}
