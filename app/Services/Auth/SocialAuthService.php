<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Session;
use JsonException;
use RuntimeException;

final class SocialAuthService
{
    /** @return array{authorize_url:string,token_url:string,user_url:string,client_id:string,client_secret:string,scope:string} */
    private function provider(string $provider): array
    {
        return match ($provider) {
            'google' => [
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'user_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
                'client_id' => $this->env('GOOGLE_CLIENT_ID'),
                'client_secret' => $this->env('GOOGLE_CLIENT_SECRET'),
                'scope' => 'openid email profile',
            ],
            default => throw new RuntimeException('Provedor de login inválido.'),
        };
    }

    public function label(string $provider): string
    {
        return match ($provider) {
            'google' => 'Google',
            default => 'social',
        };
    }

    public function authorizationUrl(string $provider): string
    {
        $config = $this->provider($provider);
        if ($config['client_id'] === '' || $config['client_secret'] === '') {
            throw new RuntimeException("Login com {$this->label($provider)} ainda não foi configurado.");
        }

        $state = bin2hex(random_bytes(32));
        $pending = Session::get('social_oauth', []);
        if (!is_array($pending)) $pending = [];
        $pending[$provider] = ['state' => $state, 'created_at' => time()];
        Session::put('social_oauth', $pending);

        $parameters = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope' => $config['scope'],
            'state' => $state,
        ];
        $parameters['prompt'] = 'select_account';

        return $config['authorize_url'] . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function consumeState(string $provider, string $state): bool
    {
        $this->provider($provider);
        $pending = Session::get('social_oauth', []);
        if (!is_array($pending)) return false;
        $attempt = $pending[$provider] ?? null;
        unset($pending[$provider]);
        Session::put('social_oauth', $pending);

        return is_array($attempt)
            && isset($attempt['state'], $attempt['created_at'])
            && time() - (int) $attempt['created_at'] <= 600
            && hash_equals((string) $attempt['state'], $state);
    }

    /** @return array{id:string,name:string,email:string,email_verified:bool,avatar:?string} */
    public function profile(string $provider, string $code): array
    {
        if ($code === '') throw new RuntimeException('O provedor não retornou uma autorização válida.');
        $config = $this->provider($provider);
        if ($config['client_id'] === '' || $config['client_secret'] === '') {
            throw new RuntimeException("Login com {$this->label($provider)} ainda não foi configurado.");
        }

        $tokenData = [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $this->redirectUri($provider),
            'code' => $code,
        ];
        $tokenData['grant_type'] = 'authorization_code';

        $token = $this->request(
            'POST',
            $config['token_url'],
            $tokenData
        );
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') throw new RuntimeException('Não foi possível validar o acesso social.');

        $data = $this->request('GET', $config['user_url'], [], ['Authorization: Bearer ' . $accessToken]);
        $verified = filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
        if (!$verified) throw new RuntimeException('Use uma conta Google com e-mail verificado.');
        return $this->normalizeProfile($data, true);
    }

    /** @param array<string,mixed> $data @return array{id:string,name:string,email:string,email_verified:bool,avatar:?string} */
    private function normalizeProfile(array $data, bool $verified): array
    {
        $id = trim((string) ($data['sub'] ?? $data['id'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if ($id === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('O provedor não compartilhou nome e e-mail. Autorize o acesso ao e-mail e tente novamente.');
        }

        $avatar = trim((string) ($data['picture_url'] ?? $data['picture'] ?? ''));
        return ['id' => $id, 'name' => $name, 'email' => $email, 'email_verified' => $verified, 'avatar' => $avatar !== '' ? $avatar : null];
    }

    /** @param array<string,string> $data @param array<int,string> $headers @return array<string,mixed> */
    private function request(string $method, string $url, array $data = [], array $headers = []): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL é necessária para o login social.');
        if ($method === 'GET' && $data !== []) $url .= '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986);

        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('Não foi possível iniciar a conexão com o provedor.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data, '', '&', PHP_QUERY_RFC3986));
            curl_setopt($curl, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], $headers));
        }

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $failure = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $body === '' || $failure !== '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('O provedor de login não respondeu corretamente. Tente novamente.');
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('O provedor retornou uma resposta inválida.', 0, $exception);
        }
        if (!is_array($decoded)) throw new RuntimeException('O provedor retornou uma resposta inválida.');
        return $decoded;
    }

    private function redirectUri(string $provider): string
    {
        return absolute_url('/auth/' . $provider . '/callback');
    }

    private function env(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return $value === false || $value === null ? '' : trim((string) $value);
    }
}
