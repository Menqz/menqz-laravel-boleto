<?php

namespace Eduardokum\LaravelBoleto\Api\Banco;

use Illuminate\Support\Arr;
use Eduardokum\LaravelBoleto\Api\AbstractAPI;
use Eduardokum\LaravelBoleto\Api\Exception\CurlException;
use Eduardokum\LaravelBoleto\Api\Exception\HttpException;
use Eduardokum\LaravelBoleto\Api\Exception\UnauthorizedException;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Boleto\Banco\Itau as BoletoItau;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoAPIContract;
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
            // $this->baseUrl = 'https://devportal.itau.com.br/sandboxapi';
            // $this->consultaBaseUrl = 'https://devportal.itau.com.br/sandboxapi';
            // $this->authBaseUrl = 'https://devportal.itau.com.br';

            $this->baseUrl = 'https://boleto22.free.beeceptor.com/sandboxapi';
            $this->consultaBaseUrl = 'https://boleto22.free.beeceptor.com/sandboxapi';
            $this->authBaseUrl = 'https://boleto22.free.beeceptor.com';
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
            'Authorization'   => $token,
            'x-itau-apikey'   => $token ? str_replace('Bearer ', '', $token) : null,
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
            'id_beneficiario'      => $this->getIdBeneficiario(),
            'codigo_carteira'      => $this->getCarteira(),
            'nosso_numero'         => $nossoNumero,
            'codigo_motivo_baixa'  => $motivo,
        ];

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($params) {
            return $this->oAuth2()->post($this->url('cancel'), $params);
        });

        return $response->body;
    }

    public function cancelID($id, $motivo)
    {
        $params = [
            'id_beneficiario'      => $this->getIdBeneficiario(),
            'codigo_carteira'      => $this->getCarteira(),
            'id_boleto'            => $id,
            'codigo_motivo_baixa'  => $motivo,
        ];

        $response = $this->withBaseUrl($this->consultaBaseUrl, function () use ($params) {
            return $this->oAuth2()->post($this->url('cancel'), $params);
        });

        return $response->body;
    }

    public function retrieveList($inputedParams = [])
    {
        throw new ValidationException('Método não disponível no banco');
    }

    public function getPdfNossoNumero($nossoNumero)
    {
        throw new ValidationException('Método não disponível no banco');
    }

    public function getPdfID($id)
    {
        throw new ValidationException('Método não disponível no banco');
    }

    private function url($type)
    {
        $urls = [
            'create' => 'cash_management/v2/boletos',
            'show'   => 'boletoscash/v2/boletos',
            'cancel' => 'boletoscash/v2/boletos/cancelar',
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

