<?php

namespace Eduardokum\LaravelBoleto\Api\Banco;

use DateTimeInterface;
use Illuminate\Support\Arr;
use Eduardokum\LaravelBoleto\Api\AbstractAPI;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Boleto\Banco\Itau as BoletoItau;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoAPIContract;
use Eduardokum\LaravelBoleto\Util;
use Illuminate\Support\Facades\Log;

class Itau extends AbstractAPI
{
    protected $baseUrl = 'https://api.itau.com.br';

    private $consultaBaseUrl = 'https://secure.api.cloud.itau.com.br';

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
            $this->baseUrl = 'https://api.gateway.itau.com.br';
            $this->consultaBaseUrl = 'https://api.gateway.itau.com.br';
            $this->authBaseUrl = 'https://sts.itau.com.br';

            // Backup URL mock Beeceptor
            // $this->baseUrl = 'https://boleto22.free.beeceptor.com/sandboxapi';
            // $this->consultaBaseUrl = 'https://boleto22.free.beeceptor.com/sandboxapi';
            // $this->authBaseUrl = 'https://boleto22.free.beeceptor.com';
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
            'Authorization' => $token,
            'x-itau-apikey' => $token ? str_replace('Bearer ', '', $token) : null,
        ]);
    }

    public function createBoleto(BoletoAPIContract $boleto)
    {
        if (! $boleto instanceof BoletoItau) {
            throw new ValidationException('Boleto deve ser instância de ' . BoletoItau::class);
        }

        $data = $boleto->toAPI();
        Log::info('Dados a serem enviados para criação do boleto: ', $data);

        $retorno = $this->oAuth2()->post($this->url('create'), $data);

        if (isset($retorno->body->data->dado_boleto->nosso_numero)) {
            $boleto->setNossoNumero($retorno->body->data->dado_boleto->nosso_numero);
        }

        if (isset($retorno->body->data->id_boleto)) {
            $boleto->setID($retorno->body->data->id_boleto);
        }

        return $boleto;
    }

    public function retrieveNossoNumero($nossoNumero)
    {
        $params = [
            'id_beneficiario' => $this->getIdBeneficiario(),
            'codigo_carteira' => $this->getCarteira(),
            'nosso_numero'    => $nossoNumero,
        ];

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($params) {
            return $this->oAuth2()->get($this->url('show') . '?' . http_build_query($params));
        });

        return $response->body;
    }

    public function retrieveID($id)
    {
        $params = [
            'id_beneficiario' => $this->getIdBeneficiario(),
            'codigo_carteira' => $this->getCarteira(),
            'id_boleto'       => $id,
        ];

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($params) {
            return $this->oAuth2()->get($this->url('show') . '?' . http_build_query($params));
        });

        return $response->body;
    }

    public function cancelNossoNumero($nossoNumero, $motivo)
    {
        $params = [
            'id_beneficiario'     => $this->getIdBeneficiario(),
            'codigo_carteira'     => $this->getCarteira(),
            'nosso_numero'        => $nossoNumero,
            'codigo_motivo_baixa' => $motivo,
        ];

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($params) {
            return $this->oAuth2()->post($this->url('cancel'), $params);
        });

        return $response->body;
    }

    public function cancelID($id, $motivo)
    {
        $params = [
            'id_beneficiario'     => $this->getIdBeneficiario(),
            'codigo_carteira'     => $this->getCarteira(),
            'id_boleto'           => $id,
            'codigo_motivo_baixa' => $motivo,
        ];

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($params) {
            return $this->oAuth2()->post($this->url('cancel'), $params);
        });

        return $response->body;
    }

    public function retrieveList($inputedParams = [])
    {
        $params = array_merge([
            'id_beneficiario' => $this->getIdBeneficiario(),
            'codigo_carteira' => $this->getCarteira(),
        ], $inputedParams);

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($params) {
            return $this->oAuth2()->get($this->url('list') . '?' . http_build_query($params));
        });

        return $response->body;
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
            'data' => [
                'dado_boleto' => [
                    'data_vencimento' => $dataVencimento,
                ],
            ],
        ];

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($id, $payload) {
            return $this->oAuth2()->patch(sprintf($this->url('patch_data_vencimento'), $id), $payload);
        });

        return $response->body;
    }

    public function alterarValorNossoNumero($nossoNumero, $novoValor)
    {
        $idBoleto = $this->resolveIdBoletoPorNossoNumero($nossoNumero);
        return $this->alterarValorID($idBoleto, $novoValor);
    }

    public function alterarValorID($id, $novoValor)
    {
        $valorFormatado = Util::nFloat($novoValor, 2, false);

        $payload = [
            'data' => [
                'dado_boleto' => [
                    'valor_total_titulo' => $valorFormatado,
                ],
            ],
        ];

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($id, $payload) {
            return $this->oAuth2()->patch(sprintf($this->url('patch_valor_nominal'), $id), $payload);
        });

        return $response->body;
    }

    public function baixarBoletoNossoNumero($nossoNumero, $motivo)
    {
        $idBoleto = $this->resolveIdBoletoPorNossoNumero($nossoNumero);
        return $this->baixarBoletoID($idBoleto, $motivo);
    }

    public function baixarBoletoID($id, $motivo)
    {
        $payload = [
            'data' => [
                'dado_boleto' => [
                    'codigo_motivo_baixa' => $motivo,
                ],
            ],
        ];

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($id, $payload) {
            return $this->oAuth2()->patch(sprintf($this->url('patch_baixa'), $id), $payload);
        });

        return $response->body;
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
        $urls = [
            'create'                         => 'cash_management/v2/boletos',
            'show'                           => 'boletoscash/v2/boletos',
            'list'                           => 'boletoscash/v2/boletos',
            'cancel'                         => 'boletoscash/v2/boletos/cancelar',
            'patch_baixa'                    => 'boletoscash/v2/boletos/%s/baixa',
            'patch_data_vencimento'          => 'boletoscash/v2/boletos/%s/data_vencimento',
            'patch_juros'                    => 'boletoscash/v2/boletos/%s/juros',
            'patch_multa'                    => 'boletoscash/v2/boletos/%s/multa',
            'patch_valor_nominal'            => 'boletoscash/v2/boletos/%s/valor_nominal',
            'patch_data_limite_pagamento'    => 'boletoscash/v2/boletos/%s/data_limite_pagamento',
            'patch_seu_numero'               => 'boletoscash/v2/boletos/%s/seu_numero',
            'patch_protesto'                 => 'boletoscash/v2/boletos/%s/protesto',
            'patch_negativacao'              => 'boletoscash/v2/boletos/%s/negativacao',
            'patch_desconto'                 => 'boletoscash/v2/boletos/%s/desconto',
            'patch_pagador'                  => 'boletoscash/v2/boletos/%s/pagador',
            'patch_sacador_avalista'         => 'boletoscash/v2/boletos/%s/sacador_avalista',
            'patch_recebimento_divergente'   => 'boletoscash/v2/boletos/%s/recebimento_divergente',
            'patch_abatimento'               => 'boletoscash/v2/boletos/%s/abatimento',
        ];

        return Arr::get($urls, $type);
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
}

