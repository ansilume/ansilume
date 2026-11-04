<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\OutboundHttpException;
use app\components\OutboundHttpResponse;

/**
 * An error of the Mattermost channel: a request to Mattermost's REST API v4
 * that failed (MattermostApi), an answer that refuses the delivery
 * (MattermostDestination), or a config the channel cannot use
 * (MattermostConfig).
 *
 * It is an OutboundHttpException, so that a delivery classifies it as every
 * error of the outbound HTTP client (DeliveryOutcome::ofError()): no answer,
 * or an answer of 408, 425, 429 or 5xx, is retried, after the answer's
 * Retry-After; any other answer fails the delivery. Its own kinds are never
 * retried:
 * - KIND_REQUEST: no request was made: the config cannot be used
 *   (CONFIG_UNUSABLE), or the delivery would need more requests than
 *   MattermostApi::MAX_REQUESTS (TOO_MANY_REQUESTS);
 * - KIND_REFUSED: Mattermost answered, and what it answered refuses the
 *   delivery: the token is a person's (PERSONAL_TOKEN), the channel is
 *   archived (ARCHIVED), or the answer is not what the API gives
 *   (UNEXPECTED_ANSWER).
 * An answer that the channel reads as a refusal of its own keeps its kind
 * and status: an edit of the bot's own post that Mattermost does not allow
 * (EDIT_NOT_ALLOWED, a 403) fails the delivery as every 403 does.
 *
 * Its message is made of the constants below: what failed and, for an
 * answer, only its status, the error id of Mattermost's answer (errorId)
 * and the request ID (requestId), which Mattermost's logs know. It never
 * holds the server's address, the token or the answer's text; an error id
 * or a request ID that is not of the form Mattermost gives is left out.
 */
final class MattermostApiException extends OutboundHttpException
{
    /** Mattermost answered, and the answer refuses the delivery for good. */
    public const KIND_REFUSED = 'refused';

    /** A request that failed; sprintf(): what it did (MattermostApi::OP_*), then the detail (detail()). */
    public const FAILED = 'Mattermost: %s failed (%s).';

    /** The detail of an answer outside 2xx; sprintf(): its status. */
    public const DETAIL_STATUS = 'HTTP %d';

    /** The detail of an answer with Mattermost's error id; sprintf(): the id. */
    public const DETAIL_ERROR_ID = 'error %s';

    /** The detail of an answer with a request ID; sprintf(): the ID. */
    public const DETAIL_REQUEST_ID = 'request ID %s';

    /** Any answer of 401: the token. sprintf(): the detail. */
    public const TOKEN_REFUSED = 'Mattermost refused the bot token (%s): it was revoked or has expired, or the bot was '
        . 'disabled when its owner was deactivated.';

    /** The lookup of the channel was refused; sprintf(): the channel (MattermostConfig::channelLabel()), the detail. */
    public const CHANNEL_NOT_FOUND = 'Mattermost has no channel %s that the bot may see (%s): check the channel ID, or the '
        . 'team and channel names, and add the bot to the team, and to the channel if it is private.';

    /** The bot is no member of the channel; sprintf(): the channel's ID, the detail. */
    public const NOT_A_MEMBER = 'The bot is not a member of the Mattermost channel %s (%s): add it to the channel; without '
        . 'it, the bot cannot edit its status posts.';

    /** A post was refused (403); sprintf(): the channel's ID, the detail. */
    public const POST_REFUSED = 'Mattermost refused the post in channel %s (%s): the channel no longer exists, or the bot is '
        . 'not a member of it.';

    /** A post in an archived channel (400 can_not_post_to_deleted); sprintf(): the channel's ID, the detail. */
    public const CHANNEL_ARCHIVED = 'The Mattermost channel %s is archived (%s): unarchive it, or choose another channel.';

    /**
     * Mattermost refused the edit of the bot's own status post, which it
     * still has (403, after the retry, of a member of the channel), as a
     * permission scheme without Edit Posts for members does; sprintf(): the
     * post's ID, the detail.
     */
    public const EDIT_NOT_ALLOWED = 'Mattermost does not let the bot edit its own status post %s (%s): allow members to edit '
        . 'their own posts (Edit Posts in the permission scheme of the system or of the team). Until then the post stays as '
        . 'it is, and no new one is posted.';

    /** The channel's lookup found it archived; sprintf(): the channel (MattermostConfig::channelLabel()). */
    public const ARCHIVED = 'The Mattermost channel %s is archived: unarchive it, or choose another channel.';

    /** The token is no bot's (GET /users/me answered is_bot false). */
    public const PERSONAL_TOKEN = 'The Mattermost token is not a bot\'s: a personal token carries the rights of a person. '
        . 'Create a bot account with the Member role, add it to the team and the channel, and use the bot\'s token.';

    /** A 2xx answer that is not what the API gives; sprintf(): what was done (MattermostApi::OP_*), the status. */
    public const UNEXPECTED_ANSWER = 'Mattermost: %s got an answer that Mattermost\'s API does not give (HTTP %d): check '
        . 'that server_url is the address of a Mattermost server, 10.11 or later.';

    /** The config cannot be used; sprintf(): what is wrong with it, as the template's rules say it. */
    public const CONFIG_UNUSABLE = 'The Mattermost config cannot be used: %s';

    /** A delivery would need more requests than it may make; sprintf(): MattermostApi::MAX_REQUESTS. */
    public const TOO_MANY_REQUESTS = 'Mattermost: a delivery makes at most %d requests, and this one needed more.';

    /** The form of an error id of Mattermost's answers, such as api.context.session_expired.app_error. */
    private const ERROR_ID = '/^[a-z0-9_.]{1,128}$/';

    /** The form of a request ID of Mattermost's answers. */
    private const REQUEST_ID = '/^[a-z0-9]{1,64}$/';

    public function __construct(
        string $kind,
        string $message,
        int $httpStatus = 0,
        ?OutboundHttpResponse $response = null,
        ?\Throwable $previous = null,
        public readonly string $errorId = '',
        public readonly string $requestId = ''
    ) {
        parent::__construct($kind, $message, $httpStatus, $response, $previous);
    }

    /**
     * The error of a request that did $operation (MattermostApi::OP_*) and
     * ended with $error of the outbound HTTP client: of its kind, status and
     * answer (so that a retry waits the answer's Retry-After), with the
     * error id and the request ID of the answer.
     */
    public static function failed(string $operation, OutboundHttpException $error): self
    {
        $response = $error->kind === self::KIND_STATUS ? $error->response : null;
        $errorId = self::errorIdOf($response);
        $requestId = self::requestIdOf($response);
        $detail = $response === null
            ? rtrim($error->getMessage(), '.')
            : self::statusDetail($error->httpStatus, $errorId, $requestId);

        return new self($error->kind, sprintf(self::FAILED, $operation, $detail), $error->httpStatus, $error->response, $error, $errorId, $requestId);
    }

    /**
     * Mattermost answered, and the answer refuses the delivery for good
     * (KIND_REFUSED), as $message says.
     */
    public static function refused(string $message): self
    {
        return new self(self::KIND_REFUSED, $message);
    }

    /**
     * No request was made (KIND_REQUEST), as $message says.
     */
    public static function unusable(string $message): self
    {
        return new self(self::KIND_REQUEST, $message);
    }

    /**
     * This error with its message in the words of $format, whose last %s is
     * the detail (detail()) and whose others are $values.
     */
    public function because(string $format, string ...$values): self
    {
        return new self(
            $this->kind,
            sprintf($format, ...[...$values, $this->detail()]),
            $this->httpStatus,
            $this->response,
            $this,
            $this->errorId,
            $this->requestId
        );
    }

    /**
     * What may be said of the answer: its status, error id and request ID
     * ('HTTP 403, error api.context.permissions.app_error, request ID
     * 8xk...'); for an error without an answer, the client's message
     * without its full stop.
     */
    public function detail(): string
    {
        if ($this->httpStatus > 0) {
            return self::statusDetail($this->httpStatus, $this->errorId, $this->requestId);
        }
        $client = $this->getPrevious();

        return rtrim($client instanceof OutboundHttpException ? $client->getMessage() : $this->getMessage(), '.');
    }

    private static function statusDetail(int $status, string $errorId, string $requestId): string
    {
        $parts = [sprintf(self::DETAIL_STATUS, $status)];
        if ($errorId !== '') {
            $parts[] = sprintf(self::DETAIL_ERROR_ID, $errorId);
        }
        if ($requestId !== '') {
            $parts[] = sprintf(self::DETAIL_REQUEST_ID, $requestId);
        }

        return implode(', ', $parts);
    }

    private static function errorIdOf(?OutboundHttpResponse $response): string
    {
        $decoded = $response === null ? null : json_decode($response->body, true);
        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;

        return is_string($id) && preg_match(self::ERROR_ID, $id) === 1 ? $id : '';
    }

    private static function requestIdOf(?OutboundHttpResponse $response): string
    {
        $id = (string)$response?->header('X-Request-Id');

        return preg_match(self::REQUEST_ID, $id) === 1 ? $id : '';
    }
}
