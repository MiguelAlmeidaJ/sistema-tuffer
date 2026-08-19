<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Content\PolicyCatalog;
use App\Http\Controllers\Controller;

final class LegalController extends Controller
{
    public function index(): string
    {
        $policies = PolicyCatalog::all();
        return $this->page('public/legal/index', 'layouts/public', [
            'pageTitle' => 'Central de Políticas',
            'policies' => $policies,
            'policyGroups' => PolicyCatalog::groups(),
            'metaDescription' => 'Consulte os termos, políticas e regras da Tuffer para compradores, vendedores e usuários da plataforma.',
        ]);
    }

    public function show(string $slug): string
    {
        $policies = PolicyCatalog::all();
        $policy = $policies[$slug] ?? null;
        if (!$policy) {
            http_response_code(404);
            return $this->page('errors/404', 'layouts/public', ['pageTitle' => 'Política não encontrada', 'path' => $slug]);
        }
        return $this->page('public/legal/show', 'layouts/public', [
            'pageTitle' => $policy['title'],
            'policy' => $policy,
            'policySlug' => $slug,
            'metaDescription' => $policy['summary'],
            'canonicalUrl' => absolute_url('/politicas/' . $slug),
        ]);
    }

    public function terms(): string { return $this->show('termos-de-uso'); }
    public function privacy(): string { return $this->show('privacidade'); }
}
