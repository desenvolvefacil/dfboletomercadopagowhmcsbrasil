<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// defina o nome do metodo de pagamento 
define('PAYMENT_METHOD_MP_BOLETO', 'dfboletomercadopago');

function dfboletomercadopago_MetaData() {
    return array(
        'DisplayName' => 'Boleto Mercado Pago',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function dfboletomercadopago_config() {
    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Boleto Mercado Pago',
        ),
        'AccessTokenProducao' => array(
            'FriendlyName' => 'Access Token de Produção',
            'Type' => 'text',
            'Default' => '',
            'Description' => 'Coloque seu Access Token de Produção',
        ),
    );
}

function dfboletomercadopago_config_validate($params) {
    if ($params['AccessTokenProducao'] == '') {
        throw new \Exception('O campo AccessTokenProducao não foi preenchido');
    }

    if (!Capsule::schema()->hasTable(PAYMENT_METHOD_MP_BOLETO)) {
        try {

            Capsule::schema()->create(
                PAYMENT_METHOD_MP_BOLETO, function ($table) {
                    /** @var \Illuminate\Database\Schema\Blueprint $table */
                    $table->bigInteger('idfatura')->unsigned();
    
                    $table->string('idpayment')->nullable();
                    $table->text('linhadigitavel')->nullable();
                    $table->text('boletourl')->nullable();
                    $table->decimal('valor', 10, 2)->nullable();
                    $table->string('vencimentoboleto')->nullable();
    
                    $table->primary('idfatura');
                }
            );
        } catch (\Exception $e) {
            throw new \Exception("Unable to create my_table: " . $e->getMessage());
        }
    } else {
        //throw new \Exception("Tabela já existe");
    }
}

function dfboletomercadopago_link($params) {

    //verifica se o cliente clicou pra gerar um novo boleto
    $gerarnovoboleto = filter_input(INPUT_GET, "gerarnovoboleto", FILTER_SANITIZE_STRING);
    
    if($gerarnovoboleto!=1){
        $gerarnovoboleto = 0;
    }
    
    //return $gerarnovoboleto;

    global $CONFIG;
    //dados para retorno automatico
    $URL_BOLETO_RETORNO = $CONFIG['SystemURL'] . "/modules/gateways/callback/dfboletomercadopago-callback.php";

    $access_token = trim($params["AccessTokenProducao"]);
    //dados da fatura
    $idfatura = $params['invoiceid'];
    $valorfatura = $params['amount'];

    //dados pessoais
    $email = trim($params['clientdetails']['email']);

    $nome = trim($params['clientdetails']['firstname']);
    $sobrenome = trim($params['clientdetails']['lastname']);

    $documento = trim($params['clientdetails']['customfields1']);
    $documento = preg_replace('/[^0-9]/', '', $documento);
    
    $cliente_cep = trim($params['clientdetails']['postcode']); 
    $cliente_logradouro = trim($params['clientdetails']['address1']); 
    $cliente_bairro = trim($params['clientdetails']['address2']); 

    if($cliente_bairro == ""){
        $cliente_bairro = "Bairro"
    }
    
    $cliente_numero = "0";
    $cliente_cidade = trim($params['clientdetails']['city']); 
    $cliente_uf = trim($params['clientdetails']['state']); 
  
    $tipodocumento = '';
    if (strlen($documento) == 11) {
        $tipodocumento = 'CPF';
    }

    if (strlen($documento) == 14) {
        $tipodocumento = 'CNPJ';
    }

    //verifica se ja existe a fatura no BD
    try {

        $fatbd = Capsule::table(PAYMENT_METHOD_MP_BOLETO)
                ->select('idfatura', 'idpayment', 'linhadigitavel', 'boletourl', 'valor', 'vencimentoboleto')
                ->where('idfatura', '=', $idfatura)
                ->get();
    } catch (\Exception $e) {
        
    }

    $idpayment = 0;
    $linhadigitavel = "";
    $boletourl = "";
    $valorbd = 0.0;

    $auxIdfatura = $idfatura;

    for (; $i < 26; $i++) {
        $auxIdfatura = '0' . $auxIdfatura;
    }

    $FaturaTexto = "DF" . $auxIdfatura;
    
    $CancelouFatura = 0;
    
    $htmlOutput = "";

    if ($fatbd[0]->idfatura > 0) {

        $idpayment = $fatbd[0]->idpayment;
        $linhadigitavel = $fatbd[0]->linhadigitavel;
        $boletourl = $fatbd[0]->boletourl;
        $valorbd = $fatbd[0]->valor;
        $vencimentoboleto = $fatbd[0]->vencimentoboleto;
        
  
        if (!empty($params['dueDate'])) {
            $dataexpiracao = $params['dueDate'];
               //verifica se fatura ta vencida
            if (strtotime($dataexpiracao) < strtotime('today 23:59:59')) {
                
                //gera uma nova data para expiração do boleto
                $dataexpiracao = date('Y-m-d', strtotime('+1 days'));

                if($gerarnovoboleto == 0){
                    $URL_NOVO_BOLETO = $CONFIG['SystemURL'] . "/viewinvoice.php?gerarnovoboleto=1&id=".$idfatura;
                
                    $htmlOutput = "<br/><a href='".$URL_NOVO_BOLETO."'><b>Sua Fatura esta Vencida, caso precise gerar um novo boleto <br/>CLIQUE AQUI</b></a><br/><br/><br/>";
                }
            }
        } else {
            $dataexpiracao = date('Y-m-d', strtotime('+3 days'));
        }
        

        if ($valorfatura != $valorbd || $gerarnovoboleto == 1) {

            $CancelouFatura = 1;

            //Cancela a Fatura Atual   
            $url = "https://api.mercadopago.com/v1/payments/" . $idpayment;

            $data = [
                "status" => "cancelled"
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_NUMERIC_CHECK));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Authorization: Bearer $access_token"
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);

            $idpayment = 0;
            $linhadigitavel = "";

            $log = [];
            $log["IdFatura"] = $idfatura;
            $log["DadosBD"] = $fatbd;
            $log["RetornoMP"] = $result;

            if($gerarnovoboleto==1){
                logTransaction(PAYMENT_METHOD_MP_BOLETO, date("Y-m-d H:i:s") , "Cliente Solicitou um novo Boleto");
            }
            
            logTransaction(PAYMENT_METHOD_MP_BOLETO, json_encode($log), "Boleto Cancelado|Geracao De Fatura");
        }
    }


    if ($linhadigitavel == "") {
        //gera um novo boleto

        

        date_default_timezone_set('America/Sao_Paulo');

        if (stripos($dataexpiracao, '00:00:00') !== false) {
            $dataexpiracao = str_ireplace('00:00:00', '23:59:59', $dataexpiracao);
        }else{
            $dataexpiracao = $dataexpiracao . ' 23:59:59';
        }

        $dataexpiracao = date('Y-m-d\TH:i:s.vP', strtotime($dataexpiracao));

        if($valorfatura < 5 ){
            $valorfatura = 5.00;
        }
        
 
        $url = "https://api.mercadopago.com/v1/payments";
        
        $data = [
            "transaction_amount" => $valorfatura,
            "description" => "Fatura #" . $idfatura,
            "payment_method_id" => "bolbradesco",
            "payer" => [
                "email" => $email,
                "first_name" => $nome,
                "last_name" => $sobrenome,
                "identification" => [
                    "type" => $tipodocumento,
                    "number" => $documento,
                ],
                "address" => [
                    "zip_code" => $cliente_cep,
                    "street_name" => $cliente_logradouro,
                    "street_number" => $cliente_numero,
                    "neighborhood" => $cliente_bairro,
                    "city" => $cliente_cidade,
                    "federal_unit" => $cliente_uf
                ]
                
            ],
            "date_of_expiration" => $dataexpiracao,
            "external_reference" => $FaturaTexto,
            "notification_url" => $URL_BOLETO_RETORNO
        ];



        //gera id unico
        $currentDate = new DateTime();
        $key = $currentDate->format("Y-m-d\TH:i:s.vP");
        //$key = preg_replace('/[^0-9]/', '', $dataexpiracao);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $access_token",
            "x-integrator-id: dev_5f464b885a5611f09813c2f1db30563c",
            "X-Idempotency-Key: $key"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_NUMERIC_CHECK)); //utilizar JSON_NUMERIC_CHECK
        // Executa requisição
        $response = curl_exec($ch);

        // Fecha conexão
        curl_close($ch);

        // Converte resposta em array
        $result = json_decode($response, true);

        $idpayment = $result['id'] ?? '';
        $boletourl = $result['transaction_details']['external_resource_url'] ?? ($result['point_of_interaction']['transaction_data']['external_resource_url'] ?? '');
        $linhadigitavel = $result['transaction_details']['digitable_line'] ?? '';

        $log = [];
        $log["IdFatura"] = $idfatura;
        $log["RetornoMP"] = $result;

        logTransaction(PAYMENT_METHOD_MP_BOLETO, json_encode($log), "Boleto Gerado");


         $existe = Capsule::table(PAYMENT_METHOD_MP_BOLETO)
            ->where('idfatura', $idfatura)
            ->first();

        if ($existe) {
            // Atualiza o registro existente
            Capsule::table(PAYMENT_METHOD_MP_BOLETO)->where('idfatura', $idfatura)
                    ->update(
                            [
                                'idpayment' => $idpayment,
                                'linhadigitavel' => $linhadigitavel,
                                'boletourl' => $boletourl,
                                'valor' => $valorfatura,
                                'vencimentoboleto' => $dataexpiracao
                            ]
            );
        } else {
            // Insere um novo registro
             Capsule::table(PAYMENT_METHOD_MP_BOLETO)->insert(
                    [
                        'idfatura' => $idfatura,
                        'idpayment' => $idpayment,
                        'linhadigitavel' => $linhadigitavel,
                        'boletourl' => $boletourl,
                        'valor' => $valorfatura,
                        'vencimentoboleto' => $dataexpiracao
                    ]
            );
        }
         
    }



    /***
     * Converte a linha digitavel para codigo de barras
     * */
     
    $linha = preg_replace('/\D/', '', $linhadigitavel);

    if (strlen($linha) != 47) {
        return false; // Linha inválida
    }

    // Estrutura: 5 campos
    $campo1 = substr($linha, 0, 9);
    $campo2 = substr($linha, 9, 10);
    $campo3 = substr($linha, 19, 10);
    $campo4 = substr($linha, 29, 1); // DV
    $campo5 = substr($linha, 30);    // Vencimento + valor

    // Monta o código de barras: Banco + Moeda + DV + Vencimento + Valor + Demais campos
    $codigobarras = substr($campo1, 0, 3)        // Código do banco
                  . substr($campo1, 3, 1)        // Código da moeda
                  . $campo4                      // DV geral
                  . $campo5                      // Fator vencimento + valor
                  . substr($campo1, 4, 5)        // Campo 1 (sem DV)
                  . substr($campo2, 0, 10)       // Campo 2 (sem DV)
                  . substr($campo3, 0, 10);      // Campo 3 (sem DV)


    $barcodeUrl = "https://barcodeapi.org/api/128/".$codigobarras;



    $htmlOutput .= '<script type="text/javascript">
        function copiarBoleto() {

        link = "' . $linhadigitavel . '";

        navigator.clipboard.writeText(link).then(
            () => {
                alert("Codigo Boleto Copiado: " + link);
            },
            () => {
                /* clipboard write failed */
            },
        );
        }
    </script>';

    $formatter = new NumberFormatter('pt_BR', NumberFormatter::CURRENCY);

    $htmlOutput .= '<p>'
            . '<p /><p>Total a Pagar: <br/><b>' . $formatter->formatCurrency($valorfatura, 'BRL') . '</b></p><p /><p />'
            . '<p>Codigo Boleto... (Clique para copiar o codigo)</p>'
            . '<input style="max-width: 300px;" type="button" onclick="javascript:copiarBoleto();" value="' . $linhadigitavel . '" />'
            . '<p />
            <p>'.$linhadigitavel.'</p><hr />'
            . '<p><img src="'.$barcodeUrl.'" alt="Código de Barras">'
            //. '<p>' . $linhadigitavel . '</p>'
            . '</p><p/><hr />
            <p><a target="_blank" href="'.$boletourl.'">Fazer download do Boleto em PDF</a></p>';


    return $htmlOutput;
}

function dfboletomercadopago_refund($params) {

    $access_token = $params["AccessTokenProducao"];

    $idfatura = $params['invoiceid'];
    $refundAmount = (float) $params['amount'];


    //verifica se ja existe a fatura no BD
    try {

        $fatbd = Capsule::table(PAYMENT_METHOD_MP_BOLETO)
                ->select('idfatura', 'idpayment', 'linhadigitavel', 'boletourl', 'valor')
                ->where('idfatura', '=', $idfatura)
                ->get();

        $idboleto = $fatbd[0]->idpayment;

        $url = "https://api.mercadopago.com/v1/payments/" . $idboleto . "/refunds";

        $data = [
            "amount" => $refundAmount // valor em BRL a ser reembolsado
        ];
        
        //echo json_encode($data);exit;


        $idempotencyKey = uniqid('refund_', true);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // corpo vazio = reembolso total
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $access_token",
            "X-Idempotency-Key: $idempotencyKey"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);


        logTransaction(PAYMENT_METHOD_MP_BOLETO, "httpCode = " . $httpCode, "Boleto Reembolsado");
        logTransaction(PAYMENT_METHOD_MP_BOLETO, json_decode($response, true), "Boleto Reembolsado");

        if ($httpCode == 201 || $httpCode == 200) {
            $result = json_decode($response, true);
            //echo "Reembolso criado! Refund ID: " . $result["id"] . " | Status: " . $result["status"];

            $log = [];
            $log["IdFatura"] = $idfatura;
            $log["DadosBD"] = $fatbd;
            $log["RetornoMP"] = $result;

            logTransaction(PAYMENT_METHOD_MP_BOLETO, json_encode($log), "Boleto Reembolsado");

            return array(
                // 'success' if successful, otherwise 'declined', 'error' for failure
                'status' => 'success',
                // Data to be recorded in the gateway log - can be a string or array
                'rawdata' => json_encode($result),
                // Unique Transaction ID for the refund transaction
                'transid' => $result["id"],
                // Optional fee amount for the fee value refunded
                'fees' => 0,
            );
        } else {
            //echo "Erro ao reembolsar pagamento. HTTP $httpCode: $response";
            return array(
                // 'success' if successful, otherwise 'declined', 'error' for failure
                'status' => 'error',
                // Data to be recorded in the gateway log - can be a string or array
                'rawdata' => json_encode($response),
                // Unique Transaction ID for the refund transaction
                'transid' => $response["id"],
                // Optional fee amount for the fee value refunded
                'fees' => 0,
            );
        }
    } catch (\Exception $e) {
        
    }
    
}
