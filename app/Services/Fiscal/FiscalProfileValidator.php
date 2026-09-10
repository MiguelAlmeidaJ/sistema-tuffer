<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

final class FiscalProfileValidator
{
    /** @param array<string,mixed> $seller @param array<string,mixed>|null $profile @return array<int,string> */
    public function sellerErrors(array $seller, ?array $profile): array
    {
        $errors = [];
        if ($profile === null) return ['Cadastro fiscal do vendedor não foi preenchido.'];
        if (!(bool) ($profile['enabled'] ?? false)) $errors[] = 'Emissão fiscal está desabilitada para o vendedor.';
        if (trim((string) ($seller['legal_name'] ?? '')) === '') $errors[] = 'Razão social do vendedor não informada.';
        if (trim((string) ($seller['document'] ?? '')) === '') $errors[] = 'Documento fiscal do vendedor não informado.';
        $indicator = (string) ($profile['state_registration_indicator'] ?? 'contributor');
        if (!in_array($indicator, ['contributor', 'exempt', 'non_contributor'], true)) {
            $errors[] = 'Indicador de inscrição estadual inválido.';
        } elseif ($indicator === 'contributor' && trim((string) ($seller['state_registration'] ?? '')) === '') {
            $errors[] = 'Inscrição estadual do vendedor não informada para contribuinte.';
        }
        if (trim((string) ($profile['tax_regime'] ?? '')) === '') $errors[] = 'Regime tributário não informado.';
        if (trim((string) ($profile['crt'] ?? '')) === '') $errors[] = 'CRT não informado.';
        foreach (['postal_code'=>'CEP fiscal','street'=>'logradouro fiscal','number'=>'número do endereço fiscal','neighborhood'=>'bairro fiscal','city'=>'município fiscal'] as $field=>$label) {
            if (trim((string) ($profile[$field] ?? '')) === '') $errors[] = ucfirst($label) . ' não informado.';
        }
        if (!preg_match('/^[A-Z]{2}$/', mb_strtoupper(trim((string) ($profile['state'] ?? ''))))) $errors[] = 'UF fiscal inválida.';
        if (!preg_match('/^\d{7}$/', trim((string) ($profile['city_ibge_code'] ?? '')))) $errors[] = 'Código IBGE do município deve possuir 7 dígitos.';
        $series = (int) ($profile['nfe_series'] ?? 0);
        if ($series < 1 || $series > 999) $errors[] = 'Série da NF-e deve estar entre 1 e 999.';
        $email = trim((string) ($profile['fiscal_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail fiscal inválido.';
        return $errors;
    }

    /** @param array<string,mixed> $item @return array<int,string> */
    public function itemErrors(array $item, string $issuerState, string $destinationState, bool $rtcRequired): array
    {
        $prefix = 'Produto ' . trim((string) ($item['sku'] ?? $item['product_name'] ?? '#')) . ': ';
        $errors = [];
        if (!preg_match('/^\d{8}$/', trim((string) ($item['ncm'] ?? '')))) $errors[] = $prefix . 'NCM deve possuir 8 dígitos.';
        $cest = trim((string) ($item['cest'] ?? ''));
        if ($cest !== '' && !preg_match('/^\d{7}$/', $cest)) $errors[] = $prefix . 'CEST deve possuir 7 dígitos quando informado.';
        $origin = $item['origin'] ?? null;
        if ($origin === null || !is_numeric($origin) || (int) $origin < 0 || (int) $origin > 8) $errors[] = $prefix . 'origem da mercadoria inválida.';
        foreach (['commercial_unit'=>'unidade comercial','tributary_unit'=>'unidade tributável'] as $field=>$label) {
            $unit = trim((string) ($item[$field] ?? ''));
            if ($unit === '' || mb_strlen($unit) > 6) $errors[] = $prefix . $label . ' inválida.';
        }
        if (trim((string) ($item['icms_code'] ?? '')) === '') $errors[] = $prefix . 'código tributário de ICMS/CSOSN não informado.';
        if (trim((string) ($item['pis_code'] ?? '')) === '') $errors[] = $prefix . 'CST de PIS não informado.';
        if (trim((string) ($item['cofins_code'] ?? '')) === '') $errors[] = $prefix . 'CST de COFINS não informado.';
        $sameState = mb_strtoupper(trim($issuerState)) !== '' && mb_strtoupper(trim($issuerState)) === mb_strtoupper(trim($destinationState));
        $cfop = trim((string) ($item[$sameState ? 'cfop_in_state' : 'cfop_out_state'] ?? ''));
        if (!preg_match('/^\d{4}$/', $cfop)) $errors[] = $prefix . 'CFOP aplicável à operação não informado ou inválido.';
        if ($rtcRequired) {
            if (trim((string) ($item['ibs_cbs_cst'] ?? '')) === '') $errors[] = $prefix . 'CST de IBS/CBS não informado.';
            if (trim((string) ($item['ibs_cbs_classification'] ?? '')) === '') $errors[] = $prefix . 'classificação tributária de IBS/CBS não informada.';
        }
        return $errors;
    }
}
