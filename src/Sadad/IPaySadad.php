<?php

namespace IPay\Sadad;


use IPay\Config;
use IPay\DataBaseManager;
use IPay\IPayAbstract;
use IPay\IPayInterface;
use SoapClient;

/**
 * class for sadad gateway (bank melli)
 *
 * @package IPay\Sadad
 */
class IPaySadad extends IPayAbstract implements IPayInterface
{
    /**
     * private transaction key
     *
     * @var string
     */
    protected $transactionKey;

    /**
     * شماره ترمینال
     *
     * @var string
     */
    protected $terminalId;

    /**
     * کد شناسایی فروشنده
     *
     * @var string
     */
    protected $merchant;

    /**
     * url of sadad gateway web service
     *
     * @var string
     */
    protected $urlWebService = 'https://sadad.shaparak.ir/services/MerchantUtility.asmx?wsdl';

    /**
     * form generated by sadad gateway
     *
     * @var string
     */
    private $form = '';

    /**
     * @inheritdoc
     */
    public function __construct(Config $configFile=null,DataBaseManager $db,$portId)
    {
        parent::__construct($configFile,$db,$portId);

        $this->merchant = $this->config->get('sadad.merchant');
        $this->terminalId = $this->config->get('sadad.terminalId');
        $this->transactionKey = $this->config->get('sadad.transactionKey');
    }


    /**
     * @inheritdoc
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function with(array $data)
    {
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function ready()
    {
        $callBack = $this->config->get('sadad.callback-url');

        $this->newTransaction();

        $query = parse_url($callBack, PHP_URL_QUERY);
        # append transaction_id to callback url (necessary for verify)
        if ($query) {
            $callBack .= '&transaction_id='.$this->transactionId();
        } else {
            $callBack .= '?transaction_id='.$this->transactionId();
        }

        $this->form = '';
        try{
            $soap = new SoapClient($this->urlWebService);
            $amount = intval($this->amount);

            $response = $soap->PaymentUtility(
                $this->merchant,
                $amount,
                $this->transactionId(),
                $this->transactionKey,
                $this->terminalId,
                $callBack
            );

            if(!isset($response['RequestKey']) || !isset($response['PaymentUtilityResult'])) {
                $this->newLog(SadadResult::INVALID_RESPONSE_CODE,SadadResult::INVALID_RESPONSE_MESSAGE);
                throw new SadadException(SadadResult::INVALID_RESPONSE_MESSAGE,SadadResult::INVALID_RESPONSE_CODE);
            }

            $this->form = $response['PaymentUtilityResult'];

            $this->refId = $response['RequestKey'];

            $this->transactionSetRefId();
            return $this;
        } catch (\SoapFault $e) {
            $this->newLog(SadadResult::ERROR_CONNECT,SadadResult::ERROR_CONNECT_MESSAGE);
            throw new SadadException(SadadResult::ERROR_CONNECT_MESSAGE,SadadResult::ERROR_CONNECT);
        }
    }

    /**
     * @inheritdoc
     */
    public function redirect()
    {
        $form = $this->form;
        include __DIR__.'/submitForm.php';
    }

    /**
     * @inheritdoc
     */
    public function verify($transaction)
    {
        if(!isset($transaction->id))
            throw new SadadException('تراکنشی برای این شناسه یافت نشد.');

        $this->transactionId = $transaction->id;
        $soap = new SoapClient($this->urlWebService);
        $result = $soap->CheckRequestStatusResult(
            intval($this->transactionId),
            $this->merchant,
            $this->terminalId,
            $this->transactionKey,
            $transaction->ref_id,
            intval($transaction->price)
        );

        if(empty($result) || !isset($result->AppStatusCode))
            throw new SadadException('در دریافت اطلاعات از بانک خطایی رخ داده است.');

        $status_result = strval($result->AppStatusCode);
        $AppStatus = strtolower($result->AppStatusDescription);

        $message = $this->getMessage($status_result,$AppStatus);

        $this->newLog($status_result,$message['fa']);

        if($status_result == 0 && $AppStatus === 'commit') {       # تراکنش با موفقیت ثبت شده.
            $this->refId = $transaction->ref_id;

            $this->trackingCode = $result->TraceNo;
            $this->cardNumber = $result->CustomerCardNumber;
            $this->transactionSucceed();
            return $this;
        } else {
            $this->transactionFailed();
            throw new SadadException($message['fa'],$status_result);
        }
    }
    /**
     * register error to error list
     *
     * @param int $code
     * @param string $message
     * @return array|null
     * @throws SadadException
     */
    private function getMessage($code, $message)
    {
        $result = SadadResult::codeResponse($code,$message);
        if (!$result)
            $result = [
                'code'=>SadadResult::UNKNOWN_CODE,
                'message'=>SadadResult::UNKNOWN_MESSAGE,
                'fa'=>'خطای ناشناخته',
                'en'=>'Unknown Error',
                'retry'=>false
            ];

        return $result;
    }
}