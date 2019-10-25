<?php

namespace Lone\SystempayBundle\Service;

use InvalidArgumentException;
use Lone\SystempayBundle\Entity\AbstractTransaction;
use Lone\SystemPayBundle\Model\AbstractCustomer;
use Lone\SystempayBundle\Model\AbstractOrder;
use Lone\SystempayBundle\Model\PaymentStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class SystempayService
{
    const SHA1 = 'sha1';
    const HMAC_SHA256 = 'hmac_sha256';
    const ACCEPTED_HASH_METHOD = [self::SHA1, self::HMAC_SHA256];

    const PAYMENT_URL = 'https://paiement.systempay.fr/vads-payment/';

    private $hash = self::SHA1;
    private $key;
    private $fields = [
        'language' => null,
        'return_mode' => null,
        'action_mode' => null,
        'ctx_mode' => null,
        'page_action' => null,
        'payment_config' => null,
        'site_id' => null,
        'version' => null,
        'url_return' => null,
    ];

    public function __construct(ContainerInterface $container)
    {
        $this->hash = $container->getParameter('systempay.hash_method');
        // Check hash method
        if (!in_array($this->hash, self::ACCEPTED_HASH_METHOD)) {
            throw new InvalidArgumentException(sprintf(
                "Hash method '%s' is not supported. Possible values are : %s",
                $this->hash,
                implode(', ', self::ACCEPTED_HASH_METHOD)
            ));
        }

        // Set fields from configuration
        foreach ($this->fields as $field => $value) {
            $this->fields[$field] = $container->getParameter('systempay.vads.'.$field);
        }

        // Set the correct key to use (dev if ctx_mode is TEST, prod otherwise)
        $this->key = $container->getParameter(
            'systempay.key_'.($this->fields['ctx_mode'] === "TEST" ? 'dev' : 'prod')
        );
    }

    /**
     * Setting up the mandatory fields
     * @param AbstractTransaction $transaction
     */
    public function init(AbstractTransaction $transaction): void
    {
        if ($transaction->systempayTransactionId() === null) {
            throw new InvalidArgumentException('SystempayTransactionId must be set before calling init().');
        }

        $this->fields['amount'] = $transaction->amount();
        $this->fields['currency'] = $transaction->currency();
        $this->fields['trans_id'] = sprintf('%06d', $transaction->systempayTransactionId());
        $this->fields['trans_date'] = gmdate('YmdHis');
    }

    /**
     * @param $fields
     * Add or change fields. You can had cust field for delivery / shipping / etc.
     * remove "vads_" prefix from field and pass an array like : [field_name => value]
     * cust_email => xxxxxx@xx.xx
     */
    public function setFields(array $fields): void
    {
        $this->fields = array_merge($this->fields, $fields);
    }

    public function setOrder(AbstractOrder $order): void {
        // TODO : Create an easy way to add a customer to a transaction
    }

    public function setCustomer(AbstractCustomer $customer): void {
        // TODO : Create an easy way to add a customer to a transaction
    }

    public function getPaymentFormFields(): array
    {
        // Prefix fields with vads and add signature
        $fields = $this->setPrefixToFields($this->fields);
        $fields['signature'] = $this->getSignature($fields);
        return $fields;
    }

    /**
     * Update the field
     * @param AbstractTransaction $transaction
     * @param Request $request
     * @return AbstractTransaction
     */
    public function responseHandler(AbstractTransaction $transaction, Request $request): AbstractTransaction
    {
        $query = $request->request->all();
        $fields = $query;
        unset($fields['signature']);
        $signature = $query['signature'] ?? null;

        // Check signature
        if ($signature === null || $signature !== $this->getSignature($fields)) {
            throw new InvalidArgumentException("Signature is not valid");
        }

        $status = $fields['vads_trans_status'];

        // Signature is valid, update transaction
        $transaction->changeStatus($status);
        $transaction->setLogResponse(base64_encode(json_encode($fields)));

        // Payment is valid
        if (PaymentStatus::isValidStatus($status)) {
            $transaction->pay();
        }

        // Pay has been cancelled by marchant
        if ($status === PaymentStatus::CANCELLED) {
            $transaction->refund();
        }

        return $transaction;
    }

    /**
     * Get vads_trans_id from request
     * @param Request $request
     * @return string|null
     */
    public function getTransactionIdFromRequest(Request $request): ?string
    {
        return $request->request->get('vads_trans_id');
    }

    /**
     * Get the payment url
     * @return string
     */
    public function getPaymentUrl(): string
    {
        return self::PAYMENT_URL;
    }

    private function setPrefixToFields(array $fields): array
    {
        $prefixedFields = [];
        foreach ($fields as $field => $value)
            $prefixedFields[sprintf('vads_%s', $field)] = $value;
        return $prefixedFields;
    }

    /**
     * @param array $fields
     * @return string
     */
    private function getSignature(array $fields)
    {
        ksort($fields);
        $signatureFields = [];
        foreach ($fields as $field => $value) {
            if (substr($field, 0, 5) === 'vads_') {
                $signatureFields[] =  $value;
            }
        }
        $signatureFields[] = $this->key;

        // Form the signature by concatenating the fields
        $signature = implode('+', $signatureFields);

        if ($this->hash === self::HMAC_SHA256) {
            return base64_encode(hash_hmac('sha256', $signature, $this->key, true));
        }

        return sha1($signature);
    }
}
