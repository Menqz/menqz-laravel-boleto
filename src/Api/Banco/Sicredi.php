<?php

declare(strict_types=1);

namespace Eduardokum\LaravelBoleto\Api\Banco;

use Eduardokum\LaravelBoleto\Api\AbstractAPI;
use Eduardokum\LaravelBoleto\Boleto\Banco\Sicredi as BoletoSicredi;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoAPIContract;
use Eduardokum\LaravelBoleto\Exception\ValidationException;

class Sicredi extends AbstractAPI
{
    protected $baseUrl = 'https://api-parceiro.sicredi.com.br/sicoob/cobranca/boleto';

    private $authBaseUrl = 'https://api-parceiro.sicredi.com.br/sicoob/autenticacao';

    protected $client_key = null;

    protected $cooperativa = null;

    protected $codigo_beneficiario = null;

    protected $posto = null;

    protected $storage_path = null;

    protected $camposObrigatorios = [
        'client_id',
        'client_key',
        'client_secret',
        'codigo_beneficiario',
        'posto',
    ];

    public function __construct($params = [])
    {
        if (isset($params['ambiente']) && $params['ambiente'] === 'H') {
            $this->baseUrl = 'https://api-parceiro.sicredi.com.br/sicoob/sandbox/cobranca/boleto';
            $this->authBaseUrl = 'https://api-parceiro.sicredi.com.br/sicoob/sandbox/autenticacao';
        }

        if (isset($params['storage_path'])) {
            $this->storage_path = (string) $params['storage_path'];
        }

        if (isset($params['sslVerify'])) {
            $this->setSslVerify((bool) $params['sslVerify']);
        }

        parent::__construct($params);

        $clientKey = (string) $this->getClientKey();
        if ($clientKey === '' || ! ctype_digit($clientKey) || strlen($clientKey) !== 9) {
            throw new ValidationException(
                'APIClientKey invalido. Deve conter exatamente 9 digitos numericos (4 digitos da Cooperativa + 5 digitos do Codigo Beneficiario). Exemplo: 678912345.'
            );
        }

        $cooperativaExtraida = substr($clientKey, 0, 4);
        $codBenExtraido = substr($clientKey, 4, 5);
        $codBenParam = (string) $this->getCodigoBeneficiario();

        if ($codBenParam !== '' && $codBenExtraido !== $codBenParam) {
            throw new ValidationException(
                "APIClientKey (username OAuth 9 digitos) nao corresponde ao NumeroCarteira cadastrado. Extraido do APIClientKey: {$codBenExtraido}, NumeroCarteira informado: {$codBenParam}."
            );
        }

        $this->setCooperativa(sprintf('%04s', $cooperativaExtraida));
        $this->setCodigoBeneficiario(sprintf('%05s', $codBenExtraido));

        $postoRaw = (string) $this->getPosto();
        if ($postoRaw === '' || ! ctype_digit($postoRaw) || strlen($postoRaw) > 2) {
            throw new ValidationException(
                'Campo Posto invalido. Informe exatamente 2 digitos numericos (Ex: 01, 10, 99).'
            );
        }
        $this->setPosto(sprintf('%02s', $postoRaw));
    }

    public function getClientKey()
    {
        return $this->client_key;
    }

    public function setClientKey($clientKey)
    {
        $this->client_key = $clientKey;

        return $this;
    }

    public function getCooperativa()
    {
        return $this->cooperativa;
    }

    public function setCooperativa($cooperativa)
    {
        $this->cooperativa = $cooperativa;

        return $this;
    }

    public function getCodigoBeneficiario()
    {
        return $this->codigo_beneficiario;
    }

    public function setCodigoBeneficiario($codigoBeneficiario)
    {
        $this->codigo_beneficiario = $codigoBeneficiario;

        return $this;
    }

    public function getPosto()
    {
        return $this->posto;
    }

    public function setPosto($posto)
    {
        $this->posto = $posto;

        return $this;
    }

    protected function headers()
    {
        return array_filter([
            'Authorization'    => $this->getAccessToken(),
            'x-api-key'        => $this->getClientId(),
            'codigoBeneficiario' => $this->getCodigoBeneficiario(),
            'cooperativa'      => $this->getCooperativa(),
            'posto'            => $this->getPosto(),
            'x-correlation-id' => bin2hex(random_bytes(16)),
        ]);
    }

    protected function oAuth2()
    {
        if ($this->getAccessToken()) {
            return $this;
        }

        $grantBody = [
            'grant_type' => 'password',
            'username'   => (string) $this->getClientKey(),
            'password'   => (string) $this->getClientSecret(),
            'contexto'   => 'Cooperativa',
        ];
        $urlOAuth = rtrim($this->authBaseUrl, '/') . '/v3/oauth2/access-token';
        $debugPayload = [
            'grant_type' => 'password',
            'username'   => (string) $this->getClientKey(),
            'password'   => '[OCULTADO]',
            'contexto'   => 'Cooperativa',
        ];
        $debugHeaders = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
            'x-api-key'    => $this->getClientId(),
        ];

        try {
            $grant = $this->withBaseUrl($this->authBaseUrl, function () use ($grantBody) {
                $raw = true;
                return $this->post('v3/oauth2/access-token', $grantBody, $raw)->body;
            });
        } finally {
            // DEBUG-INICIO
            $this->debugSalvarPayload('oAuth2', 'POST', $urlOAuth, $debugHeaders, $debugPayload, 'oauth');
            // DEBUG-FIM
        }

        if (! is_object($grant) || ! isset($grant->access_token) || ! is_string($grant->access_token) || trim($grant->access_token) === '') {
            throw new ValidationException(
                'Falha na autenticacao Sicredi OAuth2 (grant password). Verifique APIClientKey (9 digitos username), APIClientSecret (Código de Acesso 64 digitos Internet Banking), x-api-key (APIClientID) e ambiente informado. Mensagem retornada: ' . json_encode($grant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        return $this->setAccessToken('Bearer ' . $grant->access_token);
    }

    public function createBoleto(BoletoAPIContract $boleto)
    {
        if (! $boleto instanceof BoletoSicredi) {
            throw new ValidationException('Boleto deve ser instância de ' . BoletoSicredi::class);
        }

        $data = $boleto->toAPI('sicredi');
        $nossoNum = (string) ($data['nossoNumero'] ?? $boleto->getNossoNumero(false));

        try {
            $retorno = $this->oAuth2()->post($this->url('create'), $data, false);

            if (isset($retorno->body->nossoNumero) && is_scalar($retorno->body->nossoNumero)) {
                $boleto->setNossoNumero($retorno->body->nossoNumero);
            }
            $boleto->setId(isset($retorno->body->id) ? (string) $retorno->body->id : $nossoNum);

            return $retorno->body;
        } finally {
            // DEBUG-INICIO
            $this->debugSalvarPayload('createBoleto', 'POST', rtrim($this->baseUrl, '/') . $this->url('create'), $this->headers(), $data, $nossoNum);
            // DEBUG-FIM
        }
    }

    public function retrieveBoleto($boletoId)
    {
        $path = $this->url('show_id', (string) $boletoId);

        try {
            $retorno = $this->oAuth2()->get($path);

            return $retorno->body;
        } finally {
            // DEBUG-INICIO
            $this->debugSalvarPayload('retrieveBoleto', 'GET', rtrim($this->baseUrl, '/') . $path, $this->headers(), [], (string) $boletoId);
            // DEBUG-FIM
        }
    }

    public function retrieveNossoNumero($nossoNumero)
    {
        return $this->retrieveBoleto((string) $nossoNumero);
    }

    public function retrieveID($id)
    {
        return $this->retrieveBoleto((string) $id);
    }

    public function retrieve(BoletoAPIContract $boleto)
    {
        if (! $boleto instanceof BoletoSicredi) {
            throw new ValidationException('Boleto deve ser instância de ' . BoletoSicredi::class);
        }

        return $this->retrieveBoleto((string) $boleto->getNossoNumero(false));
    }

    public function retrieveList($inputedParams = [])
    {
        return $this->retrieveTodosBoletos($inputedParams);
    }

    public function retrieveTodosBoletos(array $filters = [])
    {
        $qs = http_build_query($filters);
        $path = $this->url('list') . ($qs !== '' ? ('?' . $qs) : '');

        try {
            $retorno = $this->oAuth2()->get($path);

            return $retorno->body;
        } finally {
            // DEBUG-INICIO
            $this->debugSalvarPayload('retrieveTodosBoletos', 'GET', rtrim($this->baseUrl, '/') . $path, $this->headers(), $filters, 'lista');
            // DEBUG-FIM
        }
    }

    public function baixarBoletoID($id, $motivo)
    {
        $motivosValidos = [
            'ACERTOS',
            'PROTESTAR',
            'DEVOLUCAO',
            'OUTROS',
            'PAGAMENTODIRETOCAOPROVEDOR',
        ];
        if (! is_string($motivo) || ! in_array($motivo, $motivosValidos, true)) {
            throw new ValidationException(
                'Motivo de baixa Sicredi invalido. Motivos aceitos: ' . implode(', ', $motivosValidos) . '.'
            );
        }

        $body = [];
        $path = $this->url('baixa_id', (string) $id);

        try {
            $retorno = $this->oAuth2()->patch($path, $body, false);

            return $retorno->body;
        } finally {
            // DEBUG-INICIO
            $this->debugSalvarPayload('baixarBoletoID', 'PATCH', rtrim($this->baseUrl, '/') . $path, $this->headers(), ['motivo' => $motivo] + $body, (string) $id);
            // DEBUG-FIM
        }
    }

    public function baixarBoletoNossoNumero($nossoNumero, $motivo)
    {
        return $this->baixarBoletoID((string) $nossoNumero, $motivo);
    }

    public function baixarBoleto(BoletoAPIContract $boleto, $motivo)
    {
        if (! $boleto instanceof BoletoSicredi) {
            throw new ValidationException('Boleto deve ser instância de ' . BoletoSicredi::class);
        }

        return $this->baixarBoletoID((string) $boleto->getNossoNumero(false), $motivo);
    }

    public function cancelID($id, $motivo)
    {
        return $this->baixarBoletoID((string) $id, $motivo);
    }

    public function cancelNossoNumero($nossoNumero, $motivo)
    {
        return $this->baixarBoletoNossoNumero((string) $nossoNumero, $motivo);
    }

    public function cancel(BoletoAPIContract $boleto, $motivo)
    {
        return $this->baixarBoleto($boleto, $motivo);
    }

    public function alterarVencimentoID($id, $novaData)
    {
        if (! is_string($novaData) || trim($novaData) === '') {
            throw new ValidationException('Nova data de vencimento invalida.');
        }

        $novaDataLimpa = trim($novaData);
        $dataFormatada = $novaDataLimpa;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $novaDataLimpa, $m)) {
            $dataFormatada = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFormatada)) {
            throw new ValidationException('Nova data de vencimento invalida. Use formato YYYY-MM-DD ou DD/MM/YYYY.');
        }

        $body = [
            'dataVencimento' => $dataFormatada,
        ];
        $path = $this->url('patch_vencimento_id', (string) $id);

        try {
            $retorno = $this->oAuth2()->patch($path, $body, false);

            return $retorno->body;
        } finally {
            // DEBUG-INICIO
            $this->debugSalvarPayload('alterarVencimentoID', 'PATCH', rtrim($this->baseUrl, '/') . $path, $this->headers(), $body, (string) $id);
            // DEBUG-FIM
        }
    }

    public function alterarVencimentoNossoNumero($nossoNumero, $novaData)
    {
        return $this->alterarVencimentoID((string) $nossoNumero, $novaData);
    }

    public function alterarVencimento(BoletoAPIContract $boleto, $novaData)
    {
        if (! $boleto instanceof BoletoSicredi) {
            throw new ValidationException('Boleto deve ser instância de ' . BoletoSicredi::class);
        }

        return $this->alterarVencimentoID((string) $boleto->getNossoNumero(false), $novaData);
    }

    public function alterarValorID($id, $novoValor)
    {
        throw new ValidationException(
            'Operacao Alterar Valor Nominal nao esta disponivel para o Banco Sicredi. Apenas alteracoes de juros/multa/abatimento/protesto poderao ser integradas futuramente.'
        );
    }

    public function alterarValorNossoNumero($nossoNumero, $novoValor)
    {
        throw new ValidationException(
            'Operacao Alterar Valor Nominal nao esta disponivel para o Banco Sicredi. Apenas alteracoes de juros/multa/abatimento/protesto poderao ser integradas futuramente.'
        );
    }

    public function alterarValor(BoletoAPIContract $boleto, $novoValor)
    {
        throw new ValidationException(
            'Operacao Alterar Valor Nominal nao esta disponivel para o Banco Sicredi. Apenas alteracoes de juros/multa/abatimento/protesto poderao ser integradas futuramente.'
        );
    }

    public function getPdfNossoNumero($nossoNumero)
    {
        throw new ValidationException('Método getPdfNossoNumero não integrado para o banco Sicredi.');
    }

    public function getPdfID($id)
    {
        throw new ValidationException('Método getPdfID não integrado para o banco Sicredi.');
    }

    public function getPdf(BoletoAPIContract $boleto)
    {
        throw new ValidationException('Método getPdf não integrado para o banco Sicredi.');
    }

    public function createWebhook($url, $type = 'all')
    {
        throw new ValidationException('Método createWebhook não integrado para o banco Sicredi.');
    }

    public function alterarJurosID($id, $juros)
    {
        $this->url('patch_juros_id', (string) $id);
        throw new ValidationException('Método alterarJurosID ainda não integrado para o banco Sicredi.');
    }

    public function alterarMultaID($id, $multa)
    {
        $this->url('patch_multa_id', (string) $id);
        throw new ValidationException('Método alterarMultaID ainda não integrado para o banco Sicredi.');
    }

    public function alterarDescontoID($id, $desconto)
    {
        $this->url('patch_desconto_id', (string) $id);
        throw new ValidationException('Método alterarDescontoID ainda não integrado para o banco Sicredi.');
    }

    public function alterarAbatimentoID($id, $abatimento)
    {
        $this->url('patch_abatimento_id', (string) $id);
        throw new ValidationException('Método alterarAbatimentoID ainda não integrado para o banco Sicredi.');
    }

    public function alterarSeuNumeroID($id, $seuNumero)
    {
        $this->url('patch_seunumero_id', (string) $id);
        throw new ValidationException('Método alterarSeuNumeroID ainda não integrado para o banco Sicredi.');
    }

    public function alterarProtestoID($id, $protesto)
    {
        $this->url('patch_protesto_id', (string) $id);
        throw new ValidationException('Método alterarProtestoID ainda não integrado para o banco Sicredi.');
    }

    public function alterarInfoImpressaoID($id, $infoImpressao)
    {
        $this->url('patch_infoimpressao_id', (string) $id);
        throw new ValidationException('Método alterarInfoImpressaoID ainda não integrado para o banco Sicredi.');
    }

    public function alterarInfComplementaresID($id, $infComplementares)
    {
        $this->url('patch_infcomplementares_id', (string) $id);
        throw new ValidationException('Método alterarInfComplementaresID ainda não integrado para o banco Sicredi.');
    }

    public function alterarMensagensID($id, $mensagens)
    {
        $this->url('patch_mensagens_id', (string) $id);
        throw new ValidationException('Método alterarMensagensID ainda não integrado para o banco Sicredi.');
    }

    public function alterarEspecieID($id, $especie)
    {
        $this->url('patch_especie_id', (string) $id);
        throw new ValidationException('Método alterarEspecieID ainda não integrado para o banco Sicredi.');
    }

    public function alterarPagamentoParcialID($id, $pagParcial)
    {
        $this->url('patch_pagparcial_id', (string) $id);
        throw new ValidationException('Método alterarPagamentoParcialID ainda não integrado para o banco Sicredi.');
    }

    public function alterarTipoEmbolsoID($id, $tipoEmbolso)
    {
        $this->url('patch_tipoembolso_id', (string) $id);
        throw new ValidationException('Método alterarTipoEmbolsoID ainda não integrado para o banco Sicredi.');
    }

    public function alterarCodigoBarrasID($id, $codigoBarras)
    {
        $this->url('patch_codigobarras_id', (string) $id);
        throw new ValidationException('Método alterarCodigoBarrasID ainda não integrado para o banco Sicredi.');
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

    private function url($type, $arg = '')
    {
        return match ($type) {
            'create'                       => '/v3/boletos',
            'show'                         => '/v3/boletos',
            'show_id'                      => '/v3/boletos/' . rawurlencode($arg),
            'list'                         => '/v3/boletos',
            'baixa_id'                     => '/v3/boletos/' . rawurlencode($arg) . '/baixa',
            'patch_vencimento_id'          => '/v3/boletos/' . rawurlencode($arg) . '/data-vencimento',
            'patch_juros_id'               => '/v3/boletos/' . rawurlencode($arg) . '/juros',
            'patch_multa_id'               => '/v3/boletos/' . rawurlencode($arg) . '/multa',
            'patch_desconto_id'            => '/v3/boletos/' . rawurlencode($arg) . '/descontos',
            'patch_abatimento_id'          => '/v3/boletos/' . rawurlencode($arg) . '/abatimentos',
            'patch_seunumero_id'           => '/v3/boletos/' . rawurlencode($arg) . '/seunumero',
            'patch_protesto_id'            => '/v3/boletos/' . rawurlencode($arg) . '/protesto',
            'patch_infoimpressao_id'       => '/v3/boletos/' . rawurlencode($arg) . '/infoimpressao',
            'patch_infcomplementares_id'   => '/v3/boletos/' . rawurlencode($arg) . '/infcomplementares',
            'patch_mensagens_id'           => '/v3/boletos/' . rawurlencode($arg) . '/mensagens',
            'patch_especie_id'             => '/v3/boletos/' . rawurlencode($arg) . '/especie',
            'patch_pagparcial_id'          => '/v3/boletos/' . rawurlencode($arg) . '/pagparcial',
            'patch_tipoembolso_id'         => '/v3/boletos/' . rawurlencode($arg) . '/tipoembolso',
            'patch_codigobarras_id'        => '/v3/boletos/' . rawurlencode($arg) . '/codigobarras',
            default                        => throw new ValidationException("URL Sicredi nao mapeada: {$type}."),
        };
    }

    private function debugSalvarPayload(string $metodoNome, string $metodoHttp, string $urlCompleta, array $headers, array $payload, string $nossoNumero = 'sem-nn'): void
    {
        try {
            $pasta = 'C:\\Users\\samsung\\Desktop\\debug-boleto';
            if (! is_dir($pasta)) {
                @mkdir($pasta, 0777, true);
            }
            if (! is_dir($pasta)) {
                return;
            }

            $nomeLimpo = preg_replace('/[^a-z0-9-]+/i', '-', strtolower($metodoNome)) . '-';
            $nomeLimpo .= preg_replace('/[^0-9]+/i', '-', (string) $nossoNumero);
            $arquivo = $pasta . DIRECTORY_SEPARATOR . trim($nomeLimpo, '-') . '.txt';

            $linhas = [];
            $linhas[] = 'Data/Hora: ' . date('Y-m-d H:i:s');
            $linhas[] = 'Método PHP: ' . $metodoNome;
            $linhas[] = 'Método HTTP: ' . $metodoHttp;
            $linhas[] = 'URL: ' . $urlCompleta;
            $linhas[] = '';
            $linhas[] = '--- Cabeçalhos (sensíveis mascarados) ---';
            foreach ($headers as $k => $v) {
                if (is_array($v)) {
                    $v = implode(', ', $v);
                } else {
                    $v = (string) $v;
                }
                $nomeLower = strtolower((string) $k);
                if ($nomeLower === 'authorization' && strlen($v) > 20) {
                    $v = substr($v, 0, 15) . '...[OCULTADO]';
                }
                if ($nomeLower === 'x-api-key' && strlen($v) > 8) {
                    $v = substr($v, 0, 8) . '...[OCULTADO]';
                }
                $linhas[] = $k . ': ' . $v;
            }
            $linhas[] = '';
            $linhas[] = '--- Payload ---';
            $linhas[] = json_encode(
                (object) $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );
            $linhas[] = '';
            $linhas[] = '--- Fim ---';

            @file_put_contents($arquivo, implode("\r\n", $linhas));
        } catch (\Throwable $th) {
            // DEBUG nao pode quebrar fluxo real.
        }
    }
}
