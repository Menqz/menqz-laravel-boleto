<?php

namespace Eduardokum\LaravelBoleto\Boleto\Banco;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Eduardokum\LaravelBoleto\Util;
use Eduardokum\LaravelBoleto\CalculoDV;
use Eduardokum\LaravelBoleto\Boleto\AbstractBoleto;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Contracts\Boleto\BoletoAPI as BoletoAPIContract;

class Itau extends AbstractBoleto implements BoletoAPIContract
{
    /**
     * Local de pagamento
     *
     * @var string
     */
    protected $localPagamento = 'Até o vencimento, preferencialmente no Itaú';

    /**
     * Código do banco
     *
     * @var string
     */
    protected $codigoBanco = self::COD_BANCO_ITAU;

    /**
     * Variáveis adicionais.
     *
     * @var array
     */
    public $variaveis_adicionais = [
        'carteira_nome' => '',
    ];

    /**
     * Define as carteiras disponíveis para este banco
     *
     * @var array
     */
    protected $carteiras = ['112', '115', '188', '109', '121', '180', '110', '111'];

    /**
     * Espécie do documento, coódigo para remessa
     *
     * @var string
     */
    protected $especiesCodigo = [
        'DM'  => '01',
        'NP'  => '02',
        'NS'  => '03',
        'ME'  => '04',
        'REC' => '05',
        'CT'  => '06',
        'CS'  => '07',
        'DS'  => '08',
        'LC'  => '09',
        'ND'  => '13',
        'CDA' => '15',
        'EC'  => '16',
        'CPS' => '17',
    ];

    /**
     * Seta dia para baixa automática
     *
     * @param int $baixaAutomatica
     *
     * @return Itau
     * @throws ValidationException
     */
    public function setDiasBaixaAutomatica($baixaAutomatica)
    {
        if ($this->getDiasProtesto() > 0) {
            throw new ValidationException('Você deve usar dias de protesto ou dias de baixa, nunca os 2');
        }
        $baixaAutomatica = (int) $baixaAutomatica;
        $this->diasBaixaAutomatica = $baixaAutomatica > 0 ? $baixaAutomatica : 0;

        return $this;
    }

    /**
     * Gera o Nosso Número.
     *
     * @return string
     * @throws ValidationException
     */
    protected function gerarNossoNumero()
    {
        $numero_boleto = Util::numberFormatGeral($this->getNumero(), 8);
        $carteira = Util::numberFormatGeral($this->getCarteira(), 3);
        $agencia = Util::numberFormatGeral($this->getAgencia(), 4);
        $conta = Util::numberFormatGeral($this->getConta(), 5);
        $dv = CalculoDV::itauNossoNumero($agencia, $conta, $carteira, $numero_boleto);

        return $numero_boleto . $dv;
    }

    /**
     * Método que retorna o nosso numero usado no boleto. alguns bancos possuem algumas diferenças.
     *
     * @return string
     */
    public function getNossoNumeroBoleto()
    {
        return $this->getCarteira() . '/' . substr_replace($this->getNossoNumero(), '-', -1, 0);
    }

    /**
     * Método para gerar o código da posição de 20 a 44
     *
     * @return string
     * @throws ValidationException
     */
    protected function getCampoLivre()
    {
        if ($this->campoLivre) {
            return $this->campoLivre;
        }

        $campoLivre = Util::numberFormatGeral($this->getCarteira(), 3);
        $campoLivre .= Util::numberFormatGeral($this->getNossoNumero(), 9);
        $campoLivre .= Util::numberFormatGeral($this->getAgencia(), 4);
        $campoLivre .= Util::numberFormatGeral($this->getConta(), 5);
        $campoLivre .= ! is_null($this->getContaDv()) ? $this->getContaDv() : CalculoDV::itauContaCorrente($this->getAgencia(), $this->getConta());
        $campoLivre .= '000';

        return $this->campoLivre = $campoLivre;
    }

    /**
     * Método onde qualquer boleto deve extender para gerar o código da posição de 20 a 44
     *
     * @param $campoLivre
     *
     * @return array
     */
    public static function parseCampoLivre($campoLivre)
    {
        return [
            'convenio'        => null,
            'agenciaDv'       => null,
            'codigoCliente'   => null,
            'carteira'        => substr($campoLivre, 0, 3),
            'nossoNumero'     => substr($campoLivre, 3, 8),
            'nossoNumeroDv'   => substr($campoLivre, 11, 1),
            'nossoNumeroFull' => substr($campoLivre, 3, 9),
            'agencia'         => substr($campoLivre, 12, 4),
            'contaCorrente'   => substr($campoLivre, 16, 5),
            'contaCorrenteDv' => substr($campoLivre, 21, 1),
        ];
    }

    public function toAPI()
    {
        $documentoPagador = Util::onlyNumbers($this->getPagador()->getDocumento());
        $tipoPessoa = strlen($documentoPagador) == 14 ? 'J' : 'F';

        $idBeneficiario = Util::numberFormatGeral($this->getAgencia(), 4)
            . Util::numberFormatGeral($this->getConta(), 7)
            . Util::numberFormatGeral($this->getContaDv(), 1);

        $tipoPessoaCampos = [
            'codigo_tipo_pessoa' => $tipoPessoa,
        ];

        if ($tipoPessoa === 'J') {
            $tipoPessoaCampos['numero_cadastro_nacional_pessoa_juridica'] = $documentoPagador;
        } else {
            $tipoPessoaCampos['numero_cadastro_pessoa_fisica'] = $documentoPagador;
        }

        $pagador = $this->getPagador();
        $extrair = function ($metodo, $padrao = '') use ($pagador) {
            if (is_object($pagador) && method_exists($pagador, $metodo)) {
                $valor = $pagador->{$metodo}();
                if ($valor !== null && $valor !== '') {
                    return (string) $valor;
                }
            }
            $campo = lcfirst(substr($metodo, 3));
            if (is_object($pagador) && property_exists($pagador, $campo)) {
                $valor = $pagador->{$campo};
                if ($valor !== null && $valor !== '') {
                    return (string) $valor;
                }
            }
            if (is_array($pagador) && isset($pagador[$campo])) {
                $valor = $pagador[$campo];
                if ($valor !== null && $valor !== '') {
                    return (string) $valor;
                }
            }

            return (string) $padrao;
        };

        $valorCents = (int) round((float) $this->getValor() * 100);
        $valorTotal = str_pad((string) $valorCents, 17, '0', STR_PAD_LEFT);

        return [
            'data' => [
                'etapa_processo_boleto' => 'efetivacao',
                'codigo_canal_operacao' => 'API',
                'beneficiario' => [
                    'id_beneficiario' => $idBeneficiario,
                ],
                'dado_boleto' => [
                    'descricao_instrumento_cobranca' => 'boleto',
                    'tipo_boleto' => 'a vista',
                    'codigo_carteira' => $this->getCarteira(),
                    'nosso_numero' => Util::onlyNumbers($this->getNossoNumero()),
                    'valor_total_titulo' => $valorTotal,
                    'codigo_especie' => '01',
                    'data_emissao' => $this->getDataDocumento()->format('Y-m-d'),
                    'data_vencimento' => $this->getDataVencimento()->format('Y-m-d'),
                    'pagador' => [
                        'pessoa' => [
                            'nome_pessoa' => $this->getPagador()->getNome(),
                            'tipo_pessoa' => $tipoPessoaCampos,
                        ],
                        'endereco' => [
                            'logradouro'       => $this->getPagador()->getEndereco(),
                            'numero'           => $extrair('getNumero', 'S/N'),
                            'complemento'      => $extrair('getComplemento', ''),
                            'bairro'           => $this->getPagador()->getBairro(),
                            'cidade'           => $this->getPagador()->getCidade(),
                            'uf'               => $this->getPagador()->getUf(),
                            'cep'              => Util::onlyNumbers($this->getPagador()->getCep()),
                            'pais'             => $extrair('getPais', 'Brasil'),
                            'codigo_municipio' => $extrair('getCodigoMunicipio', ''),
                            'codigo_pais'      => $extrair('getCodigoPais', '1058'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Converte resposta da API Itaú em uma instância de Boleto Itaú.
     *
     * @param array|object $boleto Resposta bruta da API Itaú.
     * @param array $appends Dados complementares (beneficiario, conta, agencia, carteira etc.) que não voltam no retorno.
     *
     * @return self
     * @throws ValidationException
     */
    public static function fromAPI($boleto, $appends)
    {
        if (! is_array($appends)) {
            throw new ValidationException('Informe o array de appends.');
        }
        if (! array_key_exists('agencia', $appends) && ! array_key_exists('conta', $appends)) {
            throw new ValidationException('Informe a agencia e conta (appends) para montagem do boleto Itaú via fromAPI.');
        }

        // Normaliza entrada: converte stdClass em array recursivamente.
        $data = json_decode(json_encode($boleto), true);

        // Caminhos comuns de retorno Itaú.
        $dadoBoleto = Arr::get($data, 'data.dado_boleto') ?? Arr::get($data, 'data.0.dado_boleto') ?? $data;
        $dataEnvelope = Arr::get($data, 'data') ?? $data;

        // Mapeamento de situação retornado pela API para constantes SITUACAO_*.
        $aSituacao = [
            // Situações de pagamento
            'PAGO' => AbstractBoleto::SITUACAO_PAGO,
            'LIQUIDADO' => AbstractBoleto::SITUACAO_PAGO,
            'LIQUIDACAO_NORMAL' => AbstractBoleto::SITUACAO_PAGO,
            '06' => AbstractBoleto::SITUACAO_PAGO,

            // Situações baixada/cancelada
            'BAIXADO' => AbstractBoleto::SITUACAO_BAIXADO,
            'CANCELADO' => AbstractBoleto::SITUACAO_BAIXADO,
            'BAIXA_SIMPLES' => AbstractBoleto::SITUACAO_BAIXADO,
            '09' => AbstractBoleto::SITUACAO_BAIXADO,

            // Situações protestado
            'PROTESTADO' => AbstractBoleto::SITUACAO_PROTESTADO,
            'PROTESTO' => AbstractBoleto::SITUACAO_PROTESTADO,

            // Situações de entrada confirmada / aberto / alterado permanecem aberto
            'ABERTO' => AbstractBoleto::SITUACAO_ABERTO,
            'EM_ABERTO' => AbstractBoleto::SITUACAO_ABERTO,
            'REGISTRADO' => AbstractBoleto::SITUACAO_ABERTO,
            'ENTRADA_CONFIRMADA' => AbstractBoleto::SITUACAO_ABERTO,
            'VENCIDO' => AbstractBoleto::SITUACAO_ABERTO,
            '02' => AbstractBoleto::SITUACAO_ABERTO,
            '14' => AbstractBoleto::SITUACAO_ABERTO,

            // Rejeições
            'REJEITADO' => AbstractBoleto::SITUACAO_REJEITADO,
            'RECUSADO' => AbstractBoleto::SITUACAO_REJEITADO,
        ];

        $situacaoBruta = strtoupper((string) (
            Arr::get($dadoBoleto, 'codigo_situacao')
            ?? Arr::get($dadoBoleto, 'situacao')
            ?? Arr::get($dataEnvelope, 'situacao')
            ?? ''
        ));
        $situacao = $aSituacao[$situacaoBruta] ?? AbstractBoleto::SITUACAO_ABERTO;

        // Datas
        $parseDate = function ($valor) {
            if (empty($valor)) {
                return null;
            }
            if ($valor instanceof \DateTimeInterface) {
                return Carbon::instance($valor);
            }
            if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}/', (string) $valor)) {
                return Carbon::parse((string) $valor);
            }
            try {
                return Carbon::createFromFormat('d/m/Y', (string) $valor);
            } catch (\Throwable $e) {
                return null;
            }
        };

        $dataVencimento = $parseDate(
            Arr::get($dadoBoleto, 'data_vencimento')
            ?? Arr::get($dadoBoleto, 'data_vencimento_titulo')
        ) ?? ($appends['dataVencimento'] ?? Carbon::now());

        $dataDocumento = $parseDate(
            Arr::get($dadoBoleto, 'data_emissao')
            ?? Arr::get($dadoBoleto, 'data_documento')
        ) ?? ($appends['dataDocumento'] ?? Carbon::now());

        $dataQuitacao = $parseDate(
            Arr::get($dadoBoleto, 'data_pagamento')
            ?? Arr::get($dadoBoleto, 'data_liquidacao')
            ?? Arr::get($dadoBoleto, 'data_quitacao')
        );

        $dataBaixa = $parseDate(
            Arr::get($dadoBoleto, 'data_baixa')
            ?? Arr::get($dadoBoleto, 'data_cancelamento')
        );

        // Valores
        $toFloat = function ($valor) {
            if (is_numeric($valor)) {
                return (float) $valor;
            }
            if (is_string($valor) && trim($valor) !== '') {
                $valorLimpo = preg_replace('/[^0-9,\.\-]/', '', $valor);
                $valorLimpo = str_replace(['.', ','], ['', '.'], $valorLimpo);
                if (is_numeric($valorLimpo)) {
                    return (float) $valorLimpo;
                }
            }

            return null;
        };

        $valorNominal = $toFloat(
            Arr::get($dadoBoleto, 'valor_total_titulo')
            ?? Arr::get($dadoBoleto, 'valor_nominal')
            ?? Arr::get($dadoBoleto, 'valor')
        ) ?? 0;

        $valorRecebido = $toFloat(
            Arr::get($dadoBoleto, 'valor_total_recebimento')
            ?? Arr::get($dadoBoleto, 'valor_pago')
            ?? Arr::get($dadoBoleto, 'valor_recebido')
        ) ?? 0;

        $valorMora = $toFloat(
            Arr::get($dadoBoleto, 'valor_juros_mora')
            ?? Arr::get($dadoBoleto, 'juros.valor')
            ?? Arr::get($dadoBoleto, 'valor_juros')
        ) ?? 0;

        $valorMulta = $toFloat(
            Arr::get($dadoBoleto, 'valor_multa')
            ?? Arr::get($dadoBoleto, 'multa.valor')
        ) ?? 0;

        $valorDesconto = $toFloat(
            Arr::get($dadoBoleto, 'valor_desconto')
            ?? Arr::get($dadoBoleto, 'desconto.valor')
        ) ?? 0;

        $valorAbatimento = $toFloat(Arr::get($dadoBoleto, 'valor_abatimento')) ?? 0;

        $nossoNumero = Util::onlyNumbers((string) (
            Arr::get($dadoBoleto, 'nosso_numero')
            ?? Arr::get($dataEnvelope, 'nosso_numero')
            ?? ''
        ));

        $seuNumero = (string) (
            Arr::get($dadoBoleto, 'seu_numero')
            ?? Arr::get($dataEnvelope, 'seu_numero')
            ?? ($appends['numero'] ?? '')
        );

        $idBoleto = (string) (
            Arr::get($dataEnvelope, 'id_boleto')
            ?? Arr::get($dadoBoleto, 'id_boleto')
            ?? ''
        );

        // Pagador
        $pessoaPagador = Arr::get($dadoBoleto, 'pagador.pessoa')
            ?? Arr::get($dadoBoleto, 'pagador')
            ?? null;
        $enderecoPagador = Arr::get($dadoBoleto, 'pagador.endereco')
            ?? null;

        $documentoPagador = Util::onlyNumbers((string) (
            Arr::get($pessoaPagador, 'tipo_pessoa.numero_cadastro_nacional_pessoa_juridica')
            ?? Arr::get($pessoaPagador, 'tipo_pessoa.numero_cadastro_pessoa_fisica')
            ?? Arr::get($pessoaPagador, 'numero_cadastro_nacional_pessoa_juridica')
            ?? Arr::get($pessoaPagador, 'numero_cadastro_pessoa_fisica')
            ?? Arr::get($pessoaPagador, 'cnpj_cpf')
            ?? Arr::get($pessoaPagador, 'documento')
            ?? ''
        ));
        $nomePagador = (string) (
            Arr::get($pessoaPagador, 'nome_pessoa')
            ?? Arr::get($pessoaPagador, 'nome')
            ?? ''
        );
        $logradouro = (string) (
            Arr::get($enderecoPagador, 'logradouro')
            ?? Arr::get($enderecoPagador, 'endereco')
            ?? ''
        );
        $bairro = (string) Arr::get($enderecoPagador, 'bairro');
        $cidade = (string) Arr::get($enderecoPagador, 'cidade');
        $uf = (string) Arr::get($enderecoPagador, 'uf');
        $cep = Util::onlyNumbers((string) Arr::get($enderecoPagador, 'cep'));

        $carteira = (string) (
            Arr::get($dadoBoleto, 'codigo_carteira')
            ?? ($appends['carteira'] ?? '')
        );

        return new self(array_merge(array_filter([
            'id'              => $idBoleto,
            'nossoNumero'     => $nossoNumero,
            'numero'          => $seuNumero,
            'numeroDocumento' => $seuNumero,
            'valor'           => $valorNominal,
            'valorRecebido'   => $valorRecebido,
            'multa'           => $valorMulta,
            'juros'           => $valorMora,
            'desconto'        => $valorDesconto,
            'abatimento'      => $valorAbatimento,
            'situacao'        => $situacao,
            'dataVencimento'  => $dataVencimento,
            'dataDocumento'   => $dataDocumento,
            'dataProcessamento' => $dataBaixa ?? $dataQuitacao ?? $dataDocumento,
            'aceite'          => Arr::get($appends, 'aceite', 'S'),
            'especieDoc'      => Arr::get($appends, 'especieDoc', 'DM'),
            'carteira'        => $carteira,
            'pagador'         => array_filter([
                'nome'      => $nomePagador,
                'documento' => $documentoPagador,
                'endereco'  => $logradouro,
                'bairro'    => $bairro,
                'cep'       => $cep,
                'uf'        => $uf,
                'cidade'    => $cidade,
            ]),
            'dataQuitacao' => $dataQuitacao,
            'dataBaixa'    => $dataBaixa,
        ]), $appends));
    }
}
