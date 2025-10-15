<?php
define("BASE_DIR", dirname(dirname(dirname(__FILE__))) . "/");

if (!defined("WHMCS")) {
    die();
}

require_once __DIR__ . "/../../init.php";

use WHMCS\Database\Capsule;

//dfboletomercadopago

function dfboletomercadopagocacelarboleto($vars, $metodo)
{
    // defina o método de pagamento
    $gatewayname =  "dfboletomercadopago";
    
    $modulo = Capsule::table("tblpaymentgateways")
        ->where("gateway", $gatewayname)
        ->where("setting", "type")
        ->where("value", "Invoices")
        ->first();


    if ($modulo) {
        //echo "Módulo '.PAYMENT_METHOD.' está ATIVO!";

        $idfatura = trim($vars["invoiceid"]);

        $credentials = getGatewayVariables($gatewayname);

        $access_token = $credentials["AccessTokenProducao"];

        //busca o id no banco para cancelar
        try {

            $fatbd = Capsule::table($gatewayname)
                ->select(
                    "idfatura",
                    "idpayment",
                    "linhadigitavel",
                    "boletourl",
                    "valor"
                )
                ->where("idfatura", "=", $idfatura)
                ->get();

            if ($fatbd[0]->idpayment != "") {
                $idpayment = $fatbd[0]->idpayment;

                $url =
                    "https://api.mercadopago.com/v1/payments/" . $idpayment;

                $data = [
                    "status" => "cancelled",
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt(
                    $ch,
                    CURLOPT_POSTFIELDS,
                    json_encode($data, JSON_NUMERIC_CHECK)
                );
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Content-Type: application/json",
                    "Authorization: Bearer $access_token",
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $result = json_decode($response, true);

                $log = [];
                $log["IdFatura"] = $idfatura;
                $log["DadosBD"] = $fatbd;
                $log["RetornoMP"] = $result;

                logTransaction(
                    $gatewayname,
                    json_encode($log),
                    "boleto Cancelado|" . $metodo
                );
            }
        } catch (\Exception $e) {
        }

        //exclui a fatura do banco de dados
        Capsule::table($gatewayname)
            ->select(
                "idfatura",
                "idpayment",
                "linhadigitavel",
                "boletourl",
                "valor"
            )
            ->where("idfatura", "=", $idfatura)
            ->delete();
    } else {
        echo "Módulo '.$gatewayname.' está INATIVO!";
    }
}

function dfboletofaturacancelada($vars)
{
    dfboletomercadopagocacelarboleto($vars, "Fatura Cancelada");
}

function dfboletofaturatualizada($vars)
{
    dfboletomercadopagocacelarboleto($vars, "Fatura Atualizada");
}

add_hook("InvoiceCancelled", 1, "dfboletofaturacancelada");
add_hook("UpdateInvoiceTotal", 1, "dfboletofaturatualizada");
