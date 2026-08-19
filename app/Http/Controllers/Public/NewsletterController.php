<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Newsletter\NewsletterService;
use RuntimeException;

final class NewsletterController extends Controller
{
    public function subscribe(): string
    {
        $attempts = Session::get('newsletter_attempts', []);
        $attempts = is_array($attempts) ? array_values(array_filter($attempts, static fn($time): bool => (int) $time > time() - 3600)) : [];
        try {
            if (count($attempts) >= 5) throw new RuntimeException('Muitas tentativas. Aguarde alguns minutos.');
            if (($_POST['consent'] ?? '') !== '1') throw new RuntimeException('É necessário autorizar o envio da newsletter.');
            $attempts[] = time();
            Session::put('newsletter_attempts', $attempts);
            (new NewsletterService())->subscribe(
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['source'] ?? 'site_footer'),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            );
            Session::flash('success', 'Enviamos um link para confirmar sua inscrição.');
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $return = (string) ($_POST['return'] ?? '/');
        if (
            !str_starts_with($return, '/')
            || str_starts_with($return, '//')
            || str_contains($return, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $return) === 1
        ) $return = '/';
        return Response::redirect($return . (str_contains($return, '#') ? '' : '#newsletter'));
    }

    public function confirm(): string
    {
        $confirmed = (new NewsletterService())->confirm((string) ($_GET['token'] ?? '')) !== null;
        return $this->page('public/newsletter/status', 'layouts/public', ['pageTitle' => $confirmed ? 'Newsletter confirmada' : 'Link inválido', 'newsletterState' => $confirmed ? 'confirmed' : 'invalid']);
    }

    public function unsubscribe(): string
    {
        $removed = (new NewsletterService())->unsubscribe((string) ($_GET['token'] ?? ''));
        return $this->page('public/newsletter/status', 'layouts/public', ['pageTitle' => 'Preferências da newsletter', 'newsletterState' => $removed ? 'unsubscribed' : 'invalid']);
    }
}
