<?php

namespace Eduardokum\LaravelBoleto\Api\Banco;

use DateTimeInterface;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Eduardokum\LaravelBoleto\Api\AbstractAPI;
use Eduardokum\LaravelBoleto\Api\DTO\CertificadoRenovado;
use Eduardokum\LaravelBoleto\Contracts\Api\CertificadoRenovavel;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Boleto\Banco\Itau as BoletoItau;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoAPIContract;
use Eduardokum\LaravelBoleto\Util;
use Eduardokum\LaravelBoleto\Util\CertificateUtil;
use Illuminate\Support\Facades\Log;
use Throwable;

class Itau extends AbstractAPI implements CertificadoRenovavel
{
    protected $baseUrl = 'https://api.itau.com.br/cash_management/v2';

    private $authBaseUrl = 'https://sts.itau.com.br';

    protected $id_beneficiario = null;

    protected $carteira = null;

    protected $ou_app_certificado = null;

    protected $cidade_empresa = null;

    protected $uf_empresa = null;

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
            $this->baseUrl = 'https://api.gateway.itau.com.br/sandbox/ca-validation/itau-ep9-gtw-cash-management-ext-v2/v2';
            $this->authBaseUrl = 'https://sts.itau.com.br';
        } else {
            $this->baseUrl = 'https://api.itau.com.br/cash_management/v2';
            $this->authBaseUrl = 'https://sts.itau.com.br';
        }

        if (isset($params['sslVerify'])) {
            $this->setSslVerify($params['sslVerify']);
        }

        if (isset($params['ou_app_certificado']) && $params['ou_app_certificado'] !== '') {
            $this->setOuAppCertificado($params['ou_app_certificado']);
        } elseif (isset($params['ouAppCertificado']) && $params['ouAppCertificado'] !== '') {
            $this->setOuAppCertificado($params['ouAppCertificado']);
        }

        if (isset($params['cidade_empresa']) && $params['cidade_empresa'] !== '') {
            $this->setCidadeEmpresa($params['cidade_empresa']);
        } elseif (isset($params['cidadeEmpresa']) && $params['cidadeEmpresa'] !== '') {
            $this->setCidadeEmpresa($params['cidadeEmpresa']);
        }

        if (isset($params['uf_empresa']) && $params['uf_empresa'] !== '') {
            $this->setUfEmpresa($params['uf_empresa']);
        } elseif (isset($params['ufEmpresa']) && $params['ufEmpresa'] !== '') {
            $this->setUfEmpresa($params['ufEmpresa']);
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

    public function getOuAppCertificado()
    {
        return $this->ou_app_certificado;
    }

    public function setOuAppCertificado($ouAppCertificado)
    {
        $this->ou_app_certificado = $ouAppCertificado;

        return $this;
    }

    public function getCidadeEmpresa()
    {
        return $this->cidade_empresa;
    }

    public function setCidadeEmpresa($cidadeEmpresa)
    {
        $this->cidade_empresa = $cidadeEmpresa;

        return $this;
    }

    public function getUfEmpresa()
    {
        return $this->uf_empresa;
    }

    public function setUfEmpresa($ufEmpresa)
    {
        $this->uf_empresa = $ufEmpresa;

        return $this;
    }

    protected function oAuth2()
    {
        if ($this->getAccessToken()) {
            return $this;
        }

        $storagePath = rtrim(($this->params['storage_path'] ?? (dirname(__DIR__, 4) . '/twentytwoproject/storage/app/certificados')), DIRECTORY_SEPARATOR);

        $clientId = (string) $this->getClientId();
        if ($clientId === '') {
            throw new ValidationException('APIClientID (client_id) nao informado na carteira Itau 341.');
        }

        $certificadoPath = (string) $this->getCertificado();
        if ($certificadoPath === '' || ! is_file($certificadoPath)) {
            throw new ValidationException(
                'Certificado (APICertificado) nao encontrado. Acesse o cadastro da Carteira de Cobranca (Banco Itau 341) e importe o arquivo .crt assinado pelo Itaú.'
            );
        }

        $chavePrivadaPath = (string) $this->getCertificadoChave();
        if ($chavePrivadaPath === '' || ! is_file($chavePrivadaPath)) {
            throw new ValidationException(
                'Chave privada (APICertificadoKey) nao encontrada. Acesse o cadastro da Carteira de Cobranca (Banco Itau 341) e importe o arquivo .key (RSA 2048) correspondente ao CRT assinado.'
            );
        }

        $privateKeyPem = file_get_contents($chavePrivadaPath);
        if (! is_string($privateKeyPem) || trim($privateKeyPem) === '') {
            throw new ValidationException('Falha ao ler o conteudo PEM da chave privada em: '.$chavePrivadaPath);
        }

        $audience    = rtrim($this->authBaseUrl, '/') . '/api/oauth/token';
        $jwtIssuedAt = time();
        $jwtExpires  = $jwtIssuedAt + 300;
        $jwtJti      = bin2hex(random_bytes(16));

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $audience,
            'iat' => $jwtIssuedAt,
            'exp' => $jwtExpires,
            'jti' => $jwtJti,
        ];

        $clientAssertion = $this->jwtAssinarRS256($header, $payload, $privateKeyPem);

        $grantBody = [
            'grant_type'            => 'client_credentials',
            'client_id'             => $clientId,
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion'      => $clientAssertion,
            'scope'                 => 'boleto-cobranca.read boleto-cobranca.write',
        ];

        $urlOAuth    = $audience;
        $payloadOAuth = [
            'grant_type'                  => 'client_credentials',
            'client_id'                   => $clientId,
            'client_assertion_type'       => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion'            => '[OCULTADO]',
            'scope'                       => 'boleto-cobranca.read boleto-cobranca.write',
            '_debug_cert_path'            => $certificadoPath,
            '_debug_key_path'             => $chavePrivadaPath,
            '_debug_cert_exists'          => is_file($certificadoPath),
            '_debug_key_exists'           => is_file($chavePrivadaPath),
            '_debug_key_has_pem_markers'  => str_contains($privateKeyPem, '-----BEGIN RSA PRIVATE KEY-----')
                                               || str_contains($privateKeyPem, '-----BEGIN PRIVATE KEY-----'),
            '_jwt_header_debug'           => $header,
            '_jwt_payload_debug'          => $payload,
        ];
        $headersOAuth = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ];

        $grant = null;
        $stsResposta = null;
        $stsErro = null;
        try {
            $grant = $this->withBaseUrl($this->authBaseUrl, function () use ($grantBody) {
                return $this->post('api/oauth/token', $grantBody, true);
            });
            $stsResposta = $grant;
        } catch (\Throwable $e) {
            $stsErro = [
                'classe'  => get_class($e),
                'mensagem' => $e->getMessage(),
                'codigo'  => $e->getCode(),
                'trace'   => $e->getTraceAsString(),
            ];
            if (isset($grant) && is_object($grant)) {
                $stsResposta = $grant;
            }
        } finally {
            $respostaDebug = [
                'grant_body' => is_object($stsResposta) && isset($stsResposta->body) ? (array) $stsResposta->body : null,
                'http_code'  => is_object($stsResposta) && isset($stsResposta->headers['http_code']) ? $stsResposta->headers['http_code'] : null,
                'headers_resposta' => is_object($stsResposta) && isset($stsResposta->headers) ? (array) $stsResposta->headers : null,
                'body_texto' => is_object($stsResposta) && isset($stsResposta->body_text) ? $stsResposta->body_text : null,
                'erro' => $stsErro,
            ];
            $this->debugSalvarPayload('oAuth2', 'POST', $urlOAuth, $headersOAuth, array_merge($payloadOAuth, $respostaDebug), 'oauth');
        }

        if (! is_object($grant) || ! isset($grant->body->access_token)) {
            $mensagem = is_array($stsErro) ? ($stsErro['mensagem'] ?? 'Erro desconhecido') : 'Resposta do STS Itau nao contem access_token.';
            if (is_object($grant) && isset($grant->body_text) && $grant->body_text !== '') {
                $mensagem .= ' | Body STS: ' . $grant->body_text;
            }
            throw new ValidationException('Falha ao obter access_token no STS Itau (private_key_jwt). ' . $mensagem);
        }

        return $this->setAccessToken('Bearer ' . $grant->body->access_token);
    }

    private function jwtBase64UrlEncode(string $data): string
    {
        $base64 = base64_encode($data);
        $base64 = strtr($base64, '+/', '-_');

        return rtrim($base64, '=');
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     * @return string JWT compacto assinado (header.payload.signature)
     *
     * @throws ValidationException Quando nao for possivel assinar com a chave privada informada.
     */
    private function jwtAssinarRS256(array $header, array $payload, string $privateKeyPem): string
    {
        $headerJson  = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($headerJson === false || $payloadJson === false) {
            throw new ValidationException('Falha ao serializar header/payload JWT (JSON).');
        }

        $headerB64u  = $this->jwtBase64UrlEncode($headerJson);
        $payloadB64u = $this->jwtBase64UrlEncode($payloadJson);
        $mensagem    = $headerB64u . '.' . $payloadB64u;

        $chave = openssl_pkey_get_private($privateKeyPem);
        if ($chave === false) {
            throw new ValidationException('Chave privada invalida ao carregar PEM (openssl_pkey_get_private falhou). Verifique APICertificadoKey.');
        }

        $assinatura = '';
        $ok = openssl_sign($mensagem, $assinatura, $chave, 'sha256WithRSAEncryption');
        if (! $ok) {
            throw new ValidationException('Falha ao assinar JWT RS256 (openssl_sign falhou). Verifique APICertificadoKey.');
        }

        return $mensagem . '.' . $this->jwtBase64UrlEncode($assinatura);
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

        $nossoNumeroDebug = (string) (\Illuminate\Support\Arr::get($data, 'data.dado_boleto.nosso_numero') ?? $boleto->getNossoNumero() ?? 'sem-nn');
        $nossoNumeroDebug = Util::onlyNumbers($nossoNumeroDebug) !== '' ? Util::onlyNumbers($nossoNumeroDebug) : $nossoNumeroDebug;
        $urlCompleta = rtrim($this->baseUrl, '/') . $this->url('create');
        $headersDebug = $this->headers();
        $payloadDebug = $data;

        try {
            $retorno = $this->oAuth2()->post($this->url('create'), $data);

            $dadosIndividuais = \Illuminate\Support\Arr::get($retorno->body, 'dado_boleto.dados_individuais_boleto')
                ?? \Illuminate\Support\Arr::get($retorno->body, 'data.dado_boleto.dados_individuais_boleto');
            if (is_array($dadosIndividuais) && count($dadosIndividuais) > 0) {
                $primeiro = reset($dadosIndividuais);
                $idBoletoRetornado = is_object($primeiro) ? ($primeiro->id_boleto_individual ?? $primeiro->id_boleto ?? null) : ($primeiro['id_boleto_individual'] ?? $primeiro['id_boleto'] ?? null);
                if (! empty($idBoletoRetornado)) {
                    $boleto->setID((string) $idBoletoRetornado);
                }
                $nossoNumeroRetornado = is_object($primeiro) ? ($primeiro->numero_nosso_numero ?? null) : ($primeiro['numero_nosso_numero'] ?? null);
                if (! empty($nossoNumeroRetornado)) {
                    $boleto->setNossoNumero((string) $nossoNumeroRetornado);
                }
            }

            if (! $boleto->getID()) {
                $idBoletoFallback = \Illuminate\Support\Arr::get($retorno->body, 'id_boleto')
                    ?? \Illuminate\Support\Arr::get($retorno->body, 'data.id_boleto');
                if (! empty($idBoletoFallback)) {
                    $boleto->setID((string) $idBoletoFallback);
                }
            }

            return $boleto;
        } finally {
            $this->debugSalvarPayload('createBoleto', 'POST', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
        }
    }

    public function retrieveNossoNumero($nossoNumero, array $paramsExtras = [])
    {
        $params = array_merge([
            'id_beneficiario' => (string) $this->getIdBeneficiario(),
            'codigo_carteira' => (string) $this->getCarteira(),
            'nosso_numero'    => (string) $nossoNumero,
            'view'            => 'full',
        ], array_filter($paramsExtras, function ($v) {
            return $v !== null && $v !== '';
        }));

        $queryString = http_build_query($params);

        $nossoNumeroDebug = Util::onlyNumbers((string) $nossoNumero) !== '' ? Util::onlyNumbers((string) $nossoNumero) : 'sem-nn';
        $urlCompleta = rtrim($this->baseUrl, '/') . $this->url('show') . '?' . $queryString;
        $headersDebug = $this->headers();
        $payloadDebug = ['_query' => $params];

        try {
            $response = $this->oAuth2()->get($this->url('show') . '?' . $queryString);

            return $response->body;
        } finally {
            $this->debugSalvarPayload('retrieveNossoNumero', 'GET', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
        }
    }

    public function retrieveID($id)
    {
        $nossoNumeroDebug = is_string($id) && $id !== '' ? Util::onlyNumbers($id) : (is_numeric($id) ? (string) $id : 'sem-nn');
        $urlCompleta = rtrim($this->baseUrl, '/') . sprintf($this->url('show_id'), $id);
        $headersDebug = $this->headers();
        $payloadDebug = ['id_boleto' => $id, 'view' => 'full'];

        try {
            $response = $this->oAuth2()->get(sprintf($this->url('show_id'), $id) . '?view=full');

            return $response->body;
        } finally {
            $this->debugSalvarPayload('retrieveID', 'GET', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
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
            'id_beneficiario' => (string) $this->getIdBeneficiario(),
            'codigo_carteira' => (string) $this->getCarteira(),
            'view'            => 'basic',
        ], $inputedParams);

        $queryString = http_build_query($params);

        $nossoNumeroDebug = isset($params['nosso_numero']) && Util::onlyNumbers((string) $params['nosso_numero']) !== ''
            ? Util::onlyNumbers((string) $params['nosso_numero'])
            : 'lista';
        $urlCompleta = rtrim($this->baseUrl, '/') . $this->url('list') . '?' . $queryString;
        $headersDebug = $this->headers();
        $payloadDebug = ['_query' => $params];

        try {
            $response = $this->oAuth2()->get($this->url('list') . '?' . $queryString);

            return $response->body;
        } finally {
            $this->debugSalvarPayload('retrieveList', 'GET', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
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

        $nossoNumeroDebug = is_string($id) && $id !== '' ? Util::onlyNumbers($id) : (is_numeric($id) ? (string) $id : 'sem-nn');
        $urlCompleta = rtrim($this->baseUrl, '/') . sprintf($this->url('patch_data_vencimento'), $id);
        $headersDebug = $this->headers();
        $payloadDebug = $payload;

        try {
            $response = $this->oAuth2()->patch(sprintf($this->url('patch_data_vencimento'), $id), $payload);

            return $response->body;
        } finally {
            $this->debugSalvarPayload('alterarVencimentoID', 'PATCH', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
        }
    }

    public function alterarValorNossoNumero($nossoNumero, $novoValor)
    {
        $idBoleto = $this->resolveIdBoletoPorNossoNumero($nossoNumero);
        return $this->alterarValorID($idBoleto, $novoValor);
    }

    public function alterarValorID($id, $novoValor)
    {
        $valorFloat = (float) $novoValor;
        $valorFormatado = number_format($valorFloat, 2, '.', '');

        $payload = [
            'valor_titulo' => $valorFormatado,
        ];

        $nossoNumeroDebug = is_string($id) && $id !== '' ? Util::onlyNumbers($id) : (is_numeric($id) ? (string) $id : 'sem-nn');
        $urlCompleta = rtrim($this->baseUrl, '/') . sprintf($this->url('patch_valor_nominal'), $id);
        $headersDebug = $this->headers();
        $payloadDebug = $payload;

        try {
            $response = $this->oAuth2()->patch(sprintf($this->url('patch_valor_nominal'), $id), $payload);

            return $response->body;
        } finally {
            $this->debugSalvarPayload('alterarValorID', 'PATCH', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
        }
    }

    public function baixarBoletoNossoNumero($nossoNumero, $motivo)
    {
        $idBoleto = $this->resolveIdBoletoPorNossoNumero($nossoNumero);
        return $this->baixarBoletoID($idBoleto, $motivo);
    }

    public function baixarBoletoID($id, $motivo)
    {
        $nossoNumeroDebug = is_string($id) && $id !== '' ? Util::onlyNumbers($id) : (is_numeric($id) ? (string) $id : 'sem-nn');
        $urlCompleta = rtrim($this->baseUrl, '/') . sprintf($this->url('patch_baixa'), $id);
        $headersDebug = $this->headers();
        $payloadDebug = ['motivo_baixa' => (string) $motivo];

        try {
            $response = $this->oAuth2()->patch(sprintf($this->url('patch_baixa'), $id), $payloadDebug);

            return $response->body;
        } finally {
            $this->debugSalvarPayload('baixarBoletoID', 'PATCH', $urlCompleta, $headersDebug, $payloadDebug, $nossoNumeroDebug);
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

        $candidatos = [];
        $candidatos[] = data_get($retorno, 'data.id_boleto');
        $candidatos[] = data_get($retorno, 'data.0.id_boleto');
        $candidatos[] = data_get($retorno, 'id_boleto');
        $listaIndividual = data_get($retorno, 'dado_boleto.dados_individuais_boleto')
            ?? data_get($retorno, 'data.dado_boleto.dados_individuais_boleto');
        if (is_array($listaIndividual) && count($listaIndividual) > 0) {
            $primeiro = reset($listaIndividual);
            $candidatos[] = is_object($primeiro) ? ($primeiro->id_boleto_individual ?? $primeiro->id_boleto ?? null) : ($primeiro['id_boleto_individual'] ?? $primeiro['id_boleto'] ?? null);
        }

        $id = null;
        foreach ($candidatos as $c) {
            if (! empty($c)) {
                $id = (string) $c;
                break;
            }
        }

        if (empty($id)) {
            throw new ValidationException('Não foi possível obter o id_boleto a partir do nosso_numero informado. Confira o retorno retrieve no debug-boleto.');
        }

        return $id;
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
            'renovacao'                    => '/seguranca/v2/certificado/renovacao',
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

    public function suportaRenovacaoAutomatica(): bool
    {
        return true;
    }

    public function extrairValidadeCertificado(): ?DateTimeInterface
    {
        $crtInput = (string) $this->getCertificado();
        if ($crtInput === '') {
            return null;
        }

        $data = CertificateUtil::extrairValidadeCertificado($crtInput);
        if (! $data instanceof DateTimeImmutable) {
            return null;
        }

        return $data;
    }

    public function renovarCertificado(array $params = []): CertificadoRenovado
    {
        $this->validarCamposObrigatoriosRenovacao();
        $this->validarJanela30DiasItau();

        $novaChavePrivadaPem = CertificateUtil::gerarChavePrivadaRSA2048Pem();

        $clientId   = trim((string) $this->getClientId());
        $ouApp      = trim((string) ($this->getOuAppCertificado() ?? 'SISTEMA-COBRANCA'));
        $cidade     = trim((string) ($this->getCidadeEmpresa() ?? 'SAO PAULO'));
        $uf         = strtoupper(trim((string) ($this->getUfEmpresa() ?? 'SP')));

        if ($ouApp === '') {
            $ouApp = 'SISTEMA-COBRANCA';
        }
        if ($cidade === '') {
            $cidade = 'SAO PAULO';
        }
        if (strlen($uf) !== 2) {
            $uf = 'SP';
        }

        $novoCsrPem = CertificateUtil::gerarCSR(
            $clientId,
            $ouApp,
            $cidade,
            $uf,
            'BR',
            $novaChavePrivadaPem,
            'sha512',
        );

        $cnExtraido = (string) CertificateUtil::extrairCNDoCSR($novoCsrPem);
        if ($cnExtraido !== $clientId) {
            throw new ValidationException(sprintf(
                'CN do CSR gerado (valor: "%s") difere do client_id da carteira (valor: "%s"). Abortando renovação antes de chamar a API Itaú.',
                $cnExtraido,
                $clientId,
            ));
        }

        $this->oAuth2();
        $accessTokenBruto = (string) $this->getAccessToken();
        $accessTokenLimpo = preg_replace('/^Bearer\s+/i', '', $accessTokenBruto) ?? $accessTokenBruto;
        if ($accessTokenLimpo === '') {
            throw new ValidationException('Não foi possível obter access_token STS válido para a renovação de certificado.');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $accessTokenLimpo,
            'Content-Type'  => 'text/plain',
            'x-itau-force-cert' => 'true',
        ];

        $endpointPath = $this->url('renovacao');
        $requestId    = (string) Str::uuid();
        $urlCompleta  = rtrim($this->authBaseUrl, '/') . $endpointPath;
        $headersDebug = array_merge($headers, [
            'Authorization' => 'Bearer [OCULTADO]',
        ]);
        $payloadDebug = [
            'request_id'            => $requestId,
            'client_id'             => $clientId,
            'ou_app'                => $ouApp,
            'cidade'                => $cidade,
            'uf'                    => $uf,
            'cn_cliente'            => $clientId,
            'cn_csr_gerado'         => $cnExtraido,
            'cn_corresponde'        => $cnExtraido === $clientId,
            'csr_pem_resumo_sha256' => hash('sha256', $novoCsrPem),
            'chave_privada_sha256'  => hash('sha256', $novaChavePrivadaPem),
        ];

        try {
            $resposta = $this->withBaseUrl($this->authBaseUrl, function () use ($endpointPath, $novoCsrPem, $headers) {
                return $this->postRawTextInterno($endpointPath, $novoCsrPem, $headers);
            });

            $httpCode = is_object($resposta) && isset($resposta->headers['http_code'])
                ? (int) $resposta->headers['http_code']
                : 0;

            $bodyRaw = is_object($resposta) && isset($resposta->body_text)
                ? (string) $resposta->body_text
                : '';

            $payloadDebug['response_http_code'] = $httpCode;
            $payloadDebug['response_body_raw']  = $bodyRaw;

            if ($httpCode < 200 || $httpCode >= 300) {
                $mensagemErro = "Falha na renovação de certificado Itaú (HTTP $httpCode).";
                if ($bodyRaw !== '') {
                    $mensagemErro .= ' | Body: ' . substr($bodyRaw, 0, 800);
                }
                throw new ValidationException($mensagemErro, $httpCode);
            }

            if (trim($bodyRaw) === '') {
                throw new ValidationException('Resposta da API Itaú de renovação veio vazia. HTTP 200 mas sem conteúdo CRT.');
            }

            $novoCrtPem = $this->parseExtrairCRTResponse($bodyRaw);

            if (! CertificateUtil::validarPEMCertificado($novoCrtPem)) {
                throw new ValidationException('O certificado retornado pela API Itaú não foi reconhecido como PEM válido após parse.');
            }

            $validadeAtual = $this->extrairValidadeCertificado();
            $validadeNova  = CertificateUtil::extrairValidadeCertificado($novoCrtPem);
            if ($validadeNova === null) {
                throw new ValidationException('Não foi possível extrair data de validade do NOVO certificado Itaú.');
            }
            if ($validadeAtual !== null && $validadeNova->getTimestamp() <= $validadeAtual->getTimestamp()) {
                throw new ValidationException(sprintf(
                    'O novo certificado não renovou a validade (até: %s vs antigo até: %s).',
                    $validadeNova->format('Y-m-d H:i:s'),
                    $validadeAtual->format('Y-m-d H:i:s'),
                ));
            }

            $payloadDebug['validade_nova']        = $validadeNova->format(DateTimeInterface::RFC3339);
            $payloadDebug['validade_nova_unix']   = $validadeNova->getTimestamp();
            if ($validadeAtual !== null) {
                $payloadDebug['validade_anterior']    = $validadeAtual->format(DateTimeInterface::RFC3339);
                $payloadDebug['dias_renovados']       = (int) ceil(($validadeNova->getTimestamp() - $validadeAtual->getTimestamp()) / 86400);
            }
            $payloadDebug['tipo_fluxo'] = 'v2-renovacao';

            return new CertificadoRenovado(
                $novoCrtPem,
                $novaChavePrivadaPem,
                null,
                $validadeNova,
                'v2-renovacao',
                [
                    'http_code'   => $httpCode,
                    'request_id'  => $requestId,
                    'body_raw'    => $bodyRaw,
                    'validade_original' => $validadeAtual !== null ? $validadeAtual->getTimestamp() : null,
                ],
            );
        } finally {
            $this->debugSalvarPayload('renovarCertificadoV2', 'POST', $urlCompleta, $headersDebug, $payloadDebug, 'renovacao-'. substr($requestId, 0, 8));
        }
    }

    private function validarCamposObrigatoriosRenovacao(): void
    {
        $campos = [];
        if (trim((string) $this->getClientId()) === '') {
            $campos[] = 'APIClientID (client_id)';
        }

        $crt = (string) $this->getCertificado();
        if ($crt === '') {
            $campos[] = 'APICertificado (certificado .crt atual)';
        } elseif (strlen($crt) <= 1024 && ! is_file($crt)) {
            $campos[] = "APICertificado (path informado não existe arquivo: '$crt')";
        }

        $key = (string) $this->getCertificadoChave();
        if ($key === '') {
            $campos[] = 'APICertificadoKey (chave privada .key atual)';
        } elseif (strlen($key) <= 1024 && ! is_file($key)) {
            $campos[] = "APICertificadoKey (path informado não existe arquivo: '$key')";
        }

        if ($campos !== []) {
            throw new ValidationException('Campos obrigatórios ausentes para renovação de certificado Itaú 341: ' . implode(', ', $campos) . '.');
        }
    }

    private function validarJanela30DiasItau(): void
    {
        $validadeAtual = $this->extrairValidadeCertificado();
        if ($validadeAtual === null) {
            return;
        }

        $agora = new DateTimeImmutable();
        $limite30Dias = $agora->modify('+30 days');
        if ($validadeAtual > $limite30Dias) {
            $diasRestantes = CertificateUtil::calcularDiasAteVencer($validadeAtual, $agora);
            throw new ValidationException(sprintf(
                'API Itaú v2 só permite renovação 30 dias antes do vencimento (atual falta %d dias). Válido a partir de %s. Vencimento atual do certificado: %s.',
                $diasRestantes,
                $limite30Dias->format('Y-m-d'),
                $validadeAtual->format('Y-m-d'),
            ));
        }
    }

    private function parseExtrairCRTResponse(string $bodyRaw): string
    {
        $bodyRaw = trim($bodyRaw);
        if ($bodyRaw === '') {
            throw new ValidationException('Body de resposta vazio ao extrair CRT.');
        }

        if (str_contains($bodyRaw, '-----BEGIN CERTIFICATE-----') && str_contains($bodyRaw, '-----END CERTIFICATE-----')) {
            $inicio = strpos($bodyRaw, '-----BEGIN CERTIFICATE-----');
            $fim    = strpos($bodyRaw, '-----END CERTIFICATE-----');
            if ($inicio !== false && $fim !== false) {
                $tamanho = ($fim - $inicio) + strlen('-----END CERTIFICATE-----');
                return trim(substr($bodyRaw, $inicio, $tamanho));
            }
        }

        if (str_contains($bodyRaw, '-----BEGIN X509 CERTIFICATE-----')) {
            return trim($bodyRaw);
        }

        $linhas = preg_split("/\r\n|\n|\r/", $bodyRaw);
        $linhas = is_array($linhas) ? $linhas : [];
        $certLinhas = [];
        $gravando = false;
        foreach ($linhas as $linha) {
            $linha = rtrim($linha);
            if (str_starts_with($linha, '-----BEGIN') && str_contains($linha, 'CERTIFICATE')) {
                $gravando = true;
            }
            if ($gravando) {
                $certLinhas[] = $linha;
            }
            if ($gravando && str_starts_with($linha, '-----END') && str_contains($linha, 'CERTIFICATE')) {
                $gravando = false;
                break;
            }
        }

        if ($certLinhas !== []) {
            $reconstruido = implode("\n", $certLinhas);
            if (CertificateUtil::validarPEMCertificado($reconstruido)) {
                return $reconstruido;
            }
        }

        $provavelPEM = "-----BEGIN CERTIFICATE-----\n" .
                       wordwrap(preg_replace('/\s+/', '', $bodyRaw) ?? '', 64, "\n", true) .
                       "\n-----END CERTIFICATE-----";
        if (CertificateUtil::validarPEMCertificado($provavelPEM)) {
            return $provavelPEM;
        }

        if (CertificateUtil::validarPEMCertificado($bodyRaw)) {
            return $bodyRaw;
        }

        throw new ValidationException('Não foi possível extrair um certificado PEM válido da resposta da API de renovação Itaú.');
    }

    private function postRawTextInterno(string $urlPath, string $bodyText, array $headers = [])
    {
        $caminhoCompleto = rtrim($this->baseUrl, '/') . '/' . ltrim($urlPath, '/');

        $certificado = (string) $this->getCertificado();
        $chave       = (string) $this->getCertificadoChave();
        if ($certificado === '' || $chave === '') {
            throw new ValidationException('Certificado/Chave privada não definidos para requisição mTLS.');
        }

        $headersFlat = [];
        foreach ($headers as $hName => $hValue) {
            if (is_int($hName)) {
                $headersFlat[] = $hValue;
            } else {
                $headersFlat[] = sprintf('%s: %s', $hName, is_array($hValue) ? implode(', ', $hValue) : (string) $hValue);
            }
        }

        $ch = curl_init($caminhoCompleto);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyText);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headersFlat);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

        if (is_file($certificado)) {
            curl_setopt($ch, CURLOPT_SSLCERT, $certificado);
        }
        if (is_file($chave)) {
            curl_setopt($ch, CURLOPT_SSLKEY, $chave);
        }

        $sslVerify = $this->getSslVerify();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool) ($sslVerify ?? true));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, (bool) ($sslVerify ?? true) ? 2 : 0);

        $responseRaw  = curl_exec($ch);
        $curlErrNo    = curl_errno($ch);
        $curlErrMsg   = curl_error($ch);
        $headersResp  = [];
        $httpCode     = 0;
        $bodyRaw      = '';
        $headerSize   = 0;

        if ($responseRaw !== false) {
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $httpCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerText = substr($responseRaw, 0, $headerSize);
            $bodyRaw    = (string) substr($responseRaw, $headerSize);

            foreach (preg_split("/\r\n|\n|\r/", $headerText) ?: [] as $headerLine) {
                $headerLine = trim($headerLine);
                if ($headerLine === '' || ! str_contains($headerLine, ':')) {
                    continue;
                }
                [$nome, $valor] = explode(':', $headerLine, 2);
                $nome  = strtolower(trim($nome));
                $valor = trim($valor);
                if (! isset($headersResp[$nome])) {
                    $headersResp[$nome] = $valor;
                } elseif (is_array($headersResp[$nome])) {
                    $headersResp[$nome][] = $valor;
                } else {
                    $headersResp[$nome] = [$headersResp[$nome], $valor];
                }
            }
        }
        $headersResp['http_code']  = $httpCode;
        $headersResp['curl_errno'] = $curlErrNo;
        $headersResp['curl_error'] = $curlErrMsg;

        $bodyDecodificado = json_decode($bodyRaw, true);
        $bodyObj          = is_array($bodyDecodificado) ? (object) $bodyDecodificado : new \stdClass();

        $resposta = (object) [
            'body'       => $bodyObj,
            'body_text'  => $bodyRaw,
            'headers'    => $headersResp,
        ];

        if ($curlErrNo) {
            throw new ValidationException('Erro cURL ao chamar API de renovação Itaú: ' . $curlErrMsg);
        }

        curl_close($ch);

        return $resposta;
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

