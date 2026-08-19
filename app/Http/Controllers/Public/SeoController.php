<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Content\PolicyCatalog;
use App\Core\Database;

final class SeoController
{
    public function sitemap(): string
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $pdo = Database::connection();
        $urls = [
            ['path' => '/', 'lastmod' => date('Y-m-d'), 'priority' => '1.0'],
            ['path' => '/produtos', 'lastmod' => date('Y-m-d'), 'priority' => '0.9'],
            ['path' => '/lojas', 'lastmod' => date('Y-m-d'), 'priority' => '0.7'],
            ['path' => '/ofertas', 'lastmod' => date('Y-m-d'), 'priority' => '0.8'],
            ['path' => '/politicas', 'lastmod' => date('Y-m-d'), 'priority' => '0.6'],
        ];
        foreach (array_keys(PolicyCatalog::all()) as $slug) $urls[] = ['path' => '/politicas/' . $slug, 'lastmod' => date('Y-m-d'), 'priority' => '0.5'];
        foreach ($pdo->query("SELECT p.slug,p.updated_at FROM products p JOIN sellers s ON s.id=p.seller_id JOIN stores st ON st.id=p.store_id WHERE p.status='active' AND p.platform_paused=0 AND st.status='active' AND s.status='active' AND s.payment_enabled=1 AND s.payment_onboarding_status='active' AND s.pagarme_recipient_id IS NOT NULL ORDER BY p.id")->fetchAll() as $row) $urls[]=['path'=>'/produto/'.$row['slug'],'lastmod'=>date('Y-m-d',strtotime((string)$row['updated_at'])),'priority'=>'0.8'];
        foreach ($pdo->query("SELECT st.slug,st.updated_at FROM stores st JOIN sellers s ON s.id=st.seller_id WHERE st.status='active' AND s.status='active' AND s.payment_enabled=1 AND s.payment_onboarding_status='active' AND s.pagarme_recipient_id IS NOT NULL ORDER BY st.id")->fetchAll() as $row) $urls[]=['path'=>'/loja/'.$row['slug'],'lastmod'=>date('Y-m-d',strtotime((string)$row['updated_at'])),'priority'=>'0.7'];
        foreach ($pdo->query("SELECT slug,updated_at FROM categories WHERE status='active' ORDER BY id")->fetchAll() as $row) $urls[]=['path'=>'/categoria/'.$row['slug'],'lastmod'=>date('Y-m-d',strtotime((string)$row['updated_at'])),'priority'=>'0.7'];
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>','<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach($urls as $url)$xml[]='<url><loc>'.$this->xml(absolute_url($url['path'])).'</loc><lastmod>'.$this->xml($url['lastmod']).'</lastmod><changefreq>daily</changefreq><priority>'.$url['priority'].'</priority></url>';
        $xml[]='</urlset>';
        return implode("\n",$xml);
    }

    public function robots(): string
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $prefix = base_url_path();
        $lines=['User-agent: *','Allow: '.$prefix.'/','Disallow: '.$prefix.'/admin/','Disallow: '.$prefix.'/vendedor/','Disallow: '.$prefix.'/minha-conta/','Disallow: '.$prefix.'/checkout','Disallow: '.$prefix.'/carrinho','Disallow: '.$prefix.'/webhooks/','','Sitemap: '.absolute_url('/sitemap.xml')];
        return implode("\n",$lines)."\n";
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
    }
}
