<?php
declare(strict_types=1);

namespace Lettermint\Email\Model;

use Lettermint\Lettermint;

/**
 * Creates the Lettermint SDK client for a sending token.
 *
 * The SDK client holds no message state, so every email is sent with exactly
 * the payload it is given. A separate factory keeps the client replaceable in
 * tests without calling the real Lettermint API.
 */
class LettermintClientFactory
{
    /**
     * Request timeout in seconds, matching the timeout of lettermint-php 1.x.
     * Emails are often sent during a storefront request, so keep this short.
     */
    public const TIMEOUT_SECONDS = 15.0;

    public function create(#[\SensitiveParameter] string $sendingToken): Lettermint
    {
        return new Lettermint(sendingToken: $sendingToken, timeout: self::TIMEOUT_SECONDS);
    }
}
