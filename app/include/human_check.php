<?php
// Keeping bots off the public account pages, with free tools only:
//
//   - a honeypot: a form field people never see (hidden by CSS) but
//     form-filling bots do fill in -- see honeypot_tripped();
//   - Cloudflare Turnstile (free), when TURNSTILE_SITE_KEY and
//     TURNSTILE_SECRET_KEY are set: a challenge that's usually
//     invisible to people. Without the keys it's simply skipped, so
//     development works without an account. Cloudflare publishes test
//     keys that always pass (site 1x00000000000000000000AA, secret
//     1x0000000000000000000000000000000AA);
//   - rate limits (see RateLimit), applied by AccountFlow.

const HONEYPOT_FIELD = 'website';

function honeypot_tripped(array $post): bool {
    return trim((string) ($post[HONEYPOT_FIELD] ?? '')) !== '';
}

class HumanCheck {
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    const RESPONSE_FIELD = 'cf-turnstile-response';

    // (url, form fields) => decoded JSON reply; replaceable for tests.
    private $http_post;

    public function __construct(
        public readonly string $site_key,
        private string $secret_key,
        ?callable $http_post = null,
    ) {
        $this->http_post = $http_post ?? 'human_check_http_post';
    }

    public static function from_environment(): HumanCheck {
        return new HumanCheck((string) getenv('TURNSTILE_SITE_KEY'), (string) getenv('TURNSTILE_SECRET_KEY'));
    }

    public function enabled(): bool {
        return $this->site_key !== '' && $this->secret_key !== '';
    }

    // True when the check is off, or Cloudflare accepts the answer the
    // form carried.
    public function passed(array $post, string $ip): bool {
        if (!$this->enabled()) {
            return true;
        }
        $answer = (string) ($post[self::RESPONSE_FIELD] ?? '');
        if ($answer === '') {
            return false;
        }
        try {
            $reply = ($this->http_post)(self::VERIFY_URL, ['secret' => $this->secret_key, 'response' => $answer, 'remoteip' => $ip]);
        } catch (\Throwable $e) {
            log_error("Turnstile check failed: " . $e->getMessage());
            return false;
        }
        return ($reply['success'] ?? false) === true;
    }
}

function human_check_http_post(string $url, array $fields): array {
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($fields),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException("Couldn't reach $url.");
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}
