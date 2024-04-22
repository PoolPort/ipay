<?php

namespace PoolPort\Azki;

use GuzzleHttp\Client;
use PoolPort\Config;
use PoolPort\DataBaseManager;
use PoolPort\Exceptions\PoolPortException;
use PoolPort\PortAbstract;
use PoolPort\PortInterface;

class Azki extends PortAbstract implements PortInterface
{
    const ALGORITHM = 'AES-256-CBC';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://api.azkiloan.com';

    /**
     * Address of payment gateway
     *
     * @var string
     */
    private $paymentUri;

    /**
     * items of invoice
     *
     * @var array
     */
    private $items;

    /**
     * {@inheritdoc}
     */
    public function __construct(Config $config, DatabaseManager $db, $portId)
    {
        parent::__construct($config, $db, $portId);
    }

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        header("Location: " . $this->paymentUri);
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $this->userPayment();
        $this->verifyPayment();

        return $this;
    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws AzkiException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $subUrl = '/payment/purchase';
            $client = new Client();

            $response = $client->request("POST", $this->gateUrl . $subUrl, [
                "json"    => [
                    'merchant_id'   => $this->config->get('azki.merchant-id'),
                    'amount'        => $this->amount,
                    'redirect_uri'  => $this->buildRedirectUrl($this->config->get('azki.callback-url')),
                    'fallback_uri'  => $this->buildRedirectUrl($this->config->get('azki.callback-url')),
                    'provider_id'   => mt_rand(1000000000, 999999999999),
                    'mobile_number' => $this->config->get('zarinpal.user-mobile', ''),
                    "items"         => $this->items
                ],
                "headers" => [
                    'Signature'  => $this->generateSignature($subUrl),
                    'MerchantId' => $this->config->get('azki.merchant-id'),
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->rsCode != AzkiCreateTicketCodes::SUCCESS) {
                $this->transactionFailed();
                $errorMessage = AzkiCreateTicketCodes::getMessage($response->rsCode);
                $this->newLog($response->rsCode, $errorMessage);
                throw new AzkiException($errorMessage, $response->rsCode);
            }

            $this->refId = $response->result->ticket_id;
            $this->transactionSetRefId($this->transactionId);
            $this->paymentUri = $response->result->payment_uri;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Check user payment with GET data
     *
     * @return bool
     *
     * @throws AzkiException
     */
    protected function userPayment()
    {
        try {
            $subUrl = '/payment/status';
            $client = new Client();

            $res = $client->request("POST", $this->gateUrl . $subUrl, [
                "json"    => [
                    'ticket_id' => $this->refId(),
                ],
                "headers" => [
                    'Signature'  => $this->generateSignature($subUrl),
                    'MerchantId' => $this->config->get('azki.merchant-id'),
                ],
            ]);

            return json_decode($res->getBody()->getContents());

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog("Error", $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verify user payment from zarinpal server
     *
     * @return bool
     *
     * @throws AzkiException
     */
    protected function verifyPayment()
    {
        try {
            $subUrl = '/payment/verify';
            $client = new Client();

            $response = $client->request("POST", $this->gateUrl . $subUrl, [
                "json"    => [
                    'ticket_id' => $this->refId(),
                ],
                "headers" => [
                    'Signature'  => $this->generateSignature($subUrl),
                    'MerchantId' => $this->config->get('azki.merchant-id'),
                ],
            ]);

            $response = json_decode($response->getBody()->getContents());

            if ($response->rsCode != AzkiStatusTicketCodes::VERIFIED) {
                $this->transactionFailed();
                $errorMessage = AzkiStatusTicketCodes::getMessage($response->rsCode);
                $this->newLog($response->rsCode, $errorMessage);
                throw new AzkiException($errorMessage, $response->rsCode);
            }

            return $response;

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw new PoolPortException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * generate a signature based in api key
     *
     * @return string
     */
    public function generateSignature($subUrl)
    {
        $apiKey = $this->config->get('azki.api_key');
        $time = time();
        $signature = "$subUrl#$time#POST#$apiKey";
        $iv = str_repeat("\0", 16);
        $encrypted = openssl_encrypt($signature, self::ALGORITHM, hex2bin($apiKey), OPENSSL_RAW_DATA, $iv);

        return bin2hex($encrypted);
    }

    /**
     * add item to invoice
     *
     * @return $this
     */
    public function addItem($name, $count, $amount, $url)
    {
        $this->items[] = [
            "name"   => $name,
            "count"  => $count,
            "amount" => $amount,
            "url"    => $url
        ];

        return $this;
    }
}
