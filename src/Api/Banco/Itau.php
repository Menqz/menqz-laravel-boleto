<?php

namespace Eduardokum\LaravelBoleto\Api\Banco;

use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Eduardokum\LaravelBoleto\Api\AbstractAPI;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Boleto\Banco\Itau as BoletoItau;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoAPIContract;
use Eduardokum\LaravelBoleto\Util;
use Illuminate\Support\Facades\Log;

class Itau extends AbstractAPI
{
    protected $baseUrl = 'https://api.itau.com.br/cash_management/v2';

    private $authBaseUrl = 'https://sts.itau.com.br';

    protected $id_beneficiario = null;

    protected $carteira = null;

    protected $camposObrigatorios = [
        // 'certificado',
        // 'certificadoChave',
        'client_id',
        'client_secret',
        'cnpj',
        'conta',
        'id_beneficiario',
        'carteira',
    ];

    public function __construct($params = [])
    {
        if (isset($params['ambiente']) && $params['ambiente'] === 'H') {
            $this->baseUrl = 'https://api.gateway.itau.com.br/cash_management/v2';
            $this->authBaseUrl = 'https://sts.itau.com.br';

            // Backup URL mock Beeceptor
            // $this->baseUrl = 'https://boleto22.free.beeceptor.com/sandboxapi';
            // $this->authBaseUrl = 'https://boleto22.free.beeceptor.com';
        }

        if (isset($params['sslVerify'])) {
            $this->setSslVerify($params['sslVerify']);
        }

        parent::__construct($params);
    }

    public function getIdBeneficiario()
    {
        return $this->id_beneficiario;
    }

    public function setIdBeneficiario($idBeneficiario)
    {
        $this->id_beneficiario = $idBeneficiario;

        return $this;
    }

    public function getCarteira()
    {
        return $this->carteira;
    }

    public function setCarteira($carteira)
    {
        $this->carteira = $carteira;

        return $this;
    }

    protected function oAuth2()
    {
        if ($this->getAccessToken()) {
            return $this;
        }

        $grant = $this->withBaseUrl($this->authBaseUrl, function () {
            return $this->post('api/oauth/token', [
                'client_id'     => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'grant_type'    => 'client_credentials',
                'scope'         => 'boleto-cobranca.read boleto-cobranca.write',
            ], true)->body;
        });

        return $this->setAccessToken('Bearer ' . $grant->access_token);
    }

    protected function headers()
    {
        $token = $this->getAccessToken();

        return array_filter([
            'Authorization'        => $token,
            'x-itau-apikey'        => $this->getClientId(),
            'x-itau-correlationID' => (string) Str::uuid(),
            'x-itau-flowID'        => (string) Str::uuid(),
        ]);
    }

    public function createBoleto(BoletoAPIContract $boleto)
    {
        if (! $boleto instanceof BoletoItau) {
            throw new ValidationException('Boleto deve ser instância de ' . BoletoItau::class);
        }

        $data = $boleto->toAPI();
        Log::info('Dados a serem enviados para criação do boleto: ', $data);

        // DEBUG-INICIO
        // Extrai dados para o arquivo, sem lançar exceção se faltar informação.
        $nossoNumeroDebug = (string) (\Illuminate\Support\Arr::get($data, 'data.dado_boleto.nosso_numero') ?? $boleto->getNossoNumero() ?? 'sem-nn');
        $nossoNumeroDebug = Util::onlyNumbers($nossoNumeroDebug) !== '' ? Util::onlyNumbers($nossoNumeroDebug) : $nossoNumeroDebug;
        $urlCompleta = rtrim($this->baseUrl, '/') . $this->url('create');
        $headersDebug = $this->headers();
        $payloadDebug = $data;
        // DEBUG-FIM

        try {
            $retorno = $this->oAuth2()->post($this->url('create'), $data);

            if (isset($retorno->body->data->dado_boleto->nosso_numero)) {
                $boleto->setNossoNumero($retorno->body->data->dado_boleto->nosso_numero);
            }

            if (isset($retorno->body->data->id_boleto)) {
                $boleto->setID($retorno->body->data->id_boleto);
            }

            return $boleto;
        } finally {
            // DEBUG-INICIO
            // Salva o payload da criação do boleto.
            // $this->debugSalvarPayload('createBoleto', 'POST', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
            // DEBUG-FIM
        }
    }

    public function retrieveNossoNumero($nossoNumero)
    {
        $params = [
            'id_beneficiario' => $this->getIdBeneficiario(),
            'codigo_carteira' => $this->getCarteira(),
            'nosso_numero'    => $nossoNumero,
        ];

        // DEBUG-INICIO
        // Extrai dados para o arquivo, sem lançar exceção se faltar informação.
        $nossoNumeroDebug = Util::onlyNumbers((string) $nossoNumero) !== '' ? Util::onlyNumbers((string) $nossoNumero) : 'sem-nn';
        $urlCompleta = rtrim($this->baseUrl, '/') . $this->url('show') . '?' . http_build_query($params);
        $headersDebug = $this->headers();
        $payloadDebug = $params;
        // DEBUG-FIM

        try {
            $response = $this->oAuth2()->get($this->url('show') . '?' . http_build_query($params));

            return $response->body;
        } finally {
            // DEBUG-INICIO
            // Salva o payload da consulta por nosso_numero.
            // $this->debugSalvarPayload('retrieveNossoNumero', 'GET', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
            // DEBUG-FIM
        }
    }

    public function retrieveID($id)
    {
        // DEBUG-INICIO
        // Extrai dados para o arquivo, sem lançar exceção se faltar informação.
        $nossoNumeroDebug = is_string($id) && $id !== '' ? Util::onlyNumbers($id) : (is_numeric($id) ? (string) $id : 'sem-nn');
        $urlCompleta = rtrim($this->baseUrl, '/') . sprintf($this->url('show_id'), $id);
        $headersDebug = $this->headers();
        $payloadDebug = ['id_boleto' => $id];
        // DEBUG-FIM

        try {
            $response = $this->oAuth2()->get(sprintf($this->url('show_id'), $id));

            return $response->body;
        } finally {
            // DEBUG-INICIO
            // Salva o payload da consulta por id_boleto.
            // $this->debugSalvarPayload('retrieveID', 'GET', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
            // DEBUG-FIM
        }
    }

    public function cancelNossoNumero($nossoNumero, $motivo)
    {
        $idBoleto = $this->resolveIdBoletoPorNossoNumero($nossoNumero);

        return $this->baixarBoletoID($idBoleto, $motivo);
    }

    public function cancelID($id, $motivo)
    {
        return $this->baixarBoletoID($id, $motivo);
    }

    public function retrieveList($inputedParams = [])
    {
        $params = array_merge([
            'id_beneficiario' => $this->getIdBeneficiario(),
            'codigo_carteira' => $this->getCarteira(),
        ], $inputedParams);

        // DEBUG-INICIO
        // Extrai dados para o arquivo, sem lançar exceção se faltar informação.
        $nossoNumeroDebug = isset($params['nosso_numero']) && Util::onlyNumbers((string) $params['nosso_numero']) !== ''
            ? Util::onlyNumbers((string) $params['nosso_numero'])
            : 'lista';
        $urlCompleta = rtrim($this->baseUrl, '/') . $this->url('list') . '?' . http_build_query($params);
        $headersDebug = $this->headers();
        $payloadDebug = $params;
        // DEBUG-FIM

        try {
            $response = $this->oAuth2()->get($this->url('list') . '?' . http_build_query($params));

            return $response->body;
        } finally {
            // DEBUG-INICIO
            // Salva o payload da consulta em lista.
            // $this->debugSalvarPayload('retrieveList', 'GET', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
            // DEBUG-FIM
        }
    }

    public function getPdfNossoNumero($nossoNumero)
    {
        throw new ValidationException('Método getPdfNossoNumero não disponível para o banco Itaú. Endpoint de PDF não documentado.');
    }

    public function getPdfID($id)
    {
        throw new ValidationException('Método getPdfID não disponível para o banco Itaú. Endpoint de PDF não documentado.');
    }

    public function alterarVencimentoNossoNumero($nossoNumero, $novaData)
    {
        $idBoleto = $this->resolveIdBoletoPorNossoNumero($nossoNumero);
        return $this->alterarVencimentoID($idBoleto, $novaData);
    }

    public function alterarVencimentoID($id, $novaData)
    {
        $dataVencimento = $novaData instanceof DateTimeInterface
            ? $novaData->format('Y-m-d')
            : date('Y-m-d', strtotime((string) $novaData));

        $payload = [
            'data_vencimento' => $dataVencimento,
        ];

        // DEBUG-INICIO
        // Extrai dados para o arquivo, sem lançar exceção se faltar informação.
        $nossoNumeroDebug = is_string($id) && $id !== '' ? Util::onlyNumbers($id) : (is_numeric($id) ? (string) $id : 'sem-nn');
        $urlCompleta = rtrim($this->baseUrl, '/') . sprintf($this->url('patch_data_vencimento'), $id);
        $headersDebug = $this->headers();
        $payloadDebug = $payload;
        // DEBUG-FIM

        try {
            $response = $this->oAuth2()->patch(sprintf($this->url('patch_data_vencimento'), $id), $payload);

            return $response->body;
        } finally {
            // DEBUG-INICIO
            // Salva o payload da alteração de vencimento.
            // $this->debugSalvarPayload('alterarVencimentoID', 'PATCH', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
            // DEBUG-FIM
        }
    }

    public function alterarValorNossoNumero($nossoNumero, $novoValor)
    {
        $idBoleto = $this->resolveIdBoletoPorNossoNumero($nossoNumero);
        return $this->alterarValorID($idBoleto, $novoValor);
    }

    public function alterarValorID($id, $novoValor)
    {
        $valorFormatado = number_format((float) $novoValor, 2, '.', '');

        $payload = [
            'valor_nominal' => $valorFormatado,
        ];

        // DEBUG-INICIO
        // Extrai dados para o arquivo, sem lançar exceção se faltar informação.
        $nossoNumeroDebug = is_string($id) && $id !== '' ? Util::onlyNumbers($id) : (is_numeric($id) ? (string) $id : 'sem-nn');
        $urlCompleta = rtrim($this->baseUrl, '/') . sprintf($this->url('patch_valor_nominal'), $id);
        $headersDebug = $this->headers();
        $payloadDebug = $payload;
        // DEBUG-FIM

        try {
            $response = $this->oAuth2()->patch(sprintf($this->url('patch_valor_nominal'), $id), $payload);

            return $response->body;
        } finally {
            // DEBUG-INICIO
            // Salva o payload da alteração de valor.
            // $this->debugSalvarPayload('alterarValorID', 'PATCH', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
            // DEBUG-FIM
        }
    }

    public function baixarBoletoNossoNumero($nossoNumero, $motivo)
    {
        $idBoleto = $this->resolveIdBoletoPorNossoNumero($nossoNumero);
        return $this->baixarBoletoID($idBoleto, $motivo);
    }

    public function baixarBoletoID($id, $motivo)
    {
        // DEBUG-INICIO 
        // Extrai dados para o arquivo, sem lançar exceção se faltar informação.
        $nossoNumeroDebug = is_string($id) && $id !== '' ? Util::onlyNumbers($id) : (is_numeric($id) ? (string) $id : 'sem-nn');
        $urlCompleta = rtrim($this->baseUrl, '/') . sprintf($this->url('patch_baixa'), $id);
        $headersDebug = $this->headers();
        $payloadDebug = [];
        // DEBUG-FIM

        try {
            $response = $this->oAuth2()->patch(sprintf($this->url('patch_baixa'), $id), []);

            return $response->body;
        } finally {
            // DEBUG-INICIO
            // Salva o payload da baixa/cancelamento do boleto.
            // $this->debugSalvarPayload('baixarBoletoID', 'PATCH', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
            // DEBUG-FIM
        }
    }

    // Endpoints de alteração adicionais declarados para documentação.
    // Lançam exceção enquanto não há integração completa.

    public function alterarAbatimentoID($id, array $dados)
    {
        $this->url('patch_abatimento');
        throw new ValidationException('Método alterarAbatimentoID ainda não integrado para o banco Itaú.');
    }

    public function alterarJurosID($id, array $dados)
    {
        $this->url('patch_juros');
        throw new ValidationException('Método alterarJurosID ainda não integrado para o banco Itaú.');
    }

    public function alterarMultaID($id, array $dados)
    {
        $this->url('patch_multa');
        throw new ValidationException('Método alterarMultaID ainda não integrado para o banco Itaú.');
    }

    public function alterarDataLimitePagamentoID($id, array $dados)
    {
        $this->url('patch_data_limite_pagamento');
        throw new ValidationException('Método alterarDataLimitePagamentoID ainda não integrado para o banco Itaú.');
    }

    public function alterarSeuNumeroID($id, array $dados)
    {
        $this->url('patch_seu_numero');
        throw new ValidationException('Método alterarSeuNumeroID ainda não integrado para o banco Itaú.');
    }

    public function alterarProtestoID($id, array $dados)
    {
        $this->url('patch_protesto');
        throw new ValidationException('Método alterarProtestoID ainda não integrado para o banco Itaú.');
    }

    public function alterarNegativacaoID($id, array $dados)
    {
        $this->url('patch_negativacao');
        throw new ValidationException('Método alterarNegativacaoID ainda não integrado para o banco Itaú.');
    }

    public function alterarDescontoID($id, array $dados)
    {
        $this->url('patch_desconto');
        throw new ValidationException('Método alterarDescontoID ainda não integrado para o banco Itaú.');
    }

    public function alterarPagadorID($id, array $dados)
    {
        $this->url('patch_pagador');
        throw new ValidationException('Método alterarPagadorID ainda não integrado para o banco Itaú.');
    }

    public function alterarSacadorAvalistaID($id, array $dados)
    {
        $this->url('patch_sacador_avalista');
        throw new ValidationException('Método alterarSacadorAvalistaID ainda não integrado para o banco Itaú.');
    }

    public function alterarRecebimentoDivergenteID($id, array $dados)
    {
        $this->url('patch_recebimento_divergente');
        throw new ValidationException('Método alterarRecebimentoDivergenteID ainda não integrado para o banco Itaú.');
    }

    /**
     * Resolve id_boleto a partir do nosso_numero via consulta individual.
     * Utilizado quando a alteração é solicitada por nosso_numero e o endpoint exige id_boleto.
     */
    private function resolveIdBoletoPorNossoNumero(string $nossoNumero): string
    {
        $retorno = $this->retrieveNossoNumero($nossoNumero);
        $id = data_get($retorno, 'data.id_boleto') ?? data_get($retorno, 'data.0.id_boleto');

        if (empty($id)) {
            throw new ValidationException('Não foi possível obter o id_boleto a partir do nosso_numero informado.');
        }

        return (string) $id;
    }

    private function url($type)
    {
        return match ($type) {
            'create'                       => '/boletos',
            'show'                         => '/boletos',
            'show_id'                      => '/boletos/%s',
            'list'                         => '/boletos',
            'patch_baixa'                  => '/boletos/%s/baixa',
            'patch_data_vencimento'        => '/boletos/%s/data_vencimento',
            'patch_juros'                  => '/boletos/%s/juros',
            'patch_multa'                  => '/boletos/%s/multa',
            'patch_valor_nominal'          => '/boletos/%s/valor_nominal',
            'patch_data_limite_pagamento'  => '/boletos/%s/data_limite_pagamento',
            'patch_seu_numero'             => '/boletos/%s/seu_numero',
            'patch_protesto'               => '/boletos/%s/protesto',
            'patch_negativacao'            => '/boletos/%s/negativacao',
            'patch_desconto'               => '/boletos/%s/desconto',
            'patch_pagador'                => '/boletos/%s/pagador',
            'patch_sacador_avalista'       => '/boletos/%s/sacador_avalista',
            'patch_recebimento_divergente' => '/boletos/%s/recebimento_divergente',
            'patch_abatimento'             => '/boletos/%s/abatimento',
            default => throw new ValidationException("URL tipo $type não definida"),
        };
    }

    private function withBaseUrl($baseUrl, callable $callback)
    {
        $oldBaseUrl = $this->baseUrl;
        $this->baseUrl = $baseUrl;

        try {
            return $callback();
        } finally {
            $this->baseUrl = $oldBaseUrl;
        }
    }

    // Persiste payloads de requisições em C:\Users\samsung\Desktop\debug-boleto.
    // Salva independentemente de sucesso ou falha na requisição.
    private function debugSalvarPayload(string $metodoNome, string $metodoHttp, string $urlCompleta, array $headers, array $payload, string $nossoNumero = 'sem-nn'): void
    {
        try {
            $pasta = 'C:\\Users\\samsung\\Desktop\\debug-boleto';
            if (! is_dir($pasta)) {
                @mkdir($pasta, 0777, true);
            }

            $arquivo = $pasta . DIRECTORY_SEPARATOR . sprintf('%s-%s.txt', Str::kebab($metodoNome), $nossoNumero);
            $linhas  = [];
            $linhas[] = 'Data/Hora: ' . date('Y-m-d H:i:s');
            $linhas[] = 'Método PHP: ' . $metodoNome;
            $linhas[] = 'Método HTTP: ' . strtoupper($metodoHttp);
            $linhas[] = 'URL: ' . $urlCompleta;
            $linhas[] = '';
            $linhas[] = '--- Cabeçalhos (sem token) ---';
            foreach ($headers as $chave => $valor) {
                $exibir = is_array($valor) ? implode(', ', $valor) : (string) $valor;
                if (stripos((string) $chave, 'authorization') !== false) {
                    $exibir = '[OCULTADO]';
                }
                $linhas[] = sprintf('%s: %s', $chave, $exibir);
            }

            $linhas[] = '';
            $linhas[] = '--- Payload ---';
            $linhas[] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $linhas[] = '';
            $linhas[] = '--- Fim ---';

            @file_put_contents($arquivo, implode(PHP_EOL, $linhas));
        } catch (\Throwable $e) {
            // Não interfere na requisição principal.
            unset($e);
        }
    }
}

