<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\HttpException;
use PublishGo\Core\Request;

/**
 * Consultas externas para auxiliar o cadastro: CEP (ViaCEP) e CNPJ (BrasilAPI).
 */
final class LookupController extends Controller
{
    private function fetch(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'PublishGo/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            return null;
        }
        $json = json_decode((string) $body, true);
        return is_array($json) ? $json : null;
    }

    /** GET /lookup/cep/{cep} */
    public function cep(Request $request): mixed
    {
        $cep = preg_replace('/\D/', '', (string) $request->param('cep')) ?? '';
        if (strlen($cep) !== 8) {
            throw HttpException::unprocessable('CEP inválido.');
        }
        $data = $this->fetch("https://viacep.com.br/ws/{$cep}/json/");
        if ($data === null || !empty($data['erro'])) {
            throw HttpException::notFound('CEP não encontrado.');
        }
        return [
            'cep' => $data['cep'] ?? $cep,
            'street' => $data['logradouro'] ?? '',
            'district' => $data['bairro'] ?? '',
            'city' => $data['localidade'] ?? '',
            'state' => $data['uf'] ?? '',
            'address' => trim(($data['logradouro'] ?? '') . ' - ' . ($data['bairro'] ?? '') . ', ' . ($data['localidade'] ?? '') . '/' . ($data['uf'] ?? ''), ' -,/'),
        ];
    }

    /** GET /lookup/cnpj/{cnpj} */
    public function cnpj(Request $request): mixed
    {
        $cnpj = preg_replace('/\D/', '', (string) $request->param('cnpj')) ?? '';
        if (strlen($cnpj) !== 14) {
            throw HttpException::unprocessable('CNPJ inválido.');
        }
        $data = $this->fetch("https://brasilapi.com.br/api/cnpj/v1/{$cnpj}");
        if ($data === null || empty($data['cnpj'])) {
            throw HttpException::notFound('CNPJ não encontrado.');
        }
        $name = $data['nome_fantasia'] ?: ($data['razao_social'] ?? '');
        return [
            'cnpj' => $cnpj,
            'company_name' => $name,
            'legal_name' => $data['razao_social'] ?? '',
            'email' => $data['email'] ?? '',
            'phone' => isset($data['ddd_telefone_1']) ? (string) $data['ddd_telefone_1'] : '',
            'cep' => isset($data['cep']) ? preg_replace('/\D/', '', (string) $data['cep']) : '',
            'address' => trim(
                ($data['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($data['logradouro'] ?? '') . ', ' .
                ($data['numero'] ?? '') . ' - ' . ($data['bairro'] ?? '') . ', ' .
                ($data['municipio'] ?? '') . '/' . ($data['uf'] ?? ''),
                ' -,/'
            ),
            'status' => $data['descricao_situacao_cadastral'] ?? '',
        ];
    }
}
