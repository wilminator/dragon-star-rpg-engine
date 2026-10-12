<?php
// Sending email. Pages call:
//
//   mailer()->send('player@example.com', 'Subject', "Plain text body", '<p>optional HTML</p>');
//
// which throws MailException if it can't be sent. How mail leaves is set
// by environment variables (see Mailer::from_environment()):
//
//   MAIL_TRANSPORT  smtp | gmail | outlook | log   (default: log)
//   MAIL_FROM, MAIL_FROM_NAME   the sender (name defaults to DragonStar)
//
//   smtp     any mail server (MTA): MAIL_HOST, MAIL_PORT (587),
//            MAIL_ENCRYPTION (starttls | ssl | none; default starttls),
//            MAIL_USERNAME and MAIL_PASSWORD when it needs a login.
//   gmail    Gmail / Google Workspace over OAuth 2 (XOAUTH2), and
//   outlook  Outlook / Microsoft 365 over OAuth 2: MAIL_USERNAME (the
//            mailbox), MAIL_OAUTH_CLIENT_ID, MAIL_OAUTH_CLIENT_SECRET,
//            MAIL_OAUTH_TENANT (outlook only; default "common"). The
//            refresh token comes from MAIL_OAUTH_REFRESH_TOKEN if set,
//            otherwise from connecting the mailbox on admin/mail.php,
//            which keeps it in the database.
//   log      writes each message to MAIL_LOG (default: the system temp
//            folder's dragonstar-mail.log) instead of sending -- for
//            development.
//
// Built on PHPMailer. The token refresh for OAuth is done here rather
// than with an OAuth library: PHPMailer only needs the access token.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\OAuthTokenProvider;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once INCLUDE_GAME . 'app_setting.php';
require_once INCLUDE_GAME . 'secret_box.php';

class MailException extends RuntimeException {}

final class OutgoingMail {
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
    ) {}
}

interface MailTransport {
    // Throws MailException on failure.
    public function send(OutgoingMail $mail, string $from, string $from_name): void;
}

class Mailer {
    public function __construct(
        private MailTransport $transport,
        public readonly string $from,
        public readonly string $from_name = 'DragonStar',
    ) {}

    public function send(string $to, string $subject, string $text, ?string $html = null): void {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("Not an email address: $to");
        }
        $this->transport->send(new OutgoingMail($to, $subject, $text, $html), $this->from, $this->from_name);
    }

    public function transport(): MailTransport {
        return $this->transport;
    }

    // $env: variable name => value (defaults to the real environment).
    public static function from_environment(Database $db, ?array $env = null): Mailer {
        $get = fn(string $name, string $default = '') => (string) (($env === null ? getenv($name) : ($env[$name] ?? false)) ?: $default);
        $from = $get('MAIL_FROM', 'noreply@localhost');
        $from_name = $get('MAIL_FROM_NAME', 'DragonStar');
        $kind = strtolower($get('MAIL_TRANSPORT', 'log'));

        $transport = match ($kind) {
            'log' => new LogTransport($get('MAIL_LOG', sys_get_temp_dir() . '/dragonstar-mail.log')),
            'smtp' => new SmtpTransport($get('MAIL_HOST', 'localhost'), (int) $get('MAIL_PORT', '587'),
                $get('MAIL_ENCRYPTION', 'starttls'), $get('MAIL_USERNAME'), $get('MAIL_PASSWORD')),
            'gmail', 'outlook' => SmtpTransport::oauth(OAuthMailbox::from_environment($db, $kind, $get)),
            default => throw new MailException("Unknown MAIL_TRANSPORT '$kind'. Use smtp, gmail, outlook or log."),
        };
        return new Mailer($transport, $from, $from_name);
    }
}

// The site's mailer, set up once per request from the environment.
function mailer(): Mailer {
    static $mailer = null;
    return $mailer ??= Mailer::from_environment(get_database());
}

class SmtpTransport implements MailTransport {
    private ?OAuthMailbox $oauth = null;

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,   // starttls | ssl | none
        public readonly string $username = '',
        private string $password = '',
    ) {
        if (!in_array($encryption, ['starttls', 'ssl', 'none'], true)) {
            throw new MailException("Unknown MAIL_ENCRYPTION '$encryption'. Use starttls, ssl or none.");
        }
    }

    public static function oauth(OAuthMailbox $mailbox): SmtpTransport {
        $transport = new SmtpTransport($mailbox->provider->smtp_host, 587, 'starttls', $mailbox->username);
        $transport->oauth = $mailbox;
        return $transport;
    }

    // The Gmail/Outlook mailbox this logs in as, if it uses OAuth.
    public function oauth_mailbox(): ?OAuthMailbox {
        return $this->oauth;
    }

    public function describe(): string {
        return ($this->oauth !== null ? 'OAuth ' : '') . "SMTP {$this->host}:{$this->port} ({$this->encryption})"
            . ($this->username !== '' ? " as {$this->username}" : '');
    }

    public function send(OutgoingMail $mail, string $from, string $from_name): void {
        $php_mailer = new PHPMailer(true);
        try {
            $php_mailer->isSMTP();
            $php_mailer->Host = $this->host;
            $php_mailer->Port = $this->port;
            $php_mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $php_mailer->SMTPSecure = match ($this->encryption) {
                'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
            };
            $php_mailer->SMTPAutoTLS = $this->encryption !== 'none';
            if ($this->oauth !== null) {
                $php_mailer->SMTPAuth = true;
                $php_mailer->AuthType = 'XOAUTH2';
                $php_mailer->setOAuth($this->oauth);
            } elseif ($this->username !== '') {
                $php_mailer->SMTPAuth = true;
                $php_mailer->Username = $this->username;
                $php_mailer->Password = $this->password;
            }
            $php_mailer->setFrom($from, $from_name);
            $php_mailer->addAddress($mail->to);
            $php_mailer->Subject = $mail->subject;
            if ($mail->html !== null) {
                $php_mailer->isHTML(true);
                $php_mailer->Body = $mail->html;
                $php_mailer->AltBody = $mail->text;
            } else {
                $php_mailer->Body = $mail->text;
            }
            $php_mailer->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            throw new MailException('Mail not sent: ' . $php_mailer->ErrorInfo, 0, $e);
        }
    }
}

// Development: appends each message to a file instead of sending it.
class LogTransport implements MailTransport {
    public function __construct(public readonly string $path) {}

    public function describe(): string {
        return "log file {$this->path}";
    }

    public function send(OutgoingMail $mail, string $from, string $from_name): void {
        $entry = "==== " . date('Y-m-d H:i:s') . "\nFrom: $from_name <$from>\nTo: {$mail->to}\nSubject: {$mail->subject}\n\n{$mail->text}\n\n";
        if (@file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new MailException("Couldn't write to the mail log {$this->path}.");
        }
    }
}

// Tests: keeps every message.
class MemoryTransport implements MailTransport {
    public array $sent = [];

    public function send(OutgoingMail $mail, string $from, string $from_name): void {
        $this->sent[] = $mail;
    }
}

// Where Gmail and Outlook do OAuth 2 and SMTP.
final class OAuthProvider {
    private function __construct(
        public readonly string $name,
        public readonly string $authorize_url,
        public readonly string $token_url,
        public readonly string $scope,
        public readonly string $smtp_host,
        public readonly array $authorize_extra,
    ) {}

    public static function for(string $kind, string $tenant = 'common'): OAuthProvider {
        return match ($kind) {
            'gmail' => new OAuthProvider('Google', 'https://accounts.google.com/o/oauth2/v2/auth',
                'https://oauth2.googleapis.com/token', 'https://mail.google.com/', 'smtp.gmail.com',
                ['access_type' => 'offline', 'prompt' => 'consent']),
            'outlook' => new OAuthProvider('Microsoft',
                'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize',
                'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
                'https://outlook.office.com/SMTP.Send offline_access', 'smtp.office365.com',
                ['prompt' => 'consent']),
            default => throw new MailException("No OAuth provider for '$kind'."),
        };
    }
}

// One mailbox reached over OAuth 2: turns its refresh token into access
// tokens for PHPMailer's XOAUTH2 login, and runs the "connect this
// mailbox" consent flow on admin/mail.php. Providers may hand back a new
// refresh token when refreshing (Microsoft does); a stored one is kept
// up to date, so it doesn't lapse.
class OAuthMailbox implements OAuthTokenProvider {
    const REFRESH_TOKEN_SETTING = 'mail_oauth_refresh_token';

    private ?string $access_token = null;
    private int $access_expires = 0;
    // (url, form fields) => decoded JSON reply; replaceable for tests.
    private $http_post;

    public function __construct(
        private Database $db,
        public readonly string $kind,
        public readonly OAuthProvider $provider,
        public readonly string $username,
        private string $client_id,
        private string $client_secret,
        private string $fixed_refresh_token = '',
        ?callable $http_post = null,
    ) {
        $this->http_post = $http_post ?? 'oauth_http_post';
    }

    public static function from_environment(Database $db, string $kind, callable $get): OAuthMailbox {
        foreach (['MAIL_USERNAME', 'MAIL_OAUTH_CLIENT_ID', 'MAIL_OAUTH_CLIENT_SECRET'] as $name) {
            if ($get($name) === '') {
                throw new MailException("MAIL_TRANSPORT=$kind needs $name.");
            }
        }
        return new OAuthMailbox($db, $kind, OAuthProvider::for($kind, $get('MAIL_OAUTH_TENANT', 'common')),
            $get('MAIL_USERNAME'), $get('MAIL_OAUTH_CLIENT_ID'), $get('MAIL_OAUTH_CLIENT_SECRET'),
            $get('MAIL_OAUTH_REFRESH_TOKEN'));
    }

    public function refresh_token(): ?string {
        if ($this->fixed_refresh_token !== '') {
            return $this->fixed_refresh_token;
        }
        $stored = AppSetting::get($this->db, self::REFRESH_TOKEN_SETTING);
        try {
            return $stored === null ? null : SecretBox::decrypt($stored, 'setting:' . self::REFRESH_TOKEN_SETTING);
        } catch (SecretBoxException $e) {
            throw new MailException("The stored mail refresh token can't be read ({$e->getMessage()}). Connect the mailbox again.");
        }
    }

    public function is_connected(): bool {
        return $this->refresh_token() !== null;
    }

    public function refresh_token_from_environment(): bool {
        return $this->fixed_refresh_token !== '';
    }

    // PHPMailer's OAuthTokenProvider.
    public function getOauth64() {
        return base64_encode("user={$this->username}\001auth=Bearer {$this->access_token()}\001\001");
    }

    public function access_token(): string {
        if ($this->access_token !== null && time() < $this->access_expires - 60) {
            return $this->access_token;
        }
        $refresh_token = $this->refresh_token();
        if ($refresh_token === null) {
            throw new MailException("The {$this->provider->name} mailbox isn't connected yet (see Mail settings in the admin pages).");
        }
        $reply = ($this->http_post)($this->provider->token_url, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'scope' => $this->provider->scope,
        ]);
        $this->take_tokens($reply);
        return $this->access_token;
    }

    // --- connecting the mailbox (admin/mail.php) ---

    // Where to send the admin to grant access. $state is checked on the
    // way back (see finish_connect()).
    public function authorize_url(string $redirect_uri, string $state): string {
        return $this->provider->authorize_url . '?' . http_build_query([
            'client_id' => $this->client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => $this->provider->scope,
            'state' => $state,
            'login_hint' => $this->username,
        ] + $this->provider->authorize_extra);
    }

    // Trades the code the provider sent back for tokens, and keeps the
    // refresh token in the database.
    public function finish_connect(string $code, string $redirect_uri): void {
        $reply = ($this->http_post)($this->provider->token_url, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'scope' => $this->provider->scope,
        ]);
        if (empty($reply['refresh_token'])) {
            throw new MailException("{$this->provider->name} didn't return a refresh token. " . self::describe_error($reply));
        }
        $this->take_tokens($reply);
    }

    private function take_tokens(array $reply): void {
        if (empty($reply['access_token'])) {
            throw new MailException("Couldn't get an access token from {$this->provider->name}. " . self::describe_error($reply));
        }
        $this->access_token = $reply['access_token'];
        $this->access_expires = time() + (int) ($reply['expires_in'] ?? 3600);
        if (!empty($reply['refresh_token']) && $this->fixed_refresh_token === '') {
            try {
                AppSetting::set($this->db, self::REFRESH_TOKEN_SETTING,
                    SecretBox::encrypt($this->db, $reply['refresh_token'], 'setting:' . self::REFRESH_TOKEN_SETTING));
            } catch (SecretBoxException $e) {
                throw new MailException("Can't keep the refresh token safely: " . $e->getMessage());
            }
        }
    }

    private static function describe_error(array $reply): string {
        return trim(($reply['error'] ?? 'No reply') . ': ' . ($reply['error_description'] ?? ''), ': ');
    }
}

// POSTs a form and decodes the JSON reply ([] if it isn't JSON).
function oauth_http_post(string $url, array $fields): array {
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => http_build_query($fields),
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new MailException("Couldn't reach $url.");
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}
