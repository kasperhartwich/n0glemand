<?php

namespace App\Services\Instagram;

use App\Services\Slack\SlackClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * An Instagram login that waits for a human: a code (SMS, email or an
 * authenticator app) or an approval in the Instagram app.
 *
 * The browser login script prints a `code_required` or `approval_required`
 * event and keeps its browser open. `InstagramBrowserLoginClient` hands that
 * event to `codeRequested()` / `approvalRequested()`, which DM the admins and
 * remember that a login waits (the cache key `ig:checkpoint:awaiting`, kept
 * a little longer than the wait). An admin answers with a digit-only DM or
 * `/n0glemand instagram code <digits>`: `handOverCode()` writes the digits
 * to the code file in the browser profile folder (0600), which the script
 * polls, types in and deletes. When the login ends, `finished()` DMs the
 * outcome and clears the key.
 *
 * A challenge the script cannot work with (no code wait configured, an
 * unknown page) still ends as `checkpoint_required`; `announce()` then asks
 * an admin to confirm it in a browser.
 */
class InstagramCheckpointChallenge
{
    public const AWAITING_KEY = 'ig:checkpoint:awaiting';

    private const ANNOUNCED_KEY = 'ig:checkpoint:announced';

    private const ANNOUNCE_EVERY = 60 * 30;

    public function __construct(
        private SlackClient $slack,
    ) {}

    /**
     * The file the script polls for a code, in the private browser profile folder.
     */
    public function codeFilePath(): string
    {
        $dir = storage_path('app/private/instagram-browser');

        File::ensureDirectoryExists($dir, 0700, true);
        @chmod($dir, 0700);

        return $dir.'/code';
    }

    /**
     * What a login is waiting for right now: `code`, `approval` or null.
     */
    public function waitingFor(): ?string
    {
        $waiting = Cache::get(self::AWAITING_KEY);

        return in_array($waiting, ['code', 'approval'], true) ? $waiting : null;
    }

    public function isAwaitingCode(): bool
    {
        return $this->waitingFor() === 'code';
    }

    /**
     * The login printed `code_required`: remember it and ask the admins.
     */
    public function codeRequested(string $method): void
    {
        $this->markWaiting('code');

        $label = match ($method) {
            'sms' => 'SMS',
            'email' => 'email',
            'app' => 'authenticator app',
            default => 'code',
        };

        $this->dmAdmins(sprintf(
            ':key: Instagram wants a code (%s), reply here with the code within %s.',
            $label,
            $this->waitText(),
        ));
    }

    /**
     * The login printed `approval_required` (a "was this you?" screen).
     */
    public function approvalRequested(): void
    {
        $this->markWaiting('approval');

        $this->dmAdmins(sprintf(
            ':iphone: Instagram wants you to approve this login in the Instagram app ("was this you?"). Approve it within %s, no reply needed.',
            $this->waitText(),
        ));
    }

    /**
     * Writes the digits to the code file the waiting script polls. Returns
     * the answer for the admin: handed over, or why not.
     */
    public function handOverCode(string $code): string
    {
        $code = trim($code);

        if (preg_match('/^\d{4,8}$/', $code) !== 1) {
            return 'A code is 4 to 8 digits. Send just the digits.';
        }

        $waiting = $this->waitingFor();

        if ($waiting === null) {
            return 'No Instagram login is waiting for a code right now, so I ignored it.';
        }

        if ($waiting === 'approval') {
            return 'The Instagram login waits for an approval in the Instagram app, not for a code. Approve it there.';
        }

        $path = $this->codeFilePath();
        $tmp = $path.'.'.Str::random(8).'.tmp';

        file_put_contents($tmp, $code);
        @chmod($tmp, 0600);
        rename($tmp, $path);

        Log::info('slack.ig.checkpoint.code_handed_over');

        return ':white_check_mark: Handed the code to the waiting Instagram login, the result follows here.';
    }

    /**
     * The login ended. After a wait for a human the admins get the outcome;
     * either way the waiting state and any unused code file are cleared.
     * `$status` is null when the login crashed.
     */
    public function finished(?InstagramLoginStatus $status): void
    {
        $waited = $this->waitingFor() !== null;

        Cache::forget(self::AWAITING_KEY);
        @unlink($this->codeFilePath());

        if (! $waited) {
            return;
        }

        $this->dmAdmins(match ($status) {
            InstagramLoginStatus::Ok => ':white_check_mark: Instagram login finished: ok, fresh cookies written.',
            InstagramLoginStatus::InvalidCode => ':x: Instagram login failed: Instagram did not accept the code. Send `instagram refresh` to start over.',
            InstagramLoginStatus::Timeout => ':x: Instagram login failed: nobody answered in time. Send `instagram refresh` to start over.',
            null => ':x: Instagram login crashed while it waited for you. See `/n0glemand logs`.',
            default => ":x: Instagram login failed: `{$status->value}`. Send `instagram refresh` to start over.",
        });
    }

    /**
     * A challenge the login could not work with: DMs every admin once per
     * ANNOUNCE_EVERY, so a download job that logs in again does not repeat it.
     */
    public function announce(): void
    {
        if (! Cache::add(self::ANNOUNCED_KEY, true, self::ANNOUNCE_EVERY)) {
            return;
        }

        $this->dmAdmins(":warning: *Instagram wants a checkpoint* (`checkpoint_required`).\n"
            .'I could not handle it myself. Open Instagram in a browser and confirm it there, then send `instagram refresh`.');
    }

    private function markWaiting(string $kind): void
    {
        Cache::put(self::AWAITING_KEY, $kind, max(0, (int) config('services.instagram.code_wait', 600)) + 60);
        @unlink($this->codeFilePath());
    }

    private function waitText(): string
    {
        $minutes = max(1, intdiv((int) config('services.instagram.code_wait', 600), 60));

        return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
    }

    private function dmAdmins(string $text): void
    {
        $admins = (array) config('services.slack.admin_user_ids', []);

        if ($admins === [] || (string) config('services.slack.bot_token', '') === '') {
            return;
        }

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

        Log::info('slack.ig.checkpoint.dm_sent', ['admins' => count($admins)]);
    }
}
