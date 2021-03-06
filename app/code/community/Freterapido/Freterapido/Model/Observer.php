<?php

/**
 * @category Freterapido
 * @package Freterapido_Freterapido
 * @author freterapido.com <suporte@freterapido.com>
 * @copyright Frete Rápido (https://freterapido.com)
 * @license https://github.com/freterapido/freterapido_magento/blob/master/LICENSE MIT
 */
class Freterapido_Freterapido_Model_Observer extends Mage_Core_Model_Abstract
{
    const CODE = 'freterapido';

    const TITLE = 'Frete Rápido';

    protected $_code = self::CODE;

    protected $_title = self::TITLE;

    protected $_sender = array(
        'cnpj' => null,
        'inscricao_estadual' => null,
        'endereco' => array(
            'cep' => null
        )
    );

    protected $_receiver = array(
        'tipo_pessoa' => 1,
        'endereco' => array(
            'cep' => null
        )
    );

    protected $_url = null;

    protected $_offer = array();

    protected $_increment_id = null;

    protected $_track_id = null;

    protected $_success = true;

    protected $_invoice = [];

    public function quote($observer)
    {
        $active = (bool) Mage::helper($this->_code)->getConfigData('active');

        // Se o módulo não estiver ativo, ignora a contratação pelo Frete Rápido
        if (!$active) {
            return false;
        }

        $_shipment = $observer->getEvent()->getShipment();

        $_order = $_shipment->getOrder();

        // If order shipping method isn't ours, ignore it
        if (strpos($_order->getShippingMethod(), 'freterapido') === false) {
            return false;
        }

        try {
            $this->_increment_id = $_order->getIncrementId();
            $_customer = Mage::getModel('customer/customer')->load($_order->getCustomerId());

            // Retornando o cpf/cnpj do destinatário
            $_cnpj_cpf = $_order->getShippingAddress()->getData('vat_id');

            if (empty($_cnpj_cpf)) {
                // Busca o cnpj do customer
                if (!empty($_customer->getId())) {
                    $_cnpj_cpf = $_customer->getData('taxvat');
                }
            }

            //Retornando a inscrição estadual pelo atributo customizado
            $ref_attr = Mage::helper($this->_code)->getConfigData('ref_attr_state_registration_type');
            $_state_registration = $_order->getShippingAddress()->getData($ref_attr);

            if (empty($_state_registration)) {
                // Busca o cnpj do customer
                if (!empty($_customer->getId())) {
                    $_state_registration = $_customer->getData($ref_attr);
                }
            }

            $_cnpj_cpf = preg_replace("/\D/", '', $_cnpj_cpf);
            $_state_registration = preg_replace("/\D/", '', $_state_registration);

            if (empty($_cnpj_cpf)) {
                throw new Exception('O CNPJ/CPF do destinatário não foi informado.');
            }

            $this->_log('Iniciando contratação...');

            $this->_getSender();

            $this->_getReceiver($_order, $_cnpj_cpf, $_state_registration);

            $this->_getOffer($_order->getShippingMethod());

            try {
                $this->_invoice = $this->_getInvoice($_order);
            } catch (\Exception $e) {
                $this->_log($e->getMessage());
            }

            $this->_url = sprintf(
                Mage::helper($this->_code)->getConfigData('api_quote_url'),
                $this->_offer['token'],
                $this->_offer['code'],
                Mage::helper($this->_code)->getConfigData('token')
            );

            $this->_doHire();

            $this->_addTracking($_shipment);

            $this->_updateOrderStatus($_order);

            $this->_log('Contratação realizada com sucesso.');

            return $this;
        } catch (Exception $e) {
            $this->_throwError($e->getMessage());
        }
    }

    /**
     * Obtém os dados da origem
     */
    protected function _getSender()
    {
        try {
            Mage::getStoreConfig('shipping/origin', $this->getStore());

            $this->_sender = array();
            $this->_sender['cnpj'] = preg_replace("/\D/", '', Mage::getStoreConfig('carriers/freterapido/shipper_cnpj'));
        } catch (Exception $e) {
            $this->_throwError('Erro ao tentar obter os dados de origem. Erro: ' . $e->getMessage());
        }
    }

    /**
     * @param  Mage_Shipping_Model_Rate_Request $request
     * @return bool
     */
    protected function _getReceiver($order, $cnpj_cpf, $_state_registration)
    {
        try {
            $name = $order->getShippingAddress()->getFirstname() . ' ' . $order->getShippingAddress()->getLastname();
            $cnpjcpf            = preg_replace("/\D/", '', $cnpj_cpf);
            $state_registration = preg_replace("/\D/", '', $_state_registration);

            $this->_receiver = array();
            $this->_receiver['tipo_pessoa'] = strlen($cnpjcpf) == 14 ? 2 : 1;
            $this->_receiver['cnpj_cpf'] = $cnpjcpf;
            $this->_receiver['inscricao_estadual'] = !empty($state_registration) ? $state_registration : 'ISENTO';
            $this->_receiver['nome'] = $name;
            $this->_receiver['email'] = $order->getShippingAddress()->getEmail();
            $this->_receiver['telefone'] = preg_replace("/\D/", '', $order->getShippingAddress()->getTelephone());

            $this->_receiver['endereco']['cep'] = $this->_formatZipCode($order->getShippingAddress()->getPostcode());
            $this->_receiver['endereco']['rua'] = $order->getShippingAddress()->getData('street');
        } catch (Exception $e) {
            $this->_throwError('Erro ao tentar obter os dados de origem. Erro: ' . $e->getMessage());
        }
    }

    /**
     * Formata e valida o CEP informado
     *
     * @param  string         $zipcode
     * @return boolean|string
     */
    protected function _formatZipCode($zipcode)
    {
        $new_zipcode = preg_replace("/\D/", '', trim($zipcode));

        if (strlen($new_zipcode) !== 8) {
            throw new Exception('O CEP digitado é inválido');
        }

        return $new_zipcode;
    }

    /**
     * Separa o token e o código da oferta armazenados no shipping method
     *
     * @param string $shipping_method
     */
    protected function _getOffer($shipping_method)
    {
        $method = explode('_', $shipping_method);
        $last_index = count($method) - 1;

        $this->_offer = array(
            'token' => $method[$last_index - 1],
            'code' => $method[$last_index]
        );
    }

    /**
     * Extrai as informações da NFe através dos comentários de faturamento
     *
     * @param mixed $order
     * @return array
     *
     * - Formato do padrão do comentário -> nfe:00000000000000000000000000000000000000000000, emissao:dd/mm/aaaa hh:mm:ss
     */
    public static function _getInvoice($order)
    {
        $invoices = $order->getInvoiceCollection();
        $comments = [];

        if (!empty($invoices)) {
            foreach ($invoices as $invoice) {
                $invoice_comments = $invoice->getCommentsCollection(true);

                foreach ($invoice_comments as $comment) {
                    $comment_data = $comment->getComment();
                    if (!empty($comment_data)) {
                        $comments[] = [
                            'content'    => $comment_data,
                            'created_at' => $comment->getCreatedAt(),
                        ];
                    }
                }
            }
        }

        // Ordena os comentarios por data de criação decrescente
        if (!empty($comments)) {
            foreach ($comments as $key => $value) {
                $created_at[$key] = $value['created_at'];
            }
            array_multisort($created_at, SORT_DESC, $comments);
        }

        // Aplica filtro nos comentários para recuperar apenas aqueles que possuem conteúdo válido
        $comments = array_map(function ($comment) {

            $data = explode(',', str_replace(' ', '', $comment['content']));

            if (count($data) === 2) {
                $invoice_key  = '';
                $invoice_date = '';
                foreach ($data as $part) {
                    $key_pos  = strpos($part, 'nfe:');
                    $date_pos = strpos($part, 'emissao:');

                    if ($key_pos !== false) {
                        $invoice_key = substr($part, $key_pos + 4);
                    }
                    if ($date_pos !== false) {
                        $invoice_date = substr($part, $date_pos + 8);
                    }
                }
                if (
                    !empty($invoice_key) &&
                    !empty($invoice_date) &&
                    strlen($invoice_key) == 44 &&
                    strlen($invoice_date) == 18
                ) {
                    return [
                        'invoice_key' => $invoice_key,
                        'invoice_date' => $invoice_date,
                    ];
                }
            }
        }, $comments);

        if (!empty($comments)) {
            // Retorna o primeiro item do array (mais recente) para obter os dados da NFe
            $invoice_data = $comments[0];

            // Cria a struct de nota fiscal
            if (!empty($invoice_data)) {
                try {
                    return [
                        'numero'       => substr($invoice_data['invoice_key'], 25, 9),
                        'serie'        => substr($invoice_data['invoice_key'], 22, 3),
                        'chave_acesso' => $invoice_data['invoice_key'],
                        'valor'        => $order->getGrandTotal(),
                        'data_emissao' => DateTime::createFromFormat('d/m/YH:i:s', $invoice_data['invoice_date'])->format('Y-m-d H:i:s'),
                    ];
                } catch (\Exception $e) {
                    throw new \Exception("Erro ao extrair dados da NFe: {$e->getMessage()}");
                }
            }
        }
        return [];
    }

    /**
     * Realiza a contratação do frete no Frete Rápido
     * @throws Exception
     */
    protected function _doHire()
    {

        // Dados que serão enviados para a API do Frete Rápido
        $request_data = array(
            'numero_pedido' => $this->_increment_id,
            'remetente' => $this->_sender,
            'destinatario' => $this->_receiver,
        );

        if (!empty($this->_invoice)) {
            $request_data = array_merge($request_data, ['nota_fiscal' => $this->_invoice]);
        }

        $config = array(
            'adapter' => 'Zend_Http_Client_Adapter_Curl',
            'curloptions' => array(
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER, false
            ),
        );

        // Configura o cliente http passando a URL da API e a configuração
        $client = new Zend_Http_Client($this->_url, $config);

        // Adiciona os parâmetros à requisição
        $client->setRawData(json_encode($request_data), 'application/json');

        // Realiza a chamada POST
        $response = $client->request('POST');

        if ($response->getStatus() != 200) {
            $_message = $response->getStatus() == 422 ? $response->getBody() : $response->getMessage();

            throw new Exception('Erro ao tentar se comunicar com a API - Code: ' . $response->getStatus() . '. Error: ' . $_message);
        }

        $response = json_decode($response->getBody());

        $this->_track_id = $response->id_frete;
    }

    /**
     * Adiciona o id de acompanhamento do Frete Rápido no tracking da ordem
     *
     * @param $shipment
     */
    protected function _addTracking($shipment)
    {
        $_shipping_method = explode('-', $shipment->getOrder()->getShippingDescription());
        $shipping_method = trim($_shipping_method[0]);

        $carrier = empty($shipping_method) ? $this->_title : $shipping_method;

        $track = Mage::getModel('sales/order_shipment_track')
            ->setNumber($this->_track_id) //tracking number / awb number
            ->setCarrierCode($this->_code) //carrier code
            ->setTitle($carrier); //carrier title

        $shipment->addTrack($track);
    }

    /**
     * Atualiza o status do pedido conforme o status configurado no módulo
     *
     * @param $_order
     */
    protected function _updateOrderStatus($_order)
    {
        //Verifica se o status informado na configuração existe
        $_custom_hired_status = Mage::getResourceModel('sales/order_status_collection')
            ->joinStates()
            ->addFieldToFilter('main_table.status', Mage::helper(self::CODE)->getConfigData('order_status_on_hire'))
            ->getFirstItem();

        if (!empty($_custom_hired_status->getStatus())) {
            $_order->setStatus($_custom_hired_status->getStatus());
            $_order->setData('state', $_custom_hired_status->getState());
        }

        $_history = $_order->addStatusHistoryComment("Frete contratado em " . date('d/m/Y H:i:s'), false);
        $_history->setIsCustomerNotified(false);
        $_order->save();
    }

    /**
     * Armazena no log a mensagem informada
     *
     * @param string $mensagem
     */
    protected function _log($mensagem)
    {
        Mage::log('Frete Rápido: ' . $mensagem);
    }

    /**
     * Armazena no log a mensagem informada
     *
     * @param string $mensagem
     */
    protected function _throwError($mensagem)
    {
        Mage::throwException('Frete Rápido - Não foi possível realizar a contratação do frete. Motivo: ' . $mensagem);
    }
}
